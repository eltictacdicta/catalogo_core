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
 * Delta spec `opcionales-tarifa-selector` OTS-02 / OTS-05 / OTS-09 (WU-4).
 *
 * The scoped "Precios por tarifa" panel keeps `precio` + `activa` editable
 * through `tarif.toggle_button_group(`; catalog/tarifa visibility is rendered
 * as a parent-product-derived read-only indicator and posted visibility fields
 * are ignored. The locked contracts (parent class, `effective(`/`set_activa(`
 * reads, `guardar_precio_tarifa` + `csrf_field()`) stay intact, and the removed
 * `set_en_catalogo(`/`set_en_tarifa(` accessors are no longer asserted.
 */
final class TarifOpcionalEditCaracteristicaTest extends TestCase
{
    private const CONTROLLER = 'plugins/catalogo_core/controller/tarif_opcional_edit.php';
    private const VIEW = 'plugins/catalogo_core/View/tarif_opcional_edit.html.twig';

    // =====================================================================
    // OTS-02 — scoped editable panel + derived read-only indicator
    // =====================================================================

    public function test_selected_tarifa_scopes_the_panel_and_keeps_the_read_only_overview(): void
    {
        $view = $this->source(self::VIEW);

        $this->assertStringContainsString(
            'tarif.toggle_button_group(',
            $view,
            'the surviving state control must keep the locked macro contract'
        );
        $this->assertStringContainsString('guardar_precio_tarifa', $view);
        $this->assertStringContainsString('fsc.tarifa_seleccionada.codtarifa', $view, 'the panel is scoped to the selected tarifa');
        $this->assertStringContainsString('Resumen por tarifa', $view, 'the compact all-tarifas read-only overview stays');
    }

    public function test_no_editable_field_targets_visibility(): void
    {
        $view = $this->source(self::VIEW);

        $this->assertDoesNotMatchRegularExpression(
            '/name=["\']en_catalogo["\']/',
            $view,
            'no editable field may target en_catalogo (OTS-02)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/name=["\']en_tarifa["\']/',
            $view,
            'no editable field may target en_tarifa (OTS-02)'
        );

        // The scoped control group is reduced to the surviving `activa` flag.
        $scoped = $this->toggleGroupLines($view)[0] ?? '';
        $this->assertNotSame('', $scoped, 'the scoped toggle_button_group( call must be found');
        $this->assertStringContainsString("'activa':", $scoped);
        $this->assertStringContainsString("'show_catalogo': false", $scoped);
        $this->assertStringContainsString("'show_en_tarifa': false", $scoped);
        $this->assertStringNotContainsString("'en_catalogo':", $scoped, 'the retired visibility button must be hidden');
        $this->assertStringNotContainsString("'en_tarifa':", $scoped, 'the retired visibility button must be hidden');

        // ... and the derived indicator is rendered read-only.
        $this->assertStringContainsString(
            'fsc.opcional_en_catalogo_tarifa(fsc.tarifa_seleccionada.codtarifa)',
            $view
        );
        $this->assertStringContainsString(
            'fsc.opcional_en_tarifa_flag(fsc.tarifa_seleccionada.codtarifa)',
            $view
        );
    }

    // =====================================================================
    // OTS-05 — CSRF-guarded save ignores visibility fields
    // =====================================================================

    public function test_scoped_save_preserves_the_locked_contracts(): void
    {
        $view = $this->source(self::VIEW);
        $this->assertStringContainsString('guardar_precio_tarifa', $view);
        $this->assertStringContainsString('{{ csrf_field() }}', $view);
        $this->assertStringContainsString('tarif.toggle_button_group(', $view);
    }

    public function test_posted_visibility_fields_are_ignored_by_the_save(): void
    {
        $save = $this->methodBody($this->source(self::CONTROLLER), 'guardar_precio_tarifa');

        $this->assertNotSame('', $save, 'guardar_precio_tarifa() body must be found');
        $this->assertStringContainsString('requireCsrf()', $save, 'the per-tarifa save must stay CSRF-guarded');
        $this->assertStringContainsString('set_activa(', $save, 'activation keeps persisting through the master');

        foreach (['set_en_catalogo(', 'set_en_tarifa('] as $removed) {
            $this->assertStringNotContainsString($removed, $save, 'the retired visibility setter must not be reached');
        }

        foreach (["\$_POST['en_catalogo']", "\$_POST['en_tarifa']"] as $posted) {
            $this->assertStringNotContainsString($posted, $save, 'posted visibility fields must be ignored');
        }
    }

    public function test_bulk_matrix_save_ignores_visibility_fields(): void
    {
        $bulk = $this->methodBody($this->source(self::CONTROLLER), 'guardar_precios_tarifas');

        $this->assertNotSame('', $bulk, 'guardar_precios_tarifas() body must be found');
        $this->assertStringContainsString('set_activa(', $bulk);
        $this->assertStringNotContainsString('set_en_catalogo(', $bulk);
        $this->assertStringNotContainsString('set_en_tarifa(', $bulk);
        $this->assertStringNotContainsString("\$_POST['catalogo_tarifa_", $bulk);
        $this->assertStringNotContainsString("\$_POST['en_tarifa_tarifa_", $bulk);
    }

    // =====================================================================
    // OTS-09 — surviving locked contracts
    // =====================================================================

    public function test_surviving_controller_contracts_are_intact(): void
    {
        $src = $this->source(self::CONTROLLER);

        $this->assertMatchesRegularExpression(
            '/class\s+tarif_opcional_edit\s+extends\s+fbase_controller\b/',
            $src,
            'the controller must keep extending catalogo_core fbase_controller'
        );
        $this->assertStringContainsString('use TarifarioOpcionalStateTrait;', $src);
        $this->assertStringContainsString('effective(', $src, 'the master effective() read stays');
        $this->assertStringContainsString('set_activa(', $src, 'the master set_activa() write stays');

        foreach (['set_en_catalogo(', 'set_en_tarifa('] as $removed) {
            $this->assertStringNotContainsString(
                $removed,
                $src,
                'the removed visibility accessor must not be asserted nor used by the controller'
            );
        }
    }

    public function test_view_keeps_the_visibility_columns_as_read_only_cells(): void
    {
        $view = $this->source(self::VIEW);

        // The compact overview still shows both columns, driven by the derived map.
        $this->assertStringContainsString('{% if datos.en_catalogo %}', $view);
        $this->assertStringContainsString('{% if datos.en_tarifa %}', $view);
        $this->assertStringContainsString('<th>Catálogo</th>', $view);
        $this->assertStringContainsString('<th>En tarifa</th>', $view);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function source(string $relative): string
    {
        $full = FS_FOLDER . '/' . $relative;
        if (!is_file($full)) {
            self::fail('missing catalogo_core path: ' . $relative);
        }

        return (string) file_get_contents($full);
    }

    /**
     * Source lines calling the toggle_button_group macro.
     *
     * @return list<string>
     */
    private function toggleGroupLines(string $view): array
    {
        $lines = [];
        foreach (explode("\n", $view) as $line) {
            if (str_contains($line, 'toggle_button_group(')) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

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
}
