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
 * Unit 4 contract/runtime for the opcional controllers + Twig matrices
 * (spec "Master consumption in UI and export"; design AD2/AD3/AD5).
 *
 * The list, edit and price controllers must resolve/list the per-(tarifa,
 * opcional) state through the `tarif_tarifa_opcional` master (`effective()` /
 * `resolve_*`) instead of inferring activation from price-row existence:
 *  - list "Estado" is the per-tarifa master `activa`;
 *  - the edit/precios matrices read and write the master flags;
 *  - the list exposes POST + CSRF `toggle_*` endpoints.
 *
 * Behaviour tests use an overridable master seam and a stub opcional/tarifa so
 * no live database row is read or written.
 */
final class TarifOpcionalesControllerMasterStateTest extends TestCase
{
    private const LIST_CONTROLLER = 'plugins/catalogo_core/Controller/VentasOpcionales.php';
    private const LIST_TRAIT = 'plugins/catalogo_core/extras/VentasOpcionalesListTrait.php';
    private const EDIT_CONTROLLER = 'plugins/catalogo_core/controller/tarif_opcional_edit.php';

    private const LIST_VIEW = 'plugins/catalogo_core/View/ventas_opcionales.html.twig';
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

    private function source(string $relative): string
    {
        $path = FS_FOLDER . '/' . $relative;
        if (!is_file($path)) {
            self::fail('missing catalogo_core path: ' . $relative);
        }

        return (string) file_get_contents($path);
    }

    private function loadController(string $relative): void
    {
        if (!is_file(FS_FOLDER . '/' . $relative)) {
            self::fail('missing catalogo_core path: ' . $relative);
        }

        require_once FS_FOLDER . '/' . $relative;
    }

    private function invoke(object $subject, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($subject, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($subject, $args);
    }

    /**
     * Extracts a method body so an assertion cannot be satisfied by unrelated
     * code elsewhere in the file.
     */
    private function methodBody(string $src, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{/';
        if (!preg_match($pattern, $src, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $matches[0][1] + strlen($matches[0][0]);
        $depth = 1;
        $length = strlen($src);
        for ($i = $start; $i < $length; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start);
                }
            }
        }

        return '';
    }

    /**
     * Overridable master-state double: records the `effective()` calls and
     * returns canned per-opcional state.
     */
    private function masterStub(array $states): object
    {
        return new class($states) {
            /** @var list<array{0: string, 1: int}> */
            public array $effectiveCalls = [];

            /** @var array<int, array<string, mixed>> */
            private array $states;

            public function __construct(array $states)
            {
                $this->states = $states;
            }

            public function effective($codtarifa, $id_opcional): array
            {
                $id = (int) $id_opcional;
                $this->effectiveCalls[] = [(string) $codtarifa, $id];

                return $this->states[$id] ?? [
                    'activa' => true,
                    'en_catalogo' => true,
                    'en_tarifa' => false,
                    'orden' => 0,
                    'source' => 'inherit',
                ];
            }

            public function resolve_activa($codtarifa, $id_opcional): bool
            {
                return (bool) $this->effective($codtarifa, $id_opcional)['activa'];
            }

            public function resolve_en_catalogo($codtarifa, $id_opcional): bool
            {
                return (bool) $this->effective($codtarifa, $id_opcional)['en_catalogo'];
            }
        };
    }

    private function buildListController(object $master): object
    {
        $this->loadController(self::LIST_CONTROLLER);

        return new class($master) extends \FSFramework\Plugins\catalogo_core\Controller\VentasOpcionales {
            private $masterStub;

            public function __construct($master)
            {
                $this->masterStub = $master;
            }

            protected function opcional_master_state()
            {
                return $this->masterStub;
            }
        };
    }

    private function buildEditController(object $master): object
    {
        $this->loadController(self::EDIT_CONTROLLER);

        return new class($master) extends \tarif_opcional_edit {
            private $masterStub;

            public function __construct($master)
            {
                $this->masterStub = $master;
            }

            protected function opcional_master_state()
            {
                return $this->masterStub;
            }
        };
    }

    // =====================================================================
    // Controllers resolve/list via the master
    // =====================================================================

    public function test_list_state_cache_resolves_effective_master_per_tarifa(): void
    {
        $master = $this->masterStub([
            7 => ['activa' => false, 'en_catalogo' => true, 'en_tarifa' => true, 'orden' => 0, 'source' => 'master'],
        ]);
        $controller = $this->buildListController($master);
        $controller->resultados = [(object) ['id' => 7]];
        $controller->tarifa_seleccionada = (object) ['codtarifa' => 'T1'];

        $this->invoke($controller, 'load_opcionales_state_cache');

        self::assertSame([['T1', 7]], $master->effectiveCalls, 'the master must be queried per (tarifa, opcional)');
        self::assertFalse($controller->opcional_activo_en_tarifa(7), 'master activa=FALSE must win');
        self::assertTrue($controller->opcional_en_catalogo_tarifa(7), 'catalog flag must come from the master');
    }

    public function test_list_state_cache_marks_master_active_without_price_rows(): void
    {
        $master = $this->masterStub([
            9 => ['activa' => true, 'en_catalogo' => false, 'en_tarifa' => false, 'orden' => 0, 'source' => 'master'],
        ]);
        $controller = $this->buildListController($master);
        $controller->resultados = [(object) ['id' => 9]];
        $controller->tarifa_seleccionada = (object) ['codtarifa' => 'T2'];

        $this->invoke($controller, 'load_opcionales_state_cache');

        self::assertTrue($controller->opcional_activo_en_tarifa(9), 'master activa=TRUE is active without any price row');
        self::assertFalse($controller->opcional_en_catalogo_tarifa(9));
    }

    public function test_edit_matrix_reads_master_flags(): void
    {
        $master = $this->masterStub([
            7 => ['activa' => true, 'en_catalogo' => false, 'en_tarifa' => true, 'orden' => 0, 'source' => 'master'],
        ]);
        $controller = $this->buildEditController($master);
        $controller->opcional = new class() {
            public $id = 7;

            public function get_precios_tarifas()
            {
                return [];
            }
        };
        $controller->tarifas = [(object) ['codtarifa' => 'T1']];

        $this->invoke($controller, 'load_precios_tarifas');

        self::assertSame([['T1', 7]], $master->effectiveCalls);
        self::assertTrue($controller->opcional_activo_en_tarifa('T1'));
        self::assertFalse($controller->opcional_en_catalogo_tarifa('T1'));
        self::assertTrue($controller->opcional_en_tarifa_flag('T1'));
    }

    // =====================================================================
    // Source contracts: master wiring, CSRF toggles, macro reuse
    // =====================================================================

    public function test_controllers_load_the_master_model(): void
    {
        // The unified list logic (and its master wiring) now lives in the list
        // trait composed by Controller/VentasOpcionales.php; the surviving
        // edit controller keeps its own require + import.
        foreach ([self::LIST_TRAIT, self::EDIT_CONTROLLER] as $relative) {
            $src = $this->source($relative);

            self::assertStringContainsString(
                'model/tarif_tarifa_opcional.php',
                $src,
                $relative . ' must require the master model'
            );
            self::assertStringContainsString(
                'use FSFramework\\model\\tarif_tarifa_opcional;',
                $src,
                $relative . ' must import the master model'
            );
        }

        self::assertStringContainsString(
            'use \\VentasOpcionalesListTrait;',
            $this->source(self::LIST_CONTROLLER),
            'Controller/VentasOpcionales.php must compose the unified list trait'
        );
    }

    public function test_list_resolves_state_from_master_not_price_rows(): void
    {
        $src = $this->source(self::LIST_TRAIT);

        $activa = $this->methodBody($src, 'opcional_activo_en_tarifa');
        self::assertNotSame('', $activa, 'opcional_activo_en_tarifa() body must be found');
        self::assertStringContainsString("['activa']", $activa, 'activation must read the master state');
        self::assertStringNotContainsString('precios_cache', $activa, 'price rows must not activate');

        $catalogo = $this->methodBody($src, 'opcional_en_catalogo_tarifa');
        self::assertNotSame('', $catalogo, 'opcional_en_catalogo_tarifa() body must be found');
        self::assertStringContainsString("['en_catalogo']", $catalogo, 'catalog flag must read the master state');
        self::assertStringNotContainsString('precios_cache', $catalogo, 'price rows must not drive the catalog flag');

        self::assertStringContainsString('effective(', $src, 'the list must build its state cache from effective()');
    }

    public function test_list_declares_csrf_guarded_toggle_endpoints(): void
    {
        $controller = $this->source(self::LIST_CONTROLLER);

        foreach (['toggle_activa', 'toggle_en_catalogo', 'toggle_en_tarifa'] as $action) {
            self::assertStringContainsString(
                "'" . $action . "'",
                $controller,
                'the list controller must declare the ' . $action . ' endpoint'
            );
        }

        // The mutation handlers live in the composed list trait and must go
        // through the PageController CSRF guard, never the legacy requireCsrf().
        $trait = $this->source(self::LIST_TRAIT);
        self::assertStringContainsString('validateFormToken(', $trait, 'toggle endpoints must enforce CSRF');
        self::assertStringNotContainsString('requireCsrf(', $trait, 'the PageController trait must not use the legacy CSRF helper');
        self::assertStringContainsString('set_activa(', $trait);
        self::assertStringContainsString('set_en_catalogo(', $trait);
        self::assertStringContainsString('set_en_tarifa(', $trait);
    }

    public function test_edit_matrix_reads_and_writes_master(): void
    {
        $src = $this->source(self::EDIT_CONTROLLER);

        $load = $this->methodBody($src, 'load_precios_tarifas');
        self::assertStringContainsString('effective(', $load, 'the matrix must load the master state');
        self::assertStringContainsString("'activa'", $load);

        $activa = $this->methodBody($src, 'opcional_activo_en_tarifa');
        self::assertStringContainsString("['activa']", $activa);
        self::assertStringNotContainsString("['precio']", $activa, 'activation must not depend on the price value');

        $catalogo = $this->methodBody($src, 'opcional_en_catalogo_tarifa');
        self::assertStringContainsString("['en_catalogo']", $catalogo);

        $bulk = $this->methodBody($src, 'guardar_precios_tarifas');
        self::assertStringContainsString('set_activa(', $bulk);
        self::assertStringContainsString('set_en_catalogo(', $bulk);

        $single = $this->methodBody($src, 'guardar_precio_tarifa');
        self::assertStringContainsString('requireCsrf()', $single, 'the single-tarifa save must enforce CSRF');
        self::assertStringContainsString('set_activa(', $single);
        self::assertStringContainsString('set_en_catalogo(', $single);
        self::assertStringContainsString('set_en_tarifa(', $single);
    }

    public function test_edit_bulk_matrix_save_is_csrf_guarded_and_atomic(): void
    {
        $src = $this->source(self::EDIT_CONTROLLER);
        $bulk = $this->methodBody($src, 'guardar_precios_tarifas');

        self::assertNotSame('', $bulk, 'guardar_precios_tarifas() body must be found');
        self::assertStringContainsString(
            'requireCsrf()',
            $bulk,
            'the legacy bulk matrix path must enforce CSRF like the per-tarifa save'
        );
        self::assertStringContainsString(
            'run_in_transaction(',
            $bulk,
            'the bulk matrix path must persist every tarifa in one transaction'
        );
        self::assertStringContainsString(
            'new_error_msg(',
            $bulk,
            'a failed bulk write must roll back and report an error, never report success'
        );

        foreach (['set_activa', 'set_en_catalogo', 'set_en_tarifa'] as $setter) {
            self::assertStringContainsString(
                '!$master->' . $setter . '(',
                $bulk,
                'the bulk path must check the ' . $setter . ' result'
            );
        }

        self::assertStringContainsString(
            '!$precio->save()',
            $bulk,
            'the bulk path must check the price save result'
        );
        self::assertStringContainsString(
            '!$opcional->delete_precio_tarifa(',
            $bulk,
            'the bulk path must check the price delete result'
        );
    }

    public function test_edit_bulk_matrix_parses_prices_with_the_validated_parser(): void
    {
        $src = $this->source(self::EDIT_CONTROLLER);
        $bulk = $this->methodBody($src, 'guardar_precios_tarifas');

        self::assertNotSame('', $bulk, 'guardar_precios_tarifas() body must be found');
        self::assertStringContainsString(
            'parse_price_input(',
            $bulk,
            'the legacy bulk matrix path must use the same validated parser as the per-tarifa save'
        );
        self::assertStringNotContainsString(
            'floatval(',
            $bulk,
            'the bulk path must not silently truncate a partially numeric price with floatval()'
        );
        self::assertStringContainsString(
            'new_error_msg(',
            $bulk,
            'an invalid bulk price must be reported instead of being written'
        );
    }

    public function test_edit_single_tarifa_save_is_atomic_and_validates_the_price(): void
    {
        $src = $this->source(self::EDIT_CONTROLLER);
        $single = $this->methodBody($src, 'guardar_precio_tarifa');

        self::assertStringContainsString(
            'run_in_transaction(',
            $single,
            'master flags + price must commit in one transaction'
        );
        self::assertStringContainsString(
            'parse_price_input(',
            $single,
            'the price must be fully validated before conversion'
        );
        self::assertStringContainsString(
            'new_error_msg(',
            $single,
            'a failed atomic write must not report success'
        );
        self::assertStringContainsString('load_precios_tarifas()', $single);
    }

    public function test_list_toggle_redirect_preserves_active_filters(): void
    {
        $src = $this->source(self::LIST_TRAIT);
        $redirect = $this->methodBody($src, 'redirect_to_list');

        foreach (['query', 'b_codfamilia', 'b_solo_activos', 'offset'] as $key) {
            self::assertStringContainsString(
                "'" . $key . "'",
                $redirect,
                'the toggle redirect must carry the active filter/pagination parameter ' . $key
            );
        }
    }

    public function test_list_toggle_forms_carry_the_active_filters(): void
    {
        // WU-1 (Phase 1) lands the unified list server side: filters resolve in
        // the trait and the toggle redirect carries them. The list view's own
        // filter inputs are locked by the WU-2 htmx contract test once the view
        // is migrated.
        $trait = $this->source(self::LIST_TRAIT);

        $filters = $this->methodBody($trait, 'ini_filters');
        self::assertNotSame('', $filters, 'ini_filters() body must be found');
        foreach (['query', 'b_codfamilia', 'b_codtarifa', 'b_solo_activos', 'offset'] as $key) {
            self::assertStringContainsString(
                $key,
                $filters,
                'the unified list must carry the active filter/pagination parameter ' . $key
            );
        }

        $redirect = $this->methodBody($trait, 'redirect_to_list');
        self::assertNotSame('', $redirect, 'redirect_to_list() body must be found');
        foreach (['query', 'b_codfamilia', 'b_solo_activos', 'offset'] as $key) {
            self::assertStringContainsString(
                "'" . $key . "'",
                $redirect,
                'the toggle redirect must carry the active filter/pagination parameter ' . $key
            );
        }
    }

    public function test_views_reuse_the_toggle_button_group_macro(): void
    {
        // The unified list view macro reuse is delivered by the WU-2 rewrite and
        // asserted by CatalogoOpcionalesHtmxContractTest; the surviving
        // edit view keeps the shared macro contract here.
        foreach ([self::EDIT_VIEW] as $relative) {
            $src = $this->source($relative);

            self::assertStringContainsString(
                "{% import 'Macro/TarifarioComponents.html.twig' as tarif %}",
                $src,
                $relative . ' must import the shared tarifario macros'
            );
            self::assertStringContainsString(
                'tarif.toggle_button_group(',
                $src,
                $relative . ' must reuse toggle_button_group'
            );
        }
    }

    public function test_mutating_view_forms_carry_a_csrf_field(): void
    {
        $list = $this->source(self::LIST_VIEW);
        self::assertStringContainsString('{{ csrf_field() }}', $list);

        $controller = $this->source(self::LIST_CONTROLLER);
        self::assertStringContainsString('toggle_activa', $controller);
        self::assertStringContainsString(
            'validateFormToken(',
            $this->source(self::LIST_TRAIT),
            'the list mutation guard must validate the CSRF token'
        );

        $edit = $this->source(self::EDIT_VIEW);
        self::assertStringContainsString('guardar_precio_tarifa', $edit);
        self::assertStringContainsString('{{ csrf_field() }}', $edit);
    }
}
