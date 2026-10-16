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

use FSFramework\Plugins\catalogo_core\Services\CatalogoOptions;
use FSFramework\Plugins\catalogo_core\Services\CatalogoRoleListNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * General options page (multitarifa task 3.4, R-CO-004).
 *
 * Admin-only opciones_catalogo page editing ALL store-managed options in one
 * form: multi-tariff flag, role-groups master flag and the Excel granted
 * roles (via the shared CatalogoRoleListNormalizer). POST is CSRF-checked
 * and input-whitelisted; persistence goes through CatalogoOptions (sole
 * writer of the catalogo_core.* keys).
 */
#[CoversClass(CatalogoOptions::class)]
final class OpcionesCatalogoControllerTest extends TestCase
{
    private const CONTROLLER_FILE = '/plugins/catalogo_core/controller/opciones_catalogo.php';
    private const VIEW_FILE = '/plugins/catalogo_core/view/opciones_catalogo.html.twig';

    protected function setUp(): void
    {
        parent::setUp();
        global $plugins;
        $plugins = [];
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_settings.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/model/fs_rol.php';
        require_once FS_FOLDER . '/model/fs_rol_user.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoOptions.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoRoleListNormalizer.php';
        foreach ([CatalogoOptions::KEY_MULTI_TARIFF, CatalogoOptions::KEY_GROUPS_ENABLED, CatalogoOptions::KEY_EXCEL_ROLES] as $key) {
            unset($GLOBALS['config2'][$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ([CatalogoOptions::KEY_MULTI_TARIFF, CatalogoOptions::KEY_GROUPS_ENABLED, CatalogoOptions::KEY_EXCEL_ROLES] as $key) {
            unset($GLOBALS['config2'][$key]);
        }
        parent::tearDown();
    }

    // ---- Page shape ----

    public function test_controller_and_view_exist(): void
    {
        $this->assertFileExists(FS_FOLDER . self::CONTROLLER_FILE);
        $this->assertFileExists(FS_FOLDER . self::VIEW_FILE);
    }

    public function test_controller_extends_legacy_fs_controller_with_admin_gate(): void
    {
        require_once FS_FOLDER . self::CONTROLLER_FILE;

        $reflection = new \ReflectionClass('opciones_catalogo');
        $this->assertTrue($reflection->isSubclassOf(\fs_controller::class));

        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);
        $this->assertMatchesRegularExpression(
            "/parent::__construct\(__CLASS__,\s*'[^']*',\s*'admin',\s*TRUE,\s*TRUE\)/",
            $source,
            'Constructor must gate the page to admins in the admin folder'
        );
    }

    // ---- POST pipeline: CSRF + whitelist ----

    public function test_post_reads_only_whitelisted_fields(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        foreach (["\$_POST['multi_tariff']", "\$_POST['groups_enabled']", "\$_POST['catalogo_excel_roles']"] as $field) {
            $this->assertStringContainsString($field, $source, "POST must read $field");
        }
        $this->assertSame(
            3,
            preg_match_all("/\\\$_POST\[/", $source),
            'No other POST field may be read (input whitelist, R-CO-004)'
        );
    }

    public function test_post_checks_csrf_before_persistence(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $csrfPos = strpos($source, 'isCsrfValid()');
        $this->assertNotFalse($csrfPos, 'POST branch must call isCsrfValid()');

        foreach (['setMultiTariff', 'setGroupsEnabled', 'setExcelRoles'] as $writer) {
            $pos = strpos($source, $writer);
            $this->assertNotFalse($pos, "Persistence must go through CatalogoOptions::$writer");
            $this->assertLessThan($pos, $csrfPos, "CSRF must run before $writer");
        }
    }

    public function test_persists_roles_through_shared_normalizer(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $this->assertStringContainsString(
            CatalogoRoleListNormalizer::class,
            $source,
            'The Excel roles save must reuse the shared CatalogoRoleListNormalizer'
        );
    }

    // ---- Round-trip through the store (R-CO-001/004) ----

    public function test_options_roundtrip_persists_all_fields(): void
    {
        $options = new CatalogoOptions();
        $options->setMultiTariff(true);
        $options->setGroupsEnabled(true);
        $options->setExcelRoles('A,B');

        $fresh = new CatalogoOptions();
        $this->assertTrue($fresh->multiTariffEnabled());
        $this->assertTrue($fresh->groupsEnabled());
        $this->assertSame('A,B', $fresh->excelRoles());
    }

    // ---- View ----

    public function test_view_includes_csrf_field_and_no_raw(): void
    {
        $content = (string) file_get_contents(FS_FOLDER . self::VIEW_FILE);

        $this->assertStringContainsString('{{ csrf_field() }}', $content);
        $this->assertStringNotContainsString('|raw', $content, 'Views must escape all output');
    }

    public function test_view_renders_all_option_fields(): void
    {
        $content = (string) file_get_contents(FS_FOLDER . self::VIEW_FILE);

        $this->assertStringContainsString('name="multi_tariff"', $content);
        $this->assertStringContainsString('name="groups_enabled"', $content);
        $this->assertStringContainsString('name="catalogo_excel_roles[]"', $content);
    }
}
