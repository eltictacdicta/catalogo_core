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
 * Per-list family structure model (familias-jerarquia task 1.1, R-FJ-002).
 *
 * Extension-table pattern: upsert by composite PK (codlista, codfamilia).
 * The plugin test bootstrap has no DB layer, so query-behavior scenarios
 * are exercised against a mock fs_db2 injected via reflection. Cascade
 * behavior is asserted structurally against the XML schema.
 */
final class CatalogoFamiliaEstructuraTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_familia_estructura.php';

        $this->db = new MockFamiliaEstructuraDb();
    }

    private function populatedModel(array $overrides = [])
    {
        $defaults = [
            'codlista' => 'L1',
            'codfamilia' => 'F1',
            'madre' => '',
            'capitulo' => '',
            'nivel' => '',
            'en_catalogo' => true,
            'en_tarifa' => false,
            'activa' => true,
            'orden' => 0,
        ];
        $model = new \FSFramework\model\catalogo_familia_estructura(array_merge($defaults, $overrides));
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($model, $this->db);

        return $model;
    }

    public function test_constructor_maps_data_row(): void
    {
        $model = $this->populatedModel([
            'madre' => 'F0',
            'capitulo' => 'Cap 1',
            'nivel' => 'A',
            'en_catalogo' => true,
            'en_tarifa' => true,
            'activa' => true,
            'orden' => 5,
        ]);

        $this->assertSame('L1', $model->codlista);
        $this->assertSame('F1', $model->codfamilia);
        $this->assertSame('F0', $model->madre);
        $this->assertSame('Cap 1', $model->capitulo);
        $this->assertSame('A', $model->nivel);
        $this->assertTrue($model->en_catalogo);
        $this->assertTrue($model->en_tarifa);
        $this->assertTrue($model->activa);
        $this->assertSame(5, $model->orden);
    }

    public function test_constructor_defaults_without_data(): void
    {
        $model = new \FSFramework\model\catalogo_familia_estructura();

        $this->assertNull($model->codlista);
        $this->assertNull($model->codfamilia);
        $this->assertNull($model->madre);
        $this->assertSame('', $model->capitulo);
        $this->assertSame('', $model->nivel);
        $this->assertTrue($model->en_catalogo);
        $this->assertFalse($model->en_tarifa);
        $this->assertTrue($model->activa);
        $this->assertSame(0, $model->orden);
    }

    public function test_save_rejects_missing_codlista(): void
    {
        $model = $this->populatedModel();
        $model->codlista = '';

        $this->assertFalse($model->save());
        $this->assertNotEmpty($model->get_errors());
    }

    public function test_save_rejects_missing_codfamilia(): void
    {
        $model = $this->populatedModel();
        $model->codfamilia = '';

        $this->assertFalse($model->save());
    }

    public function test_save_rejects_negative_orden(): void
    {
        $model = $this->populatedModel();
        $model->orden = -1;

        $this->assertFalse($model->save());
    }

    public function test_save_inserts_when_row_does_not_exist(): void
    {
        $model = $this->populatedModel();

        $this->assertTrue($model->save());

        $sql = $this->db->lastSql;
        $this->assertStringContainsString('INSERT INTO catalogo_familia_estructura', $sql);
        $this->assertStringContainsString('codlista', $sql);
    }

    public function test_save_updates_when_row_exists_no_duplicate(): void
    {
        // R-FJ-002 scenario 1: re-save F1/L1 updates the existing row.
        $db = new MockFamiliaEstructuraDb();
        $db->rows = [
            ['codlista' => 'L1', 'codfamilia' => 'F1', 'orden' => '0'],
        ];
        $this->db = $db;

        $model = $this->populatedModel(['orden' => 7]);
        $this->assertTrue($model->save());

        $sql = $db->lastSql;
        $this->assertStringContainsString('UPDATE catalogo_familia_estructura', $sql);
        $this->assertStringNotContainsString('INSERT', $sql);
    }

    public function test_two_families_in_same_list_are_independent_rows(): void
    {
        // F1/L1 + F1/L2 → 2 rows (different lists, composite key).
        $modelL1 = $this->populatedModel(['codlista' => 'L1']);
        $this->assertTrue($modelL1->save());
        $this->assertStringContainsString("'L1'", $this->db->lastSql);

        $dbL2 = new MockFamiliaEstructuraDb();
        $this->db = $dbL2;
        $modelL2 = $this->populatedModel(['codlista' => 'L2']);
        $this->assertTrue($modelL2->save());
        $this->assertStringContainsString("'L2'", $dbL2->lastSql);
    }

    public function test_delete_removes_row_for_family_and_list(): void
    {
        $model = $this->populatedModel();

        $this->assertTrue($model->delete());

        $sql = $this->db->lastSql;
        $this->assertStringContainsString('DELETE FROM catalogo_familia_estructura', $sql);
        $this->assertStringContainsString("codlista = 'L1'", $sql);
        $this->assertStringContainsString("codfamilia = 'F1'", $sql);
    }

    public function test_en_tarifa_phpdoc_documents_view_only_semantics(): void
    {
        // D2 risk note: en_tarifa must not be conflated with pricing.
        $file = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_familia_estructura.php'
        );
        $this->assertStringContainsStringIgnoringCase('en_tarifa', $file);
        $this->assertStringContainsStringIgnoringCase('view', $file);
    }

    public function test_nivel_phpdoc_documents_display_metadata_semantics(): void
    {
        $file = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_familia_estructura.php'
        );
        $this->assertStringContainsStringIgnoringCase('display', $file);
    }

    public function test_xml_schema_has_composite_pk_and_cascading_fks(): void
    {
        $xml = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/table/catalogo_familia_estructura.xml'
        );

        $this->assertStringContainsString('PRIMARY KEY (codlista, codfamilia)', $xml);
        $this->assertMatchesRegularExpression(
            '/FOREIGN KEY \(codlista\)[^"]*REFERENCES catalogo_listas_precio[^"]*ON DELETE CASCADE/s',
            $xml
        );
        $this->assertMatchesRegularExpression(
            '/FOREIGN KEY \(codfamilia\)[^"]*REFERENCES familias[^"]*ON DELETE CASCADE/s',
            $xml
        );
    }

    public function test_xml_schema_madre_column_is_fk_less_legacy_parity(): void
    {
        $xml = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/table/catalogo_familia_estructura.xml'
        );

        // madre is carried verbatim with no FK (application-level integrity).
        $this->assertDoesNotMatchRegularExpression('/FOREIGN KEY \(madre\)/s', $xml);
        $this->assertMatchesRegularExpression('/<nombre>madre<\/nombre>/s', $xml);
    }

    public function test_xml_columns_match_legacy_mapping(): void
    {
        $xml = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/table/catalogo_familia_estructura.xml'
        );

        foreach (['codlista', 'codfamilia', 'madre', 'capitulo', 'nivel', 'en_catalogo', 'en_tarifa', 'activa', 'orden'] as $col) {
            $this->assertMatchesRegularExpression(
                '/<nombre>' . $col . '<\/nombre>/s',
                $xml,
                "column $col must exist in catalogo_familia_estructura.xml"
            );
        }
        $this->assertStringNotContainsStringIgnoringCase('codtarifa', $xml);
    }

    public function test_model_uses_codlista_only(): void
    {
        $file = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_familia_estructura.php'
        );
        $this->assertStringNotContainsStringIgnoringCase('codtarifa', $file);
    }
}

/**
 * Minimal fs_db2 stand-in: records executed SQL, returns canned rows for
 * SELECT and success for exec(). No connection is ever opened.
 */
class MockFamiliaEstructuraDb
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
