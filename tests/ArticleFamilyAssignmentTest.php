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

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent;
use FSFramework\Plugins\catalogo_core\Services\CatalogoOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Task 3.4 — per-list article-to-family assignment on the article page
 * (R-FJ-003). Flag-off is inert, saves upsert by (codlista, referencia), and
 * the neutral permission filter gates the write.
 */
final class ArticleFamilyAssignmentTest extends TestCase
{
    private const CONTROLLER = '/plugins/catalogo_core/Controller/VentasArticulo.php';
    private const VIEW = '/plugins/catalogo_core/View/ventas_articulo.html.twig';

    protected function setUp(): void
    {
        parent::setUp();
        unset($GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE]);
        FSEventDispatcher::reset();

        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_familia_estructura.php';
        require_once FS_FOLDER . self::CONTROLLER;

        // The controller keeps legacy global type hints; register the aliases
        // so injected namespaced fakes satisfy them without a database.
        \fs_model_autoloader::ensureGlobalAlias('articulo');
        \fs_model_autoloader::ensureGlobalAlias('familia');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE]);
        FSEventDispatcher::reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Loading
    // ------------------------------------------------------------------

    public function test_load_is_inert_when_flag_off(): void
    {
        $listaModel = new class extends \FSFramework\model\catalogo_lista_precio {
            public function __construct() {}
            public function all() { throw new \LogicException('flag off must not query lists'); }
        };
        $asignacionModel = new class extends \FSFramework\model\catalogo_articulo_familia_estructura {
            public function __construct() {}
            public function get($codlista, $referencia) { throw new \LogicException('flag off must not query assignments'); }
        };

        $controller = $this->makeController($listaModel, $asignacionModel);
        $controller->request = Request::create('/index.php?page=ventas_articulo', 'GET');

        $this->invoke($controller, 'loadAsignacionFamilias');

        $this->assertFalse($controller->family_structure);
        $this->assertSame('', $controller->codlista_asignacion);
        $this->assertSame([], $controller->listas_estructura);
        $this->assertSame([], $controller->familias_asignables);
        $this->assertNull($controller->asignacion_actual);
    }

    public function test_assignment_is_scoped_to_the_selected_list(): void
    {
        $GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE] = 'true';

        $listaModel = $this->listaModel();
        $store = new \ArrayObject();
        $asignacionModel = $this->assignmentStore($store);
        $store['L1|REF1'] = $this->assignmentRow('L1', 'REF1', 'F1');

        $controller = $this->makeController($listaModel, $asignacionModel);
        $controller->articulo = $this->article('REF1');

        // L1 sees the assignment…
        $controller->request = Request::create('/index.php?page=ventas_articulo&codlista=L1', 'GET');
        $this->invoke($controller, 'loadAsignacionFamilias');
        $this->assertSame(['L1', 'L2'], array_column($controller->listas_estructura, 'codlista'), 'Inactive lists are excluded');
        $this->assertSame('L1', $controller->codlista_asignacion);
        $this->assertNotNull($controller->asignacion_actual);
        $this->assertSame('F1', $controller->asignacion_actual->codfamilia);
        $this->assertNotEmpty($controller->familias_asignables);

        // …L2 does not (R-FJ-003 scenario 2: list scope).
        $controller->request = Request::create('/index.php?page=ventas_articulo&codlista=L2', 'GET');
        $this->invoke($controller, 'loadAsignacionFamilias');
        $this->assertSame('L2', $controller->codlista_asignacion);
        $this->assertNull($controller->asignacion_actual, 'The L1 assignment must not leak into L2');
    }

    public function test_posted_list_is_kept_when_the_query_has_no_codlista(): void
    {
        $GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE] = 'true';

        $store = new \ArrayObject();
        $store['L2|REF1'] = $this->assignmentRow('L2', 'REF1', 'F2');

        $controller = $this->makeController($this->listaModel(), $this->assignmentStore($store));
        $controller->articulo = $this->article('REF1');
        $controller->request = Request::create('/index.php?page=ventas_articulo', 'POST', [
            'asignacion_codlista' => 'L2',
        ]);

        $this->invoke($controller, 'loadAsignacionFamilias');

        $this->assertSame(
            'L2',
            $controller->codlista_asignacion,
            'The posted list must survive the save instead of falling back to the default'
        );
        $this->assertNotNull($controller->asignacion_actual);
        $this->assertSame('F2', $controller->asignacion_actual->codfamilia);
    }

    // ------------------------------------------------------------------
    // Saving
    // ------------------------------------------------------------------

    public function test_save_is_inert_when_flag_off_and_writes_nothing(): void
    {
        $asignacionModel = new class extends \FSFramework\model\catalogo_articulo_familia_estructura {
            public function __construct() {}
            public function get($codlista, $referencia) { throw new \LogicException('flag off must not read'); }
            public function save() { throw new \LogicException('flag off must not write'); }
        };

        $controller = $this->makeController($this->listaModel(), $asignacionModel);
        $controller->request = $this->assignmentRequest('L1', 'F1');
        $controller->user = (object) ['nick' => 'admin'];

        $this->invoke($controller, 'guardarAsignacionFamilia', $controller->request);

        $this->assertNotEmpty($controller->errors);
        $this->assertSame([], $controller->messages);
    }

    public function test_save_resolves_active_lists_when_controller_state_is_empty(): void
    {
        $GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE] = 'true';

        $store = new \ArrayObject();
        $controller = $this->makeController($this->listaModel(), $this->assignmentStore($store));
        $controller->family_structure = true;
        $controller->articulo = $this->article('REF1');
        $controller->user = (object) ['nick' => 'admin'];

        // Regression: privateCore() dispatches the save BEFORE loadCatalogData()
        // populates the derived state, so a real POST reaches the handler with
        // listas_estructura still empty.
        $this->assertSame([], $controller->listas_estructura);

        $controller->request = $this->assignmentRequest('L1', 'F1');
        $this->invoke($controller, 'guardarAsignacionFamilia', $controller->request);

        $this->assertSame([], $controller->errors);
        $this->assertArrayHasKey('L1|REF1', $store);
        $this->assertSame('F1', $store['L1|REF1']->codfamilia);
    }

    public function test_save_rejects_unknown_or_inactive_list(): void
    {
        $GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE] = 'true';

        $store = new \ArrayObject();
        $controller = $this->makeController($this->listaModel(), $this->assignmentStore($store));
        $controller->family_structure = true;
        $controller->articulo = $this->article('REF1');
        $controller->user = (object) ['nick' => 'admin'];

        foreach (['L9', 'L3'] as $codlista) {
            $controller->request = $this->assignmentRequest($codlista, 'F1');
            $this->invoke($controller, 'guardarAsignacionFamilia', $controller->request);
            $this->assertContains('Artículo o lista de precios no válida.', $controller->errors);
        }

        $this->assertCount(0, $store, 'Unknown or inactive lists must not persist anything');
    }

    public function test_save_upserts_one_row_per_list(): void
    {
        $GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE] = 'true';

        $store = new \ArrayObject();
        $asignacionModel = $this->assignmentStore($store);
        $controller = $this->makeController($this->listaModel(), $asignacionModel);
        $controller->family_structure = true;
        $controller->articulo = $this->article('REF1');
        $controller->user = (object) ['nick' => 'admin'];

        $this->assertSame([], $controller->listas_estructura);

        $controller->request = $this->assignmentRequest('L1', 'F1');
        $this->invoke($controller, 'guardarAsignacionFamilia', $controller->request);

        $this->assertCount(1, $store);
        $this->assertSame('F1', $store['L1|REF1']->codfamilia);

        // Re-assign on the same list: update, never duplicate.
        $controller->request = $this->assignmentRequest('L1', 'F2');
        $this->invoke($controller, 'guardarAsignacionFamilia', $controller->request);

        $this->assertCount(1, $store, 'Re-assigning the same article/list must update the row');
        $this->assertSame('F2', $store['L1|REF1']->codfamilia);

        // A different list is an independent assignment.
        $controller->request = $this->assignmentRequest('L2', 'F1');
        $this->invoke($controller, 'guardarAsignacionFamilia', $controller->request);

        $this->assertCount(2, $store);
        $this->assertSame('F1', $store['L2|REF1']->codfamilia);
    }

    public function test_empty_family_clears_the_assignment(): void
    {
        $GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE] = 'true';

        $store = new \ArrayObject();
        $asignacionModel = $this->assignmentStore($store);
        $existing = $asignacionModel->create();
        $existing->codlista = 'L1';
        $existing->referencia = 'REF1';
        $existing->codfamilia = 'F1';
        $store['L1|REF1'] = $existing;

        $controller = $this->makeController($this->listaModel(), $asignacionModel);
        $controller->family_structure = true;
        $controller->articulo = $this->article('REF1');
        $controller->user = (object) ['nick' => 'admin'];
        $controller->request = $this->assignmentRequest('L1', '');

        $this->invoke($controller, 'guardarAsignacionFamilia', $controller->request);

        $this->assertCount(0, $store, 'An empty family must delete the assignment row');
    }

    public function test_save_rejects_unknown_family_code_without_writing(): void
    {
        $GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE] = 'true';

        $store = new \ArrayObject();
        $controller = $this->makeController($this->listaModel(), $this->assignmentStore($store));
        $controller->family_structure = true;
        $controller->articulo = $this->article('REF1');
        $controller->user = (object) ['nick' => 'admin'];
        $controller->request = $this->assignmentRequest('L1', 'F9');

        $this->invoke($controller, 'guardarAsignacionFamilia', $controller->request);

        $this->assertContains('Familia no válida.', $controller->errors);
        $this->assertSame([], $controller->messages);
        $this->assertCount(0, $store, 'An unknown family code must not be persisted');
    }

    public function test_save_rejects_family_code_longer_than_eight_chars(): void
    {
        $GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE] = 'true';

        $store = new \ArrayObject();
        $controller = $this->makeController($this->listaModel(), $this->assignmentStore($store));
        $controller->family_structure = true;
        $controller->articulo = $this->article('REF1');
        $controller->user = (object) ['nick' => 'admin'];
        $controller->request = $this->assignmentRequest('L1', 'F123456789');

        $this->invoke($controller, 'guardarAsignacionFamilia', $controller->request);

        $this->assertContains('Familia no válida.', $controller->errors);
        $this->assertSame([], $controller->messages);
        $this->assertCount(0, $store, 'An over-length family code must not be persisted');
    }

    public function test_denied_permission_filter_blocks_the_write(): void
    {
        $GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE] = 'true';

        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            static function (ArticlePermissionFilterEvent $event): void {
                $event->deny('asignación bloqueada');
            }
        );

        $asignacionModel = new class extends \FSFramework\model\catalogo_articulo_familia_estructura {
            public function __construct() {}
            public function get($codlista, $referencia) { throw new \LogicException('denied write must not read'); }
            public function save() { throw new \LogicException('denied write must not persist'); }
        };

        $controller = $this->makeController($this->listaModel(), $asignacionModel);
        $controller->family_structure = true;
        $controller->articulo = $this->article('REF1');
        $controller->user = (object) ['nick' => 'editor'];
        $controller->request = $this->assignmentRequest('L1', 'F1');

        $this->invoke($controller, 'guardarAsignacionFamilia', $controller->request);

        $this->assertStringContainsString('asignación bloqueada', implode(' ', $controller->errors));
        $this->assertSame([], $controller->messages);
    }

    // ------------------------------------------------------------------
    // View contract
    // ------------------------------------------------------------------

    public function test_view_gates_the_assignment_block_behind_the_flag(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . self::VIEW);

        $guard = mb_strpos($view, '{% if fsc.family_structure %}');
        $this->assertNotFalse($guard, 'The assignment block must be wrapped in the family_structure guard');
        $this->assertLessThan(
            mb_strpos($view, 'id="asignacion_familias"'),
            $guard,
            'The guard must precede the assignment panel (inert when off)'
        );
        $this->assertStringContainsString('name="asignacion_codfamilia"', $view);
        $this->assertStringContainsString('name="guardar_asignacion_familia"', $view);
        $this->assertStringNotContainsString('|raw', $view);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function listaModel(): object
    {
        return new class extends \FSFramework\model\catalogo_lista_precio {
            public function __construct() {}
            public function all()
            {
                return [
                    (object) ['codlista' => 'L1', 'nombre' => 'Lista 1', 'activa' => true, 'por_defecto' => true],
                    (object) ['codlista' => 'L2', 'nombre' => 'Lista 2', 'activa' => true, 'por_defecto' => false],
                    (object) ['codlista' => 'L3', 'nombre' => 'Lista 3', 'activa' => false, 'por_defecto' => false],
                ];
            }
            public function get_default()
            {
                return (object) ['codlista' => 'L1'];
            }
        };
    }

    private function article(string $referencia): object
    {
        return new class($referencia) extends \FSFramework\model\articulo {
            public function __construct(string $referencia = '')
            {
                $this->referencia = $referencia;
                $this->descripcion = 'Artículo ' . $referencia;
            }
        };
    }

    private function assignmentRow(string $codlista, string $referencia, ?string $codfamilia): object
    {
        return (object) [
            'codlista' => $codlista,
            'referencia' => $referencia,
            'codfamilia' => $codfamilia,
            'en_tarifa' => false,
            'en_catalogo' => true,
            'orden' => 0,
        ];
    }

    private function assignmentStore(\ArrayObject $store): object
    {
        return new class($store) extends \FSFramework\model\catalogo_articulo_familia_estructura {
            private \ArrayObject $store;
            public function __construct(\ArrayObject $store) { $this->store = $store; }
            public function get($codlista, $referencia)
            {
                $key = $codlista . '|' . $referencia;

                return $this->store->offsetExists($key) ? $this->store[$key] : false;
            }
            public function save()
            {
                $this->store[$this->codlista . '|' . $this->referencia] = $this;

                return true;
            }
            public function delete()
            {
                $this->store->offsetUnset($this->codlista . '|' . $this->referencia);

                return true;
            }
            public function create(): self
            {
                return new self($this->store);
            }
        };
    }

    private function makeController(?object $listaModel, ?object $asignacionModel): object
    {
        return new class($listaModel, $asignacionModel) extends \FSFramework\Plugins\catalogo_core\Controller\VentasArticulo {
            public array $errors = [];
            public array $messages = [];
            private ?object $listaModel;
            private ?object $asignacionModel;

            public function __construct(?object $listaModel = null, ?object $asignacionModel = null)
            {
                $this->listaModel = $listaModel;
                $this->asignacionModel = $asignacionModel;
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

            protected function lista_precio_model(): \FSFramework\model\catalogo_lista_precio
            {
                return $this->listaModel;
            }

            protected function articulo_familia_estructura_model(): \FSFramework\model\catalogo_articulo_familia_estructura
            {
                return $this->asignacionModel;
            }

            protected function nueva_asignacion(): \FSFramework\model\catalogo_articulo_familia_estructura
            {
                return $this->asignacionModel->create();
            }

            protected function familia_model(): \FSFramework\model\familia
            {
                return new class extends \FSFramework\model\familia {
                    public function __construct() {}
                    public function all()
                    {
                        return [
                            (object) ['codfamilia' => 'F1', 'descripcion' => 'Familia 1'],
                            (object) ['codfamilia' => 'F2', 'descripcion' => 'Familia 2'],
                        ];
                    }
                    public function get($cod)
                    {
                        return in_array($cod, ['F1', 'F2'], true)
                            ? (object) ['codfamilia' => $cod, 'descripcion' => 'Familia ' . $cod]
                            : false;
                    }
                };
            }
        };
    }

    private function assignmentRequest(string $codlista, string $codfamilia): Request
    {
        return Request::create('/index.php?page=ventas_articulo', 'POST', [
            'guardar_asignacion_familia' => 'TRUE',
            'sreferencia' => 'REF1',
            'asignacion_codlista' => $codlista,
            'asignacion_codfamilia' => $codfamilia,
            'asignacion_en_catalogo' => '1',
        ]);
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invoke($target, ...$args);
    }
}
