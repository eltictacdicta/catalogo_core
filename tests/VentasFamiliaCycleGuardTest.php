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
use Symfony\Component\HttpFoundation\Request;

/**
 * Task 3.3 — the base-family save path rejects a re-parent that would close a
 * cycle (R-FJ-001 cycle scenario) and leaves the `familias` row unmodified.
 *
 * The tree guard itself is covered by FamiliaTreeGuardTest; this test proves
 * the controller invokes it BEFORE writing and surfaces the error.
 */
final class VentasFamiliaCycleGuardTest extends TestCase
{
    private const CONTROLLER = '/plugins/catalogo_core/Controller/VentasFamilia.php';

    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
        require_once FS_FOLDER . self::CONTROLLER;
    }

    public function test_cycle_post_is_rejected_and_the_row_is_not_modified(): void
    {
        $family = $this->fakeFamily('F1', 'OLD');
        $controller = $this->makeController($family, throwOnCycle: true);
        $controller->request = $this->reParentRequest('F1', 'F2');

        $this->invoke($controller, 'editarFamilia', $controller->request);

        $this->assertNotEmpty($controller->errors, 'The cycle must surface a user-facing error');
        $this->assertStringContainsString('cycle', strtolower(implode(' ', $controller->errors)));
        $this->assertFalse($family->saveCalled, 'No familias row may be written on a rejected cycle');
        $this->assertSame('OLD', $family->madre, 'The in-memory row must stay unmodified too');
        $this->assertSame([], $controller->messages);
    }

    public function test_self_parent_is_rejected(): void
    {
        $family = $this->fakeFamily('F1', 'OLD');
        $controller = $this->makeController($family, throwOnCycle: true);
        $controller->request = $this->reParentRequest('F1', 'F1');

        $this->invoke($controller, 'editarFamilia', $controller->request);

        $this->assertFalse($family->saveCalled);
        $this->assertSame('OLD', $family->madre);
    }

    public function test_valid_re_parent_saves_the_new_mother(): void
    {
        $family = $this->fakeFamily('F1', 'OLD');
        $controller = $this->makeController($family, throwOnCycle: false);
        $controller->request = $this->reParentRequest('F1', 'F2');

        $this->invoke($controller, 'editarFamilia', $controller->request);

        $this->assertTrue($family->saveCalled, 'A valid re-parent must persist');
        $this->assertSame('F2', $family->madre);
        $this->assertSame([], $controller->errors);
        $this->assertNotEmpty($controller->messages);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function fakeFamily(string $codfamilia, ?string $madre): object
    {
        return new class($codfamilia, $madre) extends \FSFramework\model\familia {
            public bool $saveCalled = false;
            public function __construct(string $codfamilia = '', ?string $madre = null)
            {
                $this->codfamilia = $codfamilia;
                $this->descripcion = '';
                $this->madre = $madre;
                $this->nivel = '';
            }
            public function save()
            {
                $this->saveCalled = true;

                return true;
            }
        };
    }

    private function makeController(object $family, bool $throwOnCycle): object
    {
        $model = new class($family) extends \FSFramework\model\familia {
            private object $family;
            public function __construct(object $family) { $this->family = $family; }
            public function get($cod) { return $this->family; }
            public function madres() { return []; }
        };

        $controller = new class($throwOnCycle) extends \FSFramework\Plugins\catalogo_core\Controller\VentasFamilia {
            public array $errors = [];
            public array $messages = [];
            public bool $throwOnCycle;

            public function __construct(bool $throwOnCycle = false)
            {
                $this->throwOnCycle = $throwOnCycle;
            }

            public function new_error_msg(string $msg): void
            {
                $this->errors[] = $msg;
            }

            public function new_message(string $msg): void
            {
                $this->messages[] = $msg;
            }

            protected function validateFormToken(): bool
            {
                return true;
            }

            protected function assertFamilyAcyclic(string $codfamilia, string $newMadre): void
            {
                if ($this->throwOnCycle) {
                    throw new \RuntimeException(
                        'Family hierarchy cycle detected: "' . $codfamilia
                        . '" cannot become a child of "' . $newMadre . '".'
                    );
                }
            }
        };

        $controller->familia = $model;

        return $controller;
    }

    private function reParentRequest(string $codfamilia, string $madre): Request
    {
        return Request::create('/index.php?page=ventas_familia', 'POST', [
            'scodfamilia' => $codfamilia,
            'sdescripcion' => 'Familia ' . $codfamilia,
            'smadre' => $madre,
        ]);
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invoke($target, ...$args);
    }
}
