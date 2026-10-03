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

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_flujo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_flujo_condicion.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_flujo_accion.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_flujo_familia.php';

/**
 * In-memory engine for the write-side integrity tests: serves the full flow
 * graph, appends inserts and applies the cascade deletes. No DB connection.
 */
final class CycleFakeDb
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables;

    public int $execCalls = 0;

    /** @var list<string> */
    public array $executed = [];

    private int $nextId = 100;

    /** @param array<string, list<array<string, mixed>>> $tables */
    public function __construct(array $tables = [])
    {
        $this->tables = $tables;
    }

    public function select($sql, $params = [])
    {
        $sql = trim((string) $sql);

        if (preg_match('/^SELECT \* FROM (catalogo_flujo_condiciones|catalogo_flujo_acciones);?$/', $sql, $m)) {
            return $this->tables[$m[1]] ?? [];
        }

        if (preg_match('/^SELECT \* FROM (catalogo_flujo_condiciones|catalogo_flujo_acciones) WHERE id = (\d+);?$/', $sql, $m)) {
            foreach ($this->tables[$m[1]] ?? [] as $row) {
                if ((int) ($row['id'] ?? 0) === (int) $m[2]) {
                    return [$row];
                }
            }

            return [];
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $sql = trim((string) $sql);
        $this->execCalls++;
        $this->executed[] = $sql;

        if (preg_match('/^INSERT INTO (catalogo_flujo_condiciones|catalogo_flujo_acciones) \(([^)]+)\) VALUES \((.*)\);?$/', $sql, $m)) {
            $cols = array_map('trim', explode(',', $m[2]));
            $row = array_combine($cols, $this->parseValues($m[3]));
            $row['id'] = $this->nextId++;
            $this->tables[$m[1]][] = $row;

            return true;
        }

        if (preg_match(
            "/^DELETE FROM (catalogo_flujo_condiciones|catalogo_flujo_acciones) WHERE sujeto_tipo = '([^']*)' AND sujeto_codigo = '([^']*)';?$/",
            $sql,
            $m
        )) {
            $tipo = stripslashes($m[2]);
            $codigo = stripslashes($m[3]);
            $this->tables[$m[1]] = array_values(array_filter(
                $this->tables[$m[1]] ?? [],
                static fn (array $row): bool => !(
                    strtolower((string) ($row['sujeto_tipo'] ?? '')) === $tipo
                    && (string) ($row['sujeto_codigo'] ?? '') === $codigo
                )
            ));

            return true;
        }

        if (preg_match("/^DELETE FROM catalogo_flujo_familias WHERE codfamilia = '([^']*)';?$/", $sql, $m)) {
            $codfamilia = stripslashes($m[1]);
            $this->tables['catalogo_flujo_familias'] = array_values(array_filter(
                $this->tables['catalogo_flujo_familias'] ?? [],
                static fn (array $row): bool => (string) ($row['codfamilia'] ?? '') !== $codfamilia
            ));

            return true;
        }

        if (preg_match('/^DELETE FROM (catalogo_flujo_condiciones|catalogo_flujo_acciones) WHERE id = (\d+);?$/', $sql, $m)) {
            $this->tables[$m[1]] = array_values(array_filter(
                $this->tables[$m[1]] ?? [],
                static fn (array $row): bool => (int) ($row['id'] ?? 0) !== (int) $m[2]
            ));

            return true;
        }

        return true;
    }

    public function lastval()
    {
        return $this->nextId - 1;
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

    /** @return list<mixed> */
    private function parseValues(string $raw): array
    {
        $values = [];
        $buffer = '';
        $inQuote = false;
        $len = strlen($raw);

        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($ch === "'") {
                $inQuote = !$inQuote;
                $buffer .= $ch;
                continue;
            }
            if ($ch === ',' && !$inQuote) {
                $values[] = $this->decodeValue($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }

        if (trim($buffer) !== '') {
            $values[] = $this->decodeValue($buffer);
        }

        return $values;
    }

    /** @return mixed */
    private function decodeValue(string $value)
    {
        $value = trim($value);
        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }
        if (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
            return str_replace("\\'", "'", substr($value, 1, -1));
        }
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float) $value : (int) $value;
        }

        return $value;
    }
}

/** DB-free condition double that collects validation errors. */
final class CycleFakeCondicion extends \FSFramework\model\catalogo_flujo_condicion
{
    /** @var list<string> */
    public array $errors = [];

    public function __construct($data = false)
    {
        $this->table_name = \FSFramework\model\catalogo_flujo_condicion::TABLE;
        if ($data) {
            $this->id = $data['id'] ?? null;
            $this->id_flujo = $data['id_flujo'] ?? null;
            $this->grupo_and_or = $data['grupo_and_or'] ?? 'AND';
            $this->sujeto_tipo = $data['sujeto_tipo'] ?? '';
            $this->sujeto_codigo = $data['sujeto_codigo'] ?? '';
            $this->operador = $data['operador'] ?? '';
            $this->valor = $data['valor'] ?? null;
        } else {
            $this->id = null;
            $this->id_flujo = null;
            $this->grupo_and_or = 'AND';
            $this->sujeto_tipo = '';
            $this->sujeto_codigo = '';
            $this->operador = '';
            $this->valor = null;
        }
    }

    public function useFakeDb(CycleFakeDb $db): self
    {
        $this->db = $db;

        return $this;
    }

    protected function new_error_msg($msg)
    {
        $this->errors[] = (string) $msg;
    }
}

/** DB-free action double that collects validation errors. */
final class CycleFakeAccion extends \FSFramework\model\catalogo_flujo_accion
{
    /** @var list<string> */
    public array $errors = [];

    public function __construct($data = false)
    {
        $this->table_name = \FSFramework\model\catalogo_flujo_accion::TABLE;
        if ($data) {
            $this->id = $data['id'] ?? null;
            $this->id_flujo = $data['id_flujo'] ?? null;
            $this->accion = $data['accion'] ?? '';
            $this->sujeto_tipo = $data['sujeto_tipo'] ?? '';
            $this->sujeto_codigo = $data['sujeto_codigo'] ?? '';
        } else {
            $this->id = null;
            $this->id_flujo = null;
            $this->accion = '';
            $this->sujeto_tipo = '';
            $this->sujeto_codigo = '';
        }
    }

    public function useFakeDb(CycleFakeDb $db): self
    {
        $this->db = $db;

        return $this;
    }

    protected function new_error_msg($msg)
    {
        $this->errors[] = (string) $msg;
    }
}

/** DB-free family-assignment double. */
final class CycleFakeFamilia extends \FSFramework\model\catalogo_flujo_familia
{
    public function __construct($data = false)
    {
        $this->table_name = \FSFramework\model\catalogo_flujo_familia::TABLE;
        $this->id = $data['id'] ?? null;
        $this->id_flujo = $data['id_flujo'] ?? null;
        $this->codfamilia = $data['codfamilia'] ?? null;
    }

    public function useFakeDb(CycleFakeDb $db): self
    {
        $this->db = $db;

        return $this;
    }
}

/**
 * FLC-07 / FLC-09 contract: cycles are rejected at save on both the condition
 * and the action hooks, a non-cyclic graph is accepted, and deleting a subject
 * removes the flow references that point at it by código.
 */
final class CatalogoFlujoCycleTest extends TestCase
{
    /**
     * @param array<int, int> $flowIds
     * @return list<array<string, mixed>>
     */
    private function conditions(array $flowIds): array
    {
        $rows = [];
        $id = 1;
        foreach ($flowIds as $flowId) {
            $rows[] = [
                'id' => $id++,
                'id_flujo' => $flowId,
                'grupo_and_or' => 'AND',
                'sujeto_tipo' => 'opcional',
                'sujeto_codigo' => 'A' . $flowId,
                'operador' => 'is',
                'valor' => '1',
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array{0:int,1:string}> $edges flowId => [target code]
     * @return list<array<string, mixed>>
     */
    private function actions(array $edges): array
    {
        $rows = [];
        $id = 1;
        foreach ($edges as [$flowId, $target]) {
            $rows[] = [
                'id' => $id++,
                'id_flujo' => $flowId,
                'accion' => 'mostrar',
                'sujeto_tipo' => 'opcional',
                'sujeto_codigo' => $target,
            ];
        }

        return $rows;
    }

    private function condition(int $flowId, string $codigo): CycleFakeCondicion
    {
        return new CycleFakeCondicion([
            'id_flujo' => $flowId,
            'grupo_and_or' => 'AND',
            'sujeto_tipo' => 'opcional',
            'sujeto_codigo' => $codigo,
            'operador' => 'is',
            'valor' => '1',
        ]);
    }

    private function action(int $flowId, string $codigo): CycleFakeAccion
    {
        return new CycleFakeAccion([
            'id_flujo' => $flowId,
            'accion' => 'mostrar',
            'sujeto_tipo' => 'opcional',
            'sujeto_codigo' => $codigo,
        ]);
    }

    public function test_direct_cycle_is_rejected_when_saving_the_action(): void
    {
        // F1: condition A -> action B (already stored). F2: condition B stored,
        // saving its action A closes A -> B -> A.
        $db = new CycleFakeDb([
            'catalogo_flujo_condiciones' => [
                ['id' => 1, 'id_flujo' => 1, 'grupo_and_or' => 'AND', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'A', 'operador' => 'is', 'valor' => '1'],
                ['id' => 2, 'id_flujo' => 2, 'grupo_and_or' => 'AND', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'B', 'operador' => 'is', 'valor' => '1'],
            ],
            'catalogo_flujo_acciones' => [
                ['id' => 1, 'id_flujo' => 1, 'accion' => 'mostrar', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'B'],
            ],
        ]);

        $accion = $this->action(2, 'A')->useFakeDb($db);

        $this->assertFalse($accion->save(), 'a direct cycle must be rejected at save');
        $this->assertNotEmpty($accion->errors);
        $this->assertStringContainsString('iclo', $accion->errors[0]);
        $this->assertCount(
            1,
            $db->tables['catalogo_flujo_acciones'],
            'nothing may be persisted when a cycle is detected'
        );
    }

    public function test_direct_cycle_is_rejected_when_saving_the_condition(): void
    {
        // F1: condition A -> action B. F2: action A stored; saving its condition
        // B closes B -> A -> B.
        $db = new CycleFakeDb([
            'catalogo_flujo_condiciones' => [
                ['id' => 1, 'id_flujo' => 1, 'grupo_and_or' => 'AND', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'A', 'operador' => 'is', 'valor' => '1'],
            ],
            'catalogo_flujo_acciones' => [
                ['id' => 1, 'id_flujo' => 1, 'accion' => 'mostrar', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'B'],
                ['id' => 2, 'id_flujo' => 2, 'accion' => 'mostrar', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'A'],
            ],
        ]);

        $condicion = $this->condition(2, 'B')->useFakeDb($db);

        $this->assertFalse($condicion->save(), 'a direct cycle must be rejected at save');
        $this->assertNotEmpty($condicion->errors);
        $this->assertCount(1, $db->tables['catalogo_flujo_condiciones']);
    }

    public function test_non_cyclic_graph_is_accepted(): void
    {
        // F1: condition A -> action B. F2: condition B -> action C. No cycle.
        $db = new CycleFakeDb([
            'catalogo_flujo_condiciones' => [
                ['id' => 1, 'id_flujo' => 1, 'grupo_and_or' => 'AND', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'A', 'operador' => 'is', 'valor' => '1'],
                ['id' => 2, 'id_flujo' => 2, 'grupo_and_or' => 'AND', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'B', 'operador' => 'is', 'valor' => '1'],
            ],
            'catalogo_flujo_acciones' => [
                ['id' => 1, 'id_flujo' => 1, 'accion' => 'mostrar', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'B'],
            ],
        ]);

        $accion = $this->action(2, 'C')->useFakeDb($db);

        $this->assertTrue($accion->save(), 'a non-cyclic graph must be accepted');
        $this->assertSame([], $accion->errors);
        $this->assertCount(2, $db->tables['catalogo_flujo_acciones']);
    }

    public function test_deleting_an_opcional_cleans_its_conditions_and_actions(): void
    {
        $db = new CycleFakeDb([
            'catalogo_flujo_condiciones' => [
                ['id' => 1, 'id_flujo' => 1, 'grupo_and_or' => 'AND', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'OPC1', 'operador' => 'is', 'valor' => '1'],
                ['id' => 2, 'id_flujo' => 2, 'grupo_and_or' => 'AND', 'sujeto_tipo' => 'grupo', 'sujeto_codigo' => 'OPC1', 'operador' => 'is', 'valor' => '1'],
                ['id' => 3, 'id_flujo' => 3, 'grupo_and_or' => 'AND', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'OPC2', 'operador' => 'is', 'valor' => '1'],
            ],
            'catalogo_flujo_acciones' => [
                ['id' => 1, 'id_flujo' => 1, 'accion' => 'mostrar', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'OPC1'],
                ['id' => 2, 'id_flujo' => 2, 'accion' => 'ocultar', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'OPC2'],
            ],
        ]);

        $condicion = (new CycleFakeCondicion())->useFakeDb($db);
        $accion = (new CycleFakeAccion())->useFakeDb($db);

        $this->assertTrue($condicion->delete_all_from_sujeto('opcional', 'OPC1'));
        $this->assertTrue($accion->delete_all_from_sujeto('opcional', 'OPC1'));

        $this->assertSame(
            ['OPC1', 'OPC2'],
            array_column($db->tables['catalogo_flujo_condiciones'], 'sujeto_codigo'),
            'only the deleted subject type/code pair is removed; the grupo with the same code survives'
        );
        $this->assertSame(['OPC2'], array_column($db->tables['catalogo_flujo_acciones'], 'sujeto_codigo'));
    }

    public function test_deleting_a_familia_cleans_its_flow_assignments(): void
    {
        $db = new CycleFakeDb([
            'catalogo_flujo_familias' => [
                ['id' => 1, 'id_flujo' => 5, 'codfamilia' => 'FAM1'],
                ['id' => 2, 'id_flujo' => 5, 'codfamilia' => 'FAM2'],
            ],
        ]);

        $model = (new CycleFakeFamilia())->useFakeDb($db);

        $this->assertTrue($model->delete_all_from_familia('FAM1'));
        $this->assertSame(['FAM2'], array_column($db->tables['catalogo_flujo_familias'], 'codfamilia'));
    }
}
