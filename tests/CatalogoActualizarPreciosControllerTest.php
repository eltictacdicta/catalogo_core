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

use PHPUnit\Framework\TestCase;

/**
 * Batch price update page (multitarifa task 4.2, R-BU-001..005).
 *
 * Legacy fs_controller page; the preview/apply flow is CSRF-gated twice
 * (preview POST, then confirm apply POST). Persistence goes exclusively
 * through CatalogoPriceUpdateService; articulos.pvp is never rewritten.
 * Controller wiring is pinned with structural source assertions
 * (OpcionesCatalogoControllerTest precedent — no Kernel/DB available),
 * plus functional coverage of the pure resumen builder.
 */
final class CatalogoActualizarPreciosControllerTest extends TestCase
{
    private const CONTROLLER_FILE = '/plugins/catalogo_core/controller/catalogo_actualizar_precios.php';
    private const VIEW_FILE = '/plugins/catalogo_core/view/catalogo_actualizar_precios.html.twig';

    protected function setUp(): void
    {
        parent::setUp();
        global $plugins;
        $plugins = [];
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_grupo.php';
    }

    // ---- Page shape (R-BU-004: admin-gated) ----

    public function test_controller_file_and_view_exist(): void
    {
        $this->assertFileExists(FS_FOLDER . self::CONTROLLER_FILE);
        $this->assertFileExists(FS_FOLDER . self::VIEW_FILE);
    }

    public function test_controller_extends_legacy_fs_controller(): void
    {
        require_once FS_FOLDER . self::CONTROLLER_FILE;

        $reflection = new \ReflectionClass('catalogo_actualizar_precios');
        $this->assertTrue(
            $reflection->isSubclassOf(\fs_controller::class),
            'catalogo_actualizar_precios must be a legacy fs_controller page'
        );
    }

    /** Non-admin denial is enforced at the framework level (admin=TRUE). */
    public function test_controller_is_admin_gated_in_ventas_folder(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $this->assertMatchesRegularExpression(
            "/parent::__construct\(__CLASS__,\s*'[^']*',\s*'ventas',\s*TRUE,\s*TRUE\)/",
            $source,
            'Constructor must gate the page to admins (admin=TRUE) inside the ventas folder'
        );
    }

    // ---- Preview/apply flow (R-BU-001) ----

    public function test_post_has_whitelisted_preview_and_apply_actions(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $this->assertStringContainsString("'preview'", $source);
        $this->assertStringContainsString("'apply'", $source);
    }

    public function test_controller_uses_the_shared_update_service(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $this->assertStringContainsString(
            \FSFramework\Plugins\catalogo_core\Services\CatalogoPriceUpdateService::class,
            $source,
            'Persistence and filter resolution must go through CatalogoPriceUpdateService'
        );
    }

    /** Preview must run before any persistence (no set_precio in preview path). */
    public function test_preview_runs_before_apply(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $previewPos = strpos($source, '->preview(');
        $applyPos = strpos($source, '->apply(');
        $this->assertNotFalse($previewPos, 'The preview phase must call the service preview()');
        $this->assertNotFalse($applyPos, 'The apply phase must call the service apply()');
        $this->assertLessThan($applyPos, $previewPos, 'Preview must precede apply (two-phase flow)');
    }

    // ---- CSRF gating (R-BU-004) ----

    public function test_csrf_checked_before_any_persistence(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $csrfPos = strpos($source, 'isCsrfValid()');
        $this->assertNotFalse($csrfPos, 'POST branch must call isCsrfValid()');
        $applyPos = strpos($source, '->apply(');
        $this->assertNotFalse($applyPos);
        $this->assertLessThan($applyPos, $csrfPos, 'CSRF must be validated before apply persists');
    }

    // ---- codlista required (R-BU-002) ----

    public function test_codlista_is_required(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $this->assertMatchesRegularExpression(
            "/codlista[^;]*===?\s*''/",
            $source,
            'codlista must be explicitly required (fail closed when missing)'
        );
    }

    // ---- Resumen / flash messages (R-BU-005) ----

    public function test_zero_matches_produces_warning_without_write(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $emptyCheck = strpos($source, 'empty($rows)');
        $applyPos = strpos($source, '->apply(');
        $this->assertNotFalse($emptyCheck, 'Zero matches must be detected before apply');
        $this->assertLessThan($applyPos, $emptyCheck, 'Zero-match warning must short-circuit before any write');
        $this->assertStringContainsString('new_message', $source);
    }

    public function test_apply_reports_resumen_and_failures(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $this->assertStringContainsString('new_message(', $source, 'Success resumen via new_message');
        $this->assertStringContainsString('new_error_msg(', $source, 'Failed rows via new_error_msg');
    }

    /** Functional: the pure resumen builder. */
    public function test_resumen_builder_reports_updates_and_failures(): void
    {
        require_once FS_FOLDER . self::CONTROLLER_FILE;

        $ok = \catalogo_actualizar_precios::summarizeApply(3, []);
        $this->assertStringContainsString('3', $ok);
        $this->assertStringNotContainsString('error', mb_strtolower($ok));

        $partial = \catalogo_actualizar_precios::summarizeApply(2, ['B1', 'B2']);
        $this->assertStringContainsString('2', $partial);
        $this->assertStringContainsString('B1', $partial);
        $this->assertStringContainsString('B2', $partial);
    }

    // ---- View ----

    public function test_view_includes_csrf_field_and_no_raw(): void
    {
        $content = (string) file_get_contents(FS_FOLDER . self::VIEW_FILE);

        $this->assertStringContainsString('{{ csrf_field() }}', $content);
        $this->assertStringNotContainsString('|raw', $content, 'Views must escape all output');
    }

    public function test_view_renders_filter_fields_and_confirm_checkbox(): void
    {
        $content = (string) file_get_contents(FS_FOLDER . self::VIEW_FILE);

        foreach ([
            'name="codlista"',
            'name="codfamilia"',
            'name="incluir_subfamilias"',
            'name="codgrupo"',
            'name="confirm"',
            'name="pct"',
        ] as $field) {
            $this->assertStringContainsString($field, $content, "View must render $field");
        }
    }

    public function test_view_hides_flow_when_multi_tariff_off(): void
    {
        $content = (string) file_get_contents(FS_FOLDER . self::VIEW_FILE);

        $this->assertStringContainsString('multi_tariff', $content, 'Multi-tariff flag must gate the UI (R-CO-003)');
    }

    public function test_controller_reads_multi_tariff_flag(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $this->assertStringContainsString('multiTariffEnabled', $source);
    }
}
