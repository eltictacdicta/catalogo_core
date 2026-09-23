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

namespace Tests\CatalogoCore\Controller;

use FSFramework\Plugins\catalogo_core\Services\ArticuloExcelImportWizardService;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/catalogo_core/Services/ArticuloExcelImportWizardService.php';

/**
 * Slice 4b mapping (D-07): the wizard's per-column dropdown must offer the
 * field options the server already returns in the `get_preview` payload, so the
 * locale columns (`descripcion_<cod>` / `descripcion_corta_<cod>`) become
 * selectable. Rendering only from the JS `FIELD_OPTIONS` constant leaves them
 * unmappable and makes per-language import unreachable through the UI.
 *
 * Coverage is DB-free:
 *  - the wizard JS consumes the server `field_options` and falls back to the
 *    base constant when the response omits them (no regression);
 *  - the server payload the UI consumes carries the locale columns and keeps
 *    every base option intact.
 */
final class VentasArticulosExcelMappingOptionsTest extends TestCase
{
    private const WIZARD_JS = '/plugins/catalogo_core/View/js/articulos-excel-import-wizard.js';
    private const FIXTURE = '/plugins/catalogo_core/tests/fixtures/excel-wizard/basic-3col.xlsx';

    /** @var list<array{codidioma: string, nombre: string}> */
    private const ACTIVE = [
        ['codidioma' => 'es', 'nombre' => 'Español'],
        ['codidioma' => 'en', 'nombre' => 'English'],
    ];

    // =====================================================================
    // Wizard JS consumption
    // =====================================================================

    public function test_wizard_js_consumes_the_server_field_options(): void
    {
        $body = $this->methodSource($this->source(self::WIZARD_JS), 'ArticulosExcelWizard.prototype.loadPreview');

        $this->assertStringContainsString(
            'json.field_options',
            $body,
            'the preview handler must read the server-provided field options'
        );
        $this->assertStringContainsString(
            'self.fieldOptions',
            $body,
            'the server field options must be kept on the wizard state for the mapping render'
        );
    }

    public function test_mapping_dropdown_renders_the_server_options(): void
    {
        $body = $this->methodSource($this->source(self::WIZARD_JS), 'ArticulosExcelWizard.prototype.renderMapping');

        $this->assertStringContainsString(
            'optionList()',
            $body,
            'the dropdown must build its options from the resolved option list, not the constant alone'
        );
        $this->assertStringNotContainsString(
            'FIELD_OPTIONS.forEach',
            $body,
            'the constant must no longer be the dropdown source; the server list takes precedence'
        );
    }

    public function test_mapping_dropdown_falls_back_to_the_base_options(): void
    {
        $body = $this->methodSource($this->source(self::WIZARD_JS), 'ArticulosExcelWizard.prototype.optionList');

        $this->assertStringContainsString(
            'this.fieldOptions',
            $body,
            'the server options must lead the resolved list when present'
        );
        $this->assertStringContainsString(
            'FIELD_OPTIONS',
            $body,
            'the base constant must remain the fallback, so the base fields still render today'
        );
    }

    public function test_preview_labels_resolve_from_the_active_options(): void
    {
        $body = $this->methodSource($this->source(self::WIZARD_JS), 'ArticulosExcelWizard.prototype.renderPreviewTable');

        $this->assertStringContainsString(
            'fieldLabels()',
            $body,
            'the preview header must label a locale column through the active option list'
        );
    }

    // =====================================================================
    // Server payload the mapping UI consumes
    // =====================================================================

    public function test_server_field_options_offer_the_locale_columns(): void
    {
        $options = $this->previewFieldOptions();
        $values = array_column($options, 'value');
        $labels = array_column($options, 'label', 'value');

        $this->assertSame(
            ArticuloExcelImportWizardService::IGNORE_SENTINEL,
            $options[0]['value'],
            'the ignore option must lead the list, as today'
        );
        $this->assertContains('descripcion_es', $values, 'the locale column must be selectable');
        $this->assertContains('descripcion_corta_es', $values, 'the short locale column must be selectable');
        $this->assertContains('descripcion_en', $values, 'every active language must be offered');
        $this->assertContains('descripcion_corta_en', $values, 'every active language must be offered');
        $this->assertSame('Descripción (Español)', $labels['descripcion_es']);
        $this->assertSame('Descripción corta (English)', $labels['descripcion_corta_en']);
    }

    public function test_server_field_options_keep_the_base_catalog_intact(): void
    {
        $options = $this->previewFieldOptions();
        $labels = array_column($options, 'label', 'value');
        $values = array_column($options, 'value');

        foreach (ArticuloExcelImportWizardService::FIELD_CATALOG as $field => $info) {
            $this->assertContains($field, $values, 'base field ' . $field . ' must stay selectable');
            $this->assertSame(
                $info['label'],
                $labels[$field],
                'base field ' . $field . ' must keep its label'
            );
        }

        $this->assertNotContains(
            'descripcion_fr',
            $values,
            'a language outside the active set must not be offered'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * @return list<array{value: string, label: string}>
     */
    private function previewFieldOptions(): array
    {
        $path = FS_FOLDER . self::FIXTURE;
        if (!is_file($path)) {
            self::markTestSkipped('Fixture missing — run tools/generate_excel_fixtures.php');
        }

        $preview = (new ArticuloExcelImportWizardService([]))->preview($path, 'Artículos', 5, self::ACTIVE);

        return $preview['field_options'];
    }

    private function source(string $relative): string
    {
        $path = FS_FOLDER . $relative;
        if (!is_file($path)) {
            self::fail('missing path: ' . $relative);
        }

        return (string) file_get_contents($path);
    }

    /**
     * Extracts a prototype method body by brace balancing, so an assertion reads
     * only the method under test and never a sibling's.
     */
    private function methodSource(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            self::fail('missing method: ' . $signature);
        }

        $open = (int) strpos($src, '{', $start);
        $depth = 0;
        $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start + 1);
                }
            }
        }

        self::fail('method body is not terminated: ' . $signature);
    }
}
