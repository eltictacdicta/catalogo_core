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
 * Phase 3 (tasks 3.1/3.2) — per-list family structure on the family page.
 *
 * Behavioural seam: the controller's data-loading and structure-save paths are
 * invoked on an anonymous subclass with an empty constructor and injected
 * model fakes, so no database is required.
 */
final class FamiliaEstructuraTreeTest extends TestCase
{
    private const CONTROLLER = '/plugins/catalogo_core/Controller/VentasFamilia.php';
    private const VIEW = '/plugins/catalogo_core/View/ventas_familia.html.twig';

    protected function setUp(): void
    {
        parent::setUp();
        unset($GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE]);
        FSEventDispatcher::reset();
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_familia_estructura.php';
        require_once FS_FOLDER . self::CONTROLLER;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE]);
        FSEventDispatcher::reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Tree rendering data (depth derived from the madre chain)
    // ------------------------------------------------------------------

    public function test_tree_nests_children_under_madre_and_derives_depth(): void
    {
        $controller = $this->makeController();
        $controller->codlista = 'L1';

        $tree = $this->invoke($controller, 'buildEstructuraTree', $this->familyFixtures(), null, 0);

        $this->assertCount(1, $tree, 'Only the root family sits at depth 0');
        $root = $tree[0];
        $this->assertSame('A', $root->codfamilia);
        $this->assertSame(0, $root->nivel_visual);
        $this->assertCount(2, $root->hijas_tree, 'A must nest B and C');
        $this->assertSame(['B', 'C'], array_column($root->hijas_tree, 'codfamilia'));
        $this->assertSame([1, 1], array_column($root->hijas_tree, 'nivel_visual'));
        $this->assertSame('D', $root->hijas_tree[0]->hijas_tree[0]->codfamilia);
        $this->assertSame(2, $root->hijas_tree[0]->hijas_tree[0]->nivel_visual);

        $this->assertSame('L1', $root->estructura->codlista, 'Nodes without a row expose per-list defaults');
        $this->assertFalse($root->estructura_persistida);
        $this->assertTrue($root->estructura->en_catalogo);
        $this->assertTrue($root->estructura->activa);
    }

    public function test_tree_orders_siblings_by_structure_orden_then_description(): void
    {
        $controller = $this->makeController();
        $controller->codlista = 'L1';
        $controller->estructura_por_lista = [
            'B' => $this->structureRow('B', 20, 'B desc'),
            'C' => $this->structureRow('C', 10, 'C desc'),
        ];

        $tree = $this->invoke($controller, 'buildEstructuraTree', $this->familyFixtures(), null, 0);

        $this->assertSame(
            ['C', 'B'],
            array_column($tree[0]->hijas_tree, 'codfamilia'),
            'Siblings must follow structure.orden (C=10 before B=20)'
        );
        $this->assertTrue($tree[0]->hijas_tree[0]->estructura_persistida);
        $this->assertSame(10, $tree[0]->hijas_tree[0]->estructura->orden);
    }

    public function test_tree_falls_back_to_description_when_orden_ties(): void
    {
        $controller = $this->makeController();
        $controller->codlista = 'L1';
        $controller->estructura_por_lista = [
            'B' => $this->structureRow('B', 0, 'Zeta'),
            'C' => $this->structureRow('C', 0, 'Alfa'),
        ];

        $families = [
            $this->family('A', null, 'Alfa'),
            $this->family('B', 'A', 'Zeta'),
            $this->family('C', 'A', 'Alfa'),
        ];

        $tree = $this->invoke($controller, 'buildEstructuraTree', $families, null, 0);

        $this->assertSame(['C', 'B'], array_column($tree[0]->hijas_tree, 'codfamilia'));
    }

    // ------------------------------------------------------------------
    // Flag-off inertness (R-CO-003 pattern, task 3.1)
    // ------------------------------------------------------------------

    public function test_load_is_inert_when_flag_off(): void
    {
        $listaModel = new class extends \FSFramework\model\catalogo_lista_precio {
            public function __construct() {}
            public function all() { throw new \LogicException('flag off must not query lists'); }
        };
        $estructuraModel = new class extends \FSFramework\model\catalogo_familia_estructura {
            public function __construct() {}
            public function all_from_list($codlista) { throw new \LogicException('flag off must not query structure'); }
        };

        $controller = $this->makeController($listaModel, $estructuraModel);
        $controller->request = Request::create('/index.php?page=ventas_familia', 'GET');
        $controller->familia = $this->familyModel();

        $this->invoke($controller, 'loadFamiliaEstructura');

        $this->assertFalse($controller->family_structure, 'Flag off must keep the capability inert');
        $this->assertSame('', $controller->codlista);
        $this->assertSame([], $controller->listas_estructura);
        $this->assertSame([], $controller->estructura_por_lista);
        $this->assertSame([], $controller->estructura_tree);
    }

    public function test_load_reads_active_lists_and_batches_the_selected_list(): void
    {
        $GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE] = 'true';

        $listaModel = new class extends \FSFramework\model\catalogo_lista_precio {
            public function __construct() {}
            public function all()
            {
                return [
                    (object) ['codlista' => 'L1', 'nombre' => 'Lista 1', 'activa' => true, 'por_defecto' => true],
                    (object) ['codlista' => 'L2', 'nombre' => 'Lista 2', 'activa' => false, 'por_defecto' => false],
                    (object) ['codlista' => 'L3', 'nombre' => 'Lista 3', 'activa' => true, 'por_defecto' => false],
                ];
            }
            public function get_default()
            {
                return (object) ['codlista' => 'L1'];
            }
        };

        $estructuraModel = new class extends \FSFramework\model\catalogo_familia_estructura {
            /** @var list<string> */
            public array $calls = [];
            public function __construct() {}
            public function all_from_list($codlista)
            {
                $this->calls[] = (string) $codlista;

                return [
                    (object) ['codfamilia' => 'A', 'codlista' => 'L3', 'orden' => 3],
                ];
            }
        };

        $controller = $this->makeController($listaModel, $estructuraModel);
        $controller->request = Request::create('/index.php?page=ventas_familia&codlista=L3', 'GET');
        $controller->familia = $this->familyModel();

        $this->invoke($controller, 'loadFamiliaEstructura');

        $this->assertTrue($controller->family_structure);
        $this->assertSame(['L1', 'L3'], array_column($controller->listas_estructura, 'codlista'), 'Inactive lists are excluded');
        $this->assertSame('L3', $controller->codlista);
        $this->assertSame(['L3'], $estructuraModel->calls, 'Exactly one batch read for the selected list (no N+1)');
        $this->assertArrayHasKey('A', $controller->estructura_por_lista);
        $this->assertNotEmpty($controller->estructura_tree);
    }

    // ------------------------------------------------------------------
    // Structure save: flag-off inert, flag-on upsert, event gate
    // ------------------------------------------------------------------

    public function test_save_is_inert_when_flag_off_and_writes_nothing(): void
    {
        $estructuraModel = new class extends \FSFramework\model\catalogo_familia_estructura {
            public function __construct() {}
            public function get($codlista, $codfamilia) { throw new \LogicException('flag off must not read structure'); }
            public function save() { throw new \LogicException('flag off must not write structure'); }
        };

        $controller = $this->makeController(null, $estructuraModel);
        $controller->request = $this->structureRequest('F1', 'L1');
        $controller->user = (object) ['nick' => 'admin'];
        $controller->listas_estructura = [(object) ['codlista' => 'L1', 'activa' => true]];

        $this->invoke($controller, 'guardarEstructuraFamilia');

        $this->assertNotEmpty($controller->errors, 'Flag off must surface an error and abort');
        $this->assertSame([], $controller->messages);
    }

    public function test_save_upserts_structure_row_through_the_model(): void
    {
        $GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE] = 'true';

        $store = new \ArrayObject();
        $estructuraModel = $this->structureStore($store);

        $controller = $this->makeController(null, $estructuraModel);
        $controller->family_structure = true;
        $controller->request = $this->structureRequest('F1', 'L1', ['estructura_orden' => '5', 'estructura_capitulo' => 'CAP']);
        $controller->user = (object) ['nick' => 'admin'];
        $controller->listas_estructura = [(object) ['codlista' => 'L1', 'activa' => true]];

        $this->invoke($controller, 'guardarEstructuraFamilia');

        $this->assertCount(1, $store, 'A new structure row must be inserted');
        $row = $store['L1|F1'];
        $this->assertSame('L1', $row->codlista);
        $this->assertSame('F1', $row->codfamilia);
        $this->assertSame('CAP', $row->capitulo);
        $this->assertSame(5, $row->orden);
        $this->assertNotEmpty($controller->messages);

        // Re-save the same key: it must update, never duplicate.
        $controller->request = $this->structureRequest('F1', 'L1', ['estructura_orden' => '9']);
        $this->invoke($controller, 'guardarEstructuraFamilia');

        $this->assertCount(1, $store, 'Re-saving the composite key must not duplicate the row');
        $this->assertSame(9, $store['L1|F1']->orden);
    }

    public function test_save_is_not_gated_by_the_article_permission_event(): void
    {
        $GLOBALS['config2'][CatalogoOptions::KEY_FAMILY_STRUCTURE] = 'true';

        $listenerCalls = 0;
        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            static function (ArticlePermissionFilterEvent $event) use (&$listenerCalls): void {
                $listenerCalls++;
                $event->deny('estructura bloqueada');
            }
        );

        $store = new \ArrayObject();
        $controller = $this->makeController(null, $this->structureStore($store));
        $controller->family_structure = true;
        $controller->request = $this->structureRequest('F1', 'L1', ['estructura_orden' => '7']);
        $controller->user = (object) ['nick' => 'editor'];
        $controller->listas_estructura = [(object) ['codlista' => 'L1', 'activa' => true]];

        $this->invoke($controller, 'guardarEstructuraFamilia');

        $this->assertSame(0, $listenerCalls, 'Family-structure writes must not dispatch the article permission event');
        $this->assertSame([], $controller->errors, 'A denying article listener must not block a family-structure save');
        $this->assertCount(1, $store, 'The structure row must persist regardless of article listeners');
        $this->assertSame(7, $store['L1|F1']->orden);
        $this->assertNotEmpty($controller->messages);
    }

    public function test_save_path_keeps_zero_article_event_references(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER);

        $this->assertStringNotContainsString(
            'ArticlePermissionFilterEvent',
            $source,
            'Family-structure writes are governed by page access; the article event must not gate them'
        );
        $this->assertStringNotContainsString('dispatchFamilyStructureFilter', $source);
    }

    // ------------------------------------------------------------------
    // View contract (task 3.2)
    // ------------------------------------------------------------------

    public function test_view_gates_structure_ui_behind_the_flag(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . self::VIEW);

        $guard = mb_strpos($view, '{% if fsc.family_structure %}');
        $this->assertNotFalse($guard, 'The structure UI must be wrapped in the family_structure guard');
        $this->assertLessThan(
            mb_strpos($view, 'id="estructura_por_lista"'),
            $guard,
            'The guard must precede the structure panel (inert when off)'
        );
        $this->assertStringContainsString('id="estructura_por_lista"', $view);
        $this->assertStringContainsString('name="codlista"', $view, 'The active-list selector must be present');
    }

    public function test_view_renders_a_nested_ul_tree_with_per_node_structure_fields(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . self::VIEW);

        $this->assertStringContainsString('<ul class="familia-tree">', $view, 'The tree must be a nested <ul>');
        $this->assertStringContainsString('node.hijas_tree', $view, 'Children must be rendered recursively');
        $this->assertStringContainsString('node.nivel_visual', $view, 'Depth must come from the derived madre chain');
        $this->assertStringContainsString('name="estructura_nivel"', $view);
        $this->assertStringContainsString('name="estructura_orden"', $view);
        $this->assertStringContainsString('name="estructura_activa"', $view);
    }

    public function test_view_has_csrf_on_every_post_form_and_no_raw_output(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . self::VIEW);

        $this->assertSame(
            substr_count($view, 'method="post"'),
            substr_count($view, '{{ csrf_field() }}'),
            'Every POST form must carry exactly one CSRF field'
        );
        $this->assertStringNotContainsString('|raw', $view, 'No |raw with user data is allowed');
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /** @return list<object> */
    private function familyFixtures(): array
    {
        return [
            $this->family('A', null, 'Alfa'),
            $this->family('B', 'A', 'Beta'),
            $this->family('C', 'A', 'Gamma'),
            $this->family('D', 'B', 'Delta'),
        ];
    }

    private function family(string $codfamilia, ?string $madre, string $descripcion): object
    {
        return (object) [
            'codfamilia' => $codfamilia,
            'descripcion' => $descripcion,
            'madre' => $madre,
            'nivel' => '',
        ];
    }

    private function structureRow(string $codfamilia, int $orden, string $descripcion): object
    {
        return (object) [
            'codlista' => 'L1',
            'codfamilia' => $codfamilia,
            'madre' => 'A',
            'capitulo' => '',
            'nivel' => '',
            'en_catalogo' => true,
            'en_tarifa' => false,
            'activa' => true,
            'orden' => $orden,
            'descripcion' => $descripcion,
        ];
    }

    private function familyModel(): object
    {
        $families = $this->familyFixtures();

        return new class($families) extends \FSFramework\model\familia {
            /** @var list<object> */
            private array $families;
            public function __construct(array $families = []) { $this->families = $families; }
            public function all() { return $this->families; }
            public function get($cod) { return false; }
            public function madres() { return []; }
        };
    }

    private function structureStore(\ArrayObject $store): object
    {
        return new class($store) extends \FSFramework\model\catalogo_familia_estructura {
            private \ArrayObject $store;
            public function __construct(\ArrayObject $store) { $this->store = $store; }
            public function get($codlista, $codfamilia)
            {
                $key = $codlista . '|' . $codfamilia;

                return $this->store->offsetExists($key) ? $this->store[$key] : false;
            }
            public function all_from_list($codlista)
            {
                $rows = [];
                foreach ($this->store as $row) {
                    if ($row->codlista === $codlista) {
                        $rows[] = $row;
                    }
                }

                return $rows;
            }
            public function save()
            {
                $this->store[$this->codlista . '|' . $this->codfamilia] = $this;

                return true;
            }
            public function create(): self
            {
                return new self($this->store);
            }
        };
    }

    private function makeController(?object $listaModel = null, ?object $estructuraModel = null): object
    {
        require_once FS_FOLDER . self::CONTROLLER;

        return new class($listaModel, $estructuraModel) extends \FSFramework\Plugins\catalogo_core\Controller\VentasFamilia {
            public array $errors = [];
            public array $messages = [];
            private ?object $listaModel;
            private ?object $estructuraModel;

            public function __construct(?object $listaModel = null, ?object $estructuraModel = null)
            {
                $this->listaModel = $listaModel;
                $this->estructuraModel = $estructuraModel;
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

            protected function estructura_model(): \FSFramework\model\catalogo_familia_estructura
            {
                return $this->estructuraModel;
            }

            protected function nueva_estructura(): \FSFramework\model\catalogo_familia_estructura
            {
                return $this->estructuraModel->create();
            }
        };
    }

    private function structureRequest(string $codfamilia, string $codlista, array $overrides = []): Request
    {
        return Request::create('/index.php?page=ventas_familia', 'POST', array_merge([
            'save_estructura' => '1',
            'estructura_codfamilia' => $codfamilia,
            'estructura_codlista' => $codlista,
            'estructura_capitulo' => '',
            'estructura_nivel' => '',
            'estructura_orden' => '0',
            'estructura_en_catalogo' => '1',
            'estructura_activa' => '1',
        ], $overrides));
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invoke($target, ...$args);
    }
}
