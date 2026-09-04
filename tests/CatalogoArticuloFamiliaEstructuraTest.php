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
 * Per-list article family assignment model (familias-jerarquia task 1.2,
 * R-FJ-003). PK (codlista, referencia); codfamilia NULL-able with NO FK
 * (legacy parity — integrity is application-level, mirrors the legacy
 * tarif_tarifa_articulo comment).
 *
 * Mock fs_db2 injected via reflection; cascades asserted structurally
 * against the XML schema.
 */
final class CatalogoArticuloFamiliaEstructuraTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_familia_estructura.php';

        $this->db = new MockArticuloFamiliaEstructuraDb();
    }

    private function populatedModel(array $overrides = [])
    {
        $defaults = [
            'codlista' => 'L1',
            'referencia' => 'ART1',
            'codfamilia' => 'F1',
            'en_tarifa' => true,
            'en_catalogo' => true,
            'orden' => 0,
        ];
        $model = new \FSFramework\model\catalogo_articulo_familia_estructura(array_merge($defaults, $overrides));
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($model, $this->db);

        return $model;
    }

    public function test_constructor_maps_data_row(): void
    {
        $model = $this->populatedModel(['codfamilia' => 'F2', 'orden' => 3, 'en_tarifa' => false]);

        $this->assertSame('L1', $model->codlista);
        $this->assertSame('ART1', $model->referencia);
        $this->assertSame('F2', $model->codfamilia);
        $this->assertFalse($model->en_tarifa);
        $this->assertTrue($model->en_catalogo);
        $this->assertSame(3, $model->orden);
    }

    public function test_constructor_defaults_without_data(): void
    {
        $model = new \FSFramework\model\catalogo_articulo_familia_estructura();

        $this->assertNull($model->codlista);
        $this->assertNull($model->referencia);
        $this->assertNull($model->codfamilia);
        $this->assertTrue($model->en_tarifa);
        $this->assertTrue($model->en_catalogo);
        $this->assertSame(0, $model->orden);
    }

    public function test_codfamilia_accepts_null_without_fk(): void
    {
        // Legacy parity: codfamilia may be NULL with no FK constraint.
        $model = $this->populatedModel(['codfamilia' => null]);
        $this->assertTrue($model->test());
    }

    public function test_save_rejects_missing_codlista(): void
    {
        $model = $this->populatedModel();
        $model->codlista = '';

        $this->assertFalse($model->save());
        $this->assertNotEmpty($model->get_errors());
    }

    public function test_save_rejects_missing_referencia(): void
    {
        $model = $this->populatedModel();
        $model->referencia = '';

        $this->assertFalse($model->save());
    }

    public function test_save_rejects_negative_orden(): void
    {
        $model = $this->populatedModel();
        $model->orden = -2;

        $this->assertFalse($model->save());
    }

    public function test_save_inserts_when_row_does_not_exist(): void
    {
        $model = $this->populatedModel();

        $this->assertTrue($model->save());

        $sql = $this->db->lastSql;
        $this->assertStringContainsString('INSERT INTO catalogo_articulo_familia_estructura', $sql);
    }

    public function test_reassignment_updates_existing_row(): void
    {
        // R-FJ-003 scenario 1: re-assign updates, no duplicate.
        $db = new MockArticuloFamiliaEstructuraDb();
        $db->rows = [
            ['codlista' => 'L1', 'referencia' => 'ART1', 'codfamilia' => 'F1'],
        ];
        $this->db = $db;

        $model = $this->populatedModel(['codfamilia' => 'F2']);
        $this->assertTrue($model->save());

        $sql = $db->lastSql;
        $this->assertStringContainsString('UPDATE catalogo_articulo_familia_estructura', $sql);
        $this->assertStringContainsString('codfamilia', $sql);
        $this->assertStringNotContainsString('INSERT', $sql);
    }

    public function test_delete_removes_row_for_article_and_list(): void
    {
        $model = $this->populatedModel();

        $this->assertTrue($model->delete());

        $sql = $this->db->lastSql;
        $this->assertStringContainsString('DELETE FROM catalogo_articulo_familia_estructura', $sql);
        $this->assertStringContainsString("codlista = 'L1'", $sql);
        $this->assertStringContainsString("referencia = 'ART1'", $sql);
    }

    public function test_all_from_list_scopes_by_list(): void
    {
        $db = new MockArticuloFamiliaEstructuraDb();
        $db->rows = [
            ['codlista' => 'L1', 'referencia' => 'ART1', 'codfamilia' => 'F1'],
            ['codlista' => 'L1', 'referencia' => 'ART2', 'codfamilia' => 'F2'],
        ];
        $this->db = $db;

        $model = new \FSFramework\model\catalogo_articulo_familia_estructura();
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($model, $db);

        $all = $model->all_from_list('L1');
        $this->assertCount(2, $all);
        $this->assertSame('ART1', $all[0]->referencia);
        $this->assertStringContainsString("codlista = 'L1'", $db->lastSql);
    }

    public function test_xml_schema_has_composite_pk_and_cascading_fks(): void
    {
        $xml = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/table/catalogo_articulo_familia_estructura.xml'
        );

        $this->assertStringContainsString('PRIMARY KEY (codlista, referencia)', $xml);
        $this->assertMatchesRegularExpression(
            '/FOREIGN KEY \(codlista\)[^"]*REFERENCES catalogo_listas_precio[^"]*ON DELETE CASCADE/s',
            $xml
        );
        $this->assertMatchesRegularExpression(
            '/FOREIGN KEY \(referencia\)[^"]*REFERENCES articulos[^"]*ON DELETE CASCADE/s',
            $xml
        );
    }

    public function test_xml_schema_codfamilia_is_nullable_and_fk_less(): void
    {
        $xml = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/table/catalogo_articulo_familia_estructura.xml'
        );

        // codfamilia: nullable, NO FK (legacy parity per the XML comment).
        $this->assertDoesNotMatchRegularExpression('/FOREIGN KEY \(codfamilia\)/s', $xml);
        $this->assertMatchesRegularExpression(
            '/<nombre>codfamilia<\/nombre>\s*<tipo>character varying\(8\)<\/tipo>\s*<nulo>YES<\/nulo>/s',
            $xml
        );
    }

    public function test_xml_columns_match_legacy_mapping(): void
    {
        $xml = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/table/catalogo_articulo_familia_estructura.xml'
        );

        foreach (['codlista', 'referencia', 'codfamilia', 'en_tarifa', 'en_catalogo', 'orden'] as $col) {
            $this->assertMatchesRegularExpression(
                '/<nombre>' . $col . '<\/nombre>/s',
                $xml,
                "column $col must exist in catalogo_articulo_familia_estructura.xml"
            );
        }
        $this->assertStringNotContainsStringIgnoringCase('codtarifa', $xml);
    }

    public function test_model_uses_codlista_only(): void
    {
        $file = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_familia_estructura.php'
        );
        $this->assertStringNotContainsStringIgnoringCase('codtarifa', $file);
    }
}

/**
 * Minimal fs_db2 stand-in: records executed SQL, returns canned rows for
 * SELECT and success for exec(). No connection is ever opened.
 */
class MockArticuloFamiliaEstructuraDb
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public string $lastSql = '';

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
}
