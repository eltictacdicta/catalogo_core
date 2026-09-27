<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore\Controller;

use FSFramework\Plugins\catalogo_core\Event\CaracteristicaPermissionFilterEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contract tests for the ventas_caracteristicas panel (CAR-18) and the neutral
 * permission filter event.
 *
 * Source layer: files, wrapper, page data, CSRF-first mutations and the view
 * contract. Behavioral layer: a CSRF-invalid POST persists nothing, and the
 * permission event is default-allow with deny(reason).
 */
final class VentasCaracteristicasControllerTest extends TestCase
{
    private const CONTROLLER = 'plugins/catalogo_core/Controller/VentasCaracteristicas.php';

    private const WRAPPER = 'plugins/catalogo_core/controller/ventas_caracteristicas.php';

    private const VIEW = 'plugins/catalogo_core/View/ventas_caracteristicas.html.twig';

    protected function setUp(): void
    {
        parent::setUp();
        global $plugins;
        $plugins = [];
    }

    private function methodBody(string $source, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{/';
        if (!preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $matches[0][1] + strlen($matches[0][0]);
        $depth = 1;
        $length = strlen($source);
        for ($i = $start; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }

        return '';
    }

    // =====================================================================
    // CAR-18 — panel shell
    // =====================================================================

    public function test_modern_controller_and_wrapper_exist_and_are_linked(): void
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . '/' . self::CONTROLLER;
        require_once FS_FOLDER . '/' . self::WRAPPER;

        $reflection = new \ReflectionClass(
            \FSFramework\Plugins\catalogo_core\Controller\VentasCaracteristicas::class
        );
        $this->assertTrue($reflection->isSubclassOf(\FSFramework\Controller\PageController::class));
        $this->assertTrue($reflection->hasMethod('privateCore'));

        $wrapper = new \ReflectionClass('ventas_caracteristicas');
        $this->assertTrue($wrapper->isSubclassOf(
            \FSFramework\Plugins\catalogo_core\Controller\VentasCaracteristicas::class
        ));
    }

    public function test_page_data_is_pinned(): void
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . '/' . self::CONTROLLER;

        $controller = new class() extends \FSFramework\Plugins\catalogo_core\Controller\VentasCaracteristicas {
            public function __construct()
            {
            }
        };

        $data = $controller->getPageData();
        $this->assertSame('ventas_caracteristicas', $data['name']);
        $this->assertSame('catalogo', $data['menu']);
        $this->assertSame(109, $data['ordernum']);
        $this->assertTrue($data['showonmenu']);
    }

    public function test_every_mutating_action_validates_csrf_before_touching_a_model(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/' . self::CONTROLLER);

        foreach ([
            'save_definition',
            'delete_definition',
            'toggle_flag',
            'save_catalogo_valor',
            'delete_catalogo_valor',
            'assign_value',
            'clear_value',
        ] as $action) {
            $body = $this->methodBody($source, $action);
            $this->assertNotSame('', $body, $action . '() must exist');

            $csrf = strpos($body, 'validateFormToken()');
            $this->assertNotFalse($csrf, $action . '() must validate CSRF');

            foreach (['->save(', '->delete(', '->exec('] as $mutation) {
                $pos = strpos($body, $mutation);
                if ($pos === false) {
                    continue;
                }
                $this->assertLessThan($pos, $csrf, $action . '() must validate CSRF before ' . $mutation);
            }
        }
    }

    public function test_view_uses_csrf_and_escapes_output(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/' . self::VIEW);

        $this->assertStringContainsString('{{ csrf_field() }}', $view);
        $this->assertStringNotContainsString('|raw', $view);
        $this->assertStringContainsString('save_definition', $view, 'the definition form must be present');
        $this->assertStringContainsString('save_catalogo_valor', $view, 'the predefined-value form must be present');
        $this->assertStringContainsString('delete_definition', $view, 'the definition delete action must be present');
    }

    public function test_csrf_failure_persists_nothing(): void
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . '/' . self::CONTROLLER;

        $spy = new PanelSpyCaracteristica();
        $controller = new class($spy) extends \FSFramework\Plugins\catalogo_core\Controller\VentasCaracteristicas {
            private PanelSpyCaracteristica $spy;

            public function __construct(PanelSpyCaracteristica $spy)
            {
                $this->spy = $spy;
            }

            public function exposeDispatch(string $action): void
            {
                $this->dispatch_action($action);
            }

            protected function caracteristica_model()
            {
                return $this->spy;
            }

            protected function validateFormToken(): bool
            {
                return false;
            }

            public function new_message(string $msg): void
            {
            }

            public function new_error_msg(string $msg): void
            {
            }
        };
        $controller->request = Request::create('/index.php?page=ventas_caracteristicas', 'POST', [
            'codigo' => 'nuevo',
            'nombre' => 'Nuevo',
            'tipo' => 'string',
        ]);

        $controller->exposeDispatch('save_definition');

        $this->assertFalse($spy->saved, 'a CSRF-invalid POST must not persist');
        $this->assertFalse($spy->deleted, 'a CSRF-invalid POST must not delete');
    }

    // =====================================================================
    // Permission filter event
    // =====================================================================

    public function test_permission_event_is_default_allow_and_carries_context(): void
    {
        $event = new CaracteristicaPermissionFilterEvent(
            'medidas',
            CaracteristicaPermissionFilterEvent::ACTION_DELETE_DEFINITION,
            'admin',
            'T1'
        );

        $this->assertTrue($event->isAllowed());
        $this->assertSame('', $event->getDenialReason());
        $this->assertSame('medidas', $event->getCodigo());
        $this->assertSame('delete_definition', $event->getAction());
        $this->assertSame('admin', $event->getNick());
        $this->assertSame('T1', $event->getCodtarifa());
    }

    public function test_permission_event_deny_flips_and_carries_reason(): void
    {
        $event = new CaracteristicaPermissionFilterEvent(
            'medidas',
            CaracteristicaPermissionFilterEvent::ACTION_ASSIGN_GLOBAL,
            'gestor'
        );
        $event->deny('sin permisos');

        $this->assertFalse($event->isAllowed());
        $this->assertSame('sin permisos', $event->getDenialReason());
        $this->assertSame('assign_global', $event->getAction());
        $this->assertSame('', $event->getCodtarifa());
    }

    public function test_permission_event_exposes_the_three_frozen_actions(): void
    {
        $this->assertSame('delete_definition', CaracteristicaPermissionFilterEvent::ACTION_DELETE_DEFINITION);
        $this->assertSame('assign_global', CaracteristicaPermissionFilterEvent::ACTION_ASSIGN_GLOBAL);
        $this->assertSame('assign_familia', CaracteristicaPermissionFilterEvent::ACTION_ASSIGN_FAMILIA);
        $this->assertSame('catalogo_core.caracteristica_permission_filter', CaracteristicaPermissionFilterEvent::NAME);
    }

    // =====================================================================
    // CAR-18 scenario 4 — assignment through the store
    // =====================================================================

    public function test_view_exposes_the_three_assignment_scopes(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/' . self::VIEW);

        $this->assertStringContainsString('assign_value', $view);
        $this->assertStringContainsString('value="global"', $view, 'the all-products scope must be present');
        $this->assertStringContainsString('value="familia"', $view, 'the family scope must be present');
        $this->assertStringContainsString('value="articulo"', $view, 'the product scope must be present');
    }

    public function test_denied_global_assignment_persists_nothing(): void
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . '/' . self::CONTROLLER;

        $store = new PanelSpyValorStore();
        $controller = $this->assignmentController($store, true, false);
        $controller->request = Request::create('/index.php?page=ventas_caracteristicas', 'POST', [
            'action' => 'assign_value',
            'scope' => 'global',
            'codigo' => 'en_catalogo',
            'codtarifa' => 'T1',
            'id_valor' => '1',
        ]);

        $controller->exposeDispatch('assign_value');

        $this->assertSame([], $store->calls, 'a denied global assignment must persist nothing');
    }

    public function test_valid_assignment_writes_through_the_store(): void
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . '/' . self::CONTROLLER;

        $store = new PanelSpyValorStore();
        $controller = $this->assignmentController($store, true, true);
        $controller->request = Request::create('/index.php?page=ventas_caracteristicas', 'POST', [
            'action' => 'assign_value',
            'scope' => 'articulo',
            'codigo' => 'en_catalogo',
            'codtarifa' => 'T1',
            'key' => ['referencia' => 'REF-1'],
            'id_valor' => '1',
        ]);

        $controller->exposeDispatch('assign_value');

        $this->assertCount(1, $store->calls);
        $this->assertSame('assign_predefined', $store->calls[0]['method']);
        $this->assertSame('articulo', $store->calls[0]['args'][0]);
        $this->assertSame('T1', $store->calls[0]['args'][1]);
        $this->assertSame(['referencia' => 'REF-1'], $store->calls[0]['args'][2]);
        $this->assertSame('en_catalogo', $store->calls[0]['args'][3]);
        $this->assertSame(1, $store->calls[0]['args'][4]);
    }

    // =====================================================================
    // CAR-18 — owner-disabled definitions: listing hidden + actions refused
    // =====================================================================

    public function test_inert_definitions_are_absent_from_the_panel_listing(): void
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . '/' . self::CONTROLLER;

        $recorder = new PanelPersistenceRecorder();
        $controller = $this->panelController(new PanelFixtureDefinitionModel(
            $recorder,
            $this->fixtureDefinitions($recorder)
        ));
        $controller->exposeLoadDefiniciones();

        $this->assertSame(
            ['medidas'],
            array_map(static fn ($def): string => (string) $def->codigo, $controller->definiciones),
            'an owner-disabled definition must not appear in the panel listing'
        );
    }

    public function test_operator_owned_definition_stays_listed_and_writable(): void
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . '/' . self::CONTROLLER;

        $recorder = new PanelPersistenceRecorder();
        $controller = $this->panelController(new PanelFixtureDefinitionModel(
            $recorder,
            $this->fixtureDefinitions($recorder)
        ));
        $controller->exposeLoadDefiniciones();

        $this->assertSame(
            ['medidas'],
            array_map(static fn ($def): string => (string) $def->codigo, $controller->definiciones)
        );

        $controller->request = Request::create('/index.php?page=ventas_caracteristicas', 'POST', [
            'codigo' => 'medidas',
            'nombre' => 'Medidas',
            'tipo' => 'string',
        ]);
        $controller->exposeDispatch('save_definition');

        $this->assertSame(1, $recorder->saves, 'an operator-owned definition must stay writable');
    }

    public function test_save_definition_refuses_an_inert_target_and_persists_nothing(): void
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . '/' . self::CONTROLLER;

        $recorder = new PanelPersistenceRecorder();
        $controller = $this->panelController(new PanelFixtureDefinitionModel(
            $recorder,
            $this->fixtureDefinitions($recorder)
        ));
        $controller->request = Request::create('/index.php?page=ventas_caracteristicas', 'POST', [
            'codigo' => 'en_catalogo',
            'nombre' => 'Mutated',
            'tipo' => 'bool',
        ]);
        $controller->exposeDispatch('save_definition');

        $this->assertSame(0, $recorder->saves, 'an inert definition must not be saved');
        $this->assertNotSame('', $controller->lastError, 'the refusal must be reported');
    }

    public function test_toggle_flag_refuses_an_inert_target_and_persists_nothing(): void
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . '/' . self::CONTROLLER;

        $recorder = new PanelPersistenceRecorder();
        $controller = $this->panelController(new PanelFixtureDefinitionModel(
            $recorder,
            $this->fixtureDefinitions($recorder)
        ));
        $controller->request = Request::create('/index.php?page=ventas_caracteristicas', 'POST', [
            'codigo' => 'en_catalogo',
            'field' => 'activo',
        ]);
        $controller->exposeDispatch('toggle_flag');

        $this->assertSame(0, $recorder->saves, 'an inert definition flag must not be saved');
        $this->assertNotSame('', $controller->lastError, 'the refusal must be reported');
    }

    public function test_delete_definition_refuses_an_inert_target_and_persists_nothing(): void
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . '/' . self::CONTROLLER;

        $recorder = new PanelPersistenceRecorder();
        // The inert row is made deletable on purpose: the guard must refuse it
        // before the permission/deletability checks, not because of them.
        $controller = $this->panelController(new PanelFixtureDefinitionModel(
            $recorder,
            $this->fixtureDefinitions($recorder, true)
        ));
        $controller->allow_delete = true;
        $controller->request = Request::create('/index.php?page=ventas_caracteristicas', 'POST', [
            'codigo' => 'en_catalogo',
        ]);
        $controller->exposeDispatch('delete_definition');

        $this->assertSame(0, $recorder->deletes, 'an inert definition must not be deleted');
        $this->assertNotSame('', $controller->lastError, 'the refusal must be reported');
    }

    public function test_save_catalogo_valor_refuses_an_inert_target_and_persists_nothing(): void
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . '/' . self::CONTROLLER;

        $recorder = new PanelPersistenceRecorder();
        $definitions = new PanelFixtureDefinitionModel($recorder, $this->fixtureDefinitions($recorder));
        $controller = $this->panelController($definitions, new PanelFixtureValorModel($recorder));
        $controller->request = Request::create('/index.php?page=ventas_caracteristicas', 'POST', [
            'id_caracteristica' => '20',
            'valor' => 'X',
        ]);
        $controller->exposeDispatch('save_catalogo_valor');

        $this->assertSame(0, $recorder->saves, 'a value of an inert definition must not be saved');
        $this->assertNotSame('', $controller->lastError, 'the refusal must be reported');

        // The `codigo` fallback resolves the same target and must be refused too.
        $recorder2 = new PanelPersistenceRecorder();
        $definitions2 = new PanelFixtureDefinitionModel($recorder2, $this->fixtureDefinitions($recorder2));
        $controller2 = $this->panelController($definitions2, new PanelFixtureValorModel($recorder2));
        $controller2->request = Request::create('/index.php?page=ventas_caracteristicas', 'POST', [
            'codigo' => 'en_catalogo',
            'valor' => 'X',
        ]);
        $controller2->exposeDispatch('save_catalogo_valor');

        $this->assertSame(0, $recorder2->saves, 'the codigo fallback must resolve and refuse the inert target');
        $this->assertNotSame('', $controller2->lastError);
    }

    public function test_delete_catalogo_valor_refuses_an_inert_target_and_persists_nothing(): void
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . '/' . self::CONTROLLER;

        $recorder = new PanelPersistenceRecorder();
        $definitions = new PanelFixtureDefinitionModel($recorder, $this->fixtureDefinitions($recorder));
        $valorModel = new PanelFixtureValorModel($recorder, [
            new PanelFixtureValor(['id' => 1, 'id_caracteristica' => 20, 'valor' => 'X'], $recorder),
        ]);

        $controller = $this->panelController($definitions, $valorModel);
        $controller->allow_delete = true;
        $controller->request = Request::create('/index.php?page=ventas_caracteristicas', 'POST', ['id' => '1']);
        $controller->exposeDispatch('delete_catalogo_valor');

        $this->assertSame(0, $recorder->deletes, 'a value of an inert definition must not be deleted');
        $this->assertNotSame('', $controller->lastError, 'the refusal must be reported');
    }

    /**
     * @return list<PanelFixtureDefinition>
     */
    private function fixtureDefinitions(PanelPersistenceRecorder $recorder, ?bool $inertDeletable = null): array
    {
        return [
            new PanelFixtureDefinition(
                ['id' => 10, 'codigo' => 'medidas', 'nombre' => 'Medidas', 'tipo' => 'string', 'origen' => ''],
                $recorder
            ),
            new PanelFixtureDefinition(
                ['id' => 20, 'codigo' => 'en_catalogo', 'nombre' => 'En Catálogo', 'tipo' => 'bool', 'origen' => 'tarifario'],
                $recorder,
                $inertDeletable
            ),
        ];
    }

    private function panelController(
        object $definitionModel,
        ?object $valorModel = null
    ): object {
        return new class($definitionModel, $valorModel) extends \FSFramework\Plugins\catalogo_core\Controller\VentasCaracteristicas {
            public string $lastError = '';

            public function __construct(
                private object $definitionModel,
                private ?object $valorModel
            ) {
            }

            public function exposeDispatch(string $action): void
            {
                $this->dispatch_action($action);
            }

            public function exposeLoadDefiniciones(): void
            {
                $this->load_definiciones();
            }

            protected function caracteristica_model()
            {
                return $this->definitionModel;
            }

            protected function valor_model()
            {
                return $this->valorModel ?? new PanelFixtureValorModel(new PanelPersistenceRecorder());
            }

            protected function validateFormToken(): bool
            {
                return true;
            }

            protected function puede_gestionar(string $action, string $codigo, string $codtarifa = ''): bool
            {
                return true;
            }

            public function new_message(string $msg): void
            {
            }

            public function new_error_msg(string $msg): void
            {
                $this->lastError = $msg;
            }
        };
    }

    private function assignmentController(PanelSpyValorStore $store, bool $csrf, bool $allowed): object
    {
        return new class($store, $csrf, $allowed) extends \FSFramework\Plugins\catalogo_core\Controller\VentasCaracteristicas {
            private PanelSpyValorStore $store;

            private bool $csrf;

            private bool $allowed;

            public function __construct(PanelSpyValorStore $store, bool $csrf, bool $allowed)
            {
                $this->store = $store;
                $this->csrf = $csrf;
                $this->allowed = $allowed;
            }

            public function exposeDispatch(string $action): void
            {
                $this->dispatch_action($action);
            }

            protected function valor_store()
            {
                return $this->store;
            }

            protected function validateFormToken(): bool
            {
                return $this->csrf;
            }

            protected function puede_gestionar(string $action, string $codigo, string $codtarifa = ''): bool
            {
                return $this->allowed;
            }

            public function new_message(string $msg): void
            {
            }

            public function new_error_msg(string $msg): void
            {
            }
        };
    }
}

/**
 * Minimal value-store double recording the write calls the panel issued.
 */
final class PanelSpyValorStore
{
    /** @var list<array{method: string, args: array<int, mixed>}> */
    public array $calls = [];

    public function assign_predefined(string $scope, string $codtarifa, array $key, string $codigo, int $idValor): bool
    {
        $this->calls[] = ['method' => 'assign_predefined', 'args' => [$scope, $codtarifa, $key, $codigo, $idValor]];

        return true;
    }

    public function assign_custom(string $scope, string $codtarifa, array $key, string $codigo, string $valor): bool
    {
        $this->calls[] = ['method' => 'assign_custom', 'args' => [$scope, $codtarifa, $key, $codigo, $valor]];

        return true;
    }

    public function assign_bool(string $scope, string $codtarifa, array $key, string $codigo, bool $valor): bool
    {
        $this->calls[] = ['method' => 'assign_bool', 'args' => [$scope, $codtarifa, $key, $codigo, $valor]];

        return true;
    }

    public function clear(string $scope, string $codtarifa, array $key, string $codigo): bool
    {
        $this->calls[] = ['method' => 'clear', 'args' => [$scope, $codtarifa, $key, $codigo]];

        return true;
    }
}

/**
 * Minimal definition-model double recording whether the panel persisted.
 */
final class PanelSpyCaracteristica
{
    public bool $saved = false;

    public bool $deleted = false;

    public $id = null;

    public $codigo = null;

    public $nombre = null;

    public $tipo = null;

    public $activo = true;

    public $importable = false;

    public $exportable = false;

    public $listable = false;

    public $orden = 0;

    public $origen = '';

    public $valor_defecto = null;

    public function get($codigo)
    {
        return false;
    }

    public function all($onlyActive = false)
    {
        return [];
    }

    public function is_deletable(): bool
    {
        return true;
    }

    public function save(): bool
    {
        $this->saved = true;

        return true;
    }

    public function delete(): bool
    {
        $this->deleted = true;

        return true;
    }
}

/**
 * Shared write counter for the panel fixtures: any definition or value save /
 * delete routes through it, so a test can assert that nothing persisted.
 */
final class PanelPersistenceRecorder
{
    public int $saves = 0;

    public int $deletes = 0;
}

/**
 * Definition entity double with the public shape the panel mutates.
 */
final class PanelFixtureDefinition
{
    public $id;

    public $codigo;

    public $nombre;

    public $tipo;

    public $activo = true;

    public $importable = false;

    public $exportable = false;

    public $listable = false;

    public $orden = 0;

    public $origen = '';

    public $valor_defecto = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        array $data,
        private PanelPersistenceRecorder $recorder,
        private ?bool $deletable = null
    ) {
        $this->id = $data['id'] ?? null;
        $this->codigo = $data['codigo'] ?? null;
        $this->nombre = $data['nombre'] ?? null;
        $this->tipo = $data['tipo'] ?? null;
        $this->activo = $data['activo'] ?? true;
        $this->importable = $data['importable'] ?? false;
        $this->exportable = $data['exportable'] ?? false;
        $this->listable = $data['listable'] ?? false;
        $this->orden = $data['orden'] ?? 0;
        $this->origen = (string) ($data['origen'] ?? '');
        $this->valor_defecto = $data['valor_defecto'] ?? null;
    }

    public function is_deletable(): bool
    {
        return $this->deletable ?? (trim((string) $this->origen) === '');
    }

    public function save(): bool
    {
        $this->recorder->saves++;

        return true;
    }

    public function delete(): bool
    {
        $this->recorder->deletes++;

        return true;
    }
}

/**
 * Definition-model double: repository for `get`, `get_by_id` and `all`, and the
 * blank entity the panel mutates for a new definition.
 */
final class PanelFixtureDefinitionModel
{
    /** @var array<string, PanelFixtureDefinition> */
    private array $byCode = [];

    /** @var array<int, PanelFixtureDefinition> */
    private array $byId = [];

    /**
     * @param list<PanelFixtureDefinition> $definitions
     */
    public function __construct(
        private PanelPersistenceRecorder $recorder,
        private array $definitions = []
    ) {
        foreach ($definitions as $definition) {
            if ($definition->codigo !== null) {
                $this->byCode[(string) $definition->codigo] = $definition;
            }
            if ($definition->id !== null) {
                $this->byId[(int) $definition->id] = $definition;
            }
        }
    }

    public function get($codigo)
    {
        return $this->byCode[(string) $codigo] ?? false;
    }

    public function get_by_id($id)
    {
        return $this->byId[(int) $id] ?? false;
    }

    public function all($onlyActive = false)
    {
        return array_values($this->definitions);
    }

    public function is_deletable(): bool
    {
        return true;
    }

    public function save(): bool
    {
        $this->recorder->saves++;

        return true;
    }

    public function delete(): bool
    {
        $this->recorder->deletes++;

        return true;
    }
}

/**
 * Predefined-value entity double.
 */
final class PanelFixtureValor
{
    public $id;

    public $id_caracteristica;

    public $valor;

    public $orden = 0;

    public $activo = true;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data, private PanelPersistenceRecorder $recorder)
    {
        $this->id = $data['id'] ?? null;
        $this->id_caracteristica = $data['id_caracteristica'] ?? null;
        $this->valor = $data['valor'] ?? '';
        $this->orden = $data['orden'] ?? 0;
        $this->activo = $data['activo'] ?? true;
    }

    public function delete(): bool
    {
        $this->recorder->deletes++;

        return true;
    }
}

/**
 * Predefined-value-model double: repository for `get` plus the blank entity the
 * panel mutates for a new value.
 */
final class PanelFixtureValorModel
{
    /** @var array<int, PanelFixtureValor> */
    private array $byId = [];

    /**
     * @param list<PanelFixtureValor> $values
     */
    public function __construct(
        private PanelPersistenceRecorder $recorder,
        array $values = []
    ) {
        foreach ($values as $value) {
            $this->byId[(int) $value->id] = $value;
        }
    }

    public function get($id)
    {
        return $this->byId[(int) $id] ?? false;
    }

    public function save(): bool
    {
        $this->recorder->saves++;

        return true;
    }

    public function delete(): bool
    {
        $this->recorder->deletes++;

        return true;
    }

    public function all_from_caracteristica($idCaracteristica)
    {
        return [];
    }
}
