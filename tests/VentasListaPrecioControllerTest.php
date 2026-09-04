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
 * Price-list CRUD page (multitarifa task 3.2, R-MT-004).
 *
 * Legacy fs_controller page (catalogo_excel_settings precedent): the
 * constructor args gate the page to admins at the framework level, POST is
 * CSRF-checked before persistence and action-whitelisted. The save pipeline
 * is exercised against a mock fs_db2 through the real catalogo_lista_precio
 * model; controller wiring is pinned with structural source assertions
 * (CatalogoExcelSettingsPageTest precedent — no Kernel/DB available).
 */
final class VentasListaPrecioControllerTest extends TestCase
{
    private const CONTROLLER_FILE = '/plugins/catalogo_core/controller/ventas_listas_precio.php';
    private const VIEW_FILE = '/plugins/catalogo_core/view/ventas_listas_precio.html.twig';

    protected function setUp(): void
    {
        parent::setUp();
        global $plugins;
        $plugins = [];
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
    }

    // ---- Page shape (R-MT-004: admin-gated CRUD page) ----

    public function test_controller_file_and_view_exist(): void
    {
        $this->assertFileExists(FS_FOLDER . self::CONTROLLER_FILE);
        $this->assertFileExists(FS_FOLDER . self::VIEW_FILE);
    }

    public function test_controller_extends_legacy_fs_controller(): void
    {
        require_once FS_FOLDER . self::CONTROLLER_FILE;

        $reflection = new \ReflectionClass('ventas_listas_precio');
        $this->assertTrue(
            $reflection->isSubclassOf(\fs_controller::class),
            'ventas_listas_precio must be a legacy fs_controller page'
        );
    }

    /** Non-admin denial is enforced at the framework level (admin=TRUE). */
    public function test_controller_is_admin_gated_in_ventas_folder(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $this->assertMatchesRegularExpression(
            "/parent::__construct\(__CLASS__,\s*'[^']*',\s*'ventas',\s*TRUE,\s*TRUE\)/",
            $source,
            'Constructor must gate the page to admins (admin=TRUE) inside the ventas folder'
        );
    }

    // ---- POST pipeline: action whitelist + CSRF before writes ----

    public function test_post_actions_are_whitelisted(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        foreach (['create', 'edit', 'activate', 'deactivate', 'set_default', 'delete'] as $action) {
            $this->assertStringContainsString(
                "'" . $action . "'",
                $source,
                "Action '$action' must be part of the whitelisted CRUD actions"
            );
        }
    }

    public function test_post_checks_csrf_before_persistence(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $csrfPos = strpos($source, 'isCsrfValid()');
        $this->assertNotFalse($csrfPos, 'POST branch must call isCsrfValid()');
        $savePos = strpos($source, '->save()');
        $deletePos = strpos($source, '->delete()');
        $this->assertNotFalse($savePos, 'The pipeline must persist via the model save()');
        $this->assertLessThan($savePos, $csrfPos, 'CSRF check must run before model save()');
        if ($deletePos !== false) {
            $this->assertLessThan($deletePos, $csrfPos, 'CSRF check must run before model delete()');
        }
    }

    public function test_controller_never_touches_article_pvp(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $this->assertStringNotContainsString(
            '->pvp',
            $source,
            'The list CRUD page must never rewrite articulos.pvp (R-MT-003)'
        );
    }

    // ---- Inactive lists remain editable only here (R-MT-001) ----

    public function test_view_lists_all_lists_including_inactive(): void
    {
        $content = (string) file_get_contents(FS_FOLDER . self::VIEW_FILE);

        $this->assertStringContainsString(
            'fsc.listas',
            $content,
            'The view must iterate ALL lists (inactive included; admin CRUD is the only reactivation point)'
        );
    }

    // ---- View hardening ----

    public function test_view_includes_csrf_field_on_forms(): void
    {
        $content = (string) file_get_contents(FS_FOLDER . self::VIEW_FILE);

        $this->assertStringContainsString('{{ csrf_field() }}', $content);
    }

    public function test_view_has_no_raw_output(): void
    {
        $content = (string) file_get_contents(FS_FOLDER . self::VIEW_FILE);

        $this->assertStringNotContainsString('|raw', $content, 'Views must escape all output');
    }

    // ---- Save pipeline through the real model (mock db) ----

    public function test_list_model_save_pipeline_roundtrip(): void
    {
        $db = new VentasListaPrecioMockDb();
        $lista = new \FSFramework\model\catalogo_lista_precio([
            'codlista' => 'L2',
            'nombre' => 'Mayorista',
            'activa' => true,
            'por_defecto' => false,
            'coddivisa' => 'EUR',
        ]);
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($lista, $db);

        $this->assertTrue($lista->save(), 'The list save pipeline must persist through the model');
        $this->assertStringContainsString('INSERT INTO catalogo_listas_precio', $db->lastSql);
    }

    public function test_default_list_cannot_be_deleted(): void
    {
        $db = new VentasListaPrecioMockDb();
        $lista = new \FSFramework\model\catalogo_lista_precio([]);
        $lista->codlista = \FSFramework\model\catalogo_lista_precio::DEFAULT_CODE;
        $lista->por_defecto = true;
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($lista, $db);

        $this->assertFalse($lista->delete(), 'The default list must not be deletable');
        $this->assertNotEmpty($lista->get_errors());
    }
}

/**
 * Minimal fs_db2 stand-in for list-CRUD flows.
 */
class VentasListaPrecioMockDb
{
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

        return [];
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
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function rollback(): bool
    {
        return true;
    }
}
