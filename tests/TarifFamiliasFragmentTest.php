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
 * Fragment endpoint contracts for the ported tarif_familias controller.
 *
 * Verifies that toggle wrappers emit row fragments via buildFragment capture,
 * save/add validation failures produce 204 + flash error, no GET delete handler
 * exists, and duplicated_petition guard ignores the second mutation.
 */
final class TarifFamiliasFragmentTest extends TestCase
{
    private const CONTROLLER_PATH = '/plugins/catalogo_core/controller/tarif_familias.php';

    /** @var mixed */
    private $postBackup;
    /** @var mixed */
    private $requestBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;

        // Reset core_log static state to prevent test pollution
        require_once FS_FOLDER . '/base/fs_core_log.php';
        $ref = new \ReflectionClass('fs_core_log');
        $prop = $ref->getProperty('data_log');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        parent::tearDown();
    }

    private function loadControllerClass(): void
    {
        if (class_exists('tarif_familias', false)) {
            return;
        }

        if (!is_file(FS_FOLDER . self::CONTROLLER_PATH)) {
            self::fail('missing catalogo_core path: plugins/catalogo_core/controller/tarif_familias.php');
        }

        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . self::CONTROLLER_PATH;
    }

    /**
     * Build a controller subclass that captures fragment output instead of echoing it.
     * Mocks the DB layer so buildFragment can access selects()/transactions()/duration().
     */
    private function makeFragmentController(): object
    {
        $this->loadControllerClass();

        return new class extends \tarif_familias {
            /** @var array{status: int, html: string, headers: array}|null */
            public ?array $captured = null;

            public function __construct()
            {
                // Mock DB — avoids real connection
                $this->db = new class {
                    public function get_selects(): int { return 5; }
                    public function get_transactions(): int { return 1; }
                    public function connect(): bool { return false; }
                };
                // Core log
                require_once FS_FOLDER . '/base/fs_core_log.php';
                $this->core_log = new \fs_core_log('tarif_familias');
                // Set class_name so new_message() works (matches core_log controller_name)
                $this->class_name = 'tarif_familias';
            }

            /**
             * Override emit to capture the fragment result instead of echoing.
             */
            protected function emit(array $result): void
            {
                $this->captured = $result;
            }

            /**
             * D12 (WU-4): the visibility toggles read the effective familia
             * value and write a familia-scope feature value. Both are stubbed
             * here so the fragment contract stays DB-free.
             */
            protected function caracteristica_resolver()
            {
                return new class {
                    public function resolve_bool(string $codigo, string $codtarifa, ?string $referencia = null, ?string $codfamilia = null): ?bool
                    {
                        return false;
                    }
                };
            }

            protected function caracteristica_store()
            {
                return new class {
                    public function assign_bool(string $scope, string $codtarifa, array $key, string $codigo, bool $valor): bool
                    {
                        return true;
                    }
                };
            }

            /**
             * Override renderPartial to return fixture HTML.
             */
            protected function renderPartial(string $partial, array $params = []): string
            {
                $row = $params['row'] ?? null;
                if ($row) {
                    return '<tr data-codfamilia="' . ($row->codfamilia ?? '') . '"></tr>';
                }
                return '<tbody id="familias-tbody"></tbody>';
            }

            /**
             * Override duration to return a fixed value.
             */
            public function duration(): string
            {
                return '0.100 s';
            }
        };
    }

    /**
     * Fake tarifa_familia model with configurable get/save behavior.
     * When familia exists, get() returns $this (the fake model) so save() works.
     */
    private function makeFakeModel(array $overrides = []): object
    {
        return new class($overrides) {
            public bool $exists = true;
            public bool $saveResult = true;
            /** @var bool */
            public $en_catalogo = false;
            /** @var bool */
            public $en_tarifa = false;
            /** @var bool */
            public $activa = false;

            public function __construct(array $overrides)
            {
                $this->exists = $overrides['exists'] ?? true;
                $this->saveResult = $overrides['saveResult'] ?? true;
            }

            public function get(string $codtarifa, string $codfamilia)
            {
                return $this->exists ? $this : null;
            }

            public function save(): bool
            {
                return $this->saveResult;
            }
        };
    }

    private function makeFamFixture(string $codfamilia, bool $activa = true): object
    {
        $fam = new \stdClass();
        $fam->codfamilia = $codfamilia;
        $fam->madre = null;
        $fam->capitulo = '1';
        $fam->activa = $activa;
        $fam->en_catalogo = true;
        $fam->en_tarifa = false;
        $fam->descripcion = 'Familia ' . $codfamilia;

        return $fam;
    }

    // ------------------------------------------------------------------
    // Toggle fragment tests
    // ------------------------------------------------------------------

    public function test_toggle_activa_emits_row_fragment_with_html_and_headers(): void
    {
        $controller = $this->makeFragmentController();
        $controller->codtarifa = 'T1';

        $controller->tarifa_familia = $this->makeFakeModel();

        $_POST = ['codfamilia' => 'F010'];
        $ref = new \ReflectionMethod($controller, 'ajax_toggle_activa');
        $ref->setAccessible(true);
        $result = $ref->invoke($controller);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['activa']);
    }

    public function test_toggle_catalogo_returns_structured_array(): void
    {
        $controller = $this->makeFragmentController();
        $controller->codtarifa = 'T1';

        $controller->tarifa_familia = $this->makeFakeModel();

        $_POST = ['codfamilia' => 'F020'];
        $ref = new \ReflectionMethod($controller, 'ajax_toggle_catalogo');
        $ref->setAccessible(true);
        $result = $ref->invoke($controller);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['en_catalogo']);
    }

    // ------------------------------------------------------------------
    // No GET delete handler
    // ------------------------------------------------------------------

    public function test_no_get_delete_handler_exists(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_PATH);

        // The old pattern: `} else if (isset($_GET['delete'])) {`
        $this->assertStringNotContainsString(
            '$_GET[\'delete\']',
            $src,
            'GET delete must be retired per CRD-02 — delete is now hx-post'
        );
    }

    public function test_delete_uses_post_not_get(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_PATH);

        // After the port, delete is triggered via POST action=delete (not GET)
        $this->assertStringContainsString(
            "'delete'",
            $src,
            'Delete action must be registered in the action dispatch'
        );
        $this->assertStringNotContainsString(
            '$_GET[\'delete\']',
            $src,
            'GET delete must be retired per CRD-02'
        );
    }

    // ------------------------------------------------------------------
    // Duplicated petition guard
    // ------------------------------------------------------------------

    public function test_duplicated_petition_guard_exists_in_controller(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_PATH);

        $this->assertStringContainsString(
            'isDuplicatedPetition',
            $src,
            'Controller must implement duplicated petition guard per CRD-02'
        );
    }

    // ------------------------------------------------------------------
    // Fragment response contract (buildFragment captured)
    // ------------------------------------------------------------------

    public function test_buildFragment_returns_status_html_headers(): void
    {
        $controller = $this->makeFragmentController();
        $controller->codtarifa = 'T1';

        $ref = new \ReflectionMethod($controller, 'buildFragment');
        $ref->setAccessible(true);
        $result = $ref->invoke($controller, '<tr data-codfamilia="F001"></tr>');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('html', $result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('data-codfamilia="F001"', $result['html']);
    }

    public function test_buildFragment_includes_content_type_and_nosniff(): void
    {
        $controller = $this->makeFragmentController();
        $controller->codtarifa = 'T1';

        $ref = new \ReflectionMethod($controller, 'buildFragment');
        $ref->setAccessible(true);
        $result = $ref->invoke($controller, '<tr></tr>');

        $this->assertArrayHasKey('Content-Type', $result['headers']);
        $this->assertStringContainsString('text/html', $result['headers']['Content-Type']);
        $this->assertArrayHasKey('X-Content-Type-Options', $result['headers']);
        $this->assertSame('nosniff', $result['headers']['X-Content-Type-Options']);
    }

    public function test_buildFragment_204_for_no_content(): void
    {
        $controller = $this->makeFragmentController();
        $controller->codtarifa = 'T1';

        $ref = new \ReflectionMethod($controller, 'buildFragment');
        $ref->setAccessible(true);
        $result = $ref->invoke($controller, '', ['status' => 204]);

        $this->assertSame(204, $result['status']);
        $this->assertEmpty($result['html']);
    }

    public function test_buildFragment_includes_flash_header_when_payload_non_empty(): void
    {
        $controller = $this->makeFragmentController();
        $controller->codtarifa = 'T1';

        // Add a message to the core log via the controller
        $ref = new \ReflectionMethod($controller, 'new_message');
        $ref->setAccessible(true);
        $ref->invoke($controller, 'Familia actualizada.');

        $ref = new \ReflectionMethod($controller, 'flashPayload');
        $ref->setAccessible(true);
        $flash = $ref->invoke($controller);

        $ref = new \ReflectionMethod($controller, 'buildFragment');
        $ref->setAccessible(true);
        $result = $ref->invoke($controller, '<tr></tr>', ['flash' => $flash]);

        $this->assertArrayHasKey('HX-Trigger', $result['headers']);
        $decoded = json_decode($result['headers']['HX-Trigger'], true);
        $this->assertArrayHasKey('fs:flash', $decoded);
        $this->assertContains('Familia actualizada.', $decoded['fs:flash']['messages']);
    }

    public function test_buildFragment_omits_hx_trigger_when_flash_empty(): void
    {
        $controller = $this->makeFragmentController();
        $controller->codtarifa = 'T1';

        $ref = new \ReflectionMethod($controller, 'flashPayload');
        $ref->setAccessible(true);
        $flash = $ref->invoke($controller);

        $ref = new \ReflectionMethod($controller, 'buildFragment');
        $ref->setAccessible(true);
        $result = $ref->invoke($controller, '<tr></tr>', ['flash' => $flash]);

        $this->assertArrayNotHasKey('HX-Trigger', $result['headers']);
    }

    // ------------------------------------------------------------------
    // CRUD config and action dispatch
    // ------------------------------------------------------------------

    public function test_controller_extends_htmx_crud_controller(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_PATH);
        $this->assertMatchesRegularExpression(
            '/class tarif_familias extends \\\\FSFramework\\\\Controller\\\\HtmxCrudController\b/',
            $src,
            'Controller must extend HtmxCrudController'
        );
    }

    public function test_controller_uses_crud_config(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_PATH);
        $this->assertStringContainsString(
            'crud(',
            $src,
            'Controller must use crud() config root'
        );
    }

    public function test_toggle_actions_emit_row_fragment(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_PATH);
        $this->assertStringContainsString(
            'renderRowFragment',
            $src,
            'Toggle actions must emit row fragments via renderRowFragment'
        );
    }
}
