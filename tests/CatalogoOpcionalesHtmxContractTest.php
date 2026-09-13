<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
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
 * htmx 4 / Alpine CSP contract for the unified opcionales management view
 * `View/ventas_opcionales.html.twig` (spec opcionales-management OUM-08,
 * OUM-12; design AD-10).
 *
 * DB-free source-contract style: read the real template source and assert the
 * structural contracts, mirroring {@see TarifOpcionalesHtmxContractTest}.
 * The locked names (`query`, `b_codfamilia`, `b_codtarifa`, `b_solo_activos`,
 * `offset`, `action=toggle_*`), the CSRF field and the toggle macro are
 * preserved for the repointed master-state / htmx contracts.
 */
final class CatalogoOpcionalesHtmxContractTest extends TestCase
{
    private const LIST_VIEW = 'plugins/catalogo_core/View/ventas_opcionales.html.twig';

    private function listView(): string
    {
        return (string) file_get_contents(FS_FOLDER . '/' . self::LIST_VIEW);
    }

    /**
     * Extracts a tag's opening markup by its locked name attribute, so the
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

    // =====================================================================
    // OUM-08 — boot macros
    // =====================================================================

    public function test_list_view_boots_htmx_and_alpine_macros(): void
    {
        $view = $this->listView();

        self::assertStringContainsString("{% import 'Macro/Htmx.html.twig' as htmx %}", $view);
        self::assertStringContainsString("{% import 'Macro/Alpine.html.twig' as alpine %}", $view);
        self::assertStringContainsString(
            "{{ htmx.boot({'allowScriptTags': false}) }}",
            $view,
            'AD-10: the body-swap scrubber posture must be explicit'
        );
        self::assertStringContainsString('{{ alpine.boot() }}', $view, 'AD-10: Alpine CSP must boot from the macro');
        self::assertStringContainsString(
            "{% import 'Macro/TarifarioComponents.html.twig' as tarif %}",
            $view,
            'the locked tarifario macro import must stay'
        );
    }

    // =====================================================================
    // OUM-08 — filter body swaps + URL push with the locked names
    // =====================================================================

    public function test_list_filters_use_hx_get_body_swap_and_url_push(): void
    {
        $view = $this->listView();

        $query = $this->openTag($view, 'input', 'query');
        self::assertNotSame('', $query, 'the unified list must expose name="query" as the primary filter');
        $this->assertHxBodySwap($query, 'query filter');
        self::assertStringContainsString(
            "hx-trigger=\"keydown[key === 'Enter']\"",
            $query,
            'the text filter triggers on Enter, mirroring the tarifario toolbar'
        );
        self::assertStringContainsString(
            'hx-include="#input_query"',
            $view,
            'the search button must include the text input as a click fallback'
        );

        $this->assertHxBodySwap($this->openTag($view, 'select', 'b_codfamilia'), 'familia filter');
        $this->assertHxBodySwap($this->openTag($view, 'select', 'b_codtarifa'), 'tarifa filter');
        $this->assertHxBodySwap($this->openTag($view, 'select', 'b_id_grupo'), 'grupo filter');
        $this->assertHxBodySwap($this->openTag($view, 'input', 'b_solo_activos'), 'solo-activos filter');

        foreach (['b_codfamilia', 'b_codtarifa', 'b_id_grupo', 'b_solo_activos'] as $name) {
            self::assertStringContainsString(
                'hx-trigger="change"',
                $this->openTag($view, $name === 'b_solo_activos' ? 'input' : 'select', $name),
                'the ' . $name . ' filter fires on change'
            );
        }

        self::assertStringContainsString(
            '>Sin grupo</option>',
            $view,
            'the group filter must expose the "Sin grupo" sentinel'
        );

        self::assertStringNotContainsString(
            'onchange="this.form.submit()"',
            $view,
            'every migrated list filter must drop the full-page submit'
        );
    }

    // =====================================================================
    // OUM-08 — mutations are POST, never hx-delete
    // =====================================================================

    public function test_list_toggles_and_mutations_use_post_and_never_hx_delete(): void
    {
        $view = $this->listView();

        self::assertStringContainsString('hx-post=', $view, 'AD-10: toggles/create must post through htmx');
        self::assertStringContainsString(
            "htmx.ajax('POST'",
            $view,
            'AD-10: the Alpine delete confirmation issues POST via htmx.ajax'
        );
        self::assertStringNotContainsString('hx-delete', $view, 'AD-10: hx-delete would bypass the CSRF guard');
    }

    // =====================================================================
    // OUM-08 — nonce'd Alpine.data() behind the alpine:init guard
    // =====================================================================

    public function test_list_view_registers_alpine_data_with_nonce_and_init_guard(): void
    {
        $view = $this->listView();

        self::assertStringContainsString('Alpine.data(', $view, 'AD-10: CSP logic must live in Alpine.data()');
        self::assertStringContainsString("Alpine.data('opcionalConfirm'", $view, 'the delete confirmation component must exist');
        self::assertStringContainsString("Alpine.data('opcionalNew'", $view, 'the create component must exist');
        self::assertStringContainsString("Alpine.data('opcionalExport'", $view, 'the export component must exist');
        self::assertStringContainsString(
            "document.addEventListener('alpine:init'",
            $view,
            'the registration must be guarded on alpine:init'
        );
        self::assertStringContainsString('{{ csp_nonce_attr() }}', $view, 'the inline script must carry the CSP nonce');
        self::assertStringContainsString(
            'window.__opcionalesListAlpineRegistered',
            $view,
            'a window single-install marker must keep the registration idempotent across body swaps'
        );
    }

    // =====================================================================
    // OUM-08 — colon event names only
    // =====================================================================

    public function test_list_view_uses_colon_events_and_no_v2_names(): void
    {
        $view = $this->listView();

        self::assertStringContainsString("'htmx:after:swap'", $view, 'htmx 4 colon event name');
        self::assertStringContainsString("'htmx:after:request'", $view, 'htmx 4 colon event name');
        self::assertStringNotContainsString('htmx:afterSwap', $view, 'v2 names are banned');
        self::assertStringNotContainsString('htmx:afterRequest', $view, 'v2 names are banned');
    }

    // =====================================================================
    // OUM-08 — escaping hygiene
    // =====================================================================

    public function test_list_view_has_no_bootbox_and_no_raw(): void
    {
        $view = $this->listView();

        self::assertStringNotContainsString('bootbox', $view, 'AD-10: bootbox must be gone');
        self::assertStringNotContainsString('|raw', $view, 'XSS: the view must not use the raw filter');
        self::assertStringContainsString('x-text=', $view, 'AD-10: dynamic text renders escaped through x-text');
        self::assertStringNotContainsString('.innerHTML', $view, 'AD-10: never write through innerHTML');
    }

    // =====================================================================
    // OUM-12 — locked names, CSRF and toggle macro stay
    // =====================================================================

    public function test_list_view_preserves_locked_names_csrf_and_toggle_macro(): void
    {
        $view = $this->listView();

        foreach (['query', 'b_codfamilia', 'b_codtarifa', 'b_id_grupo', 'b_solo_activos', 'offset'] as $name) {
            self::assertStringContainsString(
                'name="' . $name . '"',
                $view,
                'the unified list must keep name="' . $name . '"'
            );
        }

        self::assertStringContainsString('action=toggle_', $view, 'the row toggles stay POST actions');
        self::assertStringContainsString('{{ csrf_field() }}', $view, 'the list stays CSRF-guarded');
        self::assertStringContainsString(
            'tarif.toggle_button_group(',
            $view,
            'the row toggle buttons must reuse the shared macro'
        );
    }

    // =====================================================================
    // OPG-02 / OUM-05 — group column + percentage create modal
    // =====================================================================

    public function test_list_view_renders_group_column_and_percentage_modal(): void
    {
        $view = $this->listView();

        self::assertStringContainsString('>Grupo</th>', $view, 'the unified list must render a Grupo column header');
        self::assertStringContainsString(
            'fsc.nombre_grupo_opcional(',
            $view,
            'the Grupo cell must resolve through the controller-side group map (no etiqueta_grupo() N+1)'
        );
        self::assertStringNotContainsString(
            'etiqueta_grupo(',
            $view,
            'the list must never call the per-row etiqueta_grupo() lookup'
        );

        // OUM-05 — create modal mode toggle, percentage input and group select.
        self::assertStringContainsString('name="tipo_precio"', $view, 'the create modal must offer the price mode');
        self::assertStringContainsString('name="porcentaje"', $view, 'the create modal must accept a percentage');
        self::assertStringContainsString('name="sid_grupo"', $view, 'the create modal must offer group assignment');
        self::assertStringContainsString(
            "x-show=\"tipo_precio === 'porcentaje'\"",
            $view,
            'the percentage block must toggle through Alpine CSP state'
        );
    }
}
