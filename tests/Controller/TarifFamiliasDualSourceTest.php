<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore\Controller;

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent;
use FSFramework\Plugins\catalogo_core\Services\CatalogoOptions;
use FSFramework\Plugins\catalogo_core\Services\FamiliaEstructuraStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\CatalogoCore\Support\FakeFamiliaEstructuraDb;
use Tests\CatalogoCore\Support\FakeFamiliaEstructuraModel;
use Tests\CatalogoCore\Support\FakeFamiliaEstructuraTable;

require_once FS_FOLDER . '/base/fs_controller.php';
require_once FS_FOLDER . '/base/fs_core_log.php';
require_once __DIR__ . '/../Support/FakeFamiliaEstructura.php';

if (!class_exists('tarif_familias', false)) {
    require_once FS_FOLDER . '/plugins/catalogo_core/controller/tarif_familias.php';
}

/**
 * Legacy `tarif_tarifa_familia` row double bound to an in-memory model.
 */
#[\AllowDynamicProperties]
final class FakeLegacyFamiliaRow
{
    public $codfamilia;

    public $madre;

    public $capitulo;

    public $nivel = '';

    public $en_catalogo = true;

    public $en_tarifa = false;

    public $activa = true;

    public $orden = 0;

    public $descripcion = '';

    public $nivel_tree = 0;

    public $hijas_tree = [];

    public $nivel_visual = 0;

    public function __construct(private FakeLegacyTarifaFamilia $model, string $codfamilia)
    {
        $state = $model->rows[$codfamilia];
        $this->codfamilia = $codfamilia;
        $this->madre = $state['madre'];
        $this->capitulo = $state['capitulo'];
        $this->en_catalogo = $state['en_catalogo'];
        $this->en_tarifa = $state['en_tarifa'];
        $this->activa = $state['activa'];
        $this->orden = $state['orden'];
        $this->descripcion = 'Descripcion ' . $codfamilia;
    }

    public function save(): bool
    {
        $this->model->persist($this);

        return true;
    }

    public function delete(): bool
    {
        $this->model->remove($this->codfamilia);

        return true;
    }
}

/**
 * Legacy `tarif_tarifa_familia` model double: records every write so a
 * flag-on test can prove the legacy table is never touched.
 */
final class FakeLegacyTarifaFamilia
{
    /** @var array<string, array<string, mixed>> codfamilia => state */
    public array $rows = [];

    public int $saves = 0;

    public int $deletes = 0;

    public int $gets = 0;

    public int $adds = 0;

    /** @var list<array<string, string>> */
    public array $chapterMaps = [];

    public bool $applyOk = true;

    public function seed(string $codfamilia, ?string $madre = null, string $capitulo = '', array $flags = []): void
    {
        $this->rows[$codfamilia] = array_merge([
            'madre' => $madre,
            'capitulo' => $capitulo,
            'en_catalogo' => true,
            'en_tarifa' => false,
            'activa' => true,
            'orden' => 0,
        ], $flags);
    }

    public function get($codtarifa, $codfamilia)
    {
        $this->gets++;

        return isset($this->rows[(string) $codfamilia]) ? new FakeLegacyFamiliaRow($this, (string) $codfamilia) : false;
    }

    public function persist(FakeLegacyFamiliaRow $row): void
    {
        $this->rows[$row->codfamilia] = [
            'madre' => $row->madre,
            'capitulo' => $row->capitulo,
            'en_catalogo' => (bool) $row->en_catalogo,
            'en_tarifa' => (bool) $row->en_tarifa,
            'activa' => (bool) $row->activa,
            'orden' => (int) $row->orden,
        ];
        $this->saves++;
    }

    public function remove(string $codfamilia): void
    {
        unset($this->rows[$codfamilia]);
        $this->deletes++;
    }

    public function all_by_capitulo($codtarifa): array
    {
        return array_map(
            fn (string $codfamilia): FakeLegacyFamiliaRow => new FakeLegacyFamiliaRow($this, $codfamilia),
            array_keys($this->rows)
        );
    }

    public function all_activas_from_tarifa($codtarifa): array
    {
        return array_values(array_filter(
            $this->all_by_capitulo($codtarifa),
            static fn (FakeLegacyFamiliaRow $row): bool => (bool) $row->activa
        ));
    }

    public function all_from_tarifa($codtarifa): array
    {
        return $this->all_by_capitulo($codtarifa);
    }

    public function hijas($codtarifa, $codmadre): array
    {
        return array_values(array_filter(
            $this->all_by_capitulo($codtarifa),
            static fn (FakeLegacyFamiliaRow $row): bool => $row->madre === $codmadre
        ));
    }

    public function suggest_capitulo($codtarifa, $madre = null): string
    {
        return '1';
    }

    public function apply_chapter_map(string $codtarifa, array $chapters): bool
    {
        $this->chapterMaps[] = $chapters;

        return $this->applyOk;
    }

    public function add_familia_to_tarifa($codtarifa, $codfamilia, $madre = null, $en_catalogo = true, $en_tarifa = false, $activa = true)
    {
        $this->adds++;
        $this->seed((string) $codfamilia, $madre, '1', [
            'en_catalogo' => $en_catalogo,
            'en_tarifa' => $en_tarifa,
            'activa' => $activa,
        ]);

        return new FakeLegacyFamiliaRow($this, (string) $codfamilia);
    }
}

/**
 * Price-list catalog double for the selector.
 */
final class FakeListaPrecioCatalog
{
    /** @var list<object> */
    public array $lists;

    public int $calls = 0;

    /**
     * @param list<string> $codes    active lists
     * @param string       $default  code of the default list
     * @param list<string> $inactive inactive lists (must never reach the selector)
     */
    public function __construct(array $codes = [], string $default = '', array $inactive = [])
    {
        $this->lists = [];
        foreach ($codes as $code) {
            $this->lists[] = (object) [
                'codlista' => $code,
                'nombre' => 'Lista ' . $code,
                'activa' => true,
                'por_defecto' => $code === $default,
                'coddivisa' => 'EUR',
            ];
        }
        foreach ($inactive as $code) {
            $this->lists[] = (object) [
                'codlista' => $code,
                'nombre' => 'Lista ' . $code,
                'activa' => false,
                'por_defecto' => $code === $default,
                'coddivisa' => 'EUR',
            ];
        }
    }

    public function all(): array
    {
        $this->calls++;

        return $this->lists;
    }
}

/**
 * Shared context injected into the controller double.
 */
final class TarifFamiliasTestContext
{
    public array $optionsRaw = [];

    /** @var FakeFamiliaEstructuraTable */
    public $table;

    /** @var FakeFamiliaEstructuraDb */
    public $db;

    public FamiliaEstructuraStore $store;

    public FakeLegacyTarifaFamilia $legacy;

    public FakeListaPrecioCatalog $listModel;

    public function __construct()
    {
        $this->table = new FakeFamiliaEstructuraTable();
        $this->db = new FakeFamiliaEstructuraDb($this->table);
        $this->store = new FamiliaEstructuraStore($this->db, 'juan', new FakeFamiliaEstructuraModel($this->table));
        $this->legacy = new FakeLegacyTarifaFamilia();
        $this->listModel = new FakeListaPrecioCatalog([]);
    }
}

/**
 * `tarif_familias` test double: skips the heavy FS constructor, forces the
 * HTMX response path (no `exit`) and captures flash + rendered fragments.
 */
final class TarifFamiliasTestController extends \tarif_familias
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $messages = [];

    /** @var list<string> */
    public array $advices = [];

    /** @var list<array{type: string, value: mixed}> */
    public array $responses = [];

    public TarifFamiliasTestContext $context;

    public function __construct(TarifFamiliasTestContext $context)
    {
        // Deliberately skip parent::__construct(): no Kernel, no session, no DB.
        $this->context = $context;
        $this->core_log = new \fs_core_log('tarif_familias');
        $this->class_name = 'tarif_familias';
        $this->user = new class {
            public string $nick = 'juan';
            public bool $admin = false;
        };
        $this->db = $context->db;
        $this->allow_delete = true;
        $this->template = false;
        $this->tarifas = [];
        $this->familias_tree = [];
        $this->familias_disponibles = [];
        $this->editing_familia = null;
        $this->b_solo_activas = false;
        $this->family_structure = false;
        $this->listas_estructura = [];
        $this->codlista_estructura = '';
        $this->estructura_por_familia = [];
        $this->codtarifa = '';
        $this->tarifa = null;
        $this->tarifa_etiqueta_familia = null;
        $this->tarifa_familia = $context->legacy;
    }

    public function new_error_msg($msg, $tipo = 'error', $alerta = false, $guardar = true)
    {
        $this->errors[] = (string) $msg;
    }

    public function new_message($msg, $save = false, $tipo = 'msg', $alerta = false)
    {
        $this->messages[] = (string) $msg;
    }

    public function new_advice($msg)
    {
        $this->advices[] = (string) $msg;
    }

    protected function requireHtmx(): bool
    {
        return true;
    }

    protected function renderTbodyFragment(array $rows, array $options = []): void
    {
        $this->responses[] = ['type' => 'tbody', 'value' => count($rows)];
    }

    protected function renderRowFragment(object $row, array $params = [], array $options = []): void
    {
        $this->responses[] = ['type' => 'row', 'value' => (string) $row->codfamilia];
    }

    protected function renderFragment(string $partial, array $params = [], array $options = []): void
    {
        $this->responses[] = ['type' => 'fragment', 'value' => $params];
    }

    protected function noContentWithFlash(): void
    {
        $this->responses[] = ['type' => '204', 'value' => null];
    }

    protected function catalogo_options(): CatalogoOptions
    {
        return new CatalogoOptions($this->context->optionsRaw);
    }

    protected function lista_precio_model()
    {
        return $this->context->listModel;
    }

    protected function familia_estructura_store(): FamiliaEstructuraStore
    {
        return $this->context->store;
    }

    public function lastResponseType(): string
    {
        $last = end($this->responses);

        return $last === false ? '' : $last['type'];
    }

    public function errorText(): string
    {
        return implode(' | ', $this->errors);
    }
}

/**
 * familias-jerarquia tasks 3.1/3.3 — dual-source family page.
 *
 * Contract pinned here:
 *
 *  - flag OFF: every read/write keeps using the legacy `tarif_tarifa_familia`
 *    model (rollback guarantee; the source contract test stays untouched);
 *  - flag ON: the page operates on `catalogo_familia_estructura` scoped by the
 *    selected `codlista`, reads the list with ONE batch query, upserts through
 *    the canonical model and never writes a `tarif_*` table;
 *  - re-parenting runs `FamiliaTreeGuard` plus the list-scoped guard BEFORE any
 *    write; a cycle reports an error and leaves the row untouched;
 *  - every structure write dispatches `ArticlePermissionFilterEvent`; a denied
 *    or throwing listener blocks the write (fail-closed).
 */
final class TarifFamiliasDualSourceTest extends TestCase
{
    private TarifFamiliasTestContext $context;

    private TarifFamiliasTestController $controller;

    private ?string $previousErrorLog = null;

    protected function setUp(): void
    {
        parent::setUp();

        global $plugins;
        $plugins = [];
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];

        FSEventDispatcher::reset();
        $this->previousErrorLog = ini_get('error_log') ?: null;

        $this->context = new TarifFamiliasTestContext();
        $this->controller = new TarifFamiliasTestController($this->context);
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== null) {
            ini_set('error_log', $this->previousErrorLog);
        }
        FSEventDispatcher::reset();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];

        parent::tearDown();
    }

    // =====================================================================
    // Flag OFF — the legacy path stays byte-for-byte (rollback guarantee)
    // =====================================================================

    #[Test]
    public function flagOffExposesNoListSelectorAndReadsNoStructure(): void
    {
        $this->context->listModel = new FakeListaPrecioCatalog(['L1'], 'L1');

        $this->bootStructure([]);

        $this->assertFalse($this->controller->family_structure, 'Flag off keeps the legacy path');
        $this->assertSame([], $this->controller->listas_estructura, 'Flag off exposes no list selector');
        $this->assertSame('', $this->controller->codlista_estructura);
        $this->assertSame(0, $this->context->listModel->calls, 'Flag off reads no price list');
        $this->assertSame(0, $this->context->db->structureQueries(), 'Flag off reads no canonical structure');
    }

    #[Test]
    public function flagOffSaveStillUsesTheLegacyModel(): void
    {
        $this->context->legacy->seed('F1', null, '1');
        $this->bootStructure([]);
        $this->controller->codtarifa = 'T1';

        $_POST = ['codfamilia' => 'F1', 'capitulo' => '2', 'activa' => '1'];
        $_REQUEST = $_POST;

        $this->invoke('save_familia');

        $this->assertSame(1, $this->context->legacy->saves, 'Flag off writes through tarif_tarifa_familia');
        $this->assertSame('2', $this->context->legacy->rows['F1']['capitulo']);
        $this->assertTrue((bool) $this->context->legacy->rows['F1']['activa']);
        $this->assertSame(0, $this->context->table->writes, 'Flag off writes no canonical structure row');
        $this->assertSame('tbody', $this->controller->lastResponseType());
    }

    #[Test]
    public function flagOffSourceContractSurvives(): void
    {
        // The dual-source switch must not change the file contract pinned by
        // tests/TarifFamiliasControllerContractTest.php.
        $source = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/controller/tarif_familias.php');

        $this->assertStringContainsString(
            "parent::__construct(__CLASS__, 'Familias', 'catalogo');",
            $source,
            'The page name/menu contract is untouched'
        );
        $this->assertSame(
            2,
            substr_count($source, '$this->count_tarifa_articulos($this->codtarifa'),
            'The legacy article-count helper keeps exactly its two call sites'
        );
        $this->assertSame(0, substr_count($source, 'plugins/tarifario'));
    }

    // =====================================================================
    // Flag ON — list selector and batch read (task 3.1, D5)
    // =====================================================================

    #[Test]
    public function flagOnOffersOnlyActiveListsAndDefaultsToTheDefaultOne(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1', 'L2'], 'L1', ['L9']);

        $this->bootStructure([]);

        $codes = array_map(static fn (object $list): string => (string) $list->codlista, $this->controller->listas_estructura);
        $this->assertSame(['L1', 'L2'], $codes, 'Only the active lists feed the selector');
        $this->assertSame('L1', $this->controller->codlista_estructura, 'The default list is selected');
    }

    #[Test]
    public function flagOnNeverSelectsAnInactiveList(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1'], 'L1', ['L9']);

        $this->bootStructure(['codlista' => 'L9']);

        $this->assertSame('L1', $this->controller->codlista_estructura, 'An inactive list is not selectable');
    }

    // =====================================================================
    // Task 3.2 — the template addresses the list in context (no codtarifa)
    // =====================================================================

    #[Test]
    public function flagOffSourceQueryTargetsTheLegacyTariff(): void
    {
        $this->bootStructure([]);
        $this->controller->codtarifa = 'T1';

        $this->assertSame('codtarifa=T1', $this->invoke('source_query'), 'Flag off keeps the legacy query fragment');
    }

    #[Test]
    public function flagOnSourceQueryTargetsTheListInContext(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1', 'L2'], 'L1');
        $this->bootStructure(['codlista' => 'L2']);

        // A legacy code in the request must never leak into the canonical URLs.
        $this->controller->codtarifa = 'T1';

        $this->assertSame('codlista=L2', $this->invoke('source_query'));
    }

    #[Test]
    public function flagOnSourceQueryEscapesTheListCode(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L 1'], 'L 1');
        $this->bootStructure([]);

        $this->assertSame('codlista=L%201', $this->invoke('source_query'));
    }

    #[Test]
    public function flagOnWithNoActiveListStillReturnsAWellFormedFragment(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog([]);
        $this->bootStructure([]);

        $this->assertSame('codlista=', $this->invoke('source_query'), 'The parameter name survives with an empty value');
    }

    #[Test]
    public function flagOnRespectsTheSuppliedCodlistaAndFallsBackWhenInvalid(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1', 'L2'], 'L1');

        $this->bootStructure(['codlista' => 'L2']);
        $this->assertSame('L2', $this->controller->codlista_estructura);

        $this->bootStructure(['codlista' => 'GHOST']);
        $this->assertSame('L1', $this->controller->codlista_estructura, 'An unknown list falls back to the default');
    }

    #[Test]
    public function flagOnReadsOneListWithOneBatchQueryAndNestsByStructureMother(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1', 'L2'], 'L1');
        $this->context->table->seed('L1', 'F1', null, '1');
        $this->context->table->seed('L1', 'F2', 'F1', '1.1');
        $this->context->table->seed('L2', 'F9', null, '1');
        $this->bootStructure(['codlista' => 'L1']);

        $this->invoke('load_familias_tree');

        $this->assertCount(1, $this->controller->familias_tree, 'Only the list roots are top level');
        $this->assertSame('F1', (string) $this->controller->familias_tree[0]->codfamilia);
        $this->assertCount(1, $this->controller->familias_tree[0]->hijas_tree);
        $this->assertSame('F2', (string) $this->controller->familias_tree[0]->hijas_tree[0]->codfamilia);
        $this->assertSame(1, $this->context->db->structureQueries(), 'The tree is read with ONE batch query');
        $this->assertArrayNotHasKey('F9', $this->controller->estructura_por_familia, 'A sibling list never leaks');
    }

    #[Test]
    public function flagOnSuggestsTheNextChapterFromTheStore(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1'], 'L1');
        $this->context->table->seed('L1', 'F1', null, '1');
        $this->context->table->seed('L1', 'F2', null, '2');
        $this->bootStructure([]);

        $_REQUEST = ['madre' => ''];
        $this->invoke('action_get_next_capitulo');

        $last = end($this->controller->responses);
        $this->assertSame('fragment', $last['type']);
        $this->assertSame('3', $last['value']['capitulo']);
    }

    // =====================================================================
    // Flag ON — writes (upsert, list scope, no legacy writes)
    // =====================================================================

    #[Test]
    public function flagOnSaveUpsertsOnlyTheSelectedList(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1', 'L2'], 'L1');
        $this->context->table->seed('L1', 'F1', null, '1');
        $this->context->table->seed('L2', 'F1', null, '9');
        $this->bootStructure(['codlista' => 'L1']);

        $_POST = ['codfamilia' => 'F1', 'madre' => '', 'capitulo' => '2', 'activa' => '1'];
        $_REQUEST = $_POST;
        $this->invoke('save_familia');

        $this->assertSame('2', $this->context->table->get('L1', 'F1')['capitulo']);
        $this->assertTrue((bool) $this->context->table->get('L1', 'F1')['activa']);
        $this->assertFalse((bool) $this->context->table->get('L1', 'F1')['en_catalogo'], 'Unchecked flags are stored as false');
        $this->assertSame('9', $this->context->table->get('L2', 'F1')['capitulo'], 'The sibling list is untouched');
        $this->assertSame(1, $this->context->table->writes);
        $this->assertSame('tbody', $this->controller->lastResponseType());
    }

    #[Test]
    public function flagOnReorderWritesOnlyTheChangedChapters(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1'], 'L1');
        $this->context->table->seed('L1', 'F1', null, '1');
        $this->context->table->seed('L1', 'F2', null, '2');
        $this->bootStructure([]);

        $_POST = ['order' => json_encode(['F2', 'F1'])];
        $_REQUEST = $_POST;
        $this->invoke('action_reorder');

        $this->assertSame('2', $this->context->table->get('L1', 'F1')['capitulo']);
        $this->assertSame('1', $this->context->table->get('L1', 'F2')['capitulo']);
        $this->assertSame(2, $this->context->table->writes);
        $this->assertSame([], $this->context->legacy->chapterMaps, 'The legacy chapter map is never applied');
    }

    #[Test]
    public function flagOnPromoteReparentsTheStructureRow(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1'], 'L1');
        $this->context->table->seed('L1', 'F1', null, '1');
        $this->context->table->seed('L1', 'F2', 'F1', '1.1');
        $this->context->table->seed('L1', 'F3', 'F2', '1.1.1');
        $this->context->db->madres = ['F1' => null, 'F2' => 'F1', 'F3' => 'F2'];
        $this->bootStructure([]);

        $_POST = ['codfamilia' => 'F3'];
        $_REQUEST = $_POST;
        $this->invoke('action_promote');

        $this->assertSame('F1', $this->context->table->get('L1', 'F3')['madre']);
        $this->assertSame(0, $this->context->legacy->saves, 'No legacy row is saved');
    }

    #[Test]
    public function flagOnDemoteReparentsTheStructureRow(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1'], 'L1');
        $this->context->table->seed('L1', 'F1', null, '1');
        $this->context->table->seed('L1', 'F2', null, '2');
        $this->context->db->madres = ['F1' => null, 'F2' => null];
        $this->bootStructure([]);

        $_POST = ['codfamilia' => 'F2'];
        $_REQUEST = $_POST;
        $this->invoke('action_demote');

        $this->assertSame('F1', $this->context->table->get('L1', 'F2')['madre']);
        $this->assertSame(0, $this->context->legacy->saves);
    }

    #[Test]
    public function flagOnTogglesUpdateTheStructureRow(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1'], 'L1');
        $this->context->table->seed('L1', 'F1', null, '1');
        $this->bootStructure([]);

        $_POST = ['codfamilia' => 'F1'];
        $_REQUEST = $_POST;
        $result = $this->invoke('ajax_toggle_activa');
        $this->assertTrue($result['success']);
        $this->assertFalse((bool) $this->context->table->get('L1', 'F1')['activa']);

        $result = $this->invoke('ajax_toggle_familia_visibility', ['en_catalogo']);
        $this->assertTrue($result['success']);
        $this->assertFalse((bool) $this->context->table->get('L1', 'F1')['en_catalogo']);
        $this->assertSame(0, $this->context->legacy->saves);
    }

    #[Test]
    public function flagOnDeleteRemovesOnlyTheListRow(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1', 'L2'], 'L1');
        $this->context->table->seed('L1', 'F1', null, '1');
        $this->context->table->seed('L2', 'F1', null, '1');
        $this->bootStructure([]);

        $_POST = ['codfamilia' => 'F1'];
        $_REQUEST = $_POST;
        $this->invoke('delete_familia');

        $this->assertNull($this->context->table->get('L1', 'F1'));
        $this->assertNotNull($this->context->table->get('L2', 'F1'), 'The sibling list keeps its row');
        $this->assertSame(1, $this->context->table->deletes);
        $this->assertSame(0, $this->context->legacy->deletes);
    }

    // =====================================================================
    // Task 3.3 — cycle rejection before any write (R-FJ-001)
    // =====================================================================

    #[Test]
    public function flagOnSaveRejectsADirectCycleLeavingTheRowIntact(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1'], 'L1');
        $this->context->table->seed('L1', 'F1', null, '1');
        $this->context->table->seed('L1', 'F2', 'F1', '1.1');
        $this->context->db->madres = ['F1' => null, 'F2' => 'F1'];
        $this->bootStructure([]);

        $this->saveReparent('F1', 'F2');

        $this->assertStringContainsString('cycle', strtolower($this->controller->errorText()));
        $this->assertNull($this->context->table->get('L1', 'F1')['madre'], 'The row keeps its old mother');
        $this->assertSame(0, $this->context->table->writes, 'A cycle writes nothing');
        $this->assertSame(0, $this->context->legacy->saves);
        $this->assertSame('204', $this->controller->lastResponseType());
    }

    #[Test]
    public function flagOnSaveRejectsADeepCycle(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1'], 'L1');
        $this->context->table->seed('L1', 'N1', null, '1');
        $this->context->table->seed('L1', 'N2', 'N1', '1.1');
        $this->context->table->seed('L1', 'N3', 'N2', '1.1.1');
        $this->context->table->seed('L1', 'N4', 'N3', '1.1.1.1');
        $this->context->db->madres = ['N1' => null, 'N2' => 'N1', 'N3' => 'N2', 'N4' => 'N3'];
        $this->bootStructure([]);

        $this->saveReparent('N1', 'N4');

        $this->assertStringContainsString('cycle', strtolower($this->controller->errorText()));
        $this->assertSame(0, $this->context->table->writes);
        $this->assertNull($this->context->table->get('L1', 'N1')['madre']);
    }

    #[Test]
    public function flagOnSaveRejectsASelfParent(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1'], 'L1');
        $this->context->table->seed('L1', 'F1', null, '1');
        $this->context->db->madres = ['F1' => null];
        $this->bootStructure([]);

        $this->saveReparent('F1', 'F1');

        $this->assertStringContainsString('cycle', strtolower($this->controller->errorText()));
        $this->assertSame(0, $this->context->table->writes);
    }

    #[Test]
    public function flagOnSaveRejectsACycleThatOnlyExistsInTheListStructure(): void
    {
        // Base familias is flat, so only the list-scoped chain can detect it.
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1'], 'L1');
        $this->context->table->seed('L1', 'F1', null, '1');
        $this->context->table->seed('L1', 'F2', 'F1', '1.1');
        $this->context->table->seed('L1', 'F3', 'F2', '1.1.1');
        $this->context->db->madres = ['F1' => null, 'F2' => null, 'F3' => null];
        $this->bootStructure([]);

        $this->saveReparent('F1', 'F3');

        $this->assertStringContainsString('cycle', strtolower($this->controller->errorText()));
        $this->assertSame(0, $this->context->table->writes);
        $this->assertNull($this->context->table->get('L1', 'F1')['madre']);
    }

    // =====================================================================
    // Task 3.1 — permission event on structure writes (D6)
    // =====================================================================

    #[Test]
    public function flagOnSaveDispatchesTheEventAndPersistsWhenAllowed(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1'], 'L1');
        $this->context->table->seed('L1', 'F1', null, '1');
        $this->bootStructure([]);
        $captured = null;
        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            static function (ArticlePermissionFilterEvent $event) use (&$captured): void {
                $captured = $event;
            }
        );

        $this->saveReparent('F1', '');

        $this->assertInstanceOf(ArticlePermissionFilterEvent::class, $captured);
        $this->assertSame(ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE, $captured->getAction());
        $this->assertSame('F1', $captured->getReferencia());
        $this->assertSame('juan', $captured->getNick());
        $this->assertSame('L1', $captured->getCodtarifa());
        $this->assertSame(1, $this->context->table->writes);
    }

    #[Test]
    public function flagOnDeniedEventBlocksTheWriteAndReportsTheReason(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1'], 'L1');
        $this->context->table->seed('L1', 'F1', null, '1');
        $this->bootStructure([]);
        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            static function (ArticlePermissionFilterEvent $event): void {
                $event->deny('sin permiso sobre la familia');
            }
        );

        $this->saveReparent('F1', '');

        $this->assertSame(0, $this->context->table->writes, 'A denied event writes nothing');
        $this->assertSame('1', $this->context->table->get('L1', 'F1')['capitulo'], 'The row is intact');
        $this->assertStringContainsString('sin permiso sobre la familia', $this->controller->errorText());
        $this->assertSame('204', $this->controller->lastResponseType());
    }

    #[Test]
    public function flagOnThrowingListenerDeniesFailClosed(): void
    {
        ini_set('error_log', '/dev/null');
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1'], 'L1');
        $this->context->table->seed('L1', 'F1', null, '1');
        $this->bootStructure([]);
        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            static function (): never {
                throw new \RuntimeException('listener bug');
            }
        );

        $this->saveReparent('F1', '');

        $this->assertSame(0, $this->context->table->writes, 'A throwing listener must deny fail-closed');
        $this->assertSame('1', $this->context->table->get('L1', 'F1')['capitulo']);
    }

    // =====================================================================
    // Task 3.1 — the flag-on path never writes tarif_*
    // =====================================================================

    #[Test]
    public function flagOnNeverWritesALegacyTable(): void
    {
        $this->enableFlagOn();
        $this->context->listModel = new FakeListaPrecioCatalog(['L1'], 'L1');
        $this->context->table->seed('L1', 'F1', null, '1');
        $this->context->table->seed('L1', 'F2', null, '2');
        $this->context->db->madres = ['F1' => null, 'F2' => null];
        $this->bootStructure([]);

        $this->saveReparent('F1', '');
        $_POST = ['order' => json_encode(['F2', 'F1'])];
        $_REQUEST = $_POST;
        $this->invoke('action_reorder');
        $_POST = ['codfamilia' => 'F1'];
        $_REQUEST = $_POST;
        $this->invoke('ajax_toggle_activa');
        $this->invoke('delete_familia');

        $this->assertSame(0, $this->context->legacy->saves, 'Zero legacy writes');
        $this->assertSame(0, $this->context->legacy->deletes, 'Zero legacy deletes');
        $this->assertSame(0, $this->context->legacy->gets, 'Zero legacy reads in the canonical path');
        $this->assertGreaterThan(0, $this->context->table->writes, 'The canonical table received the writes');
        foreach ($this->context->db->allSql() as $sql) {
            $this->assertStringNotContainsString('tarif_', $sql, 'The canonical path must not touch tarif_*');
        }
    }

    // =====================================================================
    // Harness
    // =====================================================================

    private function enableFlagOn(): void
    {
        $this->context->optionsRaw = [CatalogoOptions::KEY_FAMILY_STRUCTURE => 'true'];
    }

    /**
     * Runs the real `init_family_structure()` with the supplied request params.
     *
     * @param array<string, string> $query
     */
    private function bootStructure(array $query): void
    {
        $_GET = $query;
        $_REQUEST = $query;
        $this->invoke('init_family_structure');
    }

    /**
     * Runs the real `save_familia()` save path re-parenting $codfamilia.
     */
    private function saveReparent(string $codfamilia, string $madre): void
    {
        $_POST = ['codfamilia' => $codfamilia, 'madre' => $madre, 'capitulo' => '2', 'activa' => '1'];
        $_REQUEST = $_POST;
        $this->invoke('save_familia');
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invoke(string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($this->controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->controller, $args);
    }
}
