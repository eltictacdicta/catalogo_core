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
use PHPUnit\Framework\TestCase;

/**
 * Discoverability contract for the canonical per-list structure editor.
 *
 * `ventas_familia` is `showonmenu => false`, so the family-tree entry point
 * (`tarif_familias`) must expose a visible link to it when — and only when —
 * the family-structure capability is enabled. The link lives in the toolbar
 * so it is reachable without hunting for an empty-state hint.
 */
final class TarifFamiliasStructureLinkTest extends TestCase
{
    private const CONTROLLER_PATH = '/plugins/catalogo_core/controller/tarif_familias.php';
    private const VIEW_PATH = '/plugins/catalogo_core/View/tarif_familias.html.twig';
    private const LINK = "'href': 'index.php?page=ventas_familia'";

    protected function setUp(): void
    {
        parent::setUp();
        unset($GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE]);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Controller data contract
    // ------------------------------------------------------------------

    public function test_flag_loader_reads_the_enabled_capability(): void
    {
        $GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE] = 'true';

        $controller = $this->makeController();
        $this->invoke($controller, 'loadFamilyStructureFlag');

        $this->assertTrue($controller->family_structure, 'Flag on must reach the view data contract');
    }

    public function test_flag_loader_resets_to_false_when_the_setting_is_absent(): void
    {
        $controller = $this->makeController();
        $controller->family_structure = true;

        $this->invoke($controller, 'loadFamilyStructureFlag');

        $this->assertFalse($controller->family_structure, 'Absent flag must default off, not keep a stale value');
    }

    public function test_view_boot_path_loads_the_flag(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_PATH);

        $this->assertStringContainsString(
            '$this->loadFamilyStructureFlag();',
            $source,
            'private_core() must populate the flag before the view renders'
        );
    }

    // ------------------------------------------------------------------
    // Template contract
    // ------------------------------------------------------------------

    public function test_view_links_the_canonical_structure_editor_inside_the_flag_guard(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . self::VIEW_PATH);

        $guard = mb_strpos($view, '{% if fsc.family_structure %}');
        $this->assertNotFalse($guard, 'The structure link must be wrapped in the family_structure guard');

        $link = mb_strpos($view, self::LINK);
        $this->assertNotFalse($link, 'The toolbar must link index.php?page=ventas_familia');
        $this->assertSame(
            1,
            substr_count($view, self::LINK),
            'Exactly one canonical structure link must exist'
        );

        $guardEnd = mb_strpos($view, '{% endif %}', (int) $guard);
        $this->assertNotFalse($guardEnd, 'The flag guard must be closed');
        $this->assertGreaterThan((int) $guard, $link, 'The link must come after the guard opens');
        $this->assertLessThan((int) $guardEnd, $link, 'The link must be inside the guard so flag-off renders nothing');
        $this->assertStringContainsString("'label': 'Estructura por lista'", $view);
    }

    public function test_view_does_not_resurrect_the_retired_flat_page(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . self::VIEW_PATH);

        $this->assertSame(
            0,
            substr_count($view, 'page=ventas_familias'),
            'The retired ventas_familias page must stay unlinked'
        );
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function makeController(): object
    {
        if (!class_exists('tarif_familias', false)) {
            require_once FS_FOLDER . '/base/fs_controller.php';
            require_once FS_FOLDER . self::CONTROLLER_PATH;
        }

        return new class extends \tarif_familias {
            public function __construct()
            {
            }
        };
    }

    private function invoke(object $target, string $method): mixed
    {
        $ref = new \ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invoke($target);
    }
}
