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

namespace Tests\CatalogoCore\Controller;

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Plugins\catalogo_core\Controller\VentasArticulo;
use FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent;
use FSFramework\Plugins\catalogo_core\Services\CatalogoOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\CatalogoCore\Support\FakeFamilyAssignmentModel;
use Tests\CatalogoCore\Support\FakeFamilyAssignmentStore;

/**
 * familias-jerarquia task 3.4 — "Asignación a familias" block on the article
 * detail page (design D5 article assignment, D6 event decoupling, spec
 * R-FJ-003).
 *
 * Contract pinned here:
 *
 *  - the block is gated by `CatalogoOptions::familyStructureEnabled()`: with the
 *    flag off the loader exposes no lists/assignment, the template renders no
 *    block and a POST writes nothing (inert feature);
 *  - the selected price list scopes both the read and the write: the current
 *    assignment is read with ONE query for the article and only the row of the
 *    selected `codlista` is exposed (R-FJ-003 scenario 2: the L2 view never
 *    shows the L1 assignment);
 *  - a save upserts `catalogo_articulo_familia_estructura` for
 *    (`codlista`, `referencia`) through the existing model — never a `tarif_*`
 *    table — and re-assigning updates instead of duplicating (R-FJ-003
 *    scenario 1);
 *  - the write path dispatches the existing `ArticlePermissionFilterEvent`
 *    with `ACTION_EDIT_ARTICLE` + referencia before persisting; a denied event
 *    aborts the write and surfaces the listener reason through
 *    `new_error_msg()` (D6).
 *
 * DB-free by design: an anonymous `VentasArticulo` subclass skips the heavy
 * constructor, stubs the option/list/model seams and runs the REAL loader and
 * save bodies against an in-memory assignment store.
 */
final class VentasArticuloFamilyAssignmentTest extends TestCase
{
    /** @var FakeFamilyAssignmentStore In-memory catalogo_articulo_familia_estructura rows. */
    public FakeFamilyAssignmentStore $store;

    /** @var array<string, mixed> Raw CatalogoOptions override (flag values). */
    public array $optionsRaw = [];

    /** @var list<string> Active list codes offered by the list model double. */
    public array $activeListCodes = ['L1', 'L2'];

    /** @var array<string, string> codfamilia => descripcion (loaded families). */
    public array $familyLabels = ['F1' => 'Familia uno', 'F2' => 'Familia dos', 'F9' => 'Familia nueve'];

    /** @var object Anonymous VentasArticulo subclass exercising the block. */
    private $controller;

    /**
     * Legacy chain + doubles. Loaded from setUp() (child process only): during
     * discovery PHPUnit also runs beforeClass hooks in the parent, where
     * InitUpgradeTest's `FSFramework\model\familia` stub is already declared, so
     * requiring the real model there would fatally collide.
     */
    private function loadProductionChain(): void
    {
        if (class_exists(\FSFramework\Plugins\catalogo_core\Controller\VentasArticulo::class, false)) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/base/fs_app.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_familia_estructura.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php';
        // The doubles extend legacy models, so they load after the chain.
        require_once __DIR__ . '/../Support/FakeFamilyAssignmentModel.php';

        foreach (['articulo', 'familia'] as $short) {
            if (!class_exists($short, false)) {
                \fs_model_autoloader::ensureGlobalAlias($short);
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        global $plugins;
        $plugins = [];
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];

        FSEventDispatcher::reset();
        $this->loadProductionChain();
        $this->store = new FakeFamilyAssignmentStore();
        $this->optionsRaw = [];
        $this->activeListCodes = ['L1', 'L2'];
        $this->familyLabels = ['F1' => 'Familia uno', 'F2' => 'Familia dos', 'F9' => 'Familia nueve'];
        $this->resetCoreLog();
        $this->controller = $this->buildController();
    }

    protected function tearDown(): void
    {
        ini_restore('error_log');
        FSEventDispatcher::reset();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    // =====================================================================
    // R-FJ-003 scenario 1 — upsert per list, one row per (codlista, referencia)
    // =====================================================================

    public function test_assignment_save_upserts_the_selected_list_without_touching_siblings(): void
    {
        $this->store->seed('L1', 'ART1', 'F1');
        $this->store->seed('L2', 'ART1', 'F2');
        $this->enableFamilyStructure();

        $this->postSave(['codlista' => 'L1', 'codfamilia' => 'F9']);

        $this->assertSame('F9', $this->store->find('L1', 'ART1')['codfamilia'] ?? null);
        $this->assertSame(
            'F2',
            $this->store->find('L2', 'ART1')['codfamilia'] ?? null,
            'Saving L1 must leave the L2 assignment untouched'
        );
        $this->assertCount(2, $this->store->rows, 'Re-assigning updates the existing row, never duplicates it');
        $this->assertSame(1, $this->store->writes, 'A single upsert is executed for the selected list');
    }

    public function test_assignment_save_creates_the_row_when_the_list_has_no_assignment_yet(): void
    {
        $this->enableFamilyStructure();

        $this->postSave(['codlista' => 'L2', 'codfamilia' => 'F2', 'orden' => '7', 'en_catalogo' => 'TRUE']);

        $row = $this->store->find('L2', 'ART1');
        $this->assertNotNull($row, 'An empty list assignment must be created');
        $this->assertSame('F2', $row['codfamilia']);
        $this->assertSame(7, $row['orden']);
        $this->assertTrue($row['en_catalogo']);
        $this->assertCount(1, $this->store->rows);
    }

    public function test_assignment_save_with_an_empty_family_removes_the_assignment(): void
    {
        $this->store->seed('L1', 'ART1', 'F1');
        $this->store->seed('L2', 'ART1', 'F2');
        $this->enableFamilyStructure();

        $this->postSave(['codlista' => 'L1', 'codfamilia' => '']);

        $this->assertNull($this->store->find('L1', 'ART1'), 'Clearing the family removes the list assignment');
        $this->assertNotNull($this->store->find('L2', 'ART1'), 'The sibling list assignment survives');
        $this->assertSame(1, $this->store->deletes);
    }

    public function test_assignment_save_rejects_unknown_families_and_inactive_lists(): void
    {
        $this->enableFamilyStructure();

        $this->postSave(['codlista' => 'L1', 'codfamilia' => 'GHOST']);
        $this->assertNull($this->store->find('L1', 'ART1'), 'An unknown family is never persisted');

        $this->postSave(['codlista' => 'L3', 'codfamilia' => 'F1']);
        $this->assertNull($this->store->find('L3', 'ART1'), 'An inactive list is never persisted');
        $this->assertSame(0, $this->store->writes);
    }

    // =====================================================================
    // R-FJ-003 scenario 2 — list scope on the read side
    // =====================================================================

    public function test_scoped_view_for_l2_does_not_expose_the_l1_assignment(): void
    {
        $this->store->seed('L1', 'ART1', 'F1');
        $this->enableFamilyStructure();

        $this->load(['codlista' => 'L2']);

        $this->assertTrue($this->controller->family_structure);
        $this->assertSame('L2', $this->controller->codlista_estructura, 'The request scopes the block to L2');
        $this->assertNull(
            $this->controller->asignacion_familia,
            'The L2 view must not expose the L1 assignment'
        );
        $this->assertSame(1, $this->store->selects, 'The assignment is read with a single query, never one per list');
    }

    public function test_scoped_view_for_l1_exposes_only_the_l1_assignment(): void
    {
        $this->store->seed('L1', 'ART1', 'F1');
        $this->store->seed('L2', 'ART1', 'F2');
        $this->enableFamilyStructure();

        $this->load(['codlista' => 'L1']);

        $this->assertSame('L1', $this->controller->codlista_estructura);
        $this->assertNotNull($this->controller->asignacion_familia);
        $this->assertSame('F1', $this->controller->asignacion_familia->codfamilia);
        $this->assertSame(1, $this->store->selects);
    }

    public function test_scoped_save_for_l2_never_modifies_the_l1_assignment(): void
    {
        $this->store->seed('L1', 'ART1', 'F1');
        $this->enableFamilyStructure();

        $this->postSave(['codlista' => 'L2', 'codfamilia' => 'F9']);

        $this->assertSame('F1', $this->store->find('L1', 'ART1')['codfamilia'] ?? null);
        $this->assertSame('F9', $this->store->find('L2', 'ART1')['codfamilia'] ?? null);
    }

    public function test_loader_offers_only_active_lists_and_defaults_to_the_default_one(): void
    {
        $this->enableFamilyStructure();

        $this->load([]);

        $codes = array_map(static fn ($lista): string => (string) $lista->codlista, $this->controller->listas_estructura);
        $this->assertSame(['L1', 'L2'], $codes, 'Only active lists feed the selector');
        $this->assertSame('L1', $this->controller->codlista_estructura, 'The default list is selected when none is supplied');
    }

    // =====================================================================
    // Flag off — inert feature (D3 lifecycle gate, D5)
    // =====================================================================

    public function test_flag_off_exposes_no_block_state_and_reads_nothing(): void
    {
        $this->store->seed('L1', 'ART1', 'F1');

        $this->load([]);

        $this->assertFalse($this->controller->family_structure);
        $this->assertSame([], $this->controller->listas_estructura, 'flag off ⇒ no list selector');
        $this->assertSame('', $this->controller->codlista_estructura);
        $this->assertNull($this->controller->asignacion_familia);
        $this->assertSame(0, $this->store->selects, 'flag off ⇒ zero assignment reads');
    }

    public function test_flag_off_assignment_post_writes_nothing(): void
    {
        $this->store->seed('L1', 'ART1', 'F1');

        $this->postSave(['codlista' => 'L1', 'codfamilia' => 'F2']);

        $this->assertSame('F1', $this->store->find('L1', 'ART1')['codfamilia'] ?? null);
        $this->assertSame(0, $this->store->writes, 'flag off ⇒ zero writes');
        $this->assertSame(0, $this->store->deletes, 'flag off ⇒ zero deletes');
    }

    public function test_template_gates_the_block_on_the_family_structure_flag(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulo.html.twig');

        $guard = mb_strpos($view, '{% if fsc.family_structure %}');
        $block = mb_strpos($view, 'id="familias_asignacion"');

        $this->assertNotFalse($guard, 'The assignment block must be gated on fsc.family_structure');
        $this->assertNotFalse($block, 'The assignment block must exist in the article view');
        $this->assertLessThan($block, $guard, 'The block must live inside the family_structure guard');
        $this->assertStringContainsString(
            'form id="form_asignacion_familia"',
            $view,
            'The block needs its own POST form (no nested forms)'
        );
        $this->assertMatchesRegularExpression(
            '/<form id="form_asignacion_familia".*?\{\{ csrf_field\(\) \}\}/s',
            $view,
            'The assignment form must carry the CSRF field'
        );
        $this->assertStringContainsString(
            'name="guardar_asignacion_familia"',
            $view,
            'The assignment POST must be identifiable by the controller'
        );
        $this->assertStringContainsString('fsc.listas_estructura', $view, 'The selector iterates the active lists');
        $this->assertStringContainsString('fsc.asignacion_familia', $view, 'The block prefills from the scoped read');
        $this->assertStringNotContainsString('tarif_tarifa_articulo', $view, 'No legacy table reference in the view');
    }

    // =====================================================================
    // D6 — permission event on the write path
    // =====================================================================

    public function test_assignment_save_dispatches_the_edit_article_event_with_referencia(): void
    {
        $this->enableFamilyStructure();

        $captured = null;
        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            function (ArticlePermissionFilterEvent $event) use (&$captured): void {
                $captured = $event;
            }
        );

        $this->postSave(['codlista' => 'L1', 'codfamilia' => 'F1']);

        $this->assertInstanceOf(ArticlePermissionFilterEvent::class, $captured);
        $this->assertSame(ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE, $captured->getAction());
        $this->assertSame('ART1', $captured->getReferencia());
        $this->assertSame('juan', $captured->getNick());
        $this->assertSame('F1', $this->store->find('L1', 'ART1')['codfamilia'] ?? null);
    }

    public function test_denied_event_aborts_the_assignment_write_and_reports_the_reason(): void
    {
        $this->store->seed('L1', 'ART1', 'F1');
        $this->enableFamilyStructure();

        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            function (ArticlePermissionFilterEvent $event): void {
                $event->deny('editor: el artículo no está asignado a tus grupos');
            }
        );

        $this->postSave(['codlista' => 'L2', 'codfamilia' => 'F9']);

        $this->assertNull($this->store->find('L2', 'ART1'), 'A denied event must not persist');
        $this->assertSame(0, $this->store->writes);
        $this->assertSame(
            'F1',
            $this->store->find('L1', 'ART1')['codfamilia'] ?? null,
            'The sibling assignment is untouched by the denied write'
        );

        $errors = implode(' ', $this->controller->get_errors());
        $this->assertStringContainsString('editor: el artículo no está asignado a tus grupos', $errors);
    }

    public function test_throwing_listener_denies_fail_closed(): void
    {
        // The fail-closed path logs via error_log(); keep the PHPUnit output clean.
        ini_set('error_log', '/dev/null');
        $this->enableFamilyStructure();

        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            function (): never {
                throw new \RuntimeException('listener bug');
            }
        );

        $this->postSave(['codlista' => 'L1', 'codfamilia' => 'F1']);

        $this->assertNull($this->store->find('L1', 'ART1'), 'A throwing listener must deny fail-closed');
        $this->assertSame(0, $this->store->writes);
    }

    // =====================================================================
    // Harness
    // =====================================================================

    private function resetCoreLog(): void
    {
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('core_log');
        $prop->setAccessible(true);
        if ($prop->getValue() === null) {
            $prop->setValue(null, new \fs_core_log());
        }
    }

    private function enableFamilyStructure(): void
    {
        $this->optionsRaw = [CatalogoOptions::KEY_FAMILY_STRUCTURE => 'true'];
    }

    private function buildController(): object
    {
        $outer = $this;

        $controller = new class($outer) extends VentasArticulo {
            public $outer;

            public function __construct($outer)
            {
                // Skip parent::__construct — heavy FS init would hit the DB.
                $this->outer = $outer;
                $this->core_log = new \fs_core_log(VentasArticulo::class);
                $this->familias = $outer->familiaModels();
                $this->articulo = $outer->buildArticulo();
                $this->user = new class {
                    public string $nick = 'juan';
                    public bool $admin = false;
                };
            }

            protected function validateFormToken(): bool
            {
                return true;
            }

            protected function catalogo_options(): CatalogoOptions
            {
                return new CatalogoOptions($this->outer->optionsRaw);
            }

            protected function lista_precio_model(): \FSFramework\model\catalogo_lista_precio
            {
                return $this->outer->listaModel();
            }

            protected function articulo_familia_estructura_model(): \FSFramework\model\catalogo_articulo_familia_estructura
            {
                return new FakeFamilyAssignmentModel($this->outer->store);
            }

            public function setRequestObj(Request $request): void
            {
                $this->request = $request;
            }

            public function callLoadAsignacionFamilias(): void
            {
                $this->loadAsignacionFamilias();
            }

            public function callGuardarAsignacionFamilia(Request $request): void
            {
                $this->guardarAsignacionFamilia($request);
            }
        };

        $this->setRequest($controller, Request::create('/index.php?page=ventas_articulo&ref=ART1', 'GET'));

        return $controller;
    }

    private function setRequest(object $controller, Request $request): void
    {
        $controller->setRequestObj($request);
    }

    /**
     * @return list<\familia>
     */
    public function familiaModels(): array
    {
        $familias = [];
        foreach ($this->familyLabels as $codfamilia => $descripcion) {
            $familias[] = new class($codfamilia, $descripcion) extends \familia {
                public function __construct(string $codfamilia, string $descripcion)
                {
                    // Skip parent::__construct (no DB).
                    $this->codfamilia = $codfamilia;
                    $this->descripcion = $descripcion;
                    $this->madre = null;
                    $this->nivel = '';
                }
            };
        }

        return $familias;
    }

    public function buildArticulo(): object
    {
        return new class() extends \articulo {
            public function __construct()
            {
                // Skip parent::__construct (no DB).
                $this->table_name = 'articulos';
                $this->referencia = 'ART1';
                $this->codfamilia = null;
            }

            public function get($ref)
            {
                return $ref === 'ART1' ? $this : false;
            }
        };
    }

    public function listaModel(): \FSFramework\model\catalogo_lista_precio
    {
        $outer = $this;

        return new class($outer) extends \FSFramework\model\catalogo_lista_precio {
            public function __construct(private $outer)
            {
                // Skip parent::__construct (no DB).
                $this->table_name = self::TABLE;
            }

            public function all()
            {
                $list = [];
                foreach ($this->outer->activeListCodes as $codlista) {
                    $list[] = $this->row($codlista, true);
                }
                // An inactive list must never leak into the selector.
                $list[] = $this->row('L3', false);

                return $list;
            }

            private function row(string $codlista, bool $activa)
            {
                $row = new static($this->outer);
                $row->codlista = $codlista;
                $row->nombre = 'Lista ' . $codlista;
                $row->activa = $activa;
                $row->por_defecto = $codlista === 'L1';
                $row->coddivisa = 'EUR';

                return $row;
            }
        };
    }

    /**
     * Runs the real loader with the given request query parameters.
     *
     * @param array<string, string> $query
     */
    private function load(array $query): void
    {
        $_GET = $query;
        $_REQUEST = $query;
        $request = Request::create('/index.php?page=ventas_articulo&ref=ART1', 'GET', $query);
        $this->controller->setRequestObj($request);

        $this->controller->callLoadAsignacionFamilias();
    }

    /**
     * Runs the real save body with the given POST body.
     *
     * @param array<string, string> $body
     */
    private function postSave(array $body): void
    {
        // The derived state comes from privateCore()->loadCatalogData() before
        // the action runs; mirror that ordering explicitly.
        $this->controller->callLoadAsignacionFamilias();

        $body['guardar_asignacion_familia'] = 'TRUE';
        $_POST = $body;
        $_REQUEST = $body;
        $request = Request::create('/index.php?page=ventas_articulo&ref=ART1', 'POST', $body);
        $this->controller->setRequestObj($request);

        $this->controller->callGuardarAsignacionFamilia($request);
    }
}

