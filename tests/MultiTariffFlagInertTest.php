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

use FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent;
use FSFramework\Plugins\catalogo_core\Services\CatalogoOptions;
use FSFramework\Plugins\catalogo_core\Services\GroupPermissionListener;
use PHPUnit\Framework\TestCase;

/**
 * Inert-when-off guarantee (multitarifa task 3.6; R-CO-003, R-RG-005,
 * R-MT-001 inactive-list exclusion).
 *
 * The price-list UI is retired (R-MT-009): no per-article list prices, no
 * list page entry, no list section. With the
 * role-groups master off the listener allows every dispatch without any
 * model read. Flags default off on a fresh install.
 */
final class MultiTariffFlagInertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset(
            $GLOBALS['config2'][CatalogoOptions::KEY_GROUPS_ENABLED]
        );
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['config2'][CatalogoOptions::KEY_GROUPS_ENABLED]
        );
        parent::tearDown();
    }

    // ---- Flag defaults (safe default per R-CO-003) ----

    public function test_groups_flag_defaults_off_on_fresh_install(): void
    {
        $options = new CatalogoOptions();

        $this->assertFalse($options->groupsEnabled(), 'role-groups master defaults OFF');
    }

    // ---- Listener inert with master off (zero denies, zero model reads) ----

    public function test_listener_allows_every_dispatch_with_master_off(): void
    {
        $listener = new GroupPermissionListener();

        foreach ([['A', 'u1'], ['B', 'u2'], ['GHOST', 'u3']] as [$ref, $nick]) {
            $event = new ArticlePermissionFilterEvent($ref, ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE, $nick);
            $listener($event);

            $this->assertTrue($event->isAllowed(), "master off must allow $nick on $ref (inert feature)");
        }
    }

    // ---- Price-list UI retired (R-MT-009): only tariffs exist as a concept ----

    /** The article editor no longer offers per-list prices. */
    public function test_article_editor_has_no_per_list_prices(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulo.html.twig');
        $controller = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php');

        foreach (['precios_listas', 'form_precios_lista', 'precios_por_lista', 'listas_activas'] as $needle) {
            $this->assertStringNotContainsString($needle, $view, 'the per-list prices section is retired');
        }
        foreach (['guardar_precios_lista', 'loadPreciosPorLista', 'guardarPreciosPorLista', 'PriceListAvailability'] as $needle) {
            $this->assertStringNotContainsString($needle, $controller);
        }
    }

    /** The articles list has no entry to a price-list page. */
    public function test_articulos_list_has_no_price_list_entry(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulos.html.twig');
        $controller = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulos.php');

        $this->assertStringNotContainsString('Listas de precio', $view);
        $this->assertStringNotContainsString('ventas_listas_precio', $view);
        $this->assertStringNotContainsString('PriceListAvailability', $controller);
    }

    /** The tariffs page manages tariffs only: no list section. */
    public function test_tariffs_page_has_no_list_section(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/View/tarif_tarifas.html.twig');

        $this->assertStringNotContainsString('listas-sin-tarifa', $view);
        $this->assertStringNotContainsString('lista_crear', $view);

        $controller = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/controller/tarif_tarifas.php');
        $this->assertStringContainsString("parent::__construct(__CLASS__, 'Tarifas', 'catalogo')", $controller);
    }

    /** The family-structure selector speaks of tariffs, not price lists. */
    public function test_family_assignment_selector_is_labelled_tarifa(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulo.html.twig');

        $this->assertStringContainsString('for="codlista_estructura">Tarifa:</label>', $view);
        $this->assertStringNotContainsString('Lista de precio:', $view);
    }
}
