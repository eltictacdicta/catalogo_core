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

use FSFramework\Plugins\catalogo_core\Services\FlujoResolver;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_flujo.php';

/**
 * Recording, DB-free engine for the resolver contract.
 *
 * It answers the exact read queries the resolver issues, filters rows by the
 * requested key, and counts every `select()`/`exec()` so the tests can prove
 * the read path is batched and read-only.
 */
final class FlujoResolverFakeDb
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables;

    public int $selectCalls = 0;

    public int $execCalls = 0;

    /** @var list<string> */
    public array $executed = [];

    /** @param array<string, list<array<string, mixed>>> $tables */
    public function __construct(array $tables = [])
    {
        $this->tables = $tables;
    }

    public function select($sql, $params = [])
    {
        $this->selectCalls++;
        $sql = trim((string) $sql);

        if (str_contains($sql, 'FROM catalogo_flujo_articulos')) {
            $referencia = $this->eqValue($sql, 'referencia');

            return $this->filter(
                $this->tables['catalogo_flujo_articulos'] ?? [],
                static fn (array $row): bool => (string) ($row['referencia'] ?? '') === $referencia
            );
        }

        if (str_contains($sql, 'FROM catalogo_flujo_familias')) {
            $codfamilia = $this->eqValue($sql, 'codfamilia');

            return $this->filter(
                $this->tables['catalogo_flujo_familias'] ?? [],
                static fn (array $row): bool => (string) ($row['codfamilia'] ?? '') === $codfamilia
            );
        }

        if (str_contains($sql, 'FROM catalogo_flujo_condiciones')) {
            $ids = $this->inList($sql, 'id_flujo');

            return $this->filter(
                $this->tables['catalogo_flujo_condiciones'] ?? [],
                static fn (array $row): bool => in_array((int) ($row['id_flujo'] ?? 0), $ids, true)
            );
        }

        if (str_contains($sql, 'FROM catalogo_flujo_acciones')) {
            $ids = $this->inList($sql, 'id_flujo');

            return $this->filter(
                $this->tables['catalogo_flujo_acciones'] ?? [],
                static fn (array $row): bool => in_array((int) ($row['id_flujo'] ?? 0), $ids, true)
            );
        }

        // Deliberately ignores the SQL `activo = TRUE` clause: the resolver must
        // still drop inactive rows in PHP, so the fake returns every requested id.
        if (str_contains($sql, 'FROM catalogo_flujos ')) {
            $ids = $this->inList($sql, 'id');

            return $this->filter(
                $this->tables['catalogo_flujos'] ?? [],
                static fn (array $row): bool => in_array((int) ($row['id'] ?? 0), $ids, true)
            );
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $this->execCalls++;
        $this->executed[] = trim((string) $sql);

        return true;
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

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function filter(array $rows, callable $predicate): array
    {
        return array_values(array_filter($rows, $predicate));
    }

    private function eqValue(string $sql, string $column): string
    {
        if (preg_match('/' . preg_quote($column, '/') . " = '([^']*)'/", $sql, $m)) {
            return $m[1];
        }

        return '';
    }

    /** @return list<int> */
    private function inList(string $sql, string $column): array
    {
        if (!preg_match('/' . preg_quote($column, '/') . ' IN \(([^)]*)\)/', $sql, $m)) {
            return [];
        }

        $ids = [];
        foreach (explode(',', $m[1]) as $part) {
            $part = trim($part);
            if ($part !== '' && strcasecmp($part, 'NULL') !== 0) {
                $ids[] = (int) $part;
            }
        }

        return $ids;
    }
}

/**
 * FLC-04/05/06 contract for the read-only flow resolver.
 *
 * DB-free: the resolver's DB seam is replaced by a recording engine that
 * answers batched reads and counts every statement, proving the exposure is
 * read-only, ordered deterministically and issued in a constant number of
 * queries regardless of how many flows apply.
 */
final class FlujoResolverTest extends TestCase
{
    private function resolver(FlujoResolverFakeDb $db): FlujoResolver
    {
        return new class($db) extends FlujoResolver {
            public function __construct(private FlujoResolverFakeDb $fake)
            {
            }

            protected function db(): object
            {
                return $this->fake;
            }
        };
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function flow(int $id, string $codigo, bool $activo = true, int $prioridad = 0): array
    {
        return [
            'id' => $id,
            'codigo' => $codigo,
            'nombre' => $codigo,
            'descripcion' => '',
            'activo' => $activo,
            'prioridad' => $prioridad,
        ];
    }

    /**
     * @param array<int, int> $flowIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function conditionsFor(array $flowIds): array
    {
        $rows = [];
        $id = 1;
        foreach ($flowIds as $flowId) {
            $rows[] = [
                'id' => $id++,
                'id_flujo' => $flowId,
                'grupo_and_or' => 'AND',
                'sujeto_tipo' => 'opcional',
                'sujeto_codigo' => 'OPC' . $flowId,
                'operador' => 'is',
                'valor' => '1',
            ];
        }

        return ['catalogo_flujo_condiciones' => $rows];
    }

    /**
     * @param array<int, int> $flowIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function actionsFor(array $flowIds): array
    {
        $rows = [];
        $id = 1;
        foreach ($flowIds as $flowId) {
            $rows[] = [
                'id' => $id++,
                'id_flujo' => $flowId,
                'accion' => 'mostrar',
                'sujeto_tipo' => 'opcional',
                'sujeto_codigo' => 'TARGET' . $flowId,
            ];
        }

        return ['catalogo_flujo_acciones' => $rows];
    }

    public function test_exposes_the_documented_shape(): void
    {
        $db = new FlujoResolverFakeDb(array_merge(
            [
                'catalogo_flujo_articulos' => [['id' => 1, 'id_flujo' => 7, 'referencia' => 'ART1']],
                'catalogo_flujos' => [$this->flow(7, 'F7', true, 3)],
            ],
            $this->conditionsFor([7]),
            $this->actionsFor([7])
        ));

        $result = $this->resolver($db)->get_aplicables('ART1', 'FAM1');

        $this->assertSame([
            [
                'codigo' => 'F7',
                'prioridad' => 3,
                'scope' => 'articulo',
                'condiciones' => [[
                    'grupo_and_or' => 'AND',
                    'sujeto_tipo' => 'opcional',
                    'sujeto_codigo' => 'OPC7',
                    'operador' => 'is',
                    'valor' => '1',
                ]],
                'acciones' => [[
                    'accion' => 'mostrar',
                    'sujeto_tipo' => 'opcional',
                    'sujeto_codigo' => 'TARGET7',
                ]],
            ],
        ], $result);
    }

    public function test_inactive_flows_are_never_exposed(): void
    {
        $db = new FlujoResolverFakeDb(array_merge(
            [
                'catalogo_flujo_articulos' => [
                    ['id' => 1, 'id_flujo' => 1, 'referencia' => 'ART1'],
                    ['id' => 2, 'id_flujo' => 2, 'referencia' => 'ART1'],
                ],
                'catalogo_flujos' => [
                    $this->flow(1, 'ACTIVO', true, 0),
                    $this->flow(2, 'INACTIVO', false, 0),
                ],
            ],
            $this->conditionsFor([1, 2]),
            $this->actionsFor([1, 2])
        ));

        $result = $this->resolver($db)->get_aplicables('ART1', 'FAM1');

        $this->assertSame(['ACTIVO'], array_column($result, 'codigo'));
    }

    public function test_flow_applies_only_to_its_assigned_scope(): void
    {
        $db = new FlujoResolverFakeDb(array_merge(
            [
                'catalogo_flujo_articulos' => [['id' => 1, 'id_flujo' => 1, 'referencia' => 'ART-A']],
                'catalogo_flujos' => [$this->flow(1, 'F1', true, 0)],
            ],
            $this->conditionsFor([1]),
            $this->actionsFor([1])
        ));

        // Different article, same (empty) family: nothing applies.
        $this->assertSame([], $this->resolver($db)->get_aplicables('ART-B', ''));
    }

    public function test_exposes_family_and_article_scope_with_exact_order(): void
    {
        $db = new FlujoResolverFakeDb(array_merge(
            [
                'catalogo_flujo_articulos' => [
                    ['id' => 1, 'id_flujo' => 30, 'referencia' => 'ART1'], // A_LOW  (prio 1)
                    ['id' => 2, 'id_flujo' => 40, 'referencia' => 'ART1'], // A_HIGH (prio 9)
                ],
                'catalogo_flujo_familias' => [
                    ['id' => 3, 'id_flujo' => 10, 'codfamilia' => 'FAM1'], // F_A (prio 2, id 10)
                    ['id' => 4, 'id_flujo' => 20, 'codfamilia' => 'FAM1'], // F_B (prio 2, id 20)
                    ['id' => 5, 'id_flujo' => 5, 'codfamilia' => 'FAM1'],  // F_C (prio 0)
                ],
                'catalogo_flujos' => [
                    $this->flow(40, 'A_HIGH', true, 9),
                    $this->flow(30, 'A_LOW', true, 1),
                    $this->flow(20, 'F_B', true, 2),
                    $this->flow(10, 'F_A', true, 2),
                    $this->flow(5, 'F_C', true, 0),
                ],
            ],
            $this->conditionsFor([5, 10, 20, 30, 40]),
            $this->actionsFor([5, 10, 20, 30, 40])
        ));

        $result = $this->resolver($db)->get_aplicables('ART1', 'FAM1');

        $this->assertSame(
            ['F_C', 'F_A', 'F_B', 'A_LOW', 'A_HIGH'],
            array_column($result, 'codigo'),
            'family scope first (prioridad ASC, id ASC), then article scope (prioridad ASC, id ASC)'
        );
        $this->assertSame(
            ['familia', 'familia', 'familia', 'articulo', 'articulo'],
            array_column($result, 'scope')
        );
    }

    public function test_article_scope_comes_after_and_overrides_family_scope(): void
    {
        // Two different flows act the same subject: one family-scoped, one
        // article-scoped. The article-scoped one must be later so it wins.
        $db = new FlujoResolverFakeDb(array_merge(
            [
                'catalogo_flujo_articulos' => [['id' => 1, 'id_flujo' => 2, 'referencia' => 'ART1']],
                'catalogo_flujo_familias' => [['id' => 2, 'id_flujo' => 1, 'codfamilia' => 'FAM1']],
                'catalogo_flujos' => [
                    $this->flow(1, 'FAM_FLOW', true, 0),
                    $this->flow(2, 'ART_FLOW', true, 0),
                ],
            ],
            $this->conditionsFor([1, 2]),
            $this->actionsFor([1, 2])
        ));

        $result = $this->resolver($db)->get_aplicables('ART1', 'FAM1');

        $last = $result[array_key_last($result)];
        $this->assertSame('ART_FLOW', $last['codigo']);
        $this->assertSame('articulo', $last['scope']);
    }

    public function test_exposure_is_read_only(): void
    {
        $db = new FlujoResolverFakeDb(array_merge(
            [
                'catalogo_flujo_articulos' => [['id' => 1, 'id_flujo' => 7, 'referencia' => 'ART1']],
                'catalogo_flujos' => [$this->flow(7, 'F7', true, 0)],
            ],
            $this->conditionsFor([7]),
            $this->actionsFor([7])
        ));

        $this->resolver($db)->get_aplicables('ART1', 'FAM1');

        $this->assertSame(0, $db->execCalls, 'exposing applicable flows must not write any row');
        $this->assertSame([], $db->executed);
    }

    public function test_query_count_is_constant_regardless_of_flow_count(): void
    {
        $dbOne = $this->scenario(1);
        $this->resolver($dbOne)->get_aplicables('ART1', 'FAM1');

        $dbMany = $this->scenario(6);
        $this->resolver($dbMany)->get_aplicables('ART1', 'FAM1');

        $this->assertSame(
            $dbOne->selectCalls,
            $dbMany->selectCalls,
            'the query count must not grow with the number of applicable flows'
        );
        $this->assertSame(5, $dbOne->selectCalls, 'two assignment reads + flows + conditions + actions');
    }

    public function test_empty_pair_makes_no_query(): void
    {
        $db = new FlujoResolverFakeDb();

        $this->assertSame([], $this->resolver($db)->get_aplicables('', ''));
        $this->assertSame(0, $db->selectCalls);
    }

    private function scenario(int $flowCount): FlujoResolverFakeDb
    {
        $articleRows = [];
        $flows = [];
        $ids = [];
        for ($i = 1; $i <= $flowCount; $i++) {
            $articleRows[] = ['id' => $i, 'id_flujo' => $i, 'referencia' => 'ART1'];
            $flows[] = $this->flow($i, 'F' . $i, true, $i);
            $ids[] = $i;
        }

        return new FlujoResolverFakeDb(array_merge(
            [
                'catalogo_flujo_articulos' => $articleRows,
                'catalogo_flujos' => $flows,
            ],
            $this->conditionsFor($ids),
            $this->actionsFor($ids)
        ));
    }
}
