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

namespace Tests\CatalogoCore\Controller;

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Plugins\catalogo_core\Controller\VentasArticulos;
use FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent;
use FSFramework\Plugins\catalogo_core\Services\ArticuloListActionHandlerInterface;
use FSFramework\Plugins\catalogo_core\Services\ArticuloListActionRegistry;
use FSFramework\Plugins\catalogo_core\Services\ArticuloTarifaPrecioBatchReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * WU-3 absorption contract: the canonical ventas_articulos list absorbs the
 * retired tarifario list filters, per-tarifa columns and quick-create
 * (spec ALC-01..ALC-05/ALC-08; AD-W3-3, AD-W3-4, AD-W3-5, AD-W3-6, AD-W3-8).
 *
 * DB-free: an anonymous VentasArticulos subclass skips the controller
 * constructor and stubs the trait seams (tarifa_model, batch reader, price
 * model, articulo factory, CSRF/gate), so the real production method bodies
 * execute without a database. Source contracts pin the parts that cannot run
 * without one (POST-only delete, retired GET link).
 */
final class VentasArticulosListAbsorptionTest extends TestCase
{
    private const CONTROLLER = '/plugins/catalogo_core/Controller/VentasArticulos.php';

    /** @var object */
    private $controller;

    /** @var array<int, object> */
    public array $tarifasList = [];
    /** @var object|null */
    public $tarifaDefault = null;
    /** @var array<string, array<string, mixed>> */
    public array $batchMap = [];
    public int $batchCalls = 0;
    /** @var array<int, string> */
    public array $batchRefs = [];
    public string $batchCodtarifa = '';
    /** @var object */
    public $precioModel;
    /** @var object */
    public $trackedArticulo;
    public bool $csrfValid = true;
    public bool $guardAllowed = true;
    public bool $useRealGate = false;
    public int $guardCalls = 0;
    public string $guardCodtarifa = '';
    public int $articuloFactoryCalls = 0;
    public object $stateSpyHandler;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/impuesto.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/fabricante.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_articulo_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/ArticuloTarifaPrecioBatchReader.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/ArticuloListActionRegistry.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/extras/VentasArticulosListTrait.php';
        if (!class_exists('articulo', false)) {
            \fs_model_autoloader::ensureGlobalAlias('articulo');
        }
        if (!class_exists('impuesto', false)) {
            \fs_model_autoloader::ensureGlobalAlias('impuesto');
        }
        if (is_file(FS_FOLDER . self::CONTROLLER)) {
            require_once FS_FOLDER . self::CONTROLLER;
        }

        FSEventDispatcher::reset();
        ArticuloListActionRegistry::reset();
        $this->resetState();
        $this->precioModel = $this->buildTrackedPrecioModel();
        $this->trackedArticulo = $this->buildTrackedArticulo();
        $this->controller = $this->buildController();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        FSEventDispatcher::reset();
        ArticuloListActionRegistry::reset();
        parent::tearDown();
    }

    private function resetState(): void
    {
        $this->tarifasList = [];
        $this->tarifaDefault = null;
        $this->batchMap = [];
        $this->batchCalls = 0;
        $this->batchRefs = [];
        $this->batchCodtarifa = '';
        $this->csrfValid = true;
        $this->guardAllowed = true;
        $this->useRealGate = false;
        $this->guardCalls = 0;
        $this->guardCodtarifa = '';
        $this->articuloFactoryCalls = 0;
    }

    private function source(string $relative): string
    {
        $path = FS_FOLDER . $relative;
        if (!is_file($path)) {
            self::fail('missing path: ' . $relative);
        }

        return (string) file_get_contents($path);
    }

    private function methodSource(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            self::fail('missing method: ' . $signature);
        }

        $open = (int) strpos($src, '{', $start);
        $depth = 0;
        $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start + 1);
                }
            }
        }

        self::fail('method body is not terminated: ' . $signature);
    }

    private function buildTarifa(string $codtarifa, bool $defecto = false, string $coddivisa = 'EUR'): object
    {
        return (object) [
            'codtarifa' => $codtarifa,
            'nombre' => 'Tarifa ' . $codtarifa,
            'coddivisa' => $coddivisa,
            'por_defecto' => $defecto,
        ];
    }

    private function buildTrackedArticulo(): object
    {
        $outer = $this;
        return new class($outer) extends \articulo {
            public $outer;
            public bool $saveResult = true;
            public int $saveCalls = 0;

            public function __construct($outer)
            {
                $this->outer = $outer;
                $this->table_name = 'articulos';
                $this->referencia = '';
                $this->descripcion = '';
                $this->pvp = 0.0;
                $this->codfamilia = null;
                $this->codfabricante = null;
                $this->codimpuesto = null;
                $this->bloqueado = false;
            }

            public function get($ref = '')
            {
                return false;
            }

            public function save(): bool
            {
                $this->saveCalls++;
                return $this->saveResult;
            }

            public function delete(): bool
            {
                return true;
            }

            public function exists(): bool
            {
                return false;
            }
        };
    }

    private function buildTrackedPrecioModel(): object
    {
        return new class() extends \FSFramework\model\tarif_articulo_precio {
            /** @var array<string, float> */
            public array $written = [];
            public int $saveCalls = 0;

            public function __construct()
            {
                $this->table_name = 'tarif_articulo_precios';
            }

            public function get($ref, $codtarifa)
            {
                return false;
            }

            public function save(): bool
            {
                $this->saveCalls++;
                $this->written[(string) $this->codtarifa] = (float) $this->precio;

                return true;
            }
        };
    }

    private function buildController(): object
    {
        $outer = $this;
        $controller = new class($outer) extends VentasArticulos {
            public $outer;

            public function __construct($outer)
            {
                $this->outer = $outer;
                $this->className = 'ventas_articulos';
                $this->core_log = new \fs_core_log(VentasArticulos::class);
                $this->allow_delete = true;
                $this->can_import_export = true;
                $this->articulo = $outer->trackedArticulo;
                $this->resultados = [];
                $this->impuestos = [];
                $this->user = (object) ['admin' => false, 'nick' => 'tester'];
                $this->page = new class {
                    public function url(): string
                    {
                        return 'index.php?page=ventas_articulos';
                    }
                };
            }

            protected function validateFormToken(): bool
            {
                return $this->outer->csrfValid;
            }

            protected function tarifa_model(): \FSFramework\model\tarif_tarifa
            {
                return new class($this->outer->tarifasList, $this->outer->tarifaDefault) extends \FSFramework\model\tarif_tarifa {
                    public function __construct(private array $activas, private $default)
                    {
                        $this->table_name = 'tarif_tarifas';
                    }

                    public function all_activas()
                    {
                        return $this->activas;
                    }

                    public function get_default()
                    {
                        return $this->default;
                    }
                };
            }

            protected function articulo_precio_batch_reader(): ArticuloTarifaPrecioBatchReader
            {
                return new class($this->outer) extends ArticuloTarifaPrecioBatchReader {
                    public function __construct(private object $outer)
                    {
                    }

                    public function for_referencias(array $refs, string $codtarifa): array
                    {
                        $this->outer->batchCalls++;
                        $this->outer->batchRefs = $refs;
                        $this->outer->batchCodtarifa = $codtarifa;

                        return $this->outer->batchMap;
                    }
                };
            }

            protected function articulo_precio_model()
            {
                return $this->outer->precioModel;
            }

            protected function articulo_nuevo(): \articulo
            {
                $this->outer->articuloFactoryCalls++;

                return $this->outer->trackedArticulo;
            }

            protected function puedeCrearArticulo(string $referencia, string $codtarifa): bool
            {
                if ($this->outer->useRealGate) {
                    return parent::puedeCrearArticulo($referencia, $codtarifa);
                }

                $this->outer->guardCalls++;
                $this->outer->guardCodtarifa = $codtarifa;

                return $this->outer->guardAllowed;
            }

            public function setRequestObj(Request $request): void
            {
                $this->request = $request;
            }

            public function setUser(object $user): void
            {
                $this->user = $user;
            }

            public function callNuevoArticulo(Request $request): void
            {
                $method = new \ReflectionMethod(VentasArticulos::class, 'nuevoArticulo');
                $method->setAccessible(true);
                $method->invoke($this, $request);
            }

            public function callProcessExcelAction(): bool
            {
                $method = new \ReflectionMethod(VentasArticulos::class, 'processExcelAction');
                $method->setAccessible(true);

                return (bool) $method->invoke($this);
            }
        };

        return $controller;
    }

    // =====================================================================
    // ALC-01 — filter aliases + tarifa selection + solo_activos
    // =====================================================================

    public function test_query_and_b_codfamilia_alias_search_and_codfamilia(): void
    {
        $canonical = $this->buildController();
        $canonical->setRequestObj(Request::create('/index.php?page=ventas_articulos&search=TORNILLO&codfamilia=FAM-1'));
        $canonicalArgs = $canonical->list_search_args();

        $alias = $this->buildController();
        $alias->setRequestObj(Request::create('/index.php?page=ventas_articulos&query=TORNILLO&b_codfamilia=FAM-1'));
        $aliasArgs = $alias->list_search_args();

        $this->assertSame($canonicalArgs['search'], $aliasArgs['search'], 'query must alias search');
        $this->assertSame($canonicalArgs['codfamilia'], $aliasArgs['codfamilia'], 'b_codfamilia must alias codfamilia');
        $this->assertSame('TORNILLO', $aliasArgs['search']);
        $this->assertSame('FAM-1', $aliasArgs['codfamilia']);

        // Conflict: the canonical key wins.
        $conflict = $this->buildController();
        $conflict->setRequestObj(Request::create(
            '/index.php?page=ventas_articulos&search=CANON&query=ALIAS&codfamilia=CANONFAM&b_codfamilia=ALIASFAM'
        ));
        $conflictArgs = $conflict->list_search_args();
        $this->assertSame('CANON', $conflictArgs['search'], 'canonical search must win on conflict');
        $this->assertSame('CANONFAM', $conflictArgs['codfamilia'], 'canonical codfamilia must win on conflict');
    }

    public function test_b_codtarifa_resolves_default_then_first_active(): void
    {
        $this->tarifasList = [$this->buildTarifa('T1'), $this->buildTarifa('T2', true)];
        $this->tarifaDefault = $this->buildTarifa('T2', true);

        $controller = $this->buildController();
        $this->assertSame('T1', $controller->resolve_b_codtarifa('T1'), 'a requested active member wins');
        $this->assertSame('T2', $controller->resolve_b_codtarifa('NOPE'), 'otherwise the default tarifa resolves');
        $this->assertSame('T2', $controller->resolve_b_codtarifa(''), 'an empty request falls back to the default');

        // No default: the first active tarifa wins.
        $this->resetState();
        $this->tarifasList = [$this->buildTarifa('A'), $this->buildTarifa('B')];
        $this->tarifaDefault = null;
        $controller = $this->buildController();
        $this->assertSame('A', $controller->resolve_b_codtarifa(''), 'without a default the first active tarifa resolves');

        // No active tarifas at all: empty string.
        $this->resetState();
        $this->tarifasList = [];
        $this->tarifaDefault = null;
        $controller = $this->buildController();
        $this->assertSame('', $controller->resolve_b_codtarifa(''), 'zero active tarifas resolves to an empty string');
    }

    public function test_b_solo_activos_default_true_and_false_includes_blocked(): void
    {
        $default = $this->buildController();
        $default->setRequestObj(Request::create('/index.php?page=ventas_articulos'));
        $defaultArgs = $default->list_search_args();
        $this->assertTrue($defaultArgs['b_solo_activos'], 'b_solo_activos defaults to TRUE');
        $this->assertFalse($defaultArgs['bloqueados'], 'the default search excludes blocked articles');

        $onlyActive = $this->buildController();
        $onlyActive->setRequestObj(Request::create('/index.php?page=ventas_articulos&b_solo_activos=TRUE'));
        $this->assertFalse($onlyActive->list_search_args()['bloqueados']);

        $includeBlocked = $this->buildController();
        $includeBlocked->setRequestObj(Request::create('/index.php?page=ventas_articulos&b_solo_activos=FALSE'));
        $includeBlockedArgs = $includeBlocked->list_search_args();
        $this->assertFalse($includeBlockedArgs['b_solo_activos']);
        $this->assertTrue($includeBlockedArgs['bloqueados'], 'b_solo_activos=FALSE must include blocked articles');
    }

    // =====================================================================
    // ALC-02 — per-tarifa columns read the single batch map
    // =====================================================================

    public function test_per_tarifa_columns_read_the_batch_map(): void
    {
        $this->tarifasList = [$this->buildTarifa('T1', true, 'USD')];
        $this->tarifaDefault = $this->buildTarifa('T1', true, 'USD');
        $this->batchMap = [
            'A' => ['precio' => 12.5, 'activo' => true, 'en_tarifa' => true, 'en_catalogo' => false],
        ];

        $controller = $this->buildController();
        $controller->b_codtarifa = 'T1';
        $controller->tarifa_seleccionada = $this->buildTarifa('T1', true, 'USD');
        $controller->load_articulo_tarifa_columns(['A', 'B']);

        $this->assertSame(1, $this->batchCalls, 'the columns must read one batched map per page');
        $this->assertSame(['A', 'B'], $this->batchRefs);
        $this->assertSame('T1', $this->batchCodtarifa);

        $this->assertSame(12.5, $controller->get_precio_articulo_tarifa('A'));
        $this->assertTrue($controller->articulo_activo_tarifa('A'));
        $this->assertTrue($controller->articulo_en_tarifa_flag('A'));
        $this->assertFalse($controller->articulo_en_catalogo('A'));
        $this->assertNotSame('', $controller->mostrar_precio_tarifa('A'));
        $this->assertSame('$', $controller->simbolo_divisa_tarifa('USD'));

        // Missing row: ALC-02 defaults.
        $this->assertSame(0.0, $controller->get_precio_articulo_tarifa('B'));
        $this->assertTrue($controller->articulo_activo_tarifa('B'));
        $this->assertFalse($controller->articulo_en_tarifa_flag('B'));
        $this->assertFalse($controller->articulo_en_catalogo('B'));
    }

    public function test_filter_query_params_preserve_the_new_keys(): void
    {
        $controller = $this->buildController();
        $controller->setRequestObj(Request::create(
            '/index.php?page=ventas_articulos&query=q&b_codfamilia=F1&b_codtarifa=T1&b_solo_activos=FALSE&offset=50&action=export'
        ));

        $params = $controller->getListQueryParams();

        foreach (['query', 'b_codfamilia', 'b_codtarifa', 'b_solo_activos', 'offset'] as $key) {
            $this->assertArrayHasKey($key, $params, 'getListQueryParams() must preserve ' . $key);
        }
        $this->assertArrayNotHasKey('page', $params);
        $this->assertArrayNotHasKey('action', $params);
    }

    // =====================================================================
    // ALC-03 — quick-create per-tarifa fixed + percentage prices
    // =====================================================================

    public function test_quick_create_persists_fixed_and_percentage_prices(): void
    {
        $this->tarifasList = [$this->buildTarifa('T1'), $this->buildTarifa('T2'), $this->buildTarifa('T3')];
        $this->tarifaDefault = $this->buildTarifa('T1');
        $controller = $this->buildController();

        $request = $this->postRequest($controller, [
            'nreferencia' => 'REF-N',
            'ndescripcion' => 'Nuevo',
            'npvp' => '100',
            'precio_tarifa_T1' => '150',
            'porcentaje_tarifa_T2' => '10',
        ]);
        $controller->callNuevoArticulo($request);

        $this->assertSame(1, $this->articuloFactoryCalls, 'an allowed quick-create must build the article');
        $this->assertSame(1, $this->trackedArticulo->saveCalls);
        $this->assertSame(150.0, $this->precioModel->written['T1'] ?? null, 'a fixed price persists for its tarifa');
        $this->assertSame(110.0, $this->precioModel->written['T2'] ?? null, 'a percentage derives from the PVP (100 * 1.10)');
        $this->assertArrayNotHasKey('T3', $this->precioModel->written, 'an empty tarifa writes no row');

        // Fixed wins over percentage for the same tarifa.
        $this->resetState();
        $this->tarifasList = [$this->buildTarifa('T2')];
        $this->tarifaDefault = $this->buildTarifa('T2');
        $this->precioModel = $this->buildTrackedPrecioModel();
        $this->trackedArticulo = $this->buildTrackedArticulo();
        $controller = $this->buildController();
        $request = $this->postRequest($controller, [
            'nreferencia' => 'REF-M',
            'ndescripcion' => 'Mixto',
            'npvp' => '100',
            'precio_tarifa_T2' => '200',
            'porcentaje_tarifa_T2' => '10',
        ]);
        $controller->callNuevoArticulo($request);
        $this->assertSame(200.0, $this->precioModel->written['T2'] ?? null, 'the fixed price wins for the same tarifa');
    }

    public function test_quick_create_denied_or_csrf_invalid_persists_nothing(): void
    {
        $this->tarifasList = [$this->buildTarifa('TAR-1')];
        $this->tarifaDefault = $this->buildTarifa('TAR-1');

        // Invalid CSRF: no factory, no save, no price write, no gate.
        $controller = $this->buildController();
        $this->csrfValid = false;
        $controller->callNuevoArticulo($this->postRequest($controller, [
            'nreferencia' => 'REF-X',
            'ndescripcion' => 'X',
            'npvp' => '10',
            'precio_tarifa_TAR-1' => '11',
        ]));
        $this->assertSame(0, $this->articuloFactoryCalls, 'an invalid CSRF token must reject before any model access');
        $this->assertSame(0, $this->trackedArticulo->saveCalls);
        $this->assertSame(0, $this->precioModel->saveCalls);
        $this->assertSame(0, $this->guardCalls);

        // Denied gate: nothing persists and the event carries the resolved codtarifa.
        $this->resetState();
        $this->tarifasList = [$this->buildTarifa('TAR-1')];
        $this->tarifaDefault = $this->buildTarifa('TAR-1');
        $this->precioModel = $this->buildTrackedPrecioModel();
        $this->trackedArticulo = $this->buildTrackedArticulo();
        $controller = $this->buildController();
        $controller->b_codtarifa = 'TAR-1';
        $this->useRealGate = true;
        $seenCodtarifa = null;
        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            static function (ArticlePermissionFilterEvent $event) use (&$seenCodtarifa): void {
                $seenCodtarifa = $event->getCodtarifa();
                $event->deny('denegado');
            }
        );
        $request = $this->postRequest($controller, [
            'nreferencia' => 'REF-Y',
            'ndescripcion' => 'Y',
            'npvp' => '10',
            'precio_tarifa_TAR-1' => '11',
        ]);
        $controller->setUser((object) ['admin' => false, 'nick' => 'tester']);
        $controller->callNuevoArticulo($request);

        $this->assertSame('TAR-1', $seenCodtarifa, 'the permission event must carry the resolved codtarifa');
        $this->assertSame(0, $this->articuloFactoryCalls, 'a denied quick-create must not build the article');
        $this->assertSame(0, $this->trackedArticulo->saveCalls);
        $this->assertSame(0, $this->precioModel->saveCalls, 'a denied quick-create must persist no prices');
    }

    public function test_quick_create_save_failure_persists_no_prices(): void
    {
        $this->tarifasList = [$this->buildTarifa('T1')];
        $this->tarifaDefault = $this->buildTarifa('T1');
        $controller = $this->buildController();
        $this->trackedArticulo->saveResult = false;

        $controller->callNuevoArticulo($this->postRequest($controller, [
            'nreferencia' => 'REF-F',
            'ndescripcion' => 'Falla',
            'npvp' => '100',
            'precio_tarifa_T1' => '150',
        ]));

        $this->assertSame(1, $this->trackedArticulo->saveCalls);
        $this->assertSame(0, $this->precioModel->saveCalls, 'prices must never be attempted when the article save fails');
        $this->assertNotEmpty($controller->get_errors());
    }

    // =====================================================================
    // ALC-05 — neutral action seam + filtered export
    // =====================================================================

    public function test_action_entry_points_delegate_through_the_neutral_seam(): void
    {
        $seenActions = [];
        $seenState = null;
        $spy = new class($seenActions, $seenState) implements ArticuloListActionHandlerInterface {
            /** @var array<int, string> */
            public array $actions;
            /** @var array<string, mixed>|null */
            public $state;

            /** @param array<int, string> $actions */
            public function __construct(array &$actions, &$state)
            {
                $this->actions = &$actions;
                $this->state = &$state;
            }

            public function supports(string $action): bool
            {
                return $action === 'export_template';
            }

            public function handle(string $action, Request $request, array $state): bool
            {
                $this->actions[] = $action;
                $this->state = $state;

                return true;
            }
        };
        ArticuloListActionRegistry::register($spy);

        $this->tarifasList = [$this->buildTarifa('T1')];
        $this->tarifaDefault = $this->buildTarifa('T1');
        $controller = $this->buildController();
        $controller->setRequestObj(Request::create(
            '/index.php?page=ventas_articulos&action=export_template&search=q&b_codfamilia=F1&b_codtarifa=T1&b_solo_activos=FALSE&codfabricante=FAB&con_stock=TRUE'
        ));

        $this->assertTrue($controller->callProcessExcelAction(), 'a supported action must be handled through the seam');
        $this->assertSame(['export_template'], $seenActions);

        foreach ([
            'search', 'codfamilia', 'codfabricante', 'con_stock', 'bloqueados',
            'b_codtarifa', 'b_solo_activos', 'idiomas', 'tarifas', 'allow_delete',
        ] as $key) {
            $this->assertArrayHasKey($key, $spy->state, 'the seam state must carry ' . $key);
        }

        // Unknown action: no handler supports it, the canonical chain returns false.
        $controller = $this->buildController();
        $controller->setRequestObj(Request::create('/index.php?page=ventas_articulos&action=unknown_action'));
        $this->assertFalse($controller->callProcessExcelAction(), 'an unknown action must not be handled');
    }

    public function test_filtered_export_query_preserves_the_new_filters(): void
    {
        $controller = $this->buildController();
        $controller->setRequestObj(Request::create(
            '/index.php?page=ventas_articulos&query=q&b_codfamilia=F1&b_codtarifa=T1&b_solo_activos=FALSE&offset=50'
        ));

        $query = $controller->getExportFilteredQuery();

        $this->assertStringContainsString('query=q', $query);
        $this->assertStringContainsString('b_codfamilia=F1', $query);
        $this->assertStringContainsString('b_codtarifa=T1', $query);
        $this->assertStringContainsString('b_solo_activos=FALSE', $query);
        $this->assertStringNotContainsString('offset=', $query, 'the filtered export must not carry the page offset');
    }

    public function test_filtered_export_applies_the_resolved_alias_filters(): void
    {
        $src = $this->source(self::CONTROLLER);
        $filtered = $this->methodSource($src, 'private function loadArticulosForExportFiltered');
        $state = $this->methodSource($src, 'private function exportState');

        $this->assertStringContainsString(
            'list_search_args()',
            $filtered,
            'filtered export must resolve query/b_codfamilia aliases + solo_activos via list_search_args()'
        );
        $this->assertStringNotContainsString(
            "query->get('search'",
            $filtered,
            'filtered export must not read the canonical search key raw (query alias would be ignored)'
        );
        $this->assertStringNotContainsString(
            "query->get('codfamilia'",
            $filtered,
            'filtered export must not read codfamilia raw (b_codfamilia alias would be ignored)'
        );

        $this->assertStringContainsString(
            'list_search_args()',
            $state,
            'exportState must resolve the alias filters via list_search_args()'
        );
        $this->assertStringNotContainsString(
            "query->get('search'",
            $state,
            'exportState must not read the canonical search key raw (query alias would be ignored)'
        );
    }

    // =====================================================================
    // ALC-08 / AD-W3-8 — POST-only CSRF-guarded list delete
    // =====================================================================

    public function test_list_delete_is_csrf_guarded_post_only(): void
    {
        $src = $this->source(self::CONTROLLER);
        $delete = $this->methodSource($src, 'private function eliminarArticulo');

        $this->assertStringContainsString('validateFormToken()', $delete, 'the list delete must be CSRF validated');
        $this->assertStringContainsString('allow_delete', $delete, 'the list delete must keep the allow_delete guard');
        $this->assertStringContainsString('FS_DEMO', $delete, 'the list delete must keep the demo-mode guard');
        $this->assertStringContainsString("request->request->get('delete'", $delete, 'the list delete reads the POST delete field');
        $this->assertStringNotContainsString(
            "query->has('delete')",
            $src,
            'the dead ?delete= GET branch must be gone (AD-W3-8)'
        );
        $this->assertStringNotContainsString("query->get('delete'", $src, 'no delete mutation may read a GET delete parameter');
    }

    private function postRequest(object $controller, array $fields): Request
    {
        $_POST = $fields;
        $_REQUEST = $fields;
        $controller->tarifas = $this->tarifasList;
        $controller->tarifa_seleccionada = $this->tarifaDefault;
        $request = Request::create('/index.php?page=ventas_articulos', 'POST', $fields);
        $controller->setRequestObj($request);
        $controller->setUser((object) ['admin' => true, 'nick' => 'tester']);

        return $request;
    }
}
