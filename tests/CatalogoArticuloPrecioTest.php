<?php
declare(strict_types=1);
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Per-list article prices model (multitarifa task 1.1, R-MT-002).
 *
 * The plugin test bootstrap has no DB layer, so query-behavior scenarios
 * (upsert semantics, list scoping) are exercised against a mock fs_db2
 * injected via reflection, mirroring the root tests/Base pattern. Cascade
 * behavior is asserted structurally against the XML schema (FK ... CASCADE).
 */
final class CatalogoArticuloPrecioTest extends TestCase
{
    private \fs_model $model;

    /** @var MockCatalogoDb */
    private $db;

    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_precio.php';

        $this->db = new MockCatalogoDb();
        $this->model = new \FSFramework\model\catalogo_articulo_precio();
        $this->injectDb($this->db);
    }

    private function injectDb(MockCatalogoDb $db): void
    {
        $this->db = $db;
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($this->model, $db);
    }

    private function populatedModel(): \FSFramework\model\catalogo_articulo_precio
    {
        $precio = new \FSFramework\model\catalogo_articulo_precio([
            'referencia' => 'A',
            'codlista' => 'L1',
            'precio' => '12.50',
        ]);
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($precio, $this->db);

        return $precio;
    }

    public function test_constructor_maps_data_row(): void
    {
        $precio = $this->populatedModel();

        $this->assertSame('A', $precio->referencia);
        $this->assertSame('L1', $precio->codlista);
        $this->assertSame(12.5, $precio->precio);
    }

    public function test_constructor_defaults_without_data(): void
    {
        $precio = new \FSFramework\model\catalogo_articulo_precio();

        $this->assertNull($precio->referencia);
        $this->assertNull($precio->codlista);
        $this->assertSame(0.0, $precio->precio);
    }

    public function test_save_rejects_missing_referencia(): void
    {
        $precio = $this->populatedModel();
        $precio->referencia = '';

        $this->assertFalse($precio->save());
        $this->assertNotEmpty($precio->get_errors());
    }

    public function test_save_rejects_negative_precio(): void
    {
        $precio = $this->populatedModel();
        $precio->precio = -1.0;

        $this->assertFalse($precio->save());
    }

    public function test_save_rejects_invalid_codlista(): void
    {
        $precio = $this->populatedModel();
        $precio->codlista = '';

        $this->assertFalse($precio->save());
    }

    public function test_save_inserts_when_row_does_not_exist(): void
    {
        $precio = $this->populatedModel();

        $this->assertTrue($precio->save());

        $sql = $this->db->lastSql;
        $this->assertStringContainsString('INSERT INTO catalogo_articulo_precios', $sql);
        $this->assertStringContainsString("'A','L1','12.5'", $sql);
    }

    public function test_save_updates_when_row_exists(): void
    {
        $db = new MockCatalogoDb();
        // row already exists for (referencia, codlista)
        $db->rows = [
            ['referencia' => 'A', 'codlista' => 'L1', 'precio' => '10.00'],
        ];
        $this->injectDb($db);

        $precio = $this->populatedModel();
        $this->assertTrue($precio->save());

        $sql = $db->lastSql;
        $this->assertStringContainsString('UPDATE catalogo_articulo_precios', $sql);
        $this->assertStringContainsString('precio', $sql);
        $this->assertStringNotContainsString('INSERT', $sql);
    }

    public function test_set_precio_updates_existing_row_without_insert(): void
    {
        $db = new MockCatalogoDb();
        $db->rows = [
            ['referencia' => 'A', 'codlista' => 'L1', 'precio' => '10.00'],
        ];
        $this->injectDb($db);

        $precio = new \FSFramework\model\catalogo_articulo_precio();
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($precio, $db);
        $this->assertTrue($precio->set_precio('A', 'L1', 9.99));

        $this->assertStringContainsString('UPDATE catalogo_articulo_precios', $db->lastSql);
    }

    public function test_set_precio_inserts_new_row(): void
    {
        $db = new MockCatalogoDb();
        $this->injectDb($db);

        $precio = new \FSFramework\model\catalogo_articulo_precio();
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($precio, $db);
        $this->assertTrue($precio->set_precio('A', 'L1', 9.99));

        $this->assertStringContainsString('INSERT INTO catalogo_articulo_precios', $db->lastSql);
    }

    public function test_delete_removes_row_for_article_and_list(): void
    {
        $precio = $this->populatedModel();

        $this->assertTrue($precio->delete());

        $sql = $this->db->lastSql;
        $this->assertStringContainsString('DELETE FROM catalogo_articulo_precios', $sql);
        $this->assertStringContainsString("codlista = 'L1'", $sql);
    }

    public function test_xml_schema_has_composite_pk_and_cascading_fks(): void
    {
        $xml = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/table/catalogo_articulo_precios.xml'
        );
        $this->assertNotFalse($xml, 'catalogo_articulo_precios.xml must exist');

        $this->assertStringContainsString('PRIMARY KEY (referencia, codlista)', $xml);
        $this->assertMatchesRegularExpression(
            '/FOREIGN KEY \(codlista\)[^"]*REFERENCES catalogo_listas_precio[^\"]*ON DELETE CASCADE/s',
            $xml
        );
        $this->assertMatchesRegularExpression(
            '/FOREIGN KEY \(referencia\)[^"]*REFERENCES articulos[^\"]*ON DELETE CASCADE/s',
            $xml
        );
    }

    public function test_naming_uses_codlista_only(): void
    {
        $modelFile = FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_precio.php';
        $this->assertFileExists($modelFile);
        $this->assertStringNotContainsStringIgnoringCase(
            'codtarifa',
            (string) file_get_contents($modelFile)
        );
    }
}

/**
 * Minimal fs_db2 stand-in: records executed SQL, returns canned rows for
 * SELECT and success for exec(). No connection is ever opened.
 */
class MockCatalogoDb
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    /** Last SQL passed to exec() or select(). */
    public string $lastSql = '';

    public bool $transactionOpen = false;

    public function connected(): bool
    {
        return true;
    }

    public function escape_string(string $str): string
    {
        return addslashes($str);
    }

    public function var2str($val)
    {
        if (is_null($val)) {
            return 'NULL';
        }
        if (is_bool($val)) {
            return $val ? 'TRUE' : 'FALSE';
        }
        if (is_numeric($val)) {
            return "'" . (string) $val . "'";
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function select($sql)
    {
        $this->lastSql = $sql;

        return $this->rows;
    }

    public function exec($sql)
    {
        $this->lastSql = $sql;

        return true;
    }

    public function table_exists($name)
    {
        return true;
    }

    public function begin_transaction(): bool
    {
        $this->transactionOpen = true;

        return true;
    }

    public function commit(): bool
    {
        $this->transactionOpen = false;

        return true;
    }

    public function rollback(): bool
    {
        $this->transactionOpen = false;

        return true;
    }
}
