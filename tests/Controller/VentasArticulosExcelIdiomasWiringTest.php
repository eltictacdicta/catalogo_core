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

use PHPUnit\Framework\TestCase;

/**
 * Slice 4b wiring (D-07 / R4 / R5): the additive locale columns and the
 * explicit target-language parameter are inert in production until the live
 * surfaces feed them.
 *
 * DB-free coverage of the wiring:
 *  - the controller forwards the page's active languages to the export builder
 *    on every call site (filtered, full and template) and to the wizard preview;
 *  - the list trait exposes the resolved configured default for the ordering;
 *  - the import modal renders a `target_codidioma` select, default first and
 *    preselected;
 *  - the wizard JS sends `target_codidioma` with the apply request.
 */
final class VentasArticulosExcelIdiomasWiringTest extends TestCase
{
    private const CONTROLLER = '/plugins/catalogo_core/Controller/VentasArticulos.php';
    private const TRAIT = '/plugins/catalogo_core/extras/VentasArticulosListTrait.php';
    private const MODAL = '/plugins/catalogo_core/View/partials/articulos/modal_importar_excel_wizard.html.twig';
    private const WIZARD_JS = '/plugins/catalogo_core/View/js/articulos-excel-import-wizard.js';

    // =====================================================================
    // Controller pass-through
    // =====================================================================

    public function test_export_passes_the_active_languages_to_the_spreadsheet_builder(): void
    {
        $body = $this->methodSource(
            $this->source(self::CONTROLLER),
            'private function exportExcel(bool $filtered)'
        );

        $this->assertStringContainsString('buildSpreadsheet(', $body, 'the export must call the builder');
        $this->assertStringContainsString(
            'idiomas: $this->idiomas',
            $body,
            'the filtered/full export must forward the active languages'
        );
        $this->assertStringContainsString(
            'codidioma_defecto: $this->codidioma_defecto',
            $body,
            'the export must forward the configured default for the column order'
        );
    }

    public function test_template_export_passes_the_active_languages_too(): void
    {
        $body = $this->methodSource(
            $this->source(self::CONTROLLER),
            'private function exportExcelTemplate()'
        );

        $this->assertStringContainsString('buildSpreadsheet(', $body, 'the template export must call the builder');
        $this->assertStringContainsString(
            'idiomas: $this->idiomas',
            $body,
            'the template/empty export must forward the active languages as well'
        );
        $this->assertStringContainsString(
            'codidioma_defecto: $this->codidioma_defecto',
            $body,
            'the template export must forward the configured default too'
        );
    }

    public function test_preview_passes_the_active_languages_to_the_wizard_service(): void
    {
        $body = $this->methodSource(
            $this->source(self::CONTROLLER),
            'public function getPreview()'
        );

        $this->assertStringContainsString(
            'preview($filePath, $sheet, $n, $this->idiomas)',
            $body,
            'the wizard preview must forward the active languages for the locale aliases'
        );
    }

    public function test_list_trait_exposes_the_resolved_default_language(): void
    {
        $trait = $this->source(self::TRAIT);

        $this->assertStringContainsString(
            'public string $codidioma_defecto',
            $trait,
            'the list trait must expose the configured default language'
        );

        $body = $this->methodSource($trait, 'protected function load_list_idiomas()');
        $this->assertStringContainsString(
            'get_effective_default_code()',
            $body,
            'the default must be resolved through the registry accessor'
        );
        $this->assertStringContainsString(
            '$this->codidioma_defecto',
            $body,
            'the resolved default must be stored for the export wiring'
        );
    }

    // =====================================================================
    // Import modal
    // =====================================================================

    public function test_import_modal_renders_the_target_language_select_default_first(): void
    {
        // `en` is the configured default while `es` sorts first alphabetically:
        // the select must lead with the default and preselect it.
        $html = $this->renderModal([
            ['codidioma' => 'es', 'nombre' => 'Español', 'por_defecto' => false],
            ['codidioma' => 'en', 'nombre' => 'English', 'por_defecto' => true],
        ]);

        $select = $this->selectBlock($html, 'target_codidioma');

        preg_match_all('/<option value="([^"]+)"([^>]*)>/', $select, $options);
        $this->assertSame(
            ['en', 'es'],
            $options[1],
            'the target select must lead with the configured default language'
        );
        $this->assertStringContainsString(
            'selected',
            $options[2][0],
            'the configured default language must be preselected'
        );
        $this->assertStringNotContainsString(
            'selected',
            $options[2][1],
            'a non-default language must not be preselected'
        );
    }

    public function test_import_modal_target_select_lists_every_active_language(): void
    {
        $html = $this->renderModal([
            ['codidioma' => 'es', 'nombre' => 'Español', 'por_defecto' => true],
            ['codidioma' => 'en', 'nombre' => 'English', 'por_defecto' => false],
            ['codidioma' => 'fr', 'nombre' => 'Français', 'por_defecto' => false],
        ]);

        $select = $this->selectBlock($html, 'target_codidioma');

        $this->assertStringContainsString('value="es"', $select);
        $this->assertStringContainsString('value="en"', $select);
        $this->assertStringContainsString('value="fr"', $select);
        $this->assertStringContainsString('Español', $select);
        $this->assertStringContainsString('Français', $select);
    }

    public function test_wizard_js_sends_the_target_language_with_the_apply_request(): void
    {
        $js = $this->source(self::WIZARD_JS);

        $this->assertStringContainsString(
            'articulos-wizard-target-idioma',
            $js,
            'the wizard must read the rendered target-language select'
        );
        $this->assertStringContainsString(
            "params.push('target_codidioma=' + encodeURIComponent(",
            $js,
            'the apply request must carry target_codidioma'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function source(string $relative): string
    {
        $path = FS_FOLDER . $relative;
        if (!is_file($path)) {
            self::fail('missing path: ' . $relative);
        }

        return (string) file_get_contents($path);
    }

    /**
     * Extracts a method/function body by brace balancing, so the assertion
     * reads only the call site under test and never a sibling's.
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

    /**
     * Renders the REAL import modal partial with a minimal Twig environment and
     * a stub `fsc`, so the select contract is proven against the shipped bytes.
     *
     * @param list<array{codidioma: string, nombre: string, por_defecto: bool}> $idiomas
     */
    private function renderModal(array $idiomas): string
    {
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['modal' => $this->source(self::MODAL)]));
        $twig->addFilter(new \Twig\TwigFilter(
            'trans',
            static fn ($message, array $parameters = []): string => (string) $message
        ));
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', static fn (): string => ''));

        return $twig->render('modal', [
            'fsc' => [
                'idiomas' => $idiomas,
                'impuestos' => [],
                'default_codimpuesto' => 'IVA21',
                'price_decimals' => 2,
            ],
        ]);
    }

    private function selectBlock(string $html, string $name): string
    {
        $pattern = '/<select\b[^>]*\bname="' . preg_quote($name, '/') . '"[^>]*>.*?<\/select>/s';
        if (preg_match($pattern, $html, $matches) !== 1) {
            self::fail('missing select[name="' . $name . '"]');
        }

        return $matches[0];
    }
}
