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
 * htmx 4 / Alpine CSP contract for the opcionales edit surface (OTS-04,
 * OTS-06, OTS-07, OTS-09). DB-free source-contract style: read the real
 * template/controller sources and assert the structural contracts, mirroring
 * TarifCatalogoHtmxContractTest.
 */
final class TarifOpcionalesHtmxContractTest extends TestCase
{
    private const EDIT_CONTROLLER = 'plugins/catalogo_core/controller/tarif_opcional_edit.php';
    private const EDIT_VIEW = 'plugins/catalogo_core/View/tarif_opcional_edit.html.twig';
    private const LIST_VIEW = 'plugins/catalogo_core/View/ventas_opcionales.html.twig';

    private function view(): string
    {
        return (string) file_get_contents(FS_FOLDER . '/' . self::EDIT_VIEW);
    }

    private function controller(): string
    {
        return (string) file_get_contents(FS_FOLDER . '/' . self::EDIT_CONTROLLER);
    }

    private function listView(): string
    {
        return (string) file_get_contents(FS_FOLDER . '/' . self::LIST_VIEW);
    }

    /**
     * Extracts a tag's opening markup by its locked name attribute, so a
     * migrated control's htmx attributes cannot be satisfied by unrelated
     * markup elsewhere in the template.
     */
    private function openTag(string $html, string $tag, string $name): string
    {
        $pattern = '/<' . $tag . '\b[^>]*\bname="' . preg_quote($name, '/') . '"[^>]*>/';
        if (!preg_match($pattern, $html, $matches)) {
            return '';
        }

        return $matches[0];
    }

    private function assertHxBodySwap(string $markup, string $label): void
    {
        self::assertStringContainsString('hx-get=', $markup, $label . ': the control must issue an htmx GET');
        self::assertStringContainsString('hx-trigger=', $markup, $label . ': the control must declare its trigger');
        self::assertStringContainsString('hx-target="body"', $markup, $label . ': the swap must land on the body');
        self::assertStringContainsString('hx-select="body"', $markup, $label . ': the full response is selected from the body');
        self::assertStringContainsString('hx-swap="outerHTML"', $markup, $label . ': the body is replaced in place');
        self::assertStringContainsString('hx-push-url="true"', $markup, $label . ': URL and content update together');
        self::assertStringContainsString('hx-boost="true"', $markup, $label . ': boost keeps the swap consistent');
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

    // =====================================================================
    // OTS-01 / OTS-09 — controller keeps its locked shape and the resolver
    // =====================================================================

    public function test_edit_controller_rejects_unknown_tarifa_and_exposes_selection(): void
    {
        $src = $this->controller();

        self::assertStringContainsString(
            'resolver_tarifa_seleccionada(',
            $src,
            'OTS-01/OTS-03: the controller must resolve the requested tarifa against the active list'
        );
        self::assertStringContainsString('tarifa_seleccionada', $src, 'the resolved tarifa must be exposed to the view');
        self::assertStringContainsString('$this->tarifa_defecto', $src, 'the default tarifa is part of the fallback chain');
        self::assertStringContainsString('$this->tarifas', $src, 'resolution validates membership against active tarifas');

        $guard = $this->methodBody($src, 'guard_mutating_action');
        self::assertNotSame('', $guard, 'guard_mutating_action() must exist for the migrated mutations');
        self::assertStringContainsString('requireCsrf()', $guard, 'the mutation guard must enforce CSRF');
    }

    public function test_edit_controller_keeps_fbase_controller_and_bulk_save(): void
    {
        $src = $this->controller();

        self::assertMatchesRegularExpression(
            '/class\s+tarif_opcional_edit\s+extends\s+fbase_controller\b/',
            $src,
            'OTS-09: the controller must stay on fbase_controller'
        );
        self::assertStringContainsString('use TarifarioOpcionalStateTrait;', $src);
        self::assertStringContainsString('guardar_precios_tarifas(', $src, 'OTS-09: the bulk save path must stay');
        self::assertStringContainsString('requireCsrf()', $src, 'OTS-05: the bulk save must enforce CSRF');
        self::assertStringNotContainsString('HtmxCrudController', $src, 'OTS-09: no HtmxCrudController migration');

        // AD-6 / threat matrix — the four GET mutations move to guarded POST.
        foreach (['add_familia', 'remove_familia', 'add_articulo', 'remove_articulo'] as $action) {
            self::assertStringNotContainsString(
                "\$_GET['" . $action . "']",
                $src,
                'the ' . $action . ' mutation must not be dispatched from $_GET anymore'
            );
            self::assertStringContainsString(
                "\$_POST['" . $action . "']",
                $src,
                'the ' . $action . ' mutation must read its value from $_POST'
            );
        }
    }

    // =====================================================================
    // OTS-06 / OTS-07 — boot macros, Alpine registration, colon events
    // =====================================================================

    public function test_edit_view_boots_htmx_and_alpine_macros(): void
    {
        $view = $this->view();

        self::assertStringContainsString("{% import 'Macro/Htmx.html.twig' as htmx %}", $view);
        self::assertStringContainsString("{% import 'Macro/Alpine.html.twig' as alpine %}", $view);
        self::assertStringContainsString("{{ htmx.boot({'allowScriptTags': false}) }}", $view, 'AD-5: scrubbed body-swap posture');
        self::assertStringContainsString('{{ alpine.boot() }}', $view);
    }

    public function test_edit_view_registers_alpine_data_with_nonce_and_init_guard(): void
    {
        $view = $this->view();

        self::assertStringContainsString('Alpine.data(', $view, 'OTS-06: CSP logic must live in Alpine.data()');
        self::assertStringContainsString("document.addEventListener('alpine:init'", $view, 'OTS-06: the registration must be guarded');
        self::assertStringContainsString('{{ csp_nonce_attr() }}', $view, 'R10: the inline script must carry the CSP nonce');
        self::assertStringContainsString('__opcionalesAlpineRegistered', $view, 'R11: single-install marker');
    }

    public function test_edit_view_uses_colon_events_and_no_v2_names(): void
    {
        $view = $this->view();

        self::assertStringContainsString("'htmx:after:swap'", $view, 'OTS-07: htmx 4 colon event name');
        self::assertStringContainsString("'htmx:after:request'", $view, 'OTS-07: htmx 4 colon event name');
        self::assertStringNotContainsString('htmx:afterSwap', $view, 'OTS-07: v2 names are banned');
        self::assertStringNotContainsString('htmx:afterRequest', $view, 'OTS-07: v2 names are banned');
    }

    public function test_edit_view_has_no_raw_filter_or_bootbox_on_the_absorbed_panel(): void
    {
        // OPG-03: the panel absorbed from the deleted tarif_opcional_precios
        // view must keep the htmx 4 / Alpine CSP posture: autoescaped output
        // only, no bootbox, no inline onclick.
        $view = $this->view();

        self::assertStringNotContainsString('|raw', $view, 'OPG-03: user data must never render through |raw');
        self::assertStringNotContainsString('bootbox', $view, 'OPG-03: no bootbox on the unified detail');
        self::assertStringNotContainsString('onclick="', $view, 'OPG-03: no inline onclick on the unified detail');
        self::assertStringContainsString('x-text=', $view, 'OPG-03: dynamic text renders escaped through x-text');
    }

    public function test_edit_view_migrates_confirm_and_search_off_bootbox(): void
    {
        $view = $this->view();

        self::assertStringNotContainsString('bootbox', $view, 'OTS-06: migrated flows must drop bootbox');
        self::assertStringNotContainsString('onclick="', $view, 'OTS-06: migrated flows must drop inline onclick');
        self::assertStringNotContainsString('autocomplete({', $view, 'OTS-06: jQuery UI autocomplete must be gone');
        self::assertStringContainsString('opcionalConfirmAction', $view);
        self::assertStringContainsString('opcionalArticuloSearch', $view);
        self::assertStringContainsString('x-on:click=', $view, 'AD-4: CSP-safe scalar handlers replace onclick');
        self::assertStringContainsString('x-model=', $view, 'AD-4: the search input binds through x-model');
        self::assertStringContainsString('x-for=', $view, 'AD-4: suggestions iterate through x-for');
        self::assertStringContainsString('x-text=', $view, 'AD-4: suggestion text renders escaped through x-text');
        self::assertStringContainsString('x-cloak', $view, 'R5: CSP-safe components hide before Alpine initializes');
        self::assertStringContainsString(
            'x-on:input.debounce.300ms=',
            $view,
            'AD-4: search debounces through a scalar directive'
        );
        self::assertStringContainsString(':disabled=', $view, 'AD-4: the save button binds disabled through Alpine');
    }

    public function test_edit_view_mutations_use_hx_post_and_never_hx_delete(): void
    {
        $view = $this->view();

        self::assertStringContainsString("htmx.ajax('POST'", $view, 'AD-6: Alpine confirms issue POST via htmx.ajax');
        self::assertStringContainsString('hx-post=', $view, 'AD-6: the scoped save form posts through htmx');
        self::assertStringNotContainsString('hx-delete', $view, 'AD-6: hx-delete would bypass requireCsrf()');
    }

    public function test_edit_view_absorbs_the_scoped_panel_and_percentage_toggle(): void
    {
        // OPG-01/OPG-03: the per-tarifa selector + scoped price/state panel
        // absorbed from the deleted tarif_opcional_precios page keep exactly one
        // guarded hx-post form and the mode-aware value input.
        $view = $this->view();

        self::assertSame(
            1,
            substr_count($view, 'name="guardar_precio_tarifa"'),
            'OPG-03: exactly one guardar_precio_tarifa form must remain after the absorption'
        );
        self::assertStringContainsString('name="porcentaje"', $view, 'OPG-01: the percentage input must survive the absorption');
        self::assertStringContainsString('name="precio"', $view, 'OPG-01: the fixed-price input must survive the absorption');
        self::assertStringContainsString(
            'fsc.get_porcentaje_tarifa(fsc.tarifa_seleccionada.codtarifa)',
            $view,
            'OPG-01: the percentage control must be scoped to the selected tarifa'
        );

        // The unified selector drives the detail through a colon-triggered
        // body swap; the scoped panel posts through htmx.
        $selector = $this->openTag($view, 'select', 'codtarifa');
        self::assertNotSame('', $selector, 'OPG-03: the detail must keep a name="codtarifa" selector');
        $this->assertHxBodySwap($selector, 'OPG-03 unified detail selector');
        self::assertStringContainsString('hx-trigger="change"', $selector, 'OPG-03: the selector fires on change');
    }

    public function test_edit_view_autocomplete_reinit_is_idempotent_on_after_swap(): void
    {
        $view = $this->view();

        self::assertStringContainsString('__opcionalesSwapBound', $view, 'R8/R11: single-install swap re-init marker');
        self::assertStringContainsString('Alpine.initTree(', $view, 'R8: re-bind Alpine after a body swap');
    }

    // =====================================================================
    // OTS-08 — list filters migrate to hx-get + URL push
    // =====================================================================

    public function test_list_filters_use_hx_get_with_url_push(): void
    {
        // Repointed at the unified list view (WU-2): the absorbed rich filters
        // move from View/tarif_opcionales.html.twig to
        // View/ventas_opcionales.html.twig unchanged.
        $view = $this->listView();

        $query = $this->openTag($view, 'input', 'query');
        self::assertNotSame('', $query, 'OTS-08: the unified query filter must keep name="query"');
        $this->assertHxBodySwap($query, 'OTS-08 unified query filter');
        self::assertStringContainsString(
            "hx-trigger=\"keydown[key === 'Enter']\"",
            $query,
            'OTS-08: the text filter triggers on Enter, mirroring the tarifario toolbar'
        );

        // Submit-button fallback for the text filter (pilot pattern).
        self::assertStringContainsString(
            'hx-include="#input_query"',
            $view,
            'OTS-08: the search button must include the text input as a click fallback'
        );

        $this->assertHxBodySwap($this->openTag($view, 'select', 'b_codfamilia'), 'OTS-08 familia filter');
        $this->assertHxBodySwap($this->openTag($view, 'select', 'b_codtarifa'), 'OTS-08 tarifa filter');
        $this->assertHxBodySwap($this->openTag($view, 'input', 'b_solo_activos'), 'OTS-08 solo-activos filter');

        foreach (['b_codfamilia', 'b_codtarifa', 'b_solo_activos'] as $name) {
            self::assertStringContainsString(
                'hx-trigger="change"',
                $this->openTag($view, $name === 'b_solo_activos' ? 'input' : 'select', $name),
                'OTS-08: ' . $name . ' fires on change'
            );
        }

        self::assertStringNotContainsString(
            'onchange="this.form.submit()"',
            $view,
            'OTS-08: every migrated list filter must drop the full-page submit'
        );
    }

    public function test_list_view_preserves_locked_names_csrf_and_toggles(): void
    {
        $list = $this->listView();

        // ventas_opcionales (repointed WU-2) — locked filter names, CSRF and
        // POST toggles stay intact on the unified list.
        foreach (['query', 'b_codfamilia', 'b_codtarifa', 'b_solo_activos', 'offset'] as $name) {
            self::assertStringContainsString(
                'name="' . $name . '"',
                $list,
                'OTS-08: the list must keep name="' . $name . '"'
            );
        }
        self::assertStringContainsString('action=toggle_', $list, 'OTS-08/R12: row toggles stay POST actions');
        self::assertStringContainsString('{{ csrf_field() }}', $list, 'OTS-08: the list stays CSRF-guarded');
        self::assertStringContainsString('tarif.toggle_button_group(', $list, 'OTS-08: the row toggle buttons stay');
        self::assertStringContainsString(
            "{% import 'Macro/TarifarioComponents.html.twig' as tarif %}",
            $list,
            'OTS-08: the locked macro import stays byte-identical'
        );
        self::assertStringNotContainsString(
            'hx-delete',
            $list,
            'AD-7/R12: list toggles must never migrate to hx-delete'
        );
    }
}
