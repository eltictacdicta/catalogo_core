<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore\Support;

/**
 * In-memory fake of fs_db2 limited to the statements the language registry and
 * the multi-language descriptions issue. It lets the invariants be verified
 * without a database: seeded rows are mutated exactly like the emitted SQL
 * prescribes, so a second resolution observes the real post-mutation state.
 */
final class IdiomaRegistryFake
{
    /** @var array<string, array{codidioma: string, nombre: string, activo: bool, por_defecto: bool}> */
    public array $idiomas = [];

    /** @var list<array{id: int, referencia: string, codidioma: string, descripcion: string, descripcion_corta: ?string}> */
    public array $descripciones = [];

    /** @var list<string> */
    public array $executed = [];

    public bool $committed = false;

    public bool $rolledback = false;

    public int $transactions = 0;

    public bool $failNextExec = false;

    /**
     * @param list<array{codidioma: string, nombre?: string, activo?: bool, por_defecto?: bool}> $idiomas
     * @param list<array{id?: int, referencia: string, codidioma: string, descripcion?: string, descripcion_corta?: ?string}> $descripciones
     */
    public function __construct(array $idiomas = [], array $descripciones = [])
    {
        foreach ($idiomas as $row) {
            $this->seed($row['codidioma'], $row['nombre'] ?? $row['codidioma'], $row['activo'] ?? true, $row['por_defecto'] ?? false);
        }
        foreach ($descripciones as $row) {
            $this->descripciones[] = [
                'id' => $row['id'] ?? (count($this->descripciones) + 1),
                'referencia' => $row['referencia'],
                'codidioma' => $row['codidioma'],
                'descripcion' => $row['descripcion'] ?? '',
                'descripcion_corta' => $row['descripcion_corta'] ?? null,
            ];
        }
    }

    public function seed(string $codidioma, string $nombre, bool $activo, bool $porDefecto): void
    {
        $this->idiomas[$codidioma] = [
            'codidioma' => $codidioma,
            'nombre' => $nombre,
            'activo' => $activo,
            'por_defecto' => $porDefecto,
        ];
    }

    /**
     * Codes carrying the default flag over an active language (the GDI-02 invariant).
     *
     * @return list<string>
     */
    public function activeDefaults(): array
    {
        $out = [];
        foreach ($this->idiomas as $cod => $row) {
            if ($row['por_defecto'] && $row['activo']) {
                $out[] = $cod;
            }
        }

        return $out;
    }

    public function var2str($val)
    {
        if ($val === null) {
            return 'NULL';
        }
        if (is_bool($val)) {
            return $val ? '1' : '0';
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function escape_string(string $str): string
    {
        return addslashes($str);
    }

    public function begin_transaction()
    {
        $this->transactions++;

        return true;
    }

    public function commit()
    {
        $this->committed = true;

        return true;
    }

    public function rollback()
    {
        $this->rolledback = true;

        return true;
    }

    public function lastval()
    {
        return count($this->descripciones) + 1;
    }

    public function select($sql, $params = [])
    {
        $sql = trim((string) $sql);

        if (preg_match('/^SELECT \* FROM catalogo_idiomas WHERE por_defecto = TRUE AND activo = TRUE LIMIT 1;?$/i', $sql)) {
            foreach ($this->idiomas as $row) {
                if ($row['por_defecto'] && $row['activo']) {
                    return [$row];
                }
            }

            return [];
        }

        if (preg_match('/^SELECT \* FROM catalogo_idiomas WHERE por_defecto = TRUE LIMIT 1;?$/i', $sql)) {
            foreach ($this->idiomas as $row) {
                if ($row['por_defecto']) {
                    return [$row];
                }
            }

            return [];
        }

        if (preg_match('/^SELECT codidioma FROM catalogo_idiomas WHERE activo = TRUE ORDER BY codidioma ASC LIMIT 1;?$/i', $sql)) {
            $codes = [];
            foreach ($this->idiomas as $cod => $row) {
                if ($row['activo']) {
                    $codes[] = $cod;
                }
            }
            sort($codes, SORT_STRING);

            return $codes ? [['codidioma' => $codes[0]]] : [];
        }

        if (preg_match('/^SELECT \* FROM catalogo_idiomas WHERE codidioma = \'([^\']*)\';?$/i', $sql, $m)) {
            return isset($this->idiomas[$m[1]]) ? [$this->idiomas[$m[1]]] : [];
        }

        if (preg_match('/^SELECT COUNT\(\*\) as total FROM catalogo_idiomas WHERE por_defecto = TRUE;?$/i', $sql)) {
            $count = 0;
            foreach ($this->idiomas as $row) {
                if ($row['por_defecto']) {
                    $count++;
                }
            }

            return [['total' => (string) $count]];
        }

        if (preg_match('/^SELECT COUNT\(\*\) as total FROM catalogo_idiomas;?$/i', $sql)) {
            return [['total' => (string) count($this->idiomas)]];
        }

        if (preg_match('/^SELECT \* FROM catalogo_idiomas(?: WHERE activo = TRUE)? ORDER BY nombre ASC;?$/i', $sql, $m)) {
            $rows = array_values($this->idiomas);
            if (stripos($sql, 'activo = TRUE') !== false) {
                $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['activo']));
            }
            usort($rows, static fn (array $a, array $b): int => strcmp($a['nombre'], $b['nombre']));

            return $rows;
        }

        // articulo_descripcion reads used by the language API.
        if (preg_match('/^SELECT \* FROM articulo_descripciones WHERE referencia = \'([^\']*)\' AND codidioma = \'([^\']*)\';?$/i', $sql, $m)) {
            foreach ($this->descripciones as $row) {
                if ((string) $row['referencia'] === $m[1] && (string) $row['codidioma'] === $m[2]) {
                    return [$row];
                }
            }

            return [];
        }

        if (preg_match('/^SELECT \* FROM articulo_descripciones WHERE referencia = \'([^\']*)\' ORDER BY codidioma ASC;?$/i', $sql, $m)) {
            $rows = [];
            foreach ($this->descripciones as $row) {
                if ((string) $row['referencia'] === $m[1]) {
                    $rows[] = $row;
                }
            }
            usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['codidioma'], (string) $b['codidioma']));

            return $rows;
        }

        if (preg_match('/^SELECT \* FROM articulo_descripciones WHERE id = (\d+);?$/i', $sql, $m)) {
            foreach ($this->descripciones as $row) {
                if ((int) $row['id'] === (int) $m[1]) {
                    return [$row];
                }
            }

            return [];
        }

        // Cheap pre-check used by catalogo_idioma::delete() to decide whether
        // the language owns description rows (and therefore whether the
        // search-cache obligation applies).
        if (preg_match('/^SELECT 1 FROM articulo_descripciones WHERE codidioma = \'([^\']*)\' LIMIT 1;?$/i', $sql, $m)) {
            foreach ($this->descripciones as $row) {
                if ((string) $row['codidioma'] === $m[1]) {
                    return [['1' => 1]];
                }
            }

            return [];
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $sql = trim((string) $sql);
        $this->executed[] = $sql;

        if ($this->failNextExec) {
            $this->failNextExec = false;

            return false;
        }

        if (preg_match('/^DELETE FROM articulo_descripciones WHERE (?:id = (\d+)|codidioma = \'([^\']*)\');?$/i', $sql, $m)) {
            $this->descripciones = array_values(array_filter(
                $this->descripciones,
                static function (array $row) use ($m): bool {
                    if (!empty($m[1])) {
                        return (int) $row['id'] !== (int) $m[1];
                    }

                    return (string) $row['codidioma'] !== $m[2];
                }
            ));

            return true;
        }

        if (preg_match('/^DELETE (?:d FROM|FROM) articulo_descripciones d (?:LEFT JOIN catalogo_idiomas i ON i\.codidioma = d\.codidioma WHERE i\.codidioma IS NULL|WHERE NOT EXISTS \(SELECT 1 FROM catalogo_idiomas i WHERE i\.codidioma = d\.codidioma\));?$/i', $sql)) {
            $this->descripciones = array_values(array_filter(
                $this->descripciones,
                fn (array $row): bool => isset($this->idiomas[$row['codidioma']])
            ));

            return true;
        }

        if (preg_match('/^DELETE FROM catalogo_idiomas WHERE codidioma = \'([^\']*)\';?$/i', $sql, $m)) {
            unset($this->idiomas[$m[1]]);

            return true;
        }

        if (preg_match('/^INSERT INTO catalogo_idiomas \(codidioma, nombre, activo, por_defecto\) VALUES \((.*)\);?$/i', $sql, $m)) {
            $values = $this->parseSqlValues($m[1]);
            $this->idiomas[(string) $values[0]] = [
                'codidioma' => (string) $values[0],
                'nombre' => (string) $values[1],
                'activo' => (bool) $values[2],
                'por_defecto' => (bool) $values[3],
            ];

            return true;
        }

        if (preg_match('/^UPDATE catalogo_idiomas SET por_defecto = FALSE WHERE codidioma != \'([^\']*)\';?$/i', $sql, $m)) {
            foreach ($this->idiomas as $cod => $row) {
                if ($cod !== $m[1]) {
                    $this->idiomas[$cod]['por_defecto'] = false;
                }
            }

            return true;
        }

        if (preg_match('/^UPDATE catalogo_idiomas SET por_defecto = FALSE WHERE por_defecto = TRUE AND activo = FALSE;?$/i', $sql)) {
            foreach ($this->idiomas as $cod => $row) {
                if ($row['por_defecto'] && !$row['activo']) {
                    $this->idiomas[$cod]['por_defecto'] = false;
                }
            }

            return true;
        }

        if (preg_match('/^UPDATE catalogo_idiomas SET por_defecto = FALSE WHERE por_defecto = TRUE;?$/i', $sql)) {
            foreach ($this->idiomas as $cod => $row) {
                $this->idiomas[$cod]['por_defecto'] = false;
            }

            return true;
        }

        if (preg_match('/^UPDATE catalogo_idiomas SET por_defecto = TRUE, activo = TRUE WHERE codidioma = \'([^\']*)\';?$/i', $sql, $m)) {
            if (isset($this->idiomas[$m[1]])) {
                $this->idiomas[$m[1]]['por_defecto'] = true;
                $this->idiomas[$m[1]]['activo'] = true;
            }

            return true;
        }

        if (preg_match('/^UPDATE catalogo_idiomas SET por_defecto = TRUE WHERE codidioma = \'([^\']*)\';?$/i', $sql, $m)) {
            foreach ($this->idiomas as $cod => $row) {
                $this->idiomas[$cod]['por_defecto'] = ($cod === $m[1]);
            }

            return true;
        }

        if (preg_match('/^UPDATE catalogo_idiomas SET nombre = \'([^\']*)\', activo = (\d+), por_defecto = (\d+) WHERE codidioma = \'([^\']*)\';?$/i', $sql, $m)) {
            if (isset($this->idiomas[$m[4]])) {
                $this->idiomas[$m[4]]['nombre'] = $m[1];
                $this->idiomas[$m[4]]['activo'] = (bool) $m[2];
                $this->idiomas[$m[4]]['por_defecto'] = (bool) $m[3];
            }

            return true;
        }

        if (preg_match('/^UPDATE articulo_descripciones SET referencia = \'([^\']*)\', codidioma = \'([^\']*)\', descripcion = \'([^\']*)\', descripcion_corta = (NULL|\'([^\']*)\') WHERE id = (\d+);?$/i', $sql, $m)) {
            foreach ($this->descripciones as $i => $row) {
                if ((int) $row['id'] === (int) $m[6]) {
                    $this->descripciones[$i]['descripcion'] = $m[3];
                    $this->descripciones[$i]['descripcion_corta'] = ($m[4] === 'NULL') ? null : $m[5];
                }
            }

            return true;
        }

        return true;
    }

    /**
     * @return list<mixed>
     */
    private function parseSqlValues(string $raw): array
    {
        $values = [];
        foreach (array_map('trim', explode(',', $raw)) as $token) {
            if (strcasecmp($token, 'NULL') === 0) {
                $values[] = null;
            } elseif (strcasecmp($token, 'TRUE') === 0) {
                $values[] = true;
            } elseif (strcasecmp($token, 'FALSE') === 0) {
                $values[] = false;
            } elseif (preg_match('/^(\d+)$/', $token, $m)) {
                $values[] = $m[1];
            } else {
                $values[] = trim($token, "'");
            }
        }

        return $values;
    }
}
