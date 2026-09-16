<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the predefined value catalog (CAR-03).
 *
 * DB-free: the model is driven through an anonymous subclass with a spy DB and
 * a stubbed `existing_valor()` seam.
 */
final class CaracteristicaValorSpyDb
{
    /** @var list<string> */
    public array $execStatements = [];

    /** @var list<string> */
    public array $selectStatements = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<list<array<string, mixed>>> */
    public array $selectQueue = [];

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
        return 5;
    }
}

final class CaracteristicaValorCatalogoTest extends TestCase
{
    private const MODEL_RELATIVE = 'plugins/catalogo_core/model/core/catalogo_caracteristica_valor.php';

    private const XML_RELATIVE = 'plugins/catalogo_core/model/table/catalogo_caracteristica_valores.xml';

    private static bool $baseLoaded = false;

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

    private function buildModel(CaracteristicaValorSpyDb $db, ?array $existing = null): object
    {
        return new class($db, $existing) extends \FSFramework\model\catalogo_caracteristica_valor {
            /** @var list<string> */
            public array $errors = [];

            private ?array $existingRow;

            public function __construct($dbOrData = false, ?array $existing = null)
            {
                $this->table_name = 'catalogo_caracteristica_valores';
                $this->db = $dbOrData instanceof CaracteristicaValorSpyDb ? $dbOrData : null;
                $this->existingRow = $existing;
                $this->id = null;
                $this->id_caracteristica = null;
                $this->valor = null;
                $this->orden = 0;
                $this->activo = true;

                if (is_array($dbOrData)) {
                    $this->id = isset($dbOrData['id']) ? (int) $dbOrData['id'] : null;
                    $this->id_caracteristica = isset($dbOrData['id_caracteristica'])
                        ? (int) $dbOrData['id_caracteristica']
                        : null;
                    $this->valor = $dbOrData['valor'] ?? null;
                    $this->orden = isset($dbOrData['orden']) ? (int) $dbOrData['orden'] : 0;
                    $this->activo = isset($dbOrData['activo']) ? $this->str2bool($dbOrData['activo']) : true;
                }
            }

            protected function new_error_msg($msg)
            {
                $this->errors[] = (string) $msg;
            }

            protected function existing_valor($idCaracteristica, string $valor)
            {
                return $this->existingRow;
            }
        };
    }

    // =====================================================================
    // CAR-03 — Predefined value catalog
    // =====================================================================

    public function test_catalog_values_are_per_definition_and_unique(): void
    {
        // Duplicate (id_caracteristica, valor) is rejected.
        $db = new CaracteristicaValorSpyDb();
        $model = $this->buildModel($db, ['id' => 4]);
        $model->id_caracteristica = 1;
        $model->valor = 'Sí';

        $this->assertFalse($model->test(), 'a duplicated (id_caracteristica, valor) must be rejected');
        $this->assertFalse($model->save(), 'save must refuse a duplicate');
        $this->assertSame([], $db->execStatements, 'a rejected duplicate persists nothing');
        $this->assertNotSame([], $model->errors);

        // The values list is per definition.
        $listDb = new CaracteristicaValorSpyDb();
        $listDb->rows = [
            ['id' => 1, 'id_caracteristica' => 1, 'valor' => 'Sí', 'orden' => 0, 'activo' => '1'],
            ['id' => 2, 'id_caracteristica' => 1, 'valor' => 'No', 'orden' => 1, 'activo' => '1'],
        ];
        $listModel = $this->buildModel($listDb);

        $values = $listModel->all_from_caracteristica(1);

        $this->assertCount(2, $values);
        $this->assertStringContainsString('id_caracteristica = 1', $listDb->selectStatements[0]);
        $this->assertStringContainsString('ORDER BY orden ASC, id ASC', $listDb->selectStatements[0]);
        $this->assertSame('Sí', $values[0]->valor);
        $this->assertSame('No', $values[1]->valor);
    }

    public function test_bool_pair_rows_persist_with_orden_zero_and_one(): void
    {
        $db = new CaracteristicaValorSpyDb();
        $model = $this->buildModel($db);
        $model->id_caracteristica = 1;
        $model->valor = '1';
        $model->orden = 0;

        $this->assertTrue($model->save(), 'the Sí row must persist');
        $model->valor = '0';
        $model->orden = 1;
        $this->assertTrue($model->save(), 'the No row must persist');

        $inserts = array_values(array_filter(
            $db->execStatements,
            static fn (string $sql): bool => str_starts_with($sql, 'INSERT')
        ));
        $this->assertCount(2, $inserts);
        $this->assertStringContainsString("'1'", $inserts[0]);
        $this->assertStringContainsString("'0'", $inserts[1]);
    }

    public function test_xml_declares_unique_and_cascading_foreign_key(): void
    {
        $path = FS_FOLDER . '/' . self::XML_RELATIVE;
        $this->assertFileExists($path);
        $xml = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression('/PRIMARY KEY\s*\(\s*id\s*\)/i', $xml);
        $this->assertMatchesRegularExpression(
            '/UNIQUE\s*\(\s*id_caracteristica\s*,\s*valor\s*\)/i',
            $xml,
            'the catalog must be unique per (id_caracteristica, valor)'
        );
        $this->assertMatchesRegularExpression(
            '/FOREIGN KEY\s*\(\s*id_caracteristica\s*\)\s*REFERENCES\s+catalogo_caracteristicas\s*\(\s*id\s*\)\s*'
            . 'ON DELETE CASCADE ON UPDATE CASCADE/i',
            $xml,
            'id_caracteristica must cascade from catalogo_caracteristicas'
        );
    }
}
