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

namespace Tests\CatalogoCore;

use FSFramework\model\catalogo_idioma;
use FSFramework\model\catalogo_opcional;
use FSFramework\model\catalogo_opcional_idioma;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_idioma.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';

/**
 * GDI-13 — opcional `nombre`/`descripcion` multi-language read chain.
 *
 * Read chain (design AD-3): an absent language code resolves the base column; a
 * requested language resolves its own row; an absent requested row falls back to
 * the configured default language; an absent default row falls back to the base
 * column. Every leg is a pure read (no fallback materialisation). A row whose
 * `nombre` and `descripcion` are both empty is removed on save.
 *
 * DB-free: the opcional routes its language API through the two protected seams
 * (`idioma_model()` and `language_registry()`), injected with in-memory fakes.
 */
final class CatalogoOpcionalIdiomaTest extends TestCase
{
    public function test_requested_language_wins(): void
    {
        $db = new FakeOpcionalIdiomaDb([
            ['codigo' => 'OPC0001', 'codidioma' => 'es', 'nombre' => 'Hola', 'descripcion' => 'Desc ES'],
            ['codigo' => 'OPC0001', 'codidioma' => 'en', 'nombre' => 'Hello', 'descripcion' => 'Desc EN'],
        ]);
        $opcional = new FakeOpcionalIdiomaChain($db, 'es', 'OPC0001', 'Base', 'Base desc');

        $this->assertSame('Hello', $opcional->get_nombre_idioma('en'));
        $this->assertSame('Desc EN', $opcional->get_descripcion_idioma('en'));
        $this->assertSame('Hola', $opcional->get_nombre_idioma('es'));
        $this->assertSame('Desc ES', $opcional->get_descripcion_idioma('es'));
    }

    public function test_fallback_uses_the_configured_default(): void
    {
        $db = new FakeOpcionalIdiomaDb([
            ['codigo' => 'OPC0001', 'codidioma' => 'en', 'nombre' => 'Hello EN', 'descripcion' => 'Desc EN'],
        ]);
        $opcional = new FakeOpcionalIdiomaChain($db, 'en', 'OPC0001', 'Base', 'Base desc');

        $this->assertSame('Hello EN', $opcional->get_nombre_idioma('fr'), 'fr has no row: the configured default (en) wins');
        $this->assertSame('Desc EN', $opcional->get_descripcion_idioma('fr'));
    }

    public function test_base_column_is_the_terminal_fallback(): void
    {
        $db = new FakeOpcionalIdiomaDb([]);
        $opcional = new FakeOpcionalIdiomaChain($db, 'es', 'OPC0001', 'Base name', 'Base desc');

        $this->assertSame('Base name', $opcional->get_nombre_idioma('fr'));
        $this->assertSame('Base desc', $opcional->get_descripcion_idioma('fr'));
    }

    public function test_no_language_returns_the_base_column(): void
    {
        $db = new FakeOpcionalIdiomaDb([
            ['codigo' => 'OPC0001', 'codidioma' => 'es', 'nombre' => 'Hola', 'descripcion' => 'Desc ES'],
        ]);
        $opcional = new FakeOpcionalIdiomaChain($db, 'es', 'OPC0001', 'Base name', 'Base desc');

        $this->assertSame('Base name', $opcional->get_nombre_idioma(), 'a null code resolves the base column');
        $this->assertSame('Base name', $opcional->get_nombre_idioma(''), 'an empty code resolves the base column');
        $this->assertSame('Base desc', $opcional->get_descripcion_idioma(null));
    }

    public function test_a_present_row_does_not_fall_through_per_column(): void
    {
        // Row-level semantics (mirror of articulo::get_descripcion_idioma()):
        // the fallback legs trigger on an ABSENT ROW, not on an empty column.
        // A partial row is a legitimate explicit override for that language.
        $db = new FakeOpcionalIdiomaDb([
            ['codigo' => 'OPC0001', 'codidioma' => 'en', 'nombre' => '', 'descripcion' => 'Only desc'],
        ]);
        $opcional = new FakeOpcionalIdiomaChain($db, 'es', 'OPC0001', 'Base', 'Base desc');

        $this->assertSame('', $opcional->get_nombre_idioma('en'));
        $this->assertSame('Only desc', $opcional->get_descripcion_idioma('en'));
    }

    public function test_empty_pair_removes_the_row(): void
    {
        $db = new FakeOpcionalIdiomaDb([
            ['codigo' => 'OPC0001', 'codidioma' => 'en', 'nombre' => 'Hello', 'descripcion' => 'Desc EN'],
        ]);
        $opcional = new FakeOpcionalIdiomaChain($db, 'es', 'OPC0001', 'Base', 'Base desc');

        $this->assertTrue($opcional->set_idioma('en', '', ''));
        $this->assertSame([], $db->rows, 'the all-empty pair must delete the existing row');
    }

    public function test_empty_pair_on_absent_language_materialises_nothing(): void
    {
        $db = new FakeOpcionalIdiomaDb([]);
        $opcional = new FakeOpcionalIdiomaChain($db, 'es', 'OPC0001', 'Base', 'Base desc');

        $this->assertTrue($opcional->set_idioma('fr', '', ''));
        $this->assertSame([], $db->rows, 'an empty pair for a language with no row must not create one');
    }

    public function test_partial_pair_is_preserved(): void
    {
        $db = new FakeOpcionalIdiomaDb([]);
        $opcional = new FakeOpcionalIdiomaChain($db, 'es', 'OPC0001', 'Base', 'Base desc');

        $this->assertTrue($opcional->set_idioma('en', 'Hello', ''));
        $this->assertCount(1, $db->rows);
        $this->assertSame('Hello', $db->rows[0]['nombre']);
        $this->assertSame('', $db->rows[0]['descripcion']);
    }

    public function test_set_idioma_upserts_an_existing_row(): void
    {
        $db = new FakeOpcionalIdiomaDb([
            ['codigo' => 'OPC0001', 'codidioma' => 'en', 'nombre' => 'Old', 'descripcion' => 'Old desc'],
        ]);
        $opcional = new FakeOpcionalIdiomaChain($db, 'es', 'OPC0001', 'Base', 'Base desc');

        $this->assertTrue($opcional->set_idioma('en', 'New', 'New desc'));

        $this->assertCount(1, $db->rows, 'an upsert must not duplicate the natural key');
        $this->assertSame('New', $db->rows[0]['nombre']);
        $this->assertSame('New desc', $db->rows[0]['descripcion']);
    }

    public function test_get_idiomas_returns_all_rows(): void
    {
        $db = new FakeOpcionalIdiomaDb([
            ['codigo' => 'OPC0001', 'codidioma' => 'es', 'nombre' => 'Hola', 'descripcion' => 'Desc ES'],
            ['codigo' => 'OPC0001', 'codidioma' => 'en', 'nombre' => 'Hello', 'descripcion' => 'Desc EN'],
            ['codigo' => 'OPC0002', 'codidioma' => 'en', 'nombre' => 'Other', 'descripcion' => 'Other desc'],
        ]);
        $opcional = new FakeOpcionalIdiomaChain($db, 'es', 'OPC0001', 'Base', 'Base desc');

        $rows = $opcional->get_idiomas();
        $this->assertCount(2, $rows, 'only the rows of this opcional are returned');
        $this->assertSame(['en', 'es'], array_map(static fn ($row): string => (string) $row->codidioma, $rows));
    }

    public function test_set_idioma_is_refused_without_a_codigo(): void
    {
        $db = new FakeOpcionalIdiomaDb([]);
        $opcional = new FakeOpcionalIdiomaChain($db, 'es', '', 'Base', 'Base desc');

        $this->assertFalse($opcional->set_idioma('en', 'Hello', 'Desc EN'));
        $this->assertSame([], $db->rows);
        $this->assertSame([], $db->executed, 'a refused upsert must issue no statement');
    }

    public function test_reads_materialise_nothing(): void
    {
        $db = new FakeOpcionalIdiomaDb([]);
        $opcional = new FakeOpcionalIdiomaChain($db, 'es', 'OPC0001', 'Base', 'Base desc');

        $opcional->get_nombre_idioma('fr');
        $opcional->get_nombre_idioma('es');
        $opcional->get_nombre_idioma();
        $opcional->get_descripcion_idioma('en');
        $opcional->get_idiomas();

        $this->assertSame([], $db->executed, 'a read must issue no write statement');
        $this->assertSame([], $db->rows, 'no leg of the read chain may create a row');
    }
}

/**
 * In-memory fake of `fs_db2` limited to the `catalogo_opcional_idiomas`
 * statements the model emits, so the chain and the clearing semantics can be
 * exercised without a database.
 */
final class FakeOpcionalIdiomaDb
{
    /** @var list<array{id: int, codigo: string, codidioma: string, nombre: string, descripcion: ?string}> */
    public array $rows = [];

    /** @var list<string> */
    public array $executed = [];

    private int $seq = 0;

    /**
     * @param list<array{codigo: string, codidioma: string, nombre?: string, descripcion?: ?string, id?: int}> $rows
     */
    public function __construct(array $rows = [])
    {
        foreach ($rows as $row) {
            $this->seq++;
            $this->rows[] = [
                'id' => $row['id'] ?? $this->seq,
                'codigo' => (string) $row['codigo'],
                'codidioma' => (string) $row['codidioma'],
                'nombre' => (string) ($row['nombre'] ?? ''),
                'descripcion' => $row['descripcion'] ?? null,
            ];
        }
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

    public function lastval()
    {
        return $this->seq;
    }

    public function select($sql, $params = [])
    {
        $sql = trim((string) $sql);

        if (preg_match(
            '/^SELECT \* FROM catalogo_opcional_idiomas WHERE codigo = \'([^\']*)\' AND codidioma = \'([^\']*)\';?$/i',
            $sql,
            $m
        )) {
            foreach ($this->rows as $row) {
                if ($row['codigo'] === $m[1] && $row['codidioma'] === $m[2]) {
                    return [$this->export($row)];
                }
            }

            return [];
        }

        if (preg_match(
            '/^SELECT \* FROM catalogo_opcional_idiomas WHERE codigo = \'([^\']*)\' ORDER BY codidioma ASC;?$/i',
            $sql,
            $m
        )) {
            $out = [];
            foreach ($this->rows as $row) {
                if ($row['codigo'] === $m[1]) {
                    $out[] = $this->export($row);
                }
            }
            usort($out, static fn (array $a, array $b): int => strcmp($a['codidioma'], $b['codidioma']));

            return $out;
        }

        if (preg_match('/^SELECT \* FROM catalogo_opcional_idiomas WHERE id = (\d+);?$/i', $sql, $m)) {
            foreach ($this->rows as $row) {
                if ((int) $row['id'] === (int) $m[1]) {
                    return [$this->export($row)];
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

        if (preg_match(
            '/^INSERT INTO catalogo_opcional_idiomas \(codigo, codidioma, nombre, descripcion\) VALUES \((.*)\);?$/i',
            $sql,
            $m
        )) {
            $values = $this->parseValues($m[1]);
            $this->seq++;
            $this->rows[] = [
                'id' => $this->seq,
                'codigo' => (string) $values[0],
                'codidioma' => (string) $values[1],
                'nombre' => (string) ($values[2] ?? ''),
                'descripcion' => $values[3],
            ];

            return true;
        }

        if (preg_match(
            '/^UPDATE catalogo_opcional_idiomas SET codigo = (NULL|\'[^\']*\'), codidioma = (NULL|\'[^\']*\'), nombre = (NULL|\'[^\']*\'), descripcion = (NULL|\'[^\']*\') WHERE id = (\d+);?$/i',
            $sql,
            $m
        )) {
            foreach ($this->rows as $i => $row) {
                if ((int) $row['id'] === (int) $m[5]) {
                    $this->rows[$i]['codigo'] = (string) $this->unquote($m[1]);
                    $this->rows[$i]['codidioma'] = (string) $this->unquote($m[2]);
                    $this->rows[$i]['nombre'] = (string) $this->unquote($m[3]);
                    $this->rows[$i]['descripcion'] = $this->unquote($m[4]);
                }
            }

            return true;
        }

        if (preg_match('/^DELETE FROM catalogo_opcional_idiomas WHERE id = (\d+);?$/i', $sql, $m)) {
            $this->rows = array_values(array_filter(
                $this->rows,
                static fn (array $row): bool => (int) $row['id'] !== (int) $m[1]
            ));

            return true;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function export(array $row): array
    {
        return [
            'id' => $row['id'],
            'codigo' => $row['codigo'],
            'codidioma' => $row['codidioma'],
            'nombre' => $row['nombre'],
            'descripcion' => $row['descripcion'],
        ];
    }

    /**
     * @return list<mixed>
     */
    private function parseValues(string $raw): array
    {
        $values = [];
        foreach (array_map('trim', explode(',', $raw)) as $token) {
            $values[] = $this->unquote($token);
        }

        return $values;
    }

    private function unquote(string $token)
    {
        if (strcasecmp($token, 'NULL') === 0) {
            return null;
        }
        if (strcasecmp($token, 'TRUE') === 0) {
            return true;
        }
        if (strcasecmp($token, 'FALSE') === 0) {
            return false;
        }
        if (preg_match('/^-?\d+$/', $token)) {
            return $token;
        }

        return trim($token, "'");
    }
}

/**
 * DB-free double for a multi-language opcional row: it skips the `fs_model`
 * constructor, injects the in-memory fake and re-injects it into the rows
 * returned by the model's own lookups.
 */
final class FakeOpcionalIdiomaModel extends catalogo_opcional_idioma
{
    /** @var list<string> */
    public array $errors = [];

    public function __construct($data = false)
    {
        $this->table_name = catalogo_opcional_idioma::TABLE;

        if ($data) {
            $this->id = isset($data['id']) ? (int) $data['id'] : null;
            $this->codigo = $data['codigo'] ?? null;
            $this->codidioma = $data['codidioma'] ?? catalogo_idioma::DEFAULT_CODE;
            $this->nombre = $data['nombre'] ?? '';
            $this->descripcion = $data['descripcion'] ?? null;
        } else {
            $this->id = null;
            $this->codigo = null;
            $this->codidioma = catalogo_idioma::DEFAULT_CODE;
            $this->nombre = '';
            $this->descripcion = null;
        }
    }

    public function useFakeDb(FakeOpcionalIdiomaDb $db): self
    {
        $this->db = $db;

        return $this;
    }

    public function get_by_opcional_idioma($codigo, $codidioma)
    {
        $row = parent::get_by_opcional_idioma($codigo, $codidioma);
        if ($row) {
            $row->useFakeDb($this->db);
        }

        return $row;
    }

    public function all_from_opcional($codigo)
    {
        $rows = parent::all_from_opcional($codigo);
        foreach ($rows as $row) {
            $row->useFakeDb($this->db);
        }

        return $rows;
    }

    protected function new_error_msg($msg)
    {
        $this->errors[] = (string) $msg;
    }
}

/**
 * Minimal language-registry double exposing the single method the chain needs.
 */
final class FakeOpcionalLanguageRegistry
{
    public function __construct(private string $defaultCode)
    {
    }

    public function get_effective_default_code(): string
    {
        return $this->defaultCode;
    }
}

/**
 * DB-free opcional double: routes the language API through the same protected
 * seams the production model exposes.
 */
final class FakeOpcionalIdiomaChain extends catalogo_opcional
{
    private FakeOpcionalIdiomaDb $idDb;

    public function __construct(
        FakeOpcionalIdiomaDb $db,
        private string $defaultCode,
        string $codigo = 'OPC0001',
        string $nombre = '',
        string $descripcion = ''
    ) {
        $this->idDb = $db;
        $this->table_name = catalogo_opcional::TABLE;
        $this->id = 1;
        $this->codigo = $codigo;
        $this->nombre = $nombre;
        $this->descripcion = $descripcion;
        $this->precio = 0.0;
        $this->tipo_precio = catalogo_opcional::TIPO_PRECIO_FIJO;
        $this->porcentaje = null;
        $this->activo = true;
        $this->cantidad_min = 1;
        $this->cantidad_max = 1;
        $this->imagen = null;
    }

    protected function idioma_model()
    {
        return (new FakeOpcionalIdiomaModel())->useFakeDb($this->idDb);
    }

    protected function language_registry()
    {
        return new FakeOpcionalLanguageRegistry($this->defaultCode);
    }
}
