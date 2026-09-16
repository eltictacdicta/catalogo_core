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

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Batched read of the `listable` feature columns for a page (CAR-16).
 *
 * The query count is a constant independent of the page size N: the scope and
 * tarifa precedence of `CaracteristicaResolver` is replayed in PHP over
 * pre-loaded maps. No per-row query is ever issued.
 */
class CaracteristicaValorBatchReader
{
    private const DEF = 'DEF';

    /**
     * @param array<int, string> $refs
     * @param array<string, string>|null $familias ref => codfamilia
     * @param array<int, string>|null $codigos restrict to these definitions
     * @return array<string, array<string, ?string>> ref => codigo => raw value|null
     */
    public function for_referencias(
        array $refs,
        string $codtarifa,
        ?array $familias = null,
        ?array $codigos = null
    ): array {
        $refs = array_values(array_filter(array_map('strval', $refs), static fn (string $r): bool => $r !== ''));
        if ($refs === []) {
            return [];
        }

        $definitions = $this->definition_rows($codigos);
        if ($definitions === []) {
            return [];
        }

        $catalog = $this->catalog_map();
        $familyParents = $this->family_parents();
        $refFamilies = $familias ?? $this->ref_families($refs);

        $articleRows = $this->scope_rows('catalogo_caracteristica_articulo', $refs, $codtarifa, 'referencia');
        $familyRows = $this->scope_rows(
            'catalogo_caracteristica_familia',
            array_values(array_unique(array_filter(array_values($refFamilies)))),
            $codtarifa,
            'codfamilia'
        );
        $globalRows = $this->scope_rows('catalogo_caracteristica_global', [], $codtarifa, '');

        $result = [];
        foreach ($refs as $ref) {
            foreach ($definitions as $definition) {
                $value = $this->resolve_batched(
                    $definition,
                    $ref,
                    $refFamilies[$ref] ?? null,
                    $codtarifa,
                    $articleRows,
                    $familyRows,
                    $globalRows,
                    $catalog,
                    $familyParents
                );
                $result[$ref][(string) $definition['codigo']] = $value;
            }
        }

        return $result;
    }

    /**
     * Definition metadata for the column headers, ordered by orden then codigo.
     *
     * @param array<int, string>|null $codigos
     * @return array<int, array{codigo: string, nombre: string, tipo: string, orden: int}>
     */
    public function columns(?array $codigos = null): array
    {
        $columns = [];
        foreach ($this->definition_rows($codigos) as $definition) {
            $columns[] = [
                'codigo' => (string) $definition['codigo'],
                'nombre' => (string) $definition['nombre'],
                'tipo' => (string) $definition['tipo'],
                'orden' => (int) $definition['orden'],
            ];
        }

        return $columns;
    }

    // =====================================================================
    // Query steps (constant count)
    // =====================================================================

    /**
     * @param array<int, string>|null $codigos
     * @return array<int, array<string, mixed>>
     */
    private function definition_rows(?array $codigos): array
    {
        $sql = 'SELECT id, codigo, nombre, tipo, orden, valor_defecto FROM catalogo_caracteristicas'
            . ' WHERE activo = TRUE';
        if ($codigos === null) {
            $sql .= ' AND listable = TRUE';
        } else {
            $sql .= ' AND codigo IN (' . $this->in_list($codigos) . ')';
        }
        $sql .= ' ORDER BY orden ASC, codigo ASC;';

        return (array) $this->db()->select($sql);
    }

    /**
     * @return array<int, string> id_valor => valor
     */
    private function catalog_map(): array
    {
        $map = [];
        $rows = (array) $this->db()->select(
            'SELECT id, id_caracteristica, valor FROM catalogo_caracteristica_valores;'
        );
        foreach ($rows as $row) {
            $map[(int) $row['id']] = (string) $row['valor'];
        }

        return $map;
    }

    /**
     * @param array<int, string> $refs
     * @return array<string, ?string> ref => codfamilia
     */
    private function ref_families(array $refs): array
    {
        $map = [];
        $rows = (array) $this->db()->select(
            'SELECT referencia, codfamilia FROM articulos WHERE referencia IN (' . $this->in_list($refs) . ');'
        );
        foreach ($rows as $row) {
            $codfamilia = trim((string) ($row['codfamilia'] ?? ''));
            $map[(string) $row['referencia']] = $codfamilia === '' ? null : $codfamilia;
        }

        foreach ($refs as $ref) {
            $map[(string) $ref] = $map[(string) $ref] ?? null;
        }

        return $map;
    }

    /**
     * @return array<string, ?string> codfamilia => madre
     */
    private function family_parents(): array
    {
        $map = [];
        $rows = (array) $this->db()->select('SELECT codfamilia, madre FROM familias;');
        foreach ($rows as $row) {
            $madre = trim((string) ($row['madre'] ?? ''));
            $map[(string) $row['codfamilia']] = $madre === '' ? null : $madre;
        }

        return $map;
    }

    /**
     * @param array<int, string> $keys
     * @return array<string, array<string, array<int, array<string, mixed>>>>
     */
    private function scope_rows(string $table, array $keys, string $codtarifa, string $keyColumn): array
    {
        $tarifas = [$codtarifa, self::DEF];
        $where = 'codtarifa IN (' . $this->in_list($tarifas) . ')';
        if ($keyColumn !== '' && $keys !== []) {
            $where .= ' AND ' . $keyColumn . ' IN (' . $this->in_list($keys) . ')';
        } elseif ($keyColumn !== '' && $keys === []) {
            return [];
        }

        $rows = (array) $this->db()->select(
            'SELECT * FROM ' . $table . ' WHERE ' . $where . ';'
        );

        $map = [];
        foreach ($rows as $row) {
            $tarifa = (string) $row['codtarifa'];
            $key = $keyColumn === '' ? '' : (string) $row[$keyColumn];
            $map[$tarifa][$key][(int) $row['id_caracteristica']] = $row;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, array<string, array<int, array<string, mixed>>>> $articleRows
     * @param array<string, array<string, array<int, array<string, mixed>>>> $familyRows
     * @param array<string, array<int, array<string, mixed>>> $globalRows
     * @param array<int, string> $catalog
     * @param array<string, ?string> $familyParents
     */
    private function resolve_batched(
        array $definition,
        string $ref,
        ?string $codfamilia,
        string $codtarifa,
        array $articleRows,
        array $familyRows,
        array $globalRows,
        array $catalog,
        array $familyParents
    ): ?string {
        $id = (int) $definition['id'];

        foreach ([$codtarifa, self::DEF] as $tarifa) {
            $row = $articleRows[$tarifa][$ref][$id] ?? null;
            if ($row !== null) {
                return $this->value_of($row, $catalog);
            }
        }

        foreach ($this->family_chain($codfamilia, $familyParents) as $familia) {
            foreach ([$codtarifa, self::DEF] as $tarifa) {
                $row = $familyRows[$tarifa][$familia][$id] ?? null;
                if ($row !== null) {
                    return $this->value_of($row, $catalog);
                }
            }
        }

        foreach ([$codtarifa, self::DEF] as $tarifa) {
            $row = $globalRows[$tarifa][''][$id] ?? null;
            if ($row !== null) {
                return $this->value_of($row, $catalog);
            }
        }

        $default = $definition['valor_defecto'] ?? null;
        if ($default === null || (string) $default === '') {
            return null;
        }

        return (string) $default;
    }

    /**
     * @param array<string, ?string> $familyParents
     * @return list<string>
     */
    private function family_chain(?string $codfamilia, array $familyParents): array
    {
        if ($codfamilia === null || $codfamilia === '') {
            return [];
        }

        $chain = [];
        $visited = [];
        $current = $codfamilia;
        while ($current !== null
            && $current !== ''
            && !in_array($current, $visited, true)
            && count($chain) < 32) {
            $chain[] = $current;
            $visited[] = $current;
            $current = $familyParents[$current] ?? null;
        }

        return $chain;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $catalog
     */
    private function value_of(array $row, array $catalog): ?string
    {
        $custom = ($row['custom'] ?? false);
        $isCustom = is_bool($custom) ? $custom : ($custom == 't' || $custom == '1');
        if ($isCustom) {
            return $row['valor'] === null ? null : (string) $row['valor'];
        }

        $idValor = (int) ($row['id_valor'] ?? 0);

        return $catalog[$idValor] ?? null;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function in_list(array $values): string
    {
        $parts = [];
        foreach ($values as $value) {
            $parts[] = $this->db()->var2str($value);
        }

        return $parts === [] ? 'NULL' : implode(', ', $parts);
    }

    /**
     * DB seam (unit tests inject a counting double).
     *
     * @return object
     */
    protected function db()
    {
        return new \fs_db2();
    }
}
