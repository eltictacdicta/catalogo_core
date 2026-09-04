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
 * With the multi-tariff flag off: zero tariff UI is offered (per-article tab,
 * list CRUD UI and list selectors are gated on the flag) and with the
 * role-groups master off the listener allows every dispatch without any
 * model read. Flags default off on a fresh install.
 */
final class MultiTariffFlagInertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset(
            $GLOBALS['config2'][CatalogoOptions::KEY_MULTI_TARIFF],
            $GLOBALS['config2'][CatalogoOptions::KEY_GROUPS_ENABLED]
        );
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['config2'][CatalogoOptions::KEY_MULTI_TARIFF],
            $GLOBALS['config2'][CatalogoOptions::KEY_GROUPS_ENABLED]
        );
        parent::tearDown();
    }

    // ---- Flag defaults (safe default per R-CO-003) ----

    public function test_flags_default_off_on_fresh_install(): void
    {
        $options = new CatalogoOptions();

        $this->assertFalse($options->multiTariffEnabled(), 'multi-tariff defaults OFF');
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

    // ---- UI gating: multi-tariff flag off hides the tariff UI entirely ----

    /** The per-article tab markup must live inside the multi_tariff guard. */
    public function test_article_tab_gated_on_multi_tariff_flag(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulo.html.twig');

        $this->assertStringContainsString('{% if fsc.multi_tariff %}', $view);
        $this->assertLessThan(
            mb_strpos($view, 'precios_listas'),
            mb_strpos($view, '{% if fsc.multi_tariff %}'),
            'The precios por lista tab must be inside the multi_tariff guard (hidden entirely when off)'
        );
    }

    /** The list CRUD page hides its forms when the flag is off. */
    public function test_listas_page_ui_gated_on_multi_tariff_flag(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/view/ventas_listas_precio.html.twig');

        $this->assertLessThan(
            mb_strpos($view, 'value="create"'),
            mb_strpos($view, '{% if fsc.multi_tariff %}'),
            'List CRUD UI must be inside the multi_tariff guard'
        );
    }

    /** The articles-list entry button is gated on the flag. */
    public function test_articulos_entry_gated_on_multi_tariff_flag(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulos.html.twig');

        $this->assertLessThan(
            mb_strpos($view, 'ventas_listas_precio'),
            mb_strpos($view, '{% if fsc.multi_tariff %}'),
            'The price-list entry button must be inside the multi_tariff guard'
        );
    }

    /** With the flag off the controller loads ZERO lists (selectors hidden). */
    public function test_controller_loads_zero_lists_when_flag_off(): void
    {
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoOptions.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php';

        $controller = (new \ReflectionClass(\FSFramework\Plugins\catalogo_core\Controller\VentasArticulo::class))
            ->newInstanceWithoutConstructor();

        $ref = new \ReflectionMethod(\FSFramework\Plugins\catalogo_core\Controller\VentasArticulo::class, 'loadPreciosPorLista');
        $ref->setAccessible(true);
        $ref->invoke($controller);

        $this->assertFalse($controller->multi_tariff);
        $this->assertSame([], $controller->listas_activas, 'flag off ⇒ zero lists offered (selectors hidden)');
        $this->assertSame([], $controller->precios_por_lista);
    }
}
