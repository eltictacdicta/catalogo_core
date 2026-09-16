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
 * WU-4 / D3: `activa` keeps persisting on the per-tarifa family row while
 * `en_catalogo`/`en_tarifa` persist as **familia-scope feature values** through
 * the feature store, never on the `tarif_tarifa_familia` visibility columns.
 * The JSON shape is unchanged. Persistence is exercised through the store seam;
 * the live row-level write is deferred to the verify-phase smoke per design.
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

    private function makeController(?object $resolver = null, ?object $store = null)
    {
        $this->loadControllerClass();

        return new class($resolver, $store) extends \tarif_familias {
            public function __construct(private ?object $resolver, private ?object $store)
            {
            }

            protected function caracteristica_resolver()
            {
                return $this->resolver;
            }

            protected function caracteristica_store()
            {
                return $this->store;
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

    /**
     * Fake feature store recording every familia-scope assignment.
     */
    private function makeFakeStore(bool $result = true): object
    {
        return new class($result) {
            /** @var list<array{0: string, 1: string, 2: array<string, mixed>, 3: string, 4: bool}> */
            public array $assignments = [];

            public function __construct(public bool $result)
            {
            }

            public function assign_bool(string $scope, string $codtarifa, array $key, string $codigo, bool $valor): bool
            {
                $this->assignments[] = [$scope, $codtarifa, $key, $codigo, $valor];

                return $this->result;
            }
        };
    }

    /**
     * Fake resolver returning the current effective familia value per codigo.
     */
    private function makeFakeResolver(array $current = []): object
    {
        return new class($current) {
            /** @var list<array{0: string, 1: string, 2: string}> */
            public array $reads = [];

            /** @param array<string, bool> $current */
            public function __construct(private array $current)
            {
            }

            public function resolve_bool(string $codigo, string $codtarifa, ?string $referencia = null, ?string $codfamilia = null): ?bool
            {
                $this->reads[] = [$codigo, $codtarifa, (string) $codfamilia];

                return $this->current[$codigo] ?? false;
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
        $store = $this->makeFakeStore();
        $controller = $this->makeController($this->makeFakeResolver(), $store);
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

        $this->assertSame([], $store->assignments, 'no feature value may be written for a rejected request');
    }

    public function test_toggle_catalogo_flips_value_on_save_success(): void
    {
        $store = $this->makeFakeStore();
        $controller = $this->makeController($this->makeFakeResolver(['en_catalogo' => false]), $store);
        $controller->codtarifa = 'T1';
        $controller->tarifa_familia = $this->makeFakeModel();

        $this->withPostCodFamilia('FAM001');
        $result = $this->invokeToggle($controller, 'ajax_toggle_catalogo');
        $this->assertTrue($result['success']);
        $this->assertTrue($result['en_catalogo']);
        $this->assertSame(
            [['familia', 'T1', ['codfamilia' => 'FAM001'], 'en_catalogo', true]],
            $store->assignments,
            'the flip must persist as a familia-scope feature value for the current tarifa'
        );

        // Triangulation: a second flip of an already-TRUE value returns FALSE.
        $storeTrue = $this->makeFakeStore();
        $controller2 = $this->makeController($this->makeFakeResolver(['en_catalogo' => true]), $storeTrue);
        $controller2->codtarifa = 'T1';
        $controller2->tarifa_familia = $this->makeFakeModel();

        $result = $this->invokeToggle($controller2, 'ajax_toggle_catalogo');
        $this->assertTrue($result['success']);
        $this->assertFalse($result['en_catalogo'], 'Second flip returns false (triangulation)');
        $this->assertFalse($storeTrue->assignments[0][4], 'the stored value must be the flipped one');
    }

    public function test_toggle_catalogo_returns_failure_on_save_error(): void
    {
        $controller = $this->makeController($this->makeFakeResolver(), $this->makeFakeStore(false));
        $controller->codtarifa = 'T1';
        $controller->tarifa_familia = $this->makeFakeModel();

        $this->withPostCodFamilia('FAM001');
        $result = $this->invokeToggle($controller, 'ajax_toggle_catalogo');
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_toggle_en_tarifa_rejects_empty_and_unknown_familia(): void
    {
        $store = $this->makeFakeStore();
        $controller = $this->makeController($this->makeFakeResolver(), $store);
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

        $this->assertSame([], $store->assignments);
    }

    public function test_toggle_en_tarifa_flips_value_on_save_success(): void
    {
        $store = $this->makeFakeStore();
        $controller = $this->makeController($this->makeFakeResolver(['en_tarifa' => false]), $store);
        $controller->codtarifa = 'T1';
        $controller->tarifa_familia = $this->makeFakeModel();

        $this->withPostCodFamilia('FAM001');
        $result = $this->invokeToggle($controller, 'ajax_toggle_en_tarifa');
        $this->assertTrue($result['success']);
        $this->assertTrue($result['en_tarifa']);
        $this->assertSame('en_tarifa', $store->assignments[0][3]);
        $this->assertTrue($store->assignments[0][4]);
    }

    public function test_toggle_en_tarifa_returns_failure_on_save_error(): void
    {
        $controller = $this->makeController($this->makeFakeResolver(), $this->makeFakeStore(false));
        $controller->codtarifa = 'T1';
        $controller->tarifa_familia = $this->makeFakeModel();

        $this->withPostCodFamilia('FAM001');
        $result = $this->invokeToggle($controller, 'ajax_toggle_en_tarifa');
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_visibility_toggles_never_write_the_legacy_family_columns(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_PATH);

        $visibility = $this->methodBody($src, 'ajax_toggle_familia_visibility');
        $this->assertNotSame('', $visibility, 'ajax_toggle_familia_visibility() body must be found');
        $this->assertStringContainsString('assign_bool(', $visibility, 'the flip must go through the feature store');
        $this->assertStringContainsString('resolve_bool(', $visibility, 'the current value must be resolved');
        $this->assertStringNotContainsString('->en_catalogo =', $visibility, 'no legacy column write');
        $this->assertStringNotContainsString('->en_tarifa =', $visibility, 'no legacy column write');
        $this->assertStringNotContainsString('$fam->save()', $visibility, 'the family row must not be saved by the visibility toggle');
    }

    public function test_toggle_activa_rejects_empty_and_unknown_familia(): void
    {
        $controller = $this->makeController($this->makeFakeResolver(), $this->makeFakeStore());
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
        $controller = $this->makeController($this->makeFakeResolver(), $this->makeFakeStore());
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
        $controller = $this->makeController($this->makeFakeResolver(), $this->makeFakeStore());
        $controller->codtarifa = 'T1';
        $controller->tarifa_familia = $this->makeFakeModel();
        $controller->tarifa_familia->saveResult = false;

        $this->withPostCodFamilia('FAM001');
        $result = $this->invokeToggle($controller, 'ajax_toggle_activa');
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_process_action_dispatches_toggle_actions(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_PATH);
        foreach (['toggle_catalogo', 'toggle_en_tarifa', 'toggle_activa'] as $toggle) {
            $this->assertStringContainsString(
                "case '" . $toggle . "'",
                $src,
                $toggle . ' must be registered in the action dispatch switch'
            );
        }
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
}
