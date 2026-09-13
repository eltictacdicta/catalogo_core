<?php
declare(strict_types=1);
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

namespace Tests\CatalogoCore\Integration;

use PHPUnit\Framework\TestCase;

/**
 * htmx 4 / Alpine CSP contract for the canonical articles list
 * `View/ventas_articulos.html.twig` and its quick-create modal
 * (spec ALC-04/ALC-08; design AD-W3-9).
 *
 * DB-free source-contract style: read the real templates and assert the
 * structural contracts. The locked names (header/footer includes,
 * `csrf_field()`, the modal partials, the Excel/JSON carrier and its JS entry
 * point) are preserved for the locked `VentasArticulosControllerTest`.
 */
final class CatalogoArticuloListHtmxContractTest extends TestCase
{
    private const LIST_VIEW = 'plugins/catalogo_core/View/ventas_articulos.html.twig';
    private const MODAL_VIEW = 'plugins/catalogo_core/View/partials/articulos/modal_nuevo_articulo.html.twig';

    private function source(string $relative): string
    {
        $path = FS_FOLDER . '/' . $relative;
        if (!is_file($path)) {
            self::fail('missing template: ' . $relative);
        }

        return (string) file_get_contents($path);
    }

    private function listView(): string
    {
        return $this->source(self::LIST_VIEW);
    }

    private function modalView(): string
    {
        return $this->source(self::MODAL_VIEW);
    }

    /**
     * Extracts a tag's opening markup by its name attribute so the migrated
     * control's htmx attributes cannot be satisfied by unrelated markup.
     */
    private function openTag(string $html, string $tag, string $name): string
    {
        $pattern = '/<' . $tag . '\b[^>]*\bname="' . preg_quote($name, '/') . '"[^>]*>/';
        if (!preg_match($pattern, $html, $matches)) {
            return '';
        }

        return $matches[0];
    }

    public function test_filters_use_hx_get_with_push_url(): void
    {
        $view = $this->listView();

        foreach ([
            ['input', 'query'],
            ['select', 'b_codfamilia'],
            ['select', 'b_codtarifa'],
            ['input', 'b_solo_activos'],
        ] as [$tag, $name]) {
            $markup = $this->openTag($view, $tag, $name);
            $this->assertNotSame('', $markup, 'the migrated list must expose name="' . $name . '"');
            $this->assertStringContainsString('hx-get=', $markup, $name . ' filter must issue an htmx GET');
            $this->assertStringContainsString('hx-target="#articulos-list"', $markup, $name . ' filter swaps the list');
            $this->assertStringContainsString('hx-select="#articulos-list"', $markup, $name . ' selects the list fragment');
            $this->assertStringContainsString('hx-swap="outerHTML"', $markup, $name . ' replaces the list in place');
            $this->assertStringContainsString('hx-push-url="true"', $markup, $name . ' pushes the URL');
        }

        $this->assertStringNotContainsString(
            'onchange="this.form.submit()"',
            $view,
            'migrated filters must drop the full-page submit'
        );
    }

    public function test_mutations_are_hx_post_only(): void
    {
        $view = $this->listView();
        $modal = $this->modalView();

        $this->assertStringContainsString('hx-post', $view, 'list mutations must post through htmx');
        $this->assertStringContainsString('hx-post', $modal, 'the quick-create modal must post through htmx');
        $this->assertStringContainsString('name="delete"', $view, 'the delete mutation rides a POST delete field');

        foreach (['hx-delete', 'hx-put', 'hx-patch'] as $banned) {
            $this->assertStringNotContainsString($banned, $view, $banned . ' would bypass the CSRF-guarded POST');
            $this->assertStringNotContainsString($banned, $modal, $banned . ' would bypass the CSRF-guarded POST');
        }
    }

    public function test_alpine_and_htmx_hygiene(): void
    {
        $view = $this->listView();

        $this->assertStringContainsString("{% import 'Macro/Htmx.html.twig' as htmx %}", $view);
        $this->assertStringContainsString("{% import 'Macro/Alpine.html.twig' as alpine %}", $view);
        $this->assertStringContainsString("htmx.boot({'allowScriptTags': false})", $view, 'the list must boot htmx 4 once');
        $this->assertStringContainsString('{{ alpine.boot() }}', $view, 'the list must boot Alpine CSP once');
        $this->assertStringContainsString('Alpine.data(', $view, 'view logic must live in Alpine.data()');
        $this->assertStringContainsString("document.addEventListener('alpine:init'", $view, 'registration must be guarded on alpine:init');
        $this->assertStringContainsString('{{ csp_nonce_attr() }}', $view, 'the inline script must carry the CSP nonce');
        $this->assertStringContainsString('[x-cloak]', $view);
        $this->assertStringContainsString('x-text=', $view, 'dynamic text renders escaped through x-text');
        $this->assertStringContainsString("'htmx:after:swap'", $view, 'htmx 4 colon event name');
        $this->assertStringContainsString("'htmx:after:request'", $view, 'htmx 4 colon event name');

        foreach (['bootbox', '|raw', 'htmx:afterSwap', 'htmx:afterRequest', 'FSAjaxLoader', 'jQuery', '$(document).ready'] as $banned) {
            $this->assertStringNotContainsString($banned, $view, $banned . ' is forbidden in the migrated list (ALC-04)');
        }

        $this->assertDoesNotMatchRegularExpression(
            '/\bon(click|change|input|submit)\s*=/',
            $view,
            'inline on* handlers must be replaced by Alpine x-on: colon bindings'
        );
    }

    public function test_quick_create_modal_renders_per_tarifa_price_inputs(): void
    {
        $modal = $this->modalView();

        $this->assertStringContainsString('{% for tarifa in fsc.tarifas %}', $modal, 'the modal must iterate the active tarifas');
        $this->assertStringContainsString('name="precio_tarifa_{{ tarifa.codtarifa }}"', $modal, 'a fixed price input per tarifa');
        $this->assertStringContainsString('name="porcentaje_tarifa_{{ tarifa.codtarifa }}"', $modal, 'a percentage input per tarifa');

        foreach (['nreferencia', 'ndescripcion', 'npvp'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $modal, 'the modal must keep name="' . $name . '"');
        }
        $this->assertStringContainsString('{{ csrf_field() }}', $modal, 'the modal form must stay CSRF-guarded');
        $this->assertStringContainsString('method="post"', $modal);
    }

    public function test_locked_view_strings_are_preserved(): void
    {
        $view = $this->listView();

        foreach ([
            "{{ include('header.html.twig') }}",
            "{{ include('footer.html.twig') }}",
            '{{ csrf_field() }}',
            'partials/articulos/modal_nuevo_articulo.html.twig',
            'partials/articulos/modal_exportar_excel.html.twig',
            'partials/articulos/modal_importar_excel_wizard.html.twig',
            'id="catalogo-articulos-excel-config"',
            'plugins/catalogo_core/View/js/articulos-excel-import-wizard.js',
            'data-base-url=',
        ] as $locked) {
            $this->assertStringContainsString($locked, $view, 'the migrated list must keep ' . $locked);
        }
    }
}
