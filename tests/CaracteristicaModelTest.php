<?php
/**
 * This file is part of the catalogo_core plugin
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

/**
 * Contract tests for the feature definition entity (CAR-01, CAR-02).
 *
 * The model is exercised DB-free: an anonymous subclass bypasses the
 * `fs_model` constructor, injects a spy DB and stubs the protected
 * `existing_by_codigo()` seam.
 */
final class CaracteristicaModelSpyDb
{
    /** @var list<string> */
    public array $execStatements = [];

    /** @var list<string> */
    public array $selectStatements = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<list<array<string, mixed>>> */
    public array $selectQueue = [];

    public $lastId = 99;

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

    public function select($sql, $params = [])
    {
        $this->selectStatements[] = trim((string) $sql);

        if ($this->selectQueue !== []) {
            return array_shift($this->selectQueue);
        }

        return $this->rows;
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $this->execStatements[] = trim((string) $sql);

        return true;
    }

    public function lastval()
    {
        return $this->lastId;
    }
}

final class CaracteristicaModelTest extends TestCase
{
    private const MODEL_RELATIVE = 'plugins/catalogo_core/model/core/catalogo_caracteristica.php';

    private const XML_RELATIVE = 'plugins/catalogo_core/model/table/catalogo_caracteristicas.xml';

    private static bool $baseLoaded = false;

    /** @var array<string, mixed>|null */
    private ?array $existing = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/' . self::MODEL_RELATIVE;
        self::$baseLoaded = true;
    }

    /**
     * @param array<string, mixed>|null $existing row returned by the seam
     */
    private function buildModel(CaracteristicaModelSpyDb $db, ?array $existing = null): object
    {
        return new class($db, $existing) extends \FSFramework\model\catalogo_caracteristica {
            /** @var list<string> */
            public array $errors = [];

            private ?array $existingRow;

            public function __construct($dbOrData = false, ?array $existing = null)
            {
                $this->table_name = 'catalogo_caracteristicas';
                $this->db = $dbOrData instanceof CaracteristicaModelSpyDb ? $dbOrData : null;
                $this->existingRow = $existing;
                $this->setDefaults();

                if (is_array($dbOrData)) {
                    $this->hydrateFrom($dbOrData);
                }
            }

            private function setDefaults(): void
            {
                $this->id = null;
                $this->codigo = null;
                $this->nombre = null;
                $this->tipo = null;
                $this->activo = true;
                $this->importable = false;
                $this->exportable = false;
                $this->listable = false;
                $this->orden = 0;
                $this->origen = '';
                $this->valor_defecto = null;
            }

            /** @param array<string, mixed> $row */
            private function hydrateFrom(array $row): void
            {
                $this->id = isset($row['id']) ? (int) $row['id'] : null;
                $this->codigo = $row['codigo'] ?? null;
                $this->nombre = $row['nombre'] ?? null;
                $this->tipo = $row['tipo'] ?? null;
                $this->activo = isset($row['activo']) ? $this->str2bool($row['activo']) : true;
                $this->importable = isset($row['importable']) ? $this->str2bool($row['importable']) : false;
                $this->exportable = isset($row['exportable']) ? $this->str2bool($row['exportable']) : false;
                $this->listable = isset($row['listable']) ? $this->str2bool($row['listable']) : false;
                $this->orden = isset($row['orden']) ? (int) $row['orden'] : 0;
                $this->origen = (string) ($row['origen'] ?? '');
                $this->valor_defecto = $row['valor_defecto'] ?? null;
            }

            protected function new_error_msg($msg)
            {
                $this->errors[] = (string) $msg;
            }

            protected function existing_by_codigo(string $codigo)
            {
                return $this->existingRow;
            }
        };
    }

    // =====================================================================
    // CAR-01 — Feature definition entity
    // =====================================================================

    public function test_definition_persists_and_is_addressable_by_codigo(): void
    {
        $db = new CaracteristicaModelSpyDb();
        $db->rows = [];
        $model = $this->buildModel($db);
        $model->codigo = 'medidas';
        $model->nombre = 'Medidas';
        $model->tipo = \FSFramework\model\catalogo_caracteristica::TIPO_STRING;
        $model->listable = true;
        $model->orden = 10;
        $model->origen = 'catalogo_core';

        $this->assertTrue($model->save(), 'a valid definition must persist');

        $inserts = array_values(array_filter(
            $db->execStatements,
            static fn (string $sql): bool => str_starts_with($sql, 'INSERT')
        ));
        $this->assertCount(1, $inserts);
        $this->assertStringContainsString('catalogo_caracteristicas', $inserts[0]);
        $this->assertStringContainsString('medidas', $inserts[0]);
        $this->assertStringContainsString("'catalogo_core'", $inserts[0]);
        $this->assertSame(99, $model->id, 'the new id comes from lastval()');

        // Reading it back by codigo returns the same definition.
        $readDb = new CaracteristicaModelSpyDb();
        $readDb->selectQueue = [[[
            'id' => 99,
            'codigo' => 'medidas',
            'nombre' => 'Medidas',
            'tipo' => 'string',
            'activo' => '1',
            'importable' => '0',
            'exportable' => '0',
            'listable' => '1',
            'orden' => 10,
            'origen' => 'catalogo_core',
            'valor_defecto' => null,
        ]]];
        $reader = $this->buildModel($readDb);

        $found = $reader->get('medidas');

        $this->assertInstanceOf(\FSFramework\model\catalogo_caracteristica::class, $found);
        $this->assertSame('medidas', $found->codigo);
        $this->assertSame('Medidas', $found->nombre);
        $this->assertSame(10, $found->orden);
        $this->assertTrue($found->listable);
        $this->assertStringContainsString("codigo = 'medidas'", $readDb->selectStatements[0]);
    }

    public function test_duplicate_codigo_is_rejected(): void
    {
        $db = new CaracteristicaModelSpyDb();
        $model = $this->buildModel($db, ['id' => 7, 'codigo' => 'medidas']);
        $model->codigo = 'medidas';
        $model->nombre = 'Otra';
        $model->tipo = \FSFramework\model\catalogo_caracteristica::TIPO_STRING;

        $this->assertFalse($model->save(), 'a duplicated codigo must not persist');
        $this->assertSame([], $db->execStatements, 'a rejected duplicate writes nothing');
        $this->assertNotSame([], $model->errors, 'the duplicate reports an explicit error');
    }

    public function test_ordered_listing_orders_by_orden_then_codigo(): void
    {
        $db = new CaracteristicaModelSpyDb();
        $db->rows = [
            ['id' => 3, 'codigo' => 'c', 'nombre' => 'C', 'tipo' => 'bool', 'orden' => 20, 'origen' => ''],
            ['id' => 1, 'codigo' => 'a', 'nombre' => 'A', 'tipo' => 'bool', 'orden' => 0, 'origen' => ''],
            ['id' => 2, 'codigo' => 'b', 'nombre' => 'B', 'tipo' => 'bool', 'orden' => 10, 'origen' => ''],
        ];
        $model = $this->buildModel($db);

        $list = $model->all();

        $this->assertCount(3, $list);
        $this->assertStringContainsString('ORDER BY orden ASC, codigo ASC', $db->selectStatements[0]);
        $this->assertSame('c', $list[0]->codigo);
        $this->assertSame('a', $list[1]->codigo);
        $this->assertSame('b', $list[2]->codigo);
    }

    // =====================================================================
    // CAR-11 — A plugin-owned definition stays undeletable
    // =====================================================================

    public function test_plugin_owned_definition_is_refused_when_origen_is_cleared_in_memory(): void
    {
        $db = new CaracteristicaModelSpyDb();
        // The persisted row is plugin-owned; the object property was cleared
        // in memory without ever being saved.
        $db->selectQueue = [[['origen' => 'catalogo_core']]];
        $model = $this->buildModel($db);
        $model->id = 4;
        $model->origen = '';

        $this->assertTrue($model->is_deletable(), 'the in-memory property alone looks deletable');

        $this->assertFalse(
            $model->delete(),
            'the persisted origen must block the delete of a plugin-owned definition'
        );
        $this->assertSame([], $db->execStatements, 'no DELETE may reach a plugin-owned row');
        $this->assertNotSame([], $model->errors, 'the refusal must be explicit');
    }

    public function test_operator_owned_definition_delete_enforces_the_persisted_origen(): void
    {
        $db = new CaracteristicaModelSpyDb();
        $db->selectQueue = [[['origen' => '']]];
        $model = $this->buildModel($db);
        $model->id = 7;
        $model->origen = '';

        $this->assertTrue($model->delete(), 'an operator-owned definition must stay deletable');

        $deletes = array_values(array_filter(
            $db->execStatements,
            static fn (string $sql): bool => str_starts_with($sql, 'DELETE')
        ));
        $this->assertCount(1, $deletes, 'exactly one DELETE must run');
        $this->assertStringContainsString('WHERE id = 7', $deletes[0]);
        $this->assertStringContainsString(
            'origen',
            $deletes[0],
            'the DELETE must re-enforce the persisted-origen condition'
        );
    }

    // =====================================================================
    // CAR-02 — Value type whitelist
    // =====================================================================

    public function test_whitelist_accepts_bool_and_string(): void
    {
        $this->assertSame(
            ['bool', 'string'],
            \FSFramework\model\catalogo_caracteristica::TIPOS,
            'the whitelist must be iterable without instantiating the model'
        );

        foreach (\FSFramework\model\catalogo_caracteristica::TIPOS as $tipo) {
            $model = $this->buildModel(new CaracteristicaModelSpyDb());
            $model->codigo = 'test';
            $model->nombre = 'Test';
            $model->tipo = $tipo;

            $this->assertTrue($model->test(), 'tipo ' . $tipo . ' must pass');
        }
    }

    public function test_unknown_type_is_rejected(): void
    {
        $db = new CaracteristicaModelSpyDb();
        $model = $this->buildModel($db);
        $model->codigo = 'test';
        $model->nombre = 'Test';
        $model->tipo = 'int';

        $this->assertFalse($model->test(), 'tipo int is outside the whitelist');
        $this->assertFalse($model->save(), 'save must refuse an invalid tipo');
        $this->assertSame([], $db->execStatements, 'an invalid tipo persists nothing');
        $this->assertNotSame([], $model->errors);
    }

    // =====================================================================
    // Schema contract
    // =====================================================================

    public function test_xml_declares_the_pinned_columns_and_restrictions(): void
    {
        $path = FS_FOLDER . '/' . self::XML_RELATIVE;
        $this->assertFileExists($path);
        $xml = (string) file_get_contents($path);

        foreach (['id' => 'serial', 'codigo' => 'character varying\(50\)', 'nombre' => 'character varying\(100\)',
                  'tipo' => 'character varying\(10\)', 'activo' => 'boolean', 'importable' => 'boolean',
                  'exportable' => 'boolean', 'listable' => 'boolean', 'orden' => 'integer',
                  'origen' => 'character varying\(50\)', 'valor_defecto' => 'character varying\(255\)'] as $col => $type) {
            $this->assertMatchesRegularExpression(
                '/<nombre>' . preg_quote($col, '/') . '<\/nombre>\s*<tipo>' . $type . '<\/tipo>/i',
                $xml,
                'column ' . $col . ' must be declared'
            );
        }

        $this->assertMatchesRegularExpression('/PRIMARY KEY\s*\(\s*id\s*\)/i', $xml);
        $this->assertMatchesRegularExpression('/UNIQUE\s*\(\s*codigo\s*\)/i', $xml);
    }
}
