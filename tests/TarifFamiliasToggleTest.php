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
 * DB-free toggle contract for the moved tarif_familias controller
 * (spec: "Toggle state management" — en_catalogo / en_tarifa / activa AJAX
 * toggles answering structured arrays; process_action json_encodes them,
 * guaranteeing JSON-only responses with no HTML).
 *
 * Row-level persistence of the flip needs a real database and is deferred to
 * the verify-phase smoke per design.
 */
final class TarifFamiliasToggleTest extends TestCase
{
    private const CONTROLLER_PATH = '/plugins/catalogo_core/controller/tarif_familias.php';

    /** @var mixed */
    private $postBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        parent::tearDown();
    }

    private function loadControllerClass(): void
    {
        if (class_exists('tarif_familias', false)) {
            return;
        }

        if (!is_file(FS_FOLDER . self::CONTROLLER_PATH)) {
            self::fail('missing catalogo_core path: plugins/catalogo_core/controller/tarif_familias.php (moved controller not present)');
        }

        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . self::CONTROLLER_PATH;
    }

    private function makeController()
    {
        $this->loadControllerClass();

        return new class extends \tarif_familias {
            public function __construct()
            {
            }
        };
    }

    /**
     * Fake tarifa_familia model: get() returns the fake itself (the fixture)
     * when the familia exists, null otherwise; save() honours saveResult.
     */
    private function makeFakeModel()
    {
        return new class {
            public bool $exists = true;
            public bool $saveResult = true;
            /** @var bool */
            public $en_catalogo = false;
            /** @var bool */
            public $en_tarifa = false;
            /** @var bool */
            public $activa = false;

            public function get($codtarifa, $codfamilia)
            {
                return $this->exists ? $this : null;
            }

            public function save(): bool
            {
                return $this->saveResult;
            }
        };
    }

    private function invokeToggle(object $controller, string $method)
    {
        $ref = new \ReflectionMethod($controller, $method);
        $ref->setAccessible(true);

        return $ref->invoke($controller);
    }

    private function withPostCodFamilia(string $codfamilia): void
    {
        $_POST = ['codfamilia' => $codfamilia];
    }

    public function test_toggle_catalogo_rejects_empty_and_unknown_familia(): void
    {
        $controller = $this->makeController();
        $controller->codtarifa = 'T1';
        $controller->tarifa_familia = $this->makeFakeModel();

        $this->withPostCodFamilia('');
        $result = $this->invokeToggle($controller, 'ajax_toggle_catalogo');
        $this->assertIsArray($result, 'Responses are structured arrays — no HTML');
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);

        $controller->tarifa_familia->exists = false;
        $this->withPostCodFamilia('NOPE');
        $result = $this->invokeToggle($controller, 'ajax_toggle_catalogo');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_toggle_catalogo_flips_value_on_save_success(): void
    {
        $controller = $this->makeController();
        $controller->codtarifa = 'T1';
        $controller->tarifa_familia = $this->makeFakeModel();

        $this->withPostCodFamilia('FAM001');
        $result = $this->invokeToggle($controller, 'ajax_toggle_catalogo');
        $this->assertTrue($result['success']);
        $this->assertTrue($result['en_catalogo']);
        $this->assertTrue($controller->tarifa_familia->en_catalogo, 'Fake fixture must carry the flipped value');

        $controller->tarifa_familia->en_catalogo = true;
        $result = $this->invokeToggle($controller, 'ajax_toggle_catalogo');
        $this->assertTrue($result['success']);
        $this->assertFalse($result['en_catalogo'], 'Second flip returns false (triangulation)');
    }

    public function test_toggle_catalogo_returns_failure_on_save_error(): void
    {
        $controller = $this->makeController();
        $controller->codtarifa = 'T1';
        $controller->tarifa_familia = $this->makeFakeModel();
        $controller->tarifa_familia->saveResult = false;

        $this->withPostCodFamilia('FAM001');
        $result = $this->invokeToggle($controller, 'ajax_toggle_catalogo');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_toggle_en_tarifa_rejects_empty_and_unknown_familia(): void
    {
        $controller = $this->makeController();
        $controller->codtarifa = 'T1';
        $controller->tarifa_familia = $this->makeFakeModel();

        $this->withPostCodFamilia('');
        $result = $this->invokeToggle($controller, 'ajax_toggle_en_tarifa');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);

        $controller->tarifa_familia->exists = false;
        $this->withPostCodFamilia('NOPE');
        $result = $this->invokeToggle($controller, 'ajax_toggle_en_tarifa');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_toggle_en_tarifa_flips_value_on_save_success(): void
    {
        $controller = $this->makeController();
        $controller->codtarifa = 'T1';
        $controller->tarifa_familia = $this->makeFakeModel();

        $this->withPostCodFamilia('FAM001');
        $result = $this->invokeToggle($controller, 'ajax_toggle_en_tarifa');
        $this->assertTrue($result['success']);
        $this->assertTrue($result['en_tarifa']);

        $controller->tarifa_familia->en_tarifa = true;
        $result = $this->invokeToggle($controller, 'ajax_toggle_en_tarifa');
        $this->assertTrue($result['success']);
        $this->assertFalse($result['en_tarifa']);
    }

    public function test_toggle_en_tarifa_returns_failure_on_save_error(): void
    {
        $controller = $this->makeController();
        $controller->codtarifa = 'T1';
        $controller->tarifa_familia = $this->makeFakeModel();
        $controller->tarifa_familia->saveResult = false;

        $this->withPostCodFamilia('FAM001');
        $result = $this->invokeToggle($controller, 'ajax_toggle_en_tarifa');
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_toggle_activa_rejects_empty_and_unknown_familia(): void
    {
        $controller = $this->makeController();
        $controller->codtarifa = 'T1';
        $controller->tarifa_familia = $this->makeFakeModel();

        $this->withPostCodFamilia('');
        $result = $this->invokeToggle($controller, 'ajax_toggle_activa');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);

        $controller->tarifa_familia->exists = false;
        $this->withPostCodFamilia('NOPE');
        $result = $this->invokeToggle($controller, 'ajax_toggle_activa');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_toggle_activa_flips_value_on_save_success(): void
    {
        $controller = $this->makeController();
        $controller->codtarifa = 'T1';
        $controller->tarifa_familia = $this->makeFakeModel();

        $this->withPostCodFamilia('FAM001');
        $result = $this->invokeToggle($controller, 'ajax_toggle_activa');
        $this->assertTrue($result['success']);
        $this->assertTrue($result['activa']);

        $controller->tarifa_familia->activa = true;
        $result = $this->invokeToggle($controller, 'ajax_toggle_activa');
        $this->assertTrue($result['success']);
        $this->assertFalse($result['activa']);
    }

    public function test_toggle_activa_returns_failure_on_save_error(): void
    {
        $controller = $this->makeController();
        $controller->codtarifa = 'T1';
        $controller->tarifa_familia = $this->makeFakeModel();
        $controller->tarifa_familia->saveResult = false;

        $this->withPostCodFamilia('FAM001');
        $result = $this->invokeToggle($controller, 'ajax_toggle_activa');
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_process_action_json_encodes_toggle_actions(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_PATH);
        foreach (['ajax_toggle_catalogo', 'ajax_toggle_en_tarifa', 'ajax_toggle_activa'] as $toggle) {
            $this->assertStringContainsString(
                'echo json_encode($this->' . $toggle . '())',
                $src,
                $toggle . ' must be JSON-encoded by process_action — the no-HTML guarantee'
            );
        }
    }
}
