<?php
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
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Tarifa selector for tarif_opcional_edit (OTS-01..OTS-05).
 *
 * Behaviour tests resolve `tarifa_seleccionada` through the controller-local
 * `resolver_tarifa_seleccionada()` on an anonymous subclass that skips the
 * heavy constructor (no DB, no menu), mirroring the master-state test seam.
 * Source-contract tests read the real view to lock the selector, the scoped
 * panel and the read-only all-tarifas overview.
 */
final class TarifOpcionalEditTarifaSelectorTest extends TestCase
{
    private const EDIT_CONTROLLER = 'plugins/catalogo_core/controller/tarif_opcional_edit.php';
    private const EDIT_VIEW = 'plugins/catalogo_core/View/tarif_opcional_edit.html.twig';

    private static bool $baseLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_controller.php';
        self::$baseLoaded = true;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function buildController(): object
    {
        require_once FS_FOLDER . '/' . self::EDIT_CONTROLLER;

        return new class() extends \tarif_opcional_edit {
            public function __construct()
            {
                // Skip the heavy constructor: no DB, no menu, no permissions.
            }
        };
    }

    private function tarifa(string $codtarifa): object
    {
        return new class($codtarifa) {
            public string $codtarifa;

            public function __construct(string $codtarifa)
            {
                $this->codtarifa = $codtarifa;
            }
        };
    }

    private function resolve(object $controller): void
    {
        $ref = new \ReflectionMethod($controller, 'resolver_tarifa_seleccionada');
        $ref->setAccessible(true);
        $ref->invoke($controller);
    }

    private function view(): string
    {
        return (string) file_get_contents(FS_FOLDER . '/' . self::EDIT_VIEW);
    }

    private function selectorBlock(): string
    {
        $view = $this->view();
        $start = strpos($view, '<select name="codtarifa"');
        if ($start === false) {
            return '';
        }

        $end = strpos($view, '</select>', $start);

        return $end === false ? substr($view, $start) : substr($view, $start, $end - $start);
    }

    // =====================================================================
    // OTS-01 / OTS-03 — resolution behaviour
    // =====================================================================

    public function test_selector_resolution_prefers_requested_active_tarifa(): void
    {
        $controller = $this->buildController();
        $controller->codtarifa = 'T2';
        $controller->tarifas = [$this->tarifa('T1'), $this->tarifa('T2'), $this->tarifa('T3')];
        $controller->tarifa_defecto = $this->tarifa('T1');

        $this->resolve($controller);

        self::assertSame('T2', $controller->tarifa_seleccionada->codtarifa, 'the requested active tarifa wins');
        self::assertSame('T2', $controller->codtarifa, 'codtarifa is normalized to the resolved code');
    }

    public function test_unknown_codtarifa_falls_back_to_default(): void
    {
        $controller = $this->buildController();
        $controller->codtarifa = 'UNKNOWN';
        $controller->tarifas = [$this->tarifa('T1'), $this->tarifa('T2')];
        $controller->tarifa_defecto = $this->tarifa('T2');

        $this->resolve($controller);

        self::assertSame('T2', $controller->tarifa_seleccionada->codtarifa, 'an unknown code must not stay selected');
        self::assertSame('T2', $controller->codtarifa, 'codtarifa is normalized to the default');
    }

    public function test_absent_codtarifa_falls_back_to_default(): void
    {
        $controller = $this->buildController();
        $controller->codtarifa = '';
        $controller->tarifas = [$this->tarifa('T1'), $this->tarifa('T2')];
        $controller->tarifa_defecto = $this->tarifa('T2');

        $this->resolve($controller);

        self::assertSame('T2', $controller->tarifa_seleccionada->codtarifa, 'an absent code resolves to the default');
        self::assertSame('T2', $controller->codtarifa);
    }

    public function test_unknown_codtarifa_without_default_falls_back_to_first_active(): void
    {
        $controller = $this->buildController();
        $controller->codtarifa = 'UNKNOWN';
        $controller->tarifas = [$this->tarifa('T1'), $this->tarifa('T2')];
        $controller->tarifa_defecto = false;

        $this->resolve($controller);

        self::assertSame('T1', $controller->tarifa_seleccionada->codtarifa, 'without a default the first active tarifa wins');
        self::assertSame('T1', $controller->codtarifa);
    }

    public function test_no_active_tarifas_yields_false_selection(): void
    {
        $controller = $this->buildController();
        $controller->codtarifa = 'T1';
        $controller->tarifas = [];
        $controller->tarifa_defecto = false;

        $this->resolve($controller);

        self::assertFalse($controller->tarifa_seleccionada, 'no active tarifas must yield a false selection');
    }

    // =====================================================================
    // OTS-01 / OTS-04 — selector contract
    // =====================================================================

    public function test_edit_view_exposes_selector_listing_tarifas_with_default_marked(): void
    {
        $selector = $this->selectorBlock();

        self::assertNotSame('', $selector, 'the edit view must render a <select name="codtarifa"> selector');
        self::assertStringContainsString('for tarifa in fsc.tarifas', $selector, 'the selector must list every active tarifa');
        self::assertStringContainsString(
            'fsc.tarifa_seleccionada.codtarifa',
            $selector,
            'the active/default tarifa must be marked selected'
        );
        self::assertStringContainsString('selected', $selector, 'the selected option must be rendered');

        // OTS-04 — htmx 4 drives the selector update.
        self::assertStringContainsString('hx-get="{{ fsc.opcional.url() }}"', $selector);
        self::assertStringContainsString('hx-trigger="change"', $selector);
        self::assertStringContainsString('hx-target="body"', $selector);
        self::assertStringContainsString('hx-select="body"', $selector);
        self::assertStringContainsString('hx-swap="outerHTML"', $selector);
        self::assertStringContainsString('hx-push-url="true"', $selector);
        self::assertStringContainsString('hx-boost="true"', $selector);
    }

    public function test_edit_view_selector_has_no_full_page_submit(): void
    {
        $view = $this->view();

        self::assertStringNotContainsString(
            'onchange="this.form.submit()"',
            $view,
            'the tarifa selector must not full-page submit'
        );
        self::assertStringNotContainsString('onchange', $this->selectorBlock(), 'the selector carries no onchange handler');
    }

    // =====================================================================
    // OTS-02 / OTS-05 — scoped panel + locked save contract + overview
    // =====================================================================

    public function test_edit_view_scoped_panel_preserves_locked_save_contract(): void
    {
        $view = $this->view();

        self::assertStringContainsString('guardar_precio_tarifa', $view, 'OTS-05: the locked save action must stay');
        self::assertStringContainsString('{{ csrf_field() }}', $view, 'OTS-05: the CSRF field must stay');
        self::assertStringContainsString('tarif.toggle_button_group(', $view, 'OTS-05: the shared state macro must stay');
        self::assertStringContainsString(
            'fsc.get_precio_tarifa(fsc.tarifa_seleccionada.codtarifa)',
            $view,
            'OTS-02: the price control must be scoped to the selected tarifa'
        );
        self::assertSame(
            1,
            substr_count($view, 'name="guardar_precio_tarifa"'),
            'OTS-02: the bulk per-tarifa forms must be replaced by one scoped editable panel'
        );
    }

    public function test_edit_view_retains_all_tarifas_overview(): void
    {
        $view = $this->view();

        self::assertStringContainsString(
            'in fsc.precios_tarifas',
            $view,
            'OTS-02: the compact all-tarifas read-only overview must be retained'
        );
    }
}
