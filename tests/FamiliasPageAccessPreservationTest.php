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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/model/core/fs_user.php';

/**
 * fs_access preservation (familias-jerarquia task 3.5, R-FJ-006).
 *
 * The capability absorbs the family tree into an EXISTING page, so the access
 * contract is "the page name never changes". `fs_user::have_access_to()` and
 * `fs_user::allow_delete_on()` resolve permissions by `fs_pages.name` /
 * `fs_roles_access.fs_page`, therefore keeping the name keeps every existing
 * access row valid.
 *
 * Task 3.5 was written against `VentasFamilias` (`ventas_familias`, menu
 * `ventas`). That page was retired on 2026-09-06 (`3a5a42ef`) and
 * `Init::retireVentasFamiliasPage()` deletes its `fs_pages` row on every boot;
 * the settled target of this change is `tarif_familias`, which is asserted
 * here instead. The catalog view page keeps its own `ventas_catalogo_view`
 * name under the `ventas` menu, so no access-row copy migration is required.
 */
final class FamiliasPageAccessPreservationTest extends TestCase
{
    private const CONTROLLER = '/plugins/catalogo_core/controller/tarif_familias.php';

    private const CATALOG_VIEW = '/plugins/catalogo_core/Controller/VentasCatalogoView.php';

    private const CATALOG_VIEW_CLASS = 'FSFramework\\Plugins\\catalogo_core\\Controller\\VentasCatalogoView';

    /** The page the family tree lives on — unchanged by this capability. */
    private const FAMILIES_PAGE = 'tarif_familias';

    /** The retired plain list page (must never come back as an access target). */
    private const RETIRED_PAGE = 'ventas_familias';

    // =====================================================================
    // The families page keeps its identity
    // =====================================================================

    #[Test]
    public function familiesPageKeepsItsRegisteredNameTitleAndMenu(): void
    {
        $registration = $this->pageRegistration();

        $this->assertSame(
            self::FAMILIES_PAGE,
            $registration['name'],
            'The access key is the page name: renaming it would orphan every fs_access row'
        );
        $this->assertSame('Familias', $registration['title']);
        $this->assertSame('catalogo', $registration['menu']);
    }

    #[Test]
    public function familiesPageNameIsTheControllerClassName(): void
    {
        // fs_controller receives __CLASS__; the persisted page name is therefore
        // the concrete controller class name.
        $this->assertSame(
            self::FAMILIES_PAGE,
            $this->pageRegistration()['raw_name'],
            'The constructor must keep passing __CLASS__ as the page name'
        );
    }

    #[Test]
    public function accessResolutionIsKeyedByThePreservedPageName(): void
    {
        $user = $this->userWithMenu([self::FAMILIES_PAGE]);

        $this->assertTrue(
            $user->have_access_to(self::FAMILIES_PAGE),
            'An existing access row for the families page keeps working (R-FJ-006)'
        );
        $this->assertFalse(
            $user->have_access_to(self::RETIRED_PAGE),
            'The retired plain list is not an access target anymore'
        );
    }

    #[Test]
    public function theRetiredPlainPageIsNotThePageCarryingTheTree(): void
    {
        $this->assertNotSame(
            self::RETIRED_PAGE,
            $this->pageRegistration()['name'],
            'The tree must not have been moved onto the retired page name'
        );
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasFamilias.php',
            'VentasFamilias was retired; this change does not resurrect it'
        );
    }

    // =====================================================================
    // The catalog view page registers under the ventas menu
    // =====================================================================

    #[Test]
    public function catalogViewPageIsRegisteredUnderTheVentasMenu(): void
    {
        $data = $this->catalogViewController(true)->getPageData();

        $this->assertSame('ventas_catalogo_view', $data['name']);
        $this->assertSame('ventas', $data['menu']);
        $this->assertNotSame(
            $this->pageRegistration()['name'],
            $data['name'],
            'Two distinct page names: no fs_access row collides and no copy migration is needed'
        );
    }

    // =====================================================================
    // Harness
    // =====================================================================

    /**
     * Extracts the page registration triple from the constructor.
     *
     * @return array{name: string, raw_name: string, title: string, menu: string}
     */
    private function pageRegistration(): array
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER);

        if (preg_match(
            "/parent::__construct\(\s*(__CLASS__|'[^']*')\s*,\s*'([^']*)'\s*,\s*'([^']*)'/",
            $source,
            $matches
        ) !== 1) {
            self::fail('The families controller must register its page in the constructor');
        }

        $classPath = FS_FOLDER . self::CONTROLLER;
        if (!class_exists('tarif_familias', false)) {
            require_once FS_FOLDER . '/base/fs_controller.php';
            require_once $classPath;
        }

        $classShortName = (new \ReflectionClass('tarif_familias'))->getShortName();
        $rawName = $matches[1];
        $name = $rawName === '__CLASS__' ? $classShortName : trim($rawName, "'");

        return [
            'name' => $name,
            'raw_name' => $rawName === '__CLASS__' ? $classShortName : $name,
            'title' => $matches[2],
            'menu' => $matches[3],
        ];
    }

    /**
     * @param list<string> $menuPageNames
     */
    private function userWithMenu(array $menuPageNames): \FSFramework\model\fs_user
    {
        return new class($menuPageNames) extends \FSFramework\model\fs_user {
            /** @var list<string> */
            private array $pages;

            /**
             * @param list<string> $pages
             */
            public function __construct(array $pages)
            {
                $this->pages = $pages;
            }

            public function get_menu($reload = false)
            {
                $menu = [];
                foreach ($this->pages as $name) {
                    $menu[] = (object) ['name' => $name, 'title' => $name, 'folder' => 'catalogo'];
                }

                return $menu;
            }
        };
    }

    private function catalogViewController(bool $admin): object
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . self::CATALOG_VIEW;

        $controller = new class() extends \FSFramework\Plugins\catalogo_core\Controller\VentasCatalogoView {
            public function __construct()
            {
            }
        };
        $controller->user = (object) ['admin' => $admin];

        return $controller;
    }
}
