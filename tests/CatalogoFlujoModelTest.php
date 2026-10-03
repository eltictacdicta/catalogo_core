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
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_flujo_articulo.php';

/**
 * DB-free contract tests for the flujo entity and its condition/action/
 * assignment models (FLC-01/02/03/09): unique code, closed vocabularies,
 * AND/OR preservation and app-side assignment cascade.
 */
final class FlujoModelFakeDb extends \fs_db2
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables;

    /** @var list<string> */
    public array $executed = [];

    private int $nextId = 1;

    /** @param array<string, list<array<string, mixed>>> $tables */
    public function __construct(array $tables = [])
    {
        // Deliberately skip parent::__construct(): no engine, no DB connection.
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
        $sql = trim((string) $sql);

        if (preg_match("/^SELECT \* FROM catalogo_flujos WHERE codigo = '(.+)';?$/", $sql, $m)) {
            foreach ($this->tables['catalogo_flujos'] ?? [] as $row) {
                if ((string) $row['codigo'] === stripslashes($m[1])) {
                    return [$row];
                }
            }

            return [];
        }

        if (preg_match('/^SELECT \* FROM catalogo_flujo_condiciones WHERE id_flujo = (\d+) ORDER BY id ASC;?$/', $sql, $m)) {
            return array_values(array_filter(
                $this->tables['catalogo_flujo_condiciones'] ?? [],
                static fn (array $row): bool => (int) $row['id_flujo'] === (int) $m[1]
            ));
        }

        if (preg_match('/^SELECT \* FROM catalogo_flujo_acciones WHERE id_flujo = (\d+) ORDER BY id ASC;?$/', $sql, $m)) {
            return array_values(array_filter(
                $this->tables['catalogo_flujo_acciones'] ?? [],
                static fn (array $row): bool => (int) $row['id_flujo'] === (int) $m[1]
            ));
        }

        if (preg_match("/^SELECT \* FROM catalogo_flujo_articulos WHERE id_flujo = (\d+) AND referencia = '(.+)';?$/", $sql, $m)) {
            foreach ($this->tables['catalogo_flujo_articulos'] ?? [] as $row) {
                if ((int) $row['id_flujo'] === (int) $m[1] && (string) $row['referencia'] === stripslashes($m[2])) {
                    return [$row];
                }
            }

            return [];
        }

        if (preg_match('/^SELECT \* FROM catalogo_flujo_articulos WHERE id_flujo = (\d+) ORDER BY referencia ASC;?$/', $sql, $m)) {
            $rows = array_values(array_filter(
                $this->tables['catalogo_flujo_articulos'] ?? [],
                static fn (array $row): bool => (int) $row['id_flujo'] === (int) $m[1]
            ));
            usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['referencia'], (string) $b['referencia']));

            return $rows;
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $sql = trim((string) $sql);
        $this->executed[] = $sql;

        if (preg_match('/^INSERT INTO (catalogo_flujo[a-z_]*|catalogo_flujos) \(([^)]+)\) VALUES \((.*)\);?$/i', $sql, $m)) {
            $cols = array_map('trim', explode(',', $m[2]));
            $row = array_combine($cols, $this->parseValues($m[3]));
            $row['id'] = $this->nextId++;
            $this->tables[$m[1]][] = $row;

            return true;
        }

        if (preg_match("/^DELETE FROM catalogo_flujo_articulos WHERE referencia = '(.+)';?$/", $sql, $m)) {
            $ref = stripslashes($m[1]);
            $this->tables['catalogo_flujo_articulos'] = array_values(array_filter(
                $this->tables['catalogo_flujo_articulos'] ?? [],
                static fn (array $row): bool => (string) $row['referencia'] !== $ref
            ));

            return true;
        }

        if (preg_match('/^DELETE FROM (catalogo_flujo[a-z_]*|catalogo_flujos) WHERE (id|id_flujo) = (\d+);?$/i', $sql, $m)) {
            $col = $m[2];
            $value = (int) $m[3];
            $this->tables[$m[1]] = array_values(array_filter(
                $this->tables[$m[1]] ?? [],
                static fn (array $row): bool => (int) ($row[$col] ?? 0) !== $value
            ));

            return true;
        }

        return true;
    }

    public function lastval()
    {
        return $this->nextId - 1;
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

/** DB-free double that hydrates manually and collects validation errors. */
final class FakeFlujo extends \FSFramework\model\catalogo_flujo
{
    /** @var list<string> */
    public array $errors = [];

    public function __construct($data = false)
    {
        $this->table_name = \FSFramework\model\catalogo_flujo::TABLE;
        if ($data) {
            $this->id = $data['id'] ?? null;
            $this->codigo = $data['codigo'] ?? null;
            $this->nombre = $data['nombre'] ?? '';
            $this->descripcion = $data['descripcion'] ?? '';
            $this->activo = $this->str2bool($data['activo'] ?? true);
            $this->prioridad = isset($data['prioridad']) ? (int) $data['prioridad'] : 0;
        } else {
            $this->id = null;
            $this->codigo = null;
            $this->nombre = '';
            $this->descripcion = '';
            $this->activo = true;
            $this->prioridad = 0;
        }
    }

    public function useFakeDb(FlujoModelFakeDb $db): self
    {
        $this->db = $db;

        return $this;
    }

    protected function new_error_msg($msg)
    {
        $this->errors[] = (string) $msg;
    }
}

/** DB-free double for conditions. */
final class FakeFlujoCondicion extends \FSFramework\model\catalogo_flujo_condicion
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

    public function useFakeDb(FlujoModelFakeDb $db): self
    {
        $this->db = $db;

        return $this;
    }

    protected function new_error_msg($msg)
    {
        $this->errors[] = (string) $msg;
    }
}

/** DB-free double for actions. */
final class FakeFlujoAccion extends \FSFramework\model\catalogo_flujo_accion
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

    public function useFakeDb(FlujoModelFakeDb $db): self
    {
        $this->db = $db;

        return $this;
    }

    protected function new_error_msg($msg)
    {
        $this->errors[] = (string) $msg;
    }
}

/** DB-free double for article assignments. */
final class FakeFlujoArticulo extends \FSFramework\model\catalogo_flujo_articulo
{
    public function __construct($data = false)
    {
        $this->table_name = \FSFramework\model\catalogo_flujo_articulo::TABLE;
        $this->id = $data['id'] ?? null;
        $this->id_flujo = $data['id_flujo'] ?? null;
        $this->referencia = $data['referencia'] ?? null;
    }

    public function useFakeDb(FlujoModelFakeDb $db): self
    {
        $this->db = $db;

        return $this;
    }
}

final class CatalogoFlujoModelTest extends TestCase
{
    public function test_flow_code_must_be_unique(): void
    {
        $db = new FlujoModelFakeDb([
            'catalogo_flujos' => [
                ['id' => 10, 'codigo' => 'FLUJO1', 'nombre' => 'Uno', 'descripcion' => '', 'activo' => 1, 'prioridad' => 0],
            ],
        ]);

        $duplicado = (new FakeFlujo())->useFakeDb($db);
        $duplicado->codigo = 'FLUJO1';
        $duplicado->nombre = 'Duplicado';
        $this->assertFalse($duplicado->test(), 'a duplicate codigo must be rejected');
        $this->assertNotEmpty($duplicado->errors);

        $nuevo = (new FakeFlujo())->useFakeDb($db);
        $nuevo->codigo = 'FLUJO2';
        $nuevo->nombre = 'Nuevo';
        $this->assertTrue($nuevo->test());
    }

    public function test_unknown_operator_is_rejected(): void
    {
        $condicion = new FakeFlujoCondicion();
        $condicion->id_flujo = 1;
        $condicion->grupo_and_or = 'AND';
        $condicion->sujeto_tipo = 'opcional';
        $condicion->sujeto_codigo = 'OPC1';
        $condicion->operador = 'between';

        $this->assertFalse($condicion->test(), 'an operator outside the closed set must be rejected');

        $condicion->operador = 'is';
        $this->assertTrue($condicion->test());
    }

    public function test_unknown_action_is_rejected(): void
    {
        $accion = new FakeFlujoAccion();
        $accion->id_flujo = 1;
        $accion->accion = 'borrar';
        $accion->sujeto_tipo = 'opcional';
        $accion->sujeto_codigo = 'OPC1';

        $this->assertFalse($accion->test(), 'an action outside the closed set must be rejected');

        $accion->accion = 'ocultar';
        $this->assertTrue($accion->test());
    }

    public function test_unknown_subject_type_is_rejected(): void
    {
        $condicion = new FakeFlujoCondicion();
        $condicion->id_flujo = 1;
        $condicion->grupo_and_or = 'AND';
        $condicion->sujeto_tipo = 'articulo';
        $condicion->sujeto_codigo = 'ART1';
        $condicion->operador = 'is';

        $this->assertFalse($condicion->test(), 'sujeto_tipo outside {opcional, grupo} must be rejected');

        $condicion->sujeto_tipo = 'grupo';
        $this->assertTrue($condicion->test());

        $accion = new FakeFlujoAccion();
        $accion->id_flujo = 1;
        $accion->accion = 'mostrar';
        $accion->sujeto_tipo = 'familia';
        $accion->sujeto_codigo = 'FAM1';
        $this->assertFalse($accion->test());
    }

    public function test_and_or_grouping_is_preserved(): void
    {
        $db = new FlujoModelFakeDb();

        $and = (new FakeFlujoCondicion())->useFakeDb($db);
        $and->id_flujo = 1;
        $and->grupo_and_or = 'AND';
        $and->sujeto_tipo = 'opcional';
        $and->sujeto_codigo = 'OPC1';
        $and->operador = 'is';
        $and->valor = '1';
        $this->assertTrue($and->save());

        $or = (new FakeFlujoCondicion())->useFakeDb($db);
        $or->id_flujo = 1;
        $or->grupo_and_or = 'OR';
        $or->sujeto_tipo = 'opcional';
        $or->sujeto_codigo = 'OPC2';
        $or->operador = 'is';
        $or->valor = '2';
        $this->assertTrue($or->save());

        $rows = (new FakeFlujoCondicion())->useFakeDb($db)->all_from_flujo(1);

        $this->assertSame(['AND', 'OR'], array_map(static fn ($row): string => $row->grupo_and_or, $rows));
    }

    public function test_deleting_an_article_removes_its_flow_assignments(): void
    {
        $db = new FlujoModelFakeDb([
            'catalogo_flujo_articulos' => [
                ['id' => 1, 'id_flujo' => 5, 'referencia' => 'ART1'],
                ['id' => 2, 'id_flujo' => 5, 'referencia' => 'ART2'],
            ],
        ]);

        $model = (new FakeFlujoArticulo())->useFakeDb($db);

        $this->assertTrue($model->delete_all_from_referencia('ART1'));
        $this->assertSame(['ART2'], array_column($db->tables['catalogo_flujo_articulos'], 'referencia'));
    }
}
