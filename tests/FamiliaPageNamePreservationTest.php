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
 * Task 3.5 — page-name preservation (R-FJ-006).
 *
 * The repository retired the flat `ventas_familias` page (Amendment 1 of the
 * archived absorber change) and promoted `tarif_familias` to the family tree
 * under the `catalogo` menu. Phase 3 therefore preserves the names of the
 * pages that actually exist; it does not resurrect the retired one.
 *
 * This is an approval/preservation test: it documents current access-facing
 * page identity so the Phase 3 UI cannot silently rename a page and orphan
 * `fs_access` rows.
 */
final class FamiliaPageNamePreservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasFamilia.php';
    }

    public function test_family_detail_page_keeps_its_page_name_and_menu(): void
    {
        $controller = new class extends \FSFramework\Plugins\catalogo_core\Controller\VentasFamilia {
            public function __construct() {}
        };

        $pageData = $controller->getPageData();

        $this->assertSame('ventas_familia', $pageData['name'], 'The existing fs_access page name must not change');
        $this->assertSame('catalogo', $pageData['menu'], 'The page must stay in the catalogo menu folder');
    }

    public function test_family_tree_page_stays_registered_as_tarif_familias_under_catalogo(): void
    {
        $source = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/controller/tarif_familias.php'
        );

        $this->assertStringContainsString(
            "parent::__construct(__CLASS__, 'Familias', 'catalogo');",
            $source,
            'The tree page must keep its class-derived name and catalogo menu (R-FJ-006)'
        );
    }

    public function test_retired_flat_list_is_not_resurrected(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasFamilias.php',
            'Phase 3 must not resurrect the retired ventas_familias page; the tree lives on tarif_familias'
        );

        $init = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/Init.php');
        $this->assertStringContainsString(
            'self::retireVentasFamiliasPage();',
            $init,
            'The idempotent retirement cleanup must stay wired so no dead fs_pages row returns'
        );
    }

    public function test_no_new_page_is_registered_in_this_slice(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasCatalogoView.php',
            'ventas_catalogo_view belongs to task 4.1 and must not be created in the Phase 3 slice'
        );
    }
}
