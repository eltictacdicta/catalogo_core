<?php
/**
 * This file is part of catalogo_core
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
 * Read-only exposure of the conditional flows that apply to a
 * `(referencia, codfamilia)` pair (FLC-05, AD-5).
 *
 * The core stores and exposes; consumers (`tpvmod`, `clientes_core`) evaluate.
 * Nothing here writes a row. The reads are batched: two assignment queries plus
 * one query for the flows, one for their conditions and one for their actions,
 * so the count never grows with the number of applicable flows.
 *
 * Deterministic order (FLC-06 / AD-7, locked): family-scope flows first, then
 * article-scope; within a scope `prioridad` ascending, then `id` ascending.
 * The later entry wins, so an article-scoped flow overrides a family-scoped one.
 */
class FlujoResolver
{
    public const SCOPE_FAMILIA = 'familia';
    public const SCOPE_ARTICULO = 'articulo';

    private const TBL_ARTICULOS = 'catalogo_flujo_articulos';
    private const TBL_FAMILIAS = 'catalogo_flujo_familias';
    private const TBL_FLUJOS = 'catalogo_flujos';
    private const TBL_CONDICIONES = 'catalogo_flujo_condiciones';
    private const TBL_ACCIONES = 'catalogo_flujo_acciones';

    /** @var object|null */
    private $dbInstance = null;

    /**
     * @return list<array{
     *   codigo:string, prioridad:int, scope:'familia'|'articulo',
     *   condiciones:list<array{grupo_and_or:'AND'|'OR', sujeto_tipo:string,
     *     sujeto_codigo:string, operador:string, valor:?string}>,
     *   acciones:list<array{accion:string, sujeto_tipo:string, sujeto_codigo:string}>
     * }>
     */
    public function get_aplicables(string $referencia, string $codfamilia): array
    {
        $referencia = trim($referencia);
        $codfamilia = trim($codfamilia);

        $articleIds = $referencia === '' ? [] : $this->flow_ids(
            'SELECT id_flujo FROM ' . self::TBL_ARTICULOS
            . ' WHERE referencia = ' . $this->var2str($referencia) . ' ORDER BY id_flujo ASC;'
        );
        $familyIds = $codfamilia === '' ? [] : $this->flow_ids(
            'SELECT id_flujo FROM ' . self::TBL_FAMILIAS
            . ' WHERE codfamilia = ' . $this->var2str($codfamilia) . ' ORDER BY id_flujo ASC;'
        );

        $candidateIds = array_values(array_unique(array_merge($articleIds, $familyIds)));
        if ($candidateIds === []) {
            return [];
        }

        $flowMap = [];
        $flowRows = (array) $this->db()->select(
            'SELECT * FROM ' . self::TBL_FLUJOS . ' WHERE id IN (' . $this->in_list($candidateIds) . ')'
            . ' AND activo = TRUE ORDER BY prioridad ASC, id ASC;'
        );
        foreach ($flowRows as $row) {
            $flowMap[(int) $row['id']] = $row;
        }

        // The SQL already filters `activo = TRUE`; the PHP pass is the
        // authoritative guard for engines/doubles that do not.
        $activeIds = [];
        foreach ($candidateIds as $id) {
            $row = $flowMap[$id] ?? null;
            if ($row !== null && $this->is_active($row)) {
                $activeIds[] = $id;
            }
        }
        if ($activeIds === []) {
            return [];
        }

        $conditions = $this->group_by_flow((array) $this->db()->select(
            'SELECT * FROM ' . self::TBL_CONDICIONES
            . ' WHERE id_flujo IN (' . $this->in_list($activeIds) . ') ORDER BY id ASC;'
        ));
        $actions = $this->group_by_flow((array) $this->db()->select(
            'SELECT * FROM ' . self::TBL_ACCIONES
            . ' WHERE id_flujo IN (' . $this->in_list($activeIds) . ') ORDER BY id ASC;'
        ));

        $entries = [];
        foreach ($activeIds as $id) {
            $row = $flowMap[$id];
            $entries[] = [
                'id' => $id,
                'codigo' => (string) ($row['codigo'] ?? ''),
                'prioridad' => (int) ($row['prioridad'] ?? 0),
                // A flow assigned to the article is article-scope even when it
                // is also assigned to the family: the article scope wins.
                'scope' => in_array($id, $articleIds, true) ? self::SCOPE_ARTICULO : self::SCOPE_FAMILIA,
                'condiciones' => array_map([$this, 'map_condicion'], $conditions[$id] ?? []),
                'acciones' => array_map([$this, 'map_accion'], $actions[$id] ?? []),
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            $rank = [self::SCOPE_FAMILIA => 0, self::SCOPE_ARTICULO => 1];
            $scope = $rank[$a['scope']] <=> $rank[$b['scope']];
            if ($scope !== 0) {
                return $scope;
            }
            if ($a['prioridad'] !== $b['prioridad']) {
                return $a['prioridad'] <=> $b['prioridad'];
            }

            return $a['id'] <=> $b['id'];
        });

        $result = [];
        foreach ($entries as $entry) {
            unset($entry['id']);
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @return list<int>
     */
    private function flow_ids(string $sql): array
    {
        $ids = [];
        foreach ((array) $this->db()->select($sql) as $row) {
            $id = (int) ($row['id_flujo'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, list<array<string, mixed>>>
     */
    private function group_by_flow(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int) ($row['id_flujo'] ?? 0)][] = $row;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{grupo_and_or:string, sujeto_tipo:string, sujeto_codigo:string, operador:string, valor:?string}
     */
    private function map_condicion(array $row): array
    {
        $group = strtoupper((string) ($row['grupo_and_or'] ?? 'AND'));

        return [
            'grupo_and_or' => $group === 'OR' ? 'OR' : 'AND',
            'sujeto_tipo' => (string) ($row['sujeto_tipo'] ?? ''),
            'sujeto_codigo' => (string) ($row['sujeto_codigo'] ?? ''),
            'operador' => (string) ($row['operador'] ?? ''),
            'valor' => ($row['valor'] ?? null) === null ? null : (string) $row['valor'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{accion:string, sujeto_tipo:string, sujeto_codigo:string}
     */
    private function map_accion(array $row): array
    {
        return [
            'accion' => (string) ($row['accion'] ?? ''),
            'sujeto_tipo' => (string) ($row['sujeto_tipo'] ?? ''),
            'sujeto_codigo' => (string) ($row['sujeto_codigo'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function is_active(array $row): bool
    {
        $value = $row['activo'] ?? false;
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'si', 'sí'], true);
    }

    /**
     * @param array<int, mixed> $values
     */
    private function in_list(array $values): string
    {
        $parts = [];
        foreach ($values as $value) {
            $parts[] = $this->var2str($value);
        }

        return $parts === [] ? 'NULL' : implode(', ', $parts);
    }

    /**
     * @param mixed $value
     */
    private function var2str($value): string
    {
        return (string) $this->db()->var2str($value);
    }

    /**
     * DB seam (unit tests inject a recording double).
     *
     * @return object
     */
    protected function db(): object
    {
        if ($this->dbInstance === null) {
            $this->dbInstance = new \fs_db2();
        }

        return $this->dbInstance;
    }
}
