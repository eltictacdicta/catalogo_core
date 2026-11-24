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

use FSFramework\Plugins\catalogo_core\Services\PriceBookInterface;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateFilterInterface;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateFilterRegistry;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateScopeInterface;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateTargetInterface;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateTargetRegistry;
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
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceUpdateTargetRegistry.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceUpdateScopes.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceUpdateFilterRegistry.php';
        PriceUpdateTargetRegistry::reset();
        PriceUpdateFilterRegistry::reset();
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

    /**
     * R-BU-004 / D9: the real administrator gate is the class attribute. The
     * 4th constructor argument is OBSOLETE and ignored, so it must not be the
     * thing the test pins (it never protected anything).
     */
    public function testControllerDeclaresAdminOnlyAttribute(): void
    {
        require_once FS_FOLDER . self::CONTROLLER_FILE;

        $names = array_map(
            static fn (\ReflectionAttribute $a): string => ltrim($a->getName(), '\\'),
            (new \ReflectionClass('catalogo_actualizar_precios'))->getAttributes()
        );
        $this->assertContains(\FSFramework\Attribute\AdminOnly::class, $names);

        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);
        $this->assertMatchesRegularExpression(
            "/parent::__construct\(__CLASS__,\s*'[^']*',\s*'ventas',\s*FALSE,\s*TRUE\)/",
            $source,
            'The obsolete 4th argument is FALSE; the 5th keeps the page in the ventas menu'
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

    // ---- target key required (R-BU-002) ----

    public function test_target_key_is_required_and_comes_from_the_target(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $this->assertStringContainsString('keyFromFilters(', $source, 'The key is read through the target, never by field name');
        $this->assertMatchesRegularExpression(
            '/keyFromFilters\([^)]*\)\s*===\s*null/',
            $source,
            'A missing target key must fail closed'
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
            'name="{{ fsc.target_key_field }}"',
            'name="codfamilia"',
            'name="incluir_subfamilias"',
            'name="codgrupo"',
            'name="confirm"',
            'name="pct"',
        ] as $field) {
            $this->assertStringContainsString($field, $content, "View must render $field");
        }
    }

    /** Only tariffs exist (R-MT-011): the page no longer depends on the list UI rule. */
    public function test_page_no_longer_depends_on_the_list_ui_rule(): void
    {
        $content = (string) file_get_contents(FS_FOLDER . self::VIEW_FILE);
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        foreach (['multi_tariff', 'tariffs_active'] as $needle) {
            $this->assertStringNotContainsString($needle, $content);
            $this->assertStringNotContainsString($needle, $source);
        }
        $this->assertStringNotContainsString('PriceListAvailability', $source);
        $this->assertStringContainsString('index.php?page=tarif_tarifas', $content, 'without targets the notice points at the tariffs page');
    }

    // ---- Form flag decoding (regression: fatal on undefined method) ----

    public function test_form_flags_use_the_posted_true_false_contract(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $this->assertStringNotContainsString(
            '$this->str2bool(',
            $source,
            'str2bool() belongs to fs_model: fs_controller has no such method and it cannot decode form values'
        );
        $this->assertStringContainsString(
            "=== 'TRUE'",
            $source,
            'Checkbox flags are posted as TRUE/FALSE by the view; compare against TRUE explicitly'
        );
    }

    // =====================================================================
    // WU-1.5 — seam: target / scope selection, apply repost, fail closed
    // =====================================================================

    public function testTargetSelectorRendersRegisteredTargetsAndScopeSelector(): void
    {
        $content = (string) file_get_contents(FS_FOLDER . self::VIEW_FILE);

        $this->assertMatchesRegularExpression('/<select[^>]*name="target"/', $content);
        $this->assertMatchesRegularExpression('/<select[^>]*name="scope"/', $content);
        $this->assertStringContainsString('fsc.targets', $content);
        $this->assertStringContainsString('fsc.scopes', $content);
        $this->assertStringContainsString('fsc.target_key_options', $content, 'The key options come from the selected target');
    }

    /** D11.2: the apply form used to post only action+confirm, so apply always failed. */
    public function testApplyFormRepostsEveryFilterAndPercent(): void
    {
        $content = (string) file_get_contents(FS_FOLDER . self::VIEW_FILE);

        $start = strpos($content, 'name="action" value="apply"');
        $this->assertNotFalse($start, 'The apply form must exist');
        $applyForm = substr($content, $start, (int) strpos($content, '</form>', $start) - $start);

        foreach (['target', 'scope', '{{ fsc.target_key_field }}', 'pct', 'codfamilia', 'incluir_subfamilias', 'codgrupo'] as $field) {
            $this->assertMatchesRegularExpression(
                '/type="hidden"\s+name="' . preg_quote($field, '/') . '"/',
                $applyForm,
                "The apply form must repost $field as a hidden input"
            );
        }
        $this->assertStringContainsString('name="confirm"', $applyForm);
    }

    public function testApplyPersistsPreviewRowsWithRepostedFilters(): void
    {
        $book = $this->fakeBook(['A' => 10.0, 'B' => 20.0]);
        $scope = $this->fakeScope([['A', 'Uno'], ['B', 'Dos']]);
        $this->registerTarget('fake', $book);

        $controller = $this->makeController(['articulos' => $scope]);
        $_POST = [
            'action' => 'apply',
            'confirm' => 'TRUE',
            'target' => 'fake',
            'scope' => 'articulos',
            'clave' => 'K',
            'pct' => '10',
            'codfamilia' => 'F',
            'incluir_subfamilias' => 'TRUE',
            'codgrupo' => '5',
        ];

        $controller->callHandlePost();

        $this->assertSame([], $controller->errors);
        $this->assertSame([['A', 11.0], ['B', 22.0]], $book->persisted, 'Exactly the previewed rows, with the reposted percent');
        $this->assertSame(
            ['codfamilia' => 'F', 'incluir_subfamilias' => true, 'codgrupo' => 5],
            array_intersect_key($scope->lastFilters, array_flip(['codfamilia', 'incluir_subfamilias', 'codgrupo'])),
            'Reposted filters reach the scope'
        );
        $this->assertStringContainsString('2 precios actualizados', implode(' ', $controller->messages), 'R-BU-005 summary is unchanged');
    }

    public function testPreviewNeverPersists(): void
    {
        $book = $this->fakeBook(['A' => 10.0]);
        $this->registerTarget('fake', $book);
        $controller = $this->makeController(['articulos' => $this->fakeScope([['A', 'Uno']])]);
        $_POST = ['action' => 'preview', 'target' => 'fake', 'scope' => 'articulos', 'clave' => 'K', 'pct' => '5', 'codfamilia' => 'F'];

        $controller->callHandlePost();

        $this->assertSame([], $book->persisted);
        $this->assertCount(1, $controller->preview_rows);
        $this->assertSame('A', $controller->preview_rows[0]['key']);
    }

    public function testApplyRequiresExplicitConfirmation(): void
    {
        $book = $this->fakeBook(['A' => 10.0]);
        $this->registerTarget('fake', $book);
        $controller = $this->makeController(['articulos' => $this->fakeScope([['A', 'Uno']])]);
        $_POST = ['action' => 'apply', 'target' => 'fake', 'scope' => 'articulos', 'clave' => 'K', 'pct' => '5', 'codfamilia' => 'F'];

        $controller->callHandlePost();

        $this->assertSame([], $book->persisted);
        $this->assertNotSame([], $controller->errors);
    }

    /** D11.5: the empty-selection message must not name articles. */
    public function testEmptyMatchMessageIsScopeNeutral(): void
    {
        $this->registerTarget('fake', $this->fakeBook([]));
        $controller = $this->makeController(['articulos' => $this->fakeScope([])]);
        $_POST = ['action' => 'preview', 'target' => 'fake', 'scope' => 'articulos', 'clave' => 'K', 'pct' => '5', 'codfamilia' => 'F'];

        $controller->callHandlePost();

        $text = implode(' ', $controller->messages);
        $this->assertStringContainsString('Ningún elemento coincide', $text);
        $this->assertStringNotContainsStringIgnoringCase('artículo', $text);

        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);
        $this->assertStringNotContainsString('Ningún artículo coincide', $source);
        $this->assertStringNotContainsString('artículos afectados', mb_strtolower($source));

        $view = (string) file_get_contents(FS_FOLDER . self::VIEW_FILE);
        $this->assertDoesNotMatchRegularExpression('/Vista previa:[^<]*artículos/u', $view);
    }

    /** Empty registry: neutral message and zero writes. */
    public function testPageIsInertWithoutTargets(): void
    {
        PriceUpdateTargetRegistry::reset();
        $scope = $this->fakeScope([['A', 'Uno']]);
        $controller = $this->makeController(['articulos' => $scope]);
        $_POST = ['action' => 'apply', 'confirm' => 'TRUE', 'target' => 'lista', 'scope' => 'articulos', 'codlista' => 'L1', 'pct' => '5', 'codfamilia' => 'F'];

        $controller->callHandlePost();

        $this->assertSame([], $controller->messages);
        $this->assertCount(1, $controller->errors);
        $this->assertStringContainsString('No hay destinos', $controller->errors[0]);
        $this->assertSame(0, $scope->matchCalls, 'Nothing is even selected when there is no target');
        $this->assertSame([], $controller->preview_rows);
    }

    public function testUnknownTargetFailsClosed(): void
    {
        $book = $this->fakeBook(['A' => 10.0]);
        $this->registerTarget('fake', $book);
        $controller = $this->makeController(['articulos' => $this->fakeScope([['A', 'Uno']])]);
        $_POST = ['action' => 'apply', 'confirm' => 'TRUE', 'target' => 'nope', 'scope' => 'articulos', 'clave' => 'K', 'pct' => '5', 'codfamilia' => 'F'];

        $controller->callHandlePost();

        $this->assertSame([], $book->persisted);
        $this->assertCount(1, $controller->errors);
        $this->assertSame([], $controller->messages);
    }

    public function testUnavailableTargetFailsClosed(): void
    {
        $book = $this->fakeBook(['A' => 10.0]);
        $this->registerTarget('fake', $book, available: false);
        $controller = $this->makeController(['articulos' => $this->fakeScope([['A', 'Uno']])]);
        $_POST = ['action' => 'apply', 'confirm' => 'TRUE', 'target' => 'fake', 'scope' => 'articulos', 'clave' => 'K', 'pct' => '5', 'codfamilia' => 'F'];

        $controller->callHandlePost();

        $this->assertSame([], $book->persisted);
        $this->assertNotSame([], $controller->errors);
    }

    public function testUnknownScopeFailsClosed(): void
    {
        $book = $this->fakeBook(['A' => 10.0]);
        $this->registerTarget('fake', $book);
        $controller = $this->makeController(['articulos' => $this->fakeScope([['A', 'Uno']])]);
        $_POST = ['action' => 'apply', 'confirm' => 'TRUE', 'target' => 'fake', 'scope' => 'otro', 'clave' => 'K', 'pct' => '5', 'codfamilia' => 'F'];

        $controller->callHandlePost();

        $this->assertSame([], $book->persisted);
        $this->assertCount(1, $controller->errors);
    }

    public function testMissingTargetKeyFailsClosed(): void
    {
        $book = $this->fakeBook(['A' => 10.0]);
        $this->registerTarget('fake', $book);
        $controller = $this->makeController(['articulos' => $this->fakeScope([['A', 'Uno']])]);
        $_POST = ['action' => 'apply', 'confirm' => 'TRUE', 'target' => 'fake', 'scope' => 'articulos', 'clave' => '  ', 'pct' => '5', 'codfamilia' => 'F'];

        $controller->callHandlePost();

        $this->assertSame([], $book->persisted);
        $this->assertCount(1, $controller->errors);
    }

    public function testBlankTargetUsesTheDefaultTarget(): void
    {
        $book = $this->fakeBook(['A' => 10.0]);
        $this->registerTarget('lista', $book);
        $controller = $this->makeController(['articulos' => $this->fakeScope([['A', 'Uno']])]);
        $_POST = ['action' => 'apply', 'confirm' => 'TRUE', 'clave' => 'K', 'pct' => '10', 'codfamilia' => 'F'];

        $controller->callHandlePost();

        $this->assertSame([['A', 11.0]], $book->persisted, 'No target and no scope posted: defaults apply (single-option flow)');
    }

    /** Renders the real view: echoed values land in the hidden inputs, escaped. */
    public function testViewRendersThePreviewAndRepostsEscapedValues(): void
    {
        $twig = new \Twig\Environment(
            new \Twig\Loader\ArrayLoader([
                'header.html.twig' => '',
                'footer.html.twig' => '',
                'page' => (string) file_get_contents(FS_FOLDER . self::VIEW_FILE),
            ]),
            ['autoescape' => 'html', 'strict_variables' => true]
        );
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', static fn (): string => '<input type="hidden" name="_token" value="t">', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('render_hook', static fn (): string => '', ['is_safe' => ['html']]));

        $fsc = new class {
            public array $targets = ['lista' => 'Lista de precio', 'otro' => 'Otro'];
            public array $scopes = ['articulos' => 'Artículos'];
            public string $selected_target = 'otro';
            public string $selected_scope = 'articulos';
            public string $target_key_field = 'codlista';
            public string $target_key_label = 'lista de precio';
            public array $target_key_options = [['value' => 'L1', 'label' => 'Lista uno']];
            public string $scope_key_label = 'Referencia';
            public array $filter_extra = ['grupo_x' => '7"><b>'];
            public array $form = ['key' => 'L1', 'pct' => '7,5', 'codfamilia' => '"><script>x</script>', 'incluir_subfamilias' => true, 'codgrupo' => '5'];
            public array $familias = [];
            public array $grupos = [];
            public array $preview_rows = [['key' => 'A1', 'label' => 'Uno', 'precio_actual' => 10.0, 'precio_nuevo' => 11.0]];

            public function url(): string
            {
                return 'index.php?page=catalogo_actualizar_precios';
            }
        };

        $html = $twig->render('page', ['fsc' => $fsc, 'user' => null, 'empresa' => null, 'i18n' => null]);

        $this->assertStringContainsString('<option value="otro" selected>Otro</option>', $html);
        $this->assertStringContainsString('<input type="hidden" name="grupo_x" value="7&quot;&gt;&lt;b&gt;" />', $html, 'Declared consumer keys are reposted, escaped');
        $this->assertStringContainsString('Vista previa: 1 elementos', $html);
        $this->assertStringContainsString('<input type="hidden" name="codlista" value="L1" />', $html);
        $this->assertStringContainsString('<input type="hidden" name="pct" value="7,5" />', $html);
        $this->assertStringContainsString('<input type="hidden" name="incluir_subfamilias" value="TRUE" />', $html);
        $this->assertStringContainsString('<input type="hidden" name="codgrupo" value="5" />', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html, 'Echoed values must be escaped');
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    public function testViewShowsAnInertNoticeWithoutTargets(): void
    {
        $twig = new \Twig\Environment(
            new \Twig\Loader\ArrayLoader([
                'header.html.twig' => '',
                'footer.html.twig' => '',
                'page' => (string) file_get_contents(FS_FOLDER . self::VIEW_FILE),
            ]),
            ['autoescape' => 'html', 'strict_variables' => true]
        );
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', static fn (): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('render_hook', static fn (): string => '', ['is_safe' => ['html']]));
        $fsc = new class {
            public array $targets = [];
        };

        $html = $twig->render('page', ['fsc' => $fsc, 'user' => null, 'empresa' => null, 'i18n' => null]);

        $this->assertStringContainsString('No hay destinos de actualización disponibles.', $html);
        $this->assertStringNotContainsString('<form', $html, 'No form is offered without a target');
    }

    // ---- WU-3.1: consumer filters (R-BU-002, R-CRH-001, D5) ----

    /** Only keys the registry declares are reposted; the client cannot add others. */
    public function testFilterKeysAreRepostedAsHiddenInputs(): void
    {
        $content = (string) file_get_contents(FS_FOLDER . self::VIEW_FILE);
        $start = strpos($content, 'name="action" value="apply"');
        $applyForm = substr($content, (int) $start, (int) strpos($content, '</form>', (int) $start) - (int) $start);

        $this->assertStringContainsString('fsc.filter_extra', $applyForm, 'The apply form reposts the declared consumer filter keys');
        $this->assertMatchesRegularExpression(
            '/for\s+\w+\s*,\s*\w+\s+in\s+fsc\.filter_extra/',
            $applyForm
        );
    }

    public function testReadFiltersCarriesOnlyTheDeclaredConsumerKeys(): void
    {
        PriceUpdateFilterRegistry::register($this->consumerFilter(['grupo_x'], ['opciones' => [1, 2]]));
        $scope = $this->fakeScope([['A', 'Uno']]);
        $this->registerTarget('fake', $this->fakeBook(['A' => 10.0]));
        $controller = $this->makeController(['articulos' => $scope]);
        $_POST = [
            'action' => 'preview', 'target' => 'fake', 'scope' => 'articulos', 'clave' => 'K', 'pct' => '5',
            'codfamilia' => 'F', 'grupo_x' => '7', 'intruso' => 'x',
        ];

        $controller->callHandlePost();

        $this->assertSame('7', $scope->lastFilters['grupo_x'], 'A declared key reaches the scope and the filters');
        $this->assertArrayNotHasKey('intruso', $scope->lastFilters, 'An arbitrary client key is ignored');
    }

    public function testPreviewAndApplyNarrowThroughTheRegisteredFilter(): void
    {
        PriceUpdateFilterRegistry::register($this->consumerFilter(['grupo_x'], [], static fn (array $f, array $r): bool => $r['key'] !== 'B'));
        $book = $this->fakeBook(['A' => 10.0, 'B' => 20.0]);
        $this->registerTarget('fake', $book);
        $controller = $this->makeController(['articulos' => $this->fakeScope([['A', 'Uno'], ['B', 'Dos']])]);
        $_POST = [
            'action' => 'apply', 'confirm' => 'TRUE', 'target' => 'fake', 'scope' => 'articulos',
            'clave' => 'K', 'pct' => '10', 'codfamilia' => 'F', 'grupo_x' => '7',
        ];

        $controller->callHandlePost();

        $this->assertSame([['A', 11.0]], $book->persisted, 'The reposted consumer filter narrows the apply too');
    }

    public function testPriceUpdateFilterContextExposesTheRegistryContextAndPostedValues(): void
    {
        PriceUpdateFilterRegistry::register($this->consumerFilter(['grupo_x'], ['opciones' => ['G1']]));
        $controller = $this->makeController(['articulos' => $this->fakeScope([])]);
        $_POST = ['grupo_x' => '7', 'intruso' => 'x'];

        $controller->callPrepareExtraFilters('articulos');

        $this->assertSame(['opciones' => ['G1']], $controller->price_update_filter_context());
        $this->assertSame(['grupo_x' => '7'], $controller->filter_extra, 'Posted values of declared keys only');
    }

    public function testPriceUpdateFilterContextIsEmptyWithoutFilters(): void
    {
        $controller = $this->makeController(['articulos' => $this->fakeScope([])]);

        $this->assertSame([], $controller->price_update_filter_context());
        $this->assertSame([], $controller->filter_extra);
    }

    /** R-CRH-001: a registered hook lands inside the filter form, unescaped. */
    public function testRegisteredHookFragmentAppearsInsideTheFilterForm(): void
    {
        \FSFramework\View\ViewHookRegistry::register('catalogo_actualizar_precios_filters_after', 'test/hook.html.twig');
        $twig = new \Twig\Environment(
            new \Twig\Loader\ArrayLoader([
                'header.html.twig' => '',
                'footer.html.twig' => '',
                'test/hook.html.twig' => '<select name="grupo_x" data-n="{{ fsc.price_update_filter_context().n }}"></select>',
                'page' => (string) file_get_contents(FS_FOLDER . self::VIEW_FILE),
            ]),
            ['autoescape' => 'html', 'strict_variables' => true]
        );
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', static fn (): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction(
            'render_hook',
            static fn (string $name, array $context = []): string => \FSFramework\View\ViewHookRegistry::render($twig, $name, $context),
            ['is_safe' => ['html']]
        ));
        $fsc = new class {
            public array $targets = ['lista' => 'Lista'];
            public array $scopes = ['articulos' => 'Artículos'];
            public string $selected_target = 'lista';
            public string $selected_scope = 'articulos';
            public string $target_key_field = 'codlista';
            public string $target_key_label = 'lista';
            public array $target_key_options = [];
            public string $scope_key_label = 'Referencia';
            public array $filter_extra = [];
            public array $form = ['key' => '', 'pct' => '0', 'codfamilia' => '', 'incluir_subfamilias' => false, 'codgrupo' => ''];
            public array $familias = [];
            public array $grupos = [];
            public array $preview_rows = [];

            public function url(): string
            {
                return 'index.php?page=catalogo_actualizar_precios';
            }

            public function price_update_filter_context(): array
            {
                return ['n' => 3];
            }
        };

        $html = $twig->render('page', ['fsc' => $fsc, 'user' => null, 'empresa' => null, 'i18n' => null]);

        $hook = strpos($html, '<select name="grupo_x" data-n="3"></select>');
        $this->assertNotFalse($hook, 'The registered fragment is rendered, unescaped');
        $this->assertGreaterThan((int) strpos($html, 'name="pct"'), $hook, 'After the last filter control');
        $this->assertLessThan((int) strpos($html, 'Vista previa'), $hook, 'Before the submit button');
    }

    // ---- WU-4.1: legacy redirect notice (R-BU-008, R-BU-002) ----

    /** Only the whitelisted code produces a (neutral) message; anything else is dropped. */
    public function testSelectionRequiredNoticeIsWhitelisted(): void
    {
        require_once FS_FOLDER . self::CONTROLLER_FILE;

        $known = \catalogo_actualizar_precios::noticeFor('seleccion_requerida');
        $this->assertNotSame('', $known);
        $this->assertStringNotContainsString('tarifa', mb_strtolower($known), 'Neutral wording: no consumer vocabulary');
        $this->assertSame('', \catalogo_actualizar_precios::noticeFor(''));
        $this->assertSame('', \catalogo_actualizar_precios::noticeFor('<script>alert(1)</script>'));
        $this->assertSame('', \catalogo_actualizar_precios::noticeFor('SELECCION_REQUERIDA'));

        $controller = $this->makeController([]);
        $_GET = ['aviso' => '<b>x</b>'];
        $controller->callPrepareNotice();
        $this->assertSame('', $controller->notice, 'An unknown code is neither reflected nor stored');

        $_GET = ['aviso' => 'seleccion_requerida'];
        $controller->callPrepareNotice();
        $this->assertSame($known, $controller->notice);

        $view = (string) file_get_contents(FS_FOLDER . self::VIEW_FILE);
        $this->assertStringContainsString('fsc.notice', $view);
        $this->assertDoesNotMatchRegularExpression('/\{\{\s*fsc\.notice\s*\|\s*raw/', $view);
    }

    /** The notice is read-only: no target, key or write results from it. */
    public function testNoticeDoesNotPreselectAnyTarget(): void
    {
        $book = $this->fakeBook(['A' => 10.0]);
        $this->registerTarget('fake', $book);
        $controller = $this->makeController(['articulos' => $this->fakeScope([['A', 'Uno']])]);
        $_GET = ['aviso' => 'seleccion_requerida'];
        $_POST = [];

        $controller->callPrepareNotice();
        $controller->callPrepareSelection();

        $this->assertSame('', $controller->form['key'], 'No key is preselected by the notice');
        $this->assertSame([], $book->persisted, 'The notice never writes');
        $this->assertSame([], $controller->errors);
        $this->assertSame([], $controller->messages);
        $this->assertStringNotContainsString(
            'aviso',
            $this->selectionBody(),
            'prepareSelection() does not look at the notice'
        );
    }

    private function selectionBody(): string
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);
        $start = (int) strpos($source, 'protected function prepareSelection');
        $end = (int) strpos($source, 'protected function prepareExtraFilters');

        return substr($source, $start, $end - $start);
    }

    // ---- doubles ----

    protected function tearDown(): void
    {
        PriceUpdateTargetRegistry::reset();
        PriceUpdateFilterRegistry::reset();
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    /** @param array<string, PriceUpdateScopeInterface> $scopes */
    private function makeController(array $scopes): object
    {
        require_once FS_FOLDER . self::CONTROLLER_FILE;

        return new class($scopes) extends \catalogo_actualizar_precios {
            /** @var list<string> */
            public array $errors = [];

            /** @var list<string> */
            public array $messages = [];

            /** @param array<string, PriceUpdateScopeInterface> $fScopes */
            public function __construct(private readonly array $fScopes)
            {
                // Skips fs_controller: no Kernel/DB in this suite.
            }

            public function new_error_msg($msg, $tipo = 'error', $alerta = false, $guardar = true)
            {
                $this->errors[] = (string) $msg;
            }

            public function new_message($msg, $save = false, $tipo = 'msg', $alerta = false)
            {
                $this->messages[] = (string) $msg;
            }

            protected function resolveScope(string $code): ?PriceUpdateScopeInterface
            {
                return $this->fScopes[$code] ?? null;
            }

            public function callHandlePost(): void
            {
                $this->handlePost();
            }

            public function callPrepareNotice(): void
            {
                $this->prepareNotice();
            }

            public function callPrepareSelection(): void
            {
                $this->prepareSelection();
            }

            public function callPrepareExtraFilters(string $scopeCode): void
            {
                $this->prepareExtraFilters($scopeCode);
            }
        };
    }

    private function registerTarget(string $code, PriceBookInterface $book, bool $available = true): void
    {
        PriceUpdateTargetRegistry::register(new class($code, $book, $available) implements PriceUpdateTargetInterface {
            public function __construct(
                private readonly string $fCode,
                private readonly PriceBookInterface $fBook,
                private readonly bool $fAvailable,
            ) {
            }

            public function code(): string
            {
                return $this->fCode;
            }

            public function label(): string
            {
                return ucfirst($this->fCode);
            }

            public function isAvailable(): bool
            {
                return $this->fAvailable;
            }

            public function keyField(): string
            {
                return 'clave';
            }

            public function keyLabel(): string
            {
                return 'clave';
            }

            public function keyOptions(): array
            {
                return [['value' => 'K', 'label' => 'Clave K']];
            }

            public function keyFromFilters(array $filters): ?string
            {
                $value = trim((string) ($filters['clave'] ?? ''));

                return $value === '' ? null : $value;
            }

            public function bookFor(string $scope, string $key): ?PriceBookInterface
            {
                return $this->fBook;
            }
        });
    }

    /** @param list<array{string, string}> $rows */
    private function fakeScope(array $rows): object
    {
        return new class($rows) implements PriceUpdateScopeInterface {
            /** @var array<string, mixed> */
            public array $lastFilters = [];

            public int $matchCalls = 0;

            public function __construct(private readonly array $fRows)
            {
            }

            public function code(): string
            {
                return 'articulos';
            }

            public function label(): string
            {
                return 'Artículos';
            }

            public function keyLabel(): string
            {
                return 'Referencia';
            }

            public function match(array $filters): array
            {
                $this->matchCalls++;
                $this->lastFilters = $filters;

                return array_map(static fn (array $r): array => ['key' => $r[0], 'label' => $r[1]], $this->fRows);
            }

            public function canonicalBook(string $key): ?PriceBookInterface
            {
                return null;
            }
        };
    }

    /** @param array<string, float> $prices */
    private function fakeBook(array $prices): object
    {
        return new class($prices) implements PriceBookInterface {
            /** @var list<array{string, float}> */
            public array $persisted = [];

            public function __construct(private readonly array $fPrices)
            {
            }

            public function currentPrices(array $keys): array
            {
                return array_intersect_key($this->fPrices, array_flip($keys));
            }

            public function persist(string $key, float $price): bool
            {
                $this->persisted[] = [$key, $price];

                return true;
            }
        };
    }

    /**
     * @param list<string> $keys
     * @param array<string, mixed> $context
     */
    private function consumerFilter(array $keys, array $context = [], ?\Closure $keep = null): PriceUpdateFilterInterface
    {
        return new class($keys, $context, $keep) implements PriceUpdateFilterInterface {
            public function __construct(
                private readonly array $fKeys,
                private readonly array $fContext,
                private readonly ?\Closure $fKeep,
            ) {
            }

            public function supports(string $scope): bool
            {
                return $scope === 'articulos';
            }

            public function keys(string $scope): array
            {
                return $this->fKeys;
            }

            public function viewContext(string $scope): array
            {
                return $this->fContext;
            }

            public function narrow(string $scope, array $filters, array $rows): array
            {
                if ($this->fKeep === null) {
                    return $rows;
                }

                return array_values(array_filter($rows, fn (array $r): bool => ($this->fKeep)($filters, $r)));
            }
        };
    }
}
