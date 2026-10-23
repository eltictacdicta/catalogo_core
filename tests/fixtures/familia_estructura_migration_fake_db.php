<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore\Fixtures;

/**
 * Stateful `fs_db2` double for the family-structure migration and the family
 * tree guard.
 *
 * It records every executed statement, emulates the two `INSERT ... SELECT`
 * copies with their inner join on `catalogo_listas_precio` (so orphan rows are
 * skipped exactly like the real engine does), and answers the table-existence
 * and `COUNT(*)` probes used by the services.
 *
 * The parent constructor is intentionally skipped: no engine, no connection.
 */
final class FamiliaEstructuraMigrationFakeDb extends \fs_db2
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables;

    /** @var list<string> */
    public array $executed = [];

    /** @var list<string> */
    public array $selected = [];

    /** @var array{begin: int, commit: int, rollback: int} */
    public array $transactions = ['begin' => 0, 'commit' => 0, 'rollback' => 0];

    /** When set, any statement containing this needle throws. */
    public ?string $throwOn = null;

    /**
     * @param array<string, list<array<string, mixed>>> $tables
     */
    public function __construct(array $tables = [])
    {
        $this->tables = $tables;
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

    public function select($sql, $params = [])
    {
        $sql = trim($sql);
        $this->selected[] = $sql;

        if (preg_match("/^SHOW TABLES LIKE '(.+?)';?$/i", $sql, $m)) {
            return isset($this->tables[$m[1]]) ? [['Tables_in_db' => $m[1]]] : [];
        }

        if (preg_match("/information_schema\\.tables.*table_name = '([^']+)'/i", $sql, $m)) {
            return isset($this->tables[$m[1]]) ? [['1' => 1]] : [];
        }

        if (preg_match(
            '/^SELECT COUNT\(\*\) AS total FROM ([a-zA-Z0-9_]+) t '
            . 'INNER JOIN catalogo_listas_precio l ON l\.codlista = t\.codtarifa;?$/i',
            $sql,
            $m
        )) {
            return [['total' => $this->countMatched($m[1])]];
        }

        if (preg_match('/^SELECT COUNT\(\*\) AS total FROM ([a-zA-Z0-9_]+);?$/i', $sql, $m)) {
            return [['total' => count($this->tables[$m[1]] ?? [])]];
        }

        if (preg_match("/^SELECT madre FROM familias WHERE codfamilia = '([^']*)'/i", $sql, $m)) {
            foreach ($this->tables['familias'] ?? [] as $row) {
                if ((string) ($row['codfamilia'] ?? '') === $m[1]) {
                    return [$row];
                }
            }

            return [];
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $sql = trim($sql);
        $this->executed[] = $sql;

        if ($this->throwOn !== null && str_contains($sql, $this->throwOn)) {
            throw new \RuntimeException('forced failure: ' . $this->throwOn);
        }

        if (str_contains($sql, 'catalogo_articulo_familia_estructura')) {
            $this->applyArticleInsert();
        } elseif (str_contains($sql, 'catalogo_familia_estructura')) {
            $this->applyFamilyInsert();
        }

        return true;
    }

    public function begin_transaction()
    {
        $this->transactions['begin']++;

        return true;
    }

    public function commit()
    {
        $this->transactions['commit']++;

        return true;
    }

    public function rollback()
    {
        $this->transactions['rollback']++;

        return true;
    }

    /**
     * @return list<string>
     */
    public function executedContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->executed,
            static fn (string $sql): bool => str_contains($sql, $needle)
        ));
    }

    private function countMatched(string $source): int
    {
        $lists = $this->priceListCodes();
        $matched = 0;
        foreach ($this->tables[$source] ?? [] as $row) {
            if (in_array((string) ($row['codtarifa'] ?? ''), $lists, true)) {
                $matched++;
            }
        }

        return $matched;
    }

    /**
     * @return list<string>
     */
    private function priceListCodes(): array
    {
        return array_map(
            static fn ($value): string => (string) $value,
            array_column($this->tables['catalogo_listas_precio'] ?? [], 'codlista')
        );
    }

    private function applyFamilyInsert(): void
    {
        $lists = $this->priceListCodes();
        $seen = [];
        foreach ($this->tables['catalogo_familia_estructura'] ?? [] as $row) {
            $seen[(string) $row['codlista'] . '|' . (string) $row['codfamilia']] = true;
        }

        foreach ($this->tables['tarif_tarifa_familia'] ?? [] as $row) {
            $codlista = (string) ($row['codtarifa'] ?? '');
            if (!in_array($codlista, $lists, true)) {
                continue;
            }

            $codfamilia = (string) ($row['codfamilia'] ?? '');
            $key = $codlista . '|' . $codfamilia;
            if (isset($seen[$key])) {
                continue;
            }

            $this->tables['catalogo_familia_estructura'][] = [
                'codlista' => $codlista,
                'codfamilia' => $codfamilia,
                'madre' => $row['madre'] ?? null,
                'capitulo' => (string) ($row['capitulo'] ?? ''),
                'nivel' => (string) ($row['nivel'] ?? ''),
                'en_catalogo' => $row['en_catalogo'] ?? true,
                'en_tarifa' => $row['en_tarifa'] ?? false,
                'activa' => $row['activa'] ?? true,
                'orden' => (int) ($row['orden'] ?? 0),
            ];
            $seen[$key] = true;
        }
    }

    private function applyArticleInsert(): void
    {
        $lists = $this->priceListCodes();
        $seen = [];
        foreach ($this->tables['catalogo_articulo_familia_estructura'] ?? [] as $row) {
            $seen[(string) $row['codlista'] . '|' . (string) $row['referencia']] = true;
        }

        foreach ($this->tables['tarif_tarifa_articulo'] ?? [] as $row) {
            $codlista = (string) ($row['codtarifa'] ?? '');
            if (!in_array($codlista, $lists, true)) {
                continue;
            }

            $referencia = (string) ($row['referencia'] ?? '');
            $key = $codlista . '|' . $referencia;
            if (isset($seen[$key])) {
                continue;
            }

            $this->tables['catalogo_articulo_familia_estructura'][] = [
                'codlista' => $codlista,
                'referencia' => $referencia,
                'codfamilia' => $row['codfamilia'] ?? null,
                'en_tarifa' => $row['en_tarifa'] ?? true,
                'en_catalogo' => $row['en_catalogo'] ?? true,
                'orden' => (int) ($row['orden'] ?? 0),
            ];
            $seen[$key] = true;
        }
    }
}
