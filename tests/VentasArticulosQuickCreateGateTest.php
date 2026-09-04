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
use FSFramework\Security\CsrfManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Behavioral gate tests for the quick-create article form (change
 * ventas-articulos-quick-create-gate, domain articulos-quick-create-permission).
 *
 * R-QCRT-001..007 are driven through the REAL VentasArticulos::nuevoArticulo()
 * dispatch path with real listeners registered on FSEventDispatcher — no
 * source-string/line pins on the controller (REL-2 lesson).
 *
 * Harness (design D3): a reflection-built controller
 * (newInstanceWithoutConstructor) with the protected core_log and the
 * articulo duplicate-check stub injected; a benign fs_db2::$engine stub makes
 * the allow path (real \articulo construction + save()) complete DB-free.
 * Process isolation (RunTestsInSeparateProcesses + PreserveGlobalState(false))
 * keeps the engine stub and the loaded plugin models from leaking into the
 * shared root-suite process.
 *
 * error_log is redirected to a temp file for the whole class: validateFormToken
 * logs on invalid tokens (R-QCRT-006) and the host suite runs with
 * processIsolation="true", which would surface any stderr write as a PHPUnit
 * error. The R-QCRT-004 test asserts the captured file stays free of listener
 * lines (the new gate must NOT error_log, per the ArticuloExcelPermissionGate
 * precedent).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class VentasArticulosQuickCreateGateTest extends TestCase
{
    private const CONTROLLER_CLASS = \FSFramework\Plugins\catalogo_core\Controller\VentasArticulos::class;

    private const DENY_MESSAGE_PREFIX = 'No tienes permisos para crear este artículo: ';
    private const CSRF_ERROR_MESSAGE = 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.';

    private object $controller;
    private object $engineStub;
    private string $errorLogFile = '';
    private string|false $previousErrorLog = false;
    private int $bufferLevelAtSetup = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bufferLevelAtSetup = ob_get_level();
        ob_start();
        $this->captureErrorLog();
        $this->resetCoreLog();
        $this->loadModelClasses();
        $this->stubDbEngine();
        FSEventDispatcher::reset();

        // fs_model::get_base_dir() resolves model/table XML schemas through
        // $GLOBALS['plugins']; without the plugin listed the allow-path model
        // construction cannot find articulos.xml/impuestos.xml.
        $GLOBALS['plugins'] = ['catalogo_core'];
    }

    protected function tearDown(): void
    {
        FSEventDispatcher::reset();
        $this->resetCoreLog();
        while (ob_get_level() > $this->bufferLevelAtSetup) {
            ob_end_clean();
        }
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', (string) $this->previousErrorLog);
        }
        if ($this->errorLogFile !== '' && is_file($this->errorLogFile)) {
            @unlink($this->errorLogFile);
        }
        parent::tearDown();
    }

    // ---- Harness -----------------------------------------------------------

    /**
     * Builds a fresh reflection controller with the injected harness state.
     *
     * @param array<string, string> $postData quick-create form fields
     */
    private function buildController(
        array $postData = [],
        string $nick = 'editor1',
        bool $isAdmin = false,
        bool $validCsrf = true
    ): void {
        $reflection = new \ReflectionClass(self::CONTROLLER_CLASS);
        $this->controller = $reflection->newInstanceWithoutConstructor();

        $this->controller->className = 'ventas_articulos';
        $this->controller->user = $this->mockUser($nick, $isAdmin);
        $this->controller->articulo = $this->mockArticuloModel();
        $this->controller->cache = new \fs_cache();
        $this->controller->db = new \fs_db2();

        $token = $validCsrf ? CsrfManager::generateToken() : 'invalid-token';
        $this->controller->request = Request::create('/', 'POST', array_merge(
            [CsrfManager::FIELD_NAME => $token],
            $postData
        ));

        $coreLogProp = new \ReflectionProperty(\FSFramework\Core\Base\Controller::class, 'core_log');
        $coreLogProp->setAccessible(true);
        $coreLogProp->setValue($this->controller, new \fs_core_log('VentasArticulos'));
    }

    private function invokeNuevoArticulo(): void
    {
        $reflection = new \ReflectionClass(self::CONTROLLER_CLASS);
        $method = $reflection->getMethod('nuevoArticulo');
        $method->invoke($this->controller, $this->controller->request);
    }

    private function mockUser(string $nick, bool $isAdmin): \FSFramework\model\fs_user
    {
        return new class($nick, $isAdmin) extends \FSFramework\model\fs_user {
            public function __construct(string $nick, bool $isAdmin)
            {
                $this->nick = $nick;
                $this->admin = $isAdmin;
            }
        };
    }

    private function mockArticuloModel(): \FSFramework\model\articulo
    {
        return new class extends \FSFramework\model\articulo {
            public function __construct()
            {
                // Skip the parent: no DB, no table check (AGENTS.md fs_model pattern).
            }

            public function get($ref = '')
            {
                return false;
            }

            public function delete(): bool
            {
                return false;
            }

            public function exists(): bool
            {
                return false;
            }

            public function save(): bool
            {
                return false;
            }
        };
    }

    private function loadModelClasses(): void
    {
        require_once FS_FOLDER . '/model/core/fs_user.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/impuesto.php';

        // The controller instantiates `new \articulo()` (global legacy name);
        // the plugin models live under FSFramework\model\* (fs_model_autoloader
        // cannot reach plugin dirs with an empty $GLOBALS['plugins'] in tests).
        \fs_model_autoloader::ensureGlobalAlias('articulo');
        \fs_model_autoloader::ensureGlobalAlias('impuesto');
    }

    /**
     * Benign fs_db2::$engine fake so allow-path model construction (real
     * \articulo → fs_model::__construct → check_table → table_exists and
     * save() → exec) completes without any DB. Records exec() calls so deny
     * tests can prove save() was never reached.
     */
    private function stubDbEngine(): void
    {
        $this->engineStub = new class {
            public int $execCalls = 0;

            public function connected(): bool
            {
                return true;
            }

            public function date_style(): string
            {
                return 'Y-m-d';
            }

            public function escape_string(string $str): string
            {
                return addslashes($str);
            }

            public function list_tables(): array
            {
                // fs_extensions2 is included because FSEventDispatcher::getInstance()
                // constructs \fs_extension on first use (registerLegacyExtensions).
                return [['name' => 'articulos'], ['name' => 'impuestos'], ['name' => 'fs_extensions2']];
            }

            public function select($sql, $params = [])
            {
                // fs_model::table_has_rows() probes "SELECT 1 FROM <table> LIMIT 1".
                // Report a row so seed_if_empty() skips install() (which would
                // construct fabricante/familia/impuesto just to check tables).
                if (str_contains((string) $sql, 'SELECT 1 FROM')) {
                    return [['1' => 1]];
                }

                return [];
            }

            public function select_limit($sql, $limit = 50, $offset = 0, $params = [])
            {
                return [];
            }

            public function exec($sql, $transaction = null, $params = [], $batch = false)
            {
                $this->execCalls++;

                return true;
            }

            public function check_table_aux($table_name)
            {
                return true;
            }

            public function compare_columns($table_name, $xml_cols, $db_cols)
            {
                return '';
            }

            public function compare_constraints($table_name, $xml_cons, $db_cons, $delete_only = false)
            {
                return '';
            }

            public function get_columns($table_name)
            {
                return [];
            }

            public function get_constraints($table_name, $extended = false)
            {
                return [];
            }

            public function generate_table($table_name, $xml_cols, $xml_cons)
            {
                return '';
            }

            public function get_error_msg()
            {
                return '';
            }
        };

        $ref = new \ReflectionClass(\fs_db2::class);
        $engineProp = $ref->getProperty('engine');
        $engineProp->setAccessible(true);
        $engineProp->setValue(null, $this->engineStub);

        // fs_db2::__construct only initializes these when the engine is unset;
        // with a stubbed engine we must prime them ourselves.
        $tableListProp = $ref->getProperty('table_list');
        $tableListProp->setAccessible(true);
        $tableListProp->setValue(null, false);

        $autoTxProp = $ref->getProperty('auto_transactions');
        $autoTxProp->setAccessible(true);
        $autoTxProp->setValue(null, true);
    }

    private function captureErrorLog(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'qcrt_errlog_');
        $this->assertNotFalse($tmpFile, 'Could not create the error_log capture file');
        $this->errorLogFile = (string) $tmpFile;
        $this->previousErrorLog = ini_set('error_log', $this->errorLogFile);
        file_put_contents($this->errorLogFile, '');
    }

    private function resetCoreLog(): void
    {
        $ref = new \ReflectionClass(\fs_core_log::class);
        $dataProp = $ref->getProperty('data_log');
        $dataProp->setAccessible(true);
        $dataProp->setValue(null, []);

        $nameProp = $ref->getProperty('controller_name');
        $nameProp->setAccessible(true);
        $nameProp->setValue(null, null);

        $modelRef = new \ReflectionClass(\fs_model::class);
        $coreLogProp = $modelRef->getProperty('core_log');
        $coreLogProp->setAccessible(true);
        $coreLogProp->setValue(null, new \fs_core_log());

        $checkedProp = $modelRef->getProperty('checked_tables');
        $checkedProp->setAccessible(true);
        $checkedProp->setValue(null, null);
    }

    // ---- Listeners ---------------------------------------------------------

    private function recordingListener(): object
    {
        return new class {
            public int $calls = 0;
            public ?ArticlePermissionFilterEvent $event = null;

            public function __invoke(ArticlePermissionFilterEvent $event): void
            {
                $this->calls++;
                $this->event = $event;
            }
        };
    }

    private function denyingListener(string $reason = 'editor sin asignación'): object
    {
        return new class($reason) {
            public int $calls = 0;

            public function __construct(private readonly string $reason)
            {
            }

            public function __invoke(ArticlePermissionFilterEvent $event): void
            {
                $this->calls++;
                $event->deny($this->reason);
            }
        };
    }

    private function throwingListener(): object
    {
        return new class {
            public function __invoke(ArticlePermissionFilterEvent $event): void
            {
                throw new \RuntimeException('listener bug');
            }
        };
    }

    // ---- R-QCRT-001: non-admin dispatch before mutation/save --------------

    public function testNonAdminDispatchesFilterOnceBeforeCreate(): void
    {
        $recorder = $this->recordingListener();
        FSEventDispatcher::getInstance()->addListener(ArticlePermissionFilterEvent::NAME, $recorder);

        $this->buildController(
            ['nreferencia' => 'REF-001', 'ndescripcion' => 'Artículo test', 'npvp' => '10.50'],
            nick: 'editor1',
            isAdmin: false
        );
        $this->invokeNuevoArticulo();

        $this->assertSame(
            1,
            $recorder->calls,
            'R-QCRT-001: a non-admin quick-create must dispatch the filter exactly once'
        );
        $this->assertNotNull($recorder->event, 'The dispatched event must be recorded');
        $this->assertSame('REF-001', $recorder->event->getReferencia());
        $this->assertSame('editor1', $recorder->event->getNick());
        $this->assertSame(
            ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            $recorder->event->getAction(),
            'The quick-create gate must reuse the frozen ACTION_EDIT_ARTICLE'
        );
    }

    // ---- R-QCRT-003 S1 + Beh-S1: deny blocks whole create ------------------

    public function testDeniedEditorCreateIsBlockedAndSaveNeverCalled(): void
    {
        $denier = $this->denyingListener('editor sin asignación');
        FSEventDispatcher::getInstance()->addListener(ArticlePermissionFilterEvent::NAME, $denier);

        $this->buildController(
            ['nreferencia' => 'REF-002', 'ndescripcion' => 'Artículo test', 'npvp' => '9.99'],
            nick: 'editor1',
            isAdmin: false
        );
        $this->invokeNuevoArticulo();

        $this->assertSame(
            [self::DENY_MESSAGE_PREFIX . 'editor sin asignación'],
            $this->controller->get_errors(),
            'A denied create must surface exactly the denial message with the listener reason'
        );
        $this->assertSame(
            [],
            $this->controller->get_messages(),
            'No success message may appear: save() must be unreachable on deny'
        );
        $this->assertSame(0, $this->engineStub->execCalls, 'A denied create must never reach the DB layer');
    }

    // ---- R-QCRT-003 S2: npvp defaults to 0.0, still blocked ----------------

    public function testDenyWithoutExplicitNpvpStillBlocksWholeCreate(): void
    {
        $denier = $this->denyingListener('editor sin asignación');
        FSEventDispatcher::getInstance()->addListener(ArticlePermissionFilterEvent::NAME, $denier);

        $this->buildController(
            ['nreferencia' => 'REF-003', 'ndescripcion' => 'Artículo test'],
            nick: 'editor1',
            isAdmin: false
        );
        $this->invokeNuevoArticulo();

        $this->assertSame(
            [self::DENY_MESSAGE_PREFIX . 'editor sin asignación'],
            $this->controller->get_errors(),
            'npvp defaults to 0.0: the create must still be blocked entirely, never create-without-price'
        );
        $this->assertSame(0, $this->engineStub->execCalls, 'No DB write may happen on a denied create');
    }

    // ---- R-QCRT-003 S3: gestor allowed ------------------------------------

    public function testAllowedCreatePersistsWithSuccessMessage(): void
    {
        $recorder = $this->recordingListener();
        FSEventDispatcher::getInstance()->addListener(ArticlePermissionFilterEvent::NAME, $recorder);

        $this->buildController(
            ['nreferencia' => 'REF-004', 'ndescripcion' => 'Artículo test', 'npvp' => '25.75'],
            nick: 'gestor1',
            isAdmin: false
        );
        $this->invokeNuevoArticulo();

        $this->assertNotNull($recorder->event, 'The filter must have been dispatched');
        $this->assertTrue(
            $recorder->event->isAllowed(),
            'An undented listener resolution must allow the create'
        );
        $this->assertContains(
            'Artículo REF-004 guardado correctamente.',
            $this->controller->get_messages(),
            'An allowed create must persist exactly as before the change'
        );
        $this->assertGreaterThanOrEqual(
            1,
            $this->engineStub->execCalls,
            'An allowed create must reach the DB layer (save)'
        );
    }

    // ---- R-QCRT-004: fail-closed, no error_log ----------------------------

    public function testThrowingListenerDeniesFailClosedWithoutErrorLog(): void
    {
        FSEventDispatcher::getInstance()->addListener(ArticlePermissionFilterEvent::NAME, $this->throwingListener());

        $this->buildController(
            ['nreferencia' => 'REF-005', 'ndescripcion' => 'Artículo test', 'npvp' => '5.00'],
            nick: 'editor1',
            isAdmin: false
        );
        $this->invokeNuevoArticulo();

        $this->assertSame(
            [self::DENY_MESSAGE_PREFIX . 'permission filter error'],
            $this->controller->get_errors(),
            'R-QCRT-004: a throwing listener must deny fail-closed with the generic reason'
        );
        $this->assertSame(0, $this->engineStub->execCalls, 'The fail-closed deny must never reach save()');

        $logContents = is_file($this->errorLogFile) ? (string) file_get_contents($this->errorLogFile) : '';
        $this->assertSame(
            '',
            $logContents,
            'R-QCRT-004: the gate must not write to error_log (host suite processIsolation=true)'
        );
    }

    // ---- R-QCRT-002: admin dominance, zero dispatch ------------------------

    public function testAdminSkipsDispatchEntirely(): void
    {
        $recorder = $this->recordingListener();
        FSEventDispatcher::getInstance()->addListener(ArticlePermissionFilterEvent::NAME, $recorder);

        $this->buildController(
            ['nreferencia' => 'REF-006', 'ndescripcion' => 'Artículo test', 'npvp' => '33.33'],
            nick: 'admin1',
            isAdmin: true
        );
        $this->invokeNuevoArticulo();

        $this->assertSame(
            0,
            $recorder->calls,
            'R-QCRT-002: admin quick-create must never construct/dispatch the filter (zero dispatch)'
        );
        $this->assertContains(
            'Artículo REF-006 guardado correctamente.',
            $this->controller->get_messages(),
            'An admin quick-create must succeed exactly as today'
        );
    }

    // ---- R-QCRT-005: zero listeners default allow --------------------------

    public function testZeroListenersDefaultAllow(): void
    {
        $this->buildController(
            ['nreferencia' => 'REF-007', 'ndescripcion' => 'Artículo test', 'npvp' => '12.34'],
            nick: 'editor1',
            isAdmin: false
        );
        $this->invokeNuevoArticulo();

        $this->assertContains(
            'Artículo REF-007 guardado correctamente.',
            $this->controller->get_messages(),
            'R-QCRT-005: zero listeners must resolve allow (host neutrality, unchanged behavior)'
        );
    }

    // ---- R-QCRT-006: CSRF failure still blocks -----------------------------

    public function testCsrfFailureBlocksBeforeAnyDispatchOrCreate(): void
    {
        $recorder = $this->recordingListener();
        FSEventDispatcher::getInstance()->addListener(ArticlePermissionFilterEvent::NAME, $recorder);

        $this->buildController(
            ['nreferencia' => 'REF-008', 'ndescripcion' => 'Artículo test', 'npvp' => '1.00'],
            nick: 'editor1',
            isAdmin: false,
            validCsrf: false
        );
        $this->invokeNuevoArticulo();

        $this->assertSame(
            [self::CSRF_ERROR_MESSAGE],
            $this->controller->get_errors(),
            'R-QCRT-006: an invalid token must keep blocking with the existing message'
        );
        $this->assertSame(0, $recorder->calls, 'CSRF failure must block before any dispatch');
        $this->assertSame(0, $this->engineStub->execCalls, 'CSRF failure must block before any create work');
    }

    // ---- R-QCRT-007 S2 proxy: frozen constant ------------------------------

    public function testActionEditArticleConstantIsFrozen(): void
    {
        $this->assertSame(
            'edit_article',
            ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            'R-QCRT-007: the gate must reuse the frozen action constant, no new event surface'
        );
    }
}