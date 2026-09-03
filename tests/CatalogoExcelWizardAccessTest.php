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

use FSFramework\Plugins\catalogo_core\Services\ArticleExcelAccessPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Access-control tests for the catalogo_core Excel wizard surfaces
 * (R-CEXC-001/R-CEXC-004; scenario 17 — the standalone direct-URL hole).
 *
 * The standalone dispatch endpoint (`process_excel_wizard.php` →
 * `catalogo_excel_wizard_run(false)`) previously required login + CSRF only:
 * any logged-in user could run imports by direct URL. The policy gate below
 * must deny non-granted users with an explicit 403 JSON payload on every
 * standalone action (start, progress, status).
 *
 * Pure-function level: the verdict helper is tested directly with a policy
 * configured via @internal test setters (no HTTP run, no DB).
 */
#[CoversClass(ArticleExcelAccessPolicy::class)]
final class CatalogoExcelWizardAccessTest extends TestCase
{
    private const DISPATCH_FILE = '/plugins/catalogo_core/process_excel_wizard_dispatch.php';

    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_settings.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/model/fs_rol.php';
        require_once FS_FOLDER . '/model/fs_rol_user.php';
        require_once FS_FOLDER . self::DISPATCH_FILE;
        unset($GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY]);
        parent::tearDown();
    }

    // ---- Scenario 17: standalone endpoint denies non-granted users ----

    public function testPolicyDenialReturnsNullForAdmin(): void
    {
        $policy = new ArticleExcelAccessPolicy();
        $policy->setUser($this->mockUser('admin', true));

        $this->assertNull(
            catalogo_excel_wizard_policy_denial($policy),
            'Admin must pass the standalone gate with no denial payload'
        );
    }

    public function testPolicyDenialReturnsNullForGrantedUser(): void
    {
        $GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY] = 'A';
        $policy = new ArticleExcelAccessPolicy();
        $policy->setUser($this->mockUser('pepe', false));
        $policy->setRolUserModel($this->mockRolUserModel(['pepe' => ['A']]));
        $policy->setRolModel($this->mockRolModel(['A']));

        $this->assertNull(
            catalogo_excel_wizard_policy_denial($policy),
            'Granted non-admin must pass the standalone gate'
        );
    }

    public function testPolicyDenialReturnsPayloadForNonGrantedUser(): void
    {
        // The standalone-hole proof: a logged-in non-granted user reaching
        // process_excel_wizard.php?action=start must be denied explicitly.
        $policy = new ArticleExcelAccessPolicy();
        $policy->setUser($this->mockUser('pepe', false));
        $policy->setRolUserModel($this->mockRolUserModel(['pepe' => []]));
        $policy->setRolModel($this->mockRolModel(['A']));

        $payload = catalogo_excel_wizard_policy_denial($policy);

        $this->assertIsArray($payload, 'Non-granted user must get a denial payload, not silent pass-through');
        $this->assertFalse($payload['success'] ?? null, 'Denial payload success must be false');
        $this->assertNotSame('', (string) ($payload['error'] ?? ''), 'Denial payload must carry a user-facing error');
        $this->assertSame(
            ['success', 'error'],
            array_keys($payload),
            'Denial payload shape must be exactly {success:false, error:...} (client parses json.error)'
        );
    }

    public function testPolicyDenialWithoutExplicitPolicyDeniesAnonymousSession(): void
    {
        // No session user resolvable in test context ⇒ fail-closed denial.
        $payload = catalogo_excel_wizard_policy_denial();

        $this->assertIsArray($payload, 'No resolvable user must fail closed with a denial payload');
        $this->assertFalse($payload['success'] ?? null);
    }

    // ---- Wiring: the runner must gate ALL standalone actions after login ----

    public function testStandaloneRunAppliesPolicyGateAfterLogin(): void
    {
        $source = file_get_contents(FS_FOLDER . self::DISPATCH_FILE);
        $this->assertNotFalse($source);

        $loginPos = strpos($source, 'catalogo_excel_wizard_require_login');
        $gatePos = strpos($source, 'catalogo_excel_wizard_policy_denial()');
        $switchPos = strpos($source, 'switch ($action)');

        $this->assertNotFalse($loginPos, 'Runner must keep the login requirement');
        $this->assertNotFalse($gatePos, 'Runner must apply the policy gate (catalogo_excel_wizard_policy_denial)');
        $this->assertNotFalse($switchPos, 'Runner must keep the action dispatch');

        $this->assertGreaterThan(
            $loginPos,
            $gatePos,
            'Policy gate must run AFTER the login check'
        );
        $this->assertLessThan(
            $switchPos,
            $gatePos,
            'Policy gate must run BEFORE wizard action handling (start/progress/status all gated)'
        );
    }

    public function testStandaloneDenialResponds403JsonAndExits(): void
    {
        $source = file_get_contents(FS_FOLDER . self::DISPATCH_FILE);
        $this->assertNotFalse($source);

        $this->assertStringContainsString(
            'function catalogo_excel_wizard_emit_denial',
            $source,
            'A dedicated emit helper must exist for the denied response'
        );

        $emitStart = (int) strpos($source, 'function catalogo_excel_wizard_emit_denial');
        $nextFunction = strpos($source, "\nfunction ", $emitStart + 10);
        $emitEnd = $nextFunction === false ? strlen($source) : $nextFunction;
        $emitBody = (string) substr($source, $emitStart, $emitEnd - $emitStart);

        $this->assertStringContainsString('403', $emitBody, 'Denied standalone response must be HTTP 403');
        $this->assertStringContainsString('application/json', $emitBody, 'Denied standalone response must be JSON');
        $this->assertStringContainsString('exit', $emitBody, 'Denied standalone response must terminate before wizard handling');
    }

    // ---- Embedded controller: explicit denials, never silent fall-through ----

    private const CONTROLLER_FILE = '/plugins/catalogo_core/Controller/VentasArticulos.php';
    private const TWIG_FILE = '/plugins/catalogo_core/View/ventas_articulos.html.twig';

    public function testControllerGateUsesPolicyVerdictNotTautology(): void
    {
        $source = file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);
        $this->assertNotFalse($source);

        $method = $this->methodSource($source, 'private function initImportExportPermissions');

        $this->assertNotSame('', $method, 'initImportExportPermissions must exist');
        $this->assertStringContainsString(
            'ArticleExcelAccessPolicy',
            $method,
            'can_import_export must come from the central access policy'
        );
        $this->assertStringContainsString(
            '->isAllowed(',
            $method,
            'can_import_export must be the policy verdict'
        );
        $this->assertStringNotContainsString(
            'have_access_to',
            $method,
            'The tautological admin || have_access_to(page) gate must be gone'
        );
    }

    public function testProcessExcelActionDeniesKnownExcelActionsExplicitly(): void
    {
        $source = file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);
        $this->assertNotFalse($source);

        $method = $this->methodSource($source, 'private function processExcelAction');
        $this->assertNotSame('', $method, 'processExcelAction must exist');

        $this->assertStringContainsString(
            'self::EXCEL_ACTIONS',
            $method,
            'Known Excel actions must be enumerated so denials are explicit'
        );
        $this->assertStringContainsString(
            '$this->emitExcelAccessDenied($action);',
            $method,
            'A denied known action must call the explicit denial emitter'
        );
        $denialPos = (int) strpos($method, 'emitExcelAccessDenied');
        $this->assertGreaterThan(
            0,
            $denialPos,
            'Denial emitter must be invoked from processExcelAction'
        );
        $this->assertStringContainsString(
            'return true;',
            (string) substr($method, $denialPos, 120),
            'After emitting the denial the action must be consumed (never fall through to list render)'
        );
    }

    public function testExcelActionsListCoversEveryEntryPoint(): void
    {
        $source = file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);
        $this->assertNotFalse($source);

        $const = $this->methodSource($source, 'private const EXCEL_ACTIONS');
        foreach (
            [
                'preview_excel',
                'get_preview',
                'export_excel',
                'export_excel_filtered',
                'export_excel_template',
                'download_import_log',
                'excel_import_sse',
            ] as $action
        ) {
            $this->assertStringContainsString(
                "'$action'",
                $const,
                "EXCEL_ACTIONS must gate entry point $action"
            );
        }
    }

    public function testExcelDenialResponds403WithAd4Shapes(): void
    {
        $source = file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);
        $this->assertNotFalse($source);

        $method = $this->methodSource($source, 'private function emitExcelAccessDenied');
        $this->assertNotSame('', $method, 'emitExcelAccessDenied must exist');

        $this->assertStringContainsString('403', $method, 'Denied responses must be HTTP 403');
        $this->assertStringContainsString(
            "'success' => false",
            $method,
            'JSON surfaces (fetch/EventSource) must get {success:false, error:...}'
        );
        $this->assertStringContainsString('application/json', $method, 'JSON surfaces must send a JSON content type');
        $this->assertStringContainsString(
            "'preview_excel', 'get_preview', 'excel_import_sse'",
            $method,
            'preview_excel, get_preview and excel_import_sse are the JSON surfaces (AD-4)'
        );
        $this->assertStringContainsString(
            'echo $message;',
            $method,
            'Link-navigation surfaces (export/log) must get a 403 text body (mirrors 404 pattern)'
        );
    }

    // NOTE (PR1 escape hatch): the SSE stream-start ordering test
    // (task 1.9, scenario 23) is deferred to PR2 per the documented
    // 400-line budget escape hatch. The stream-start GATE itself ships in
    // PR1: excel_import_sse is in EXCEL_ACTIONS and the JSON denial shape
    // (asserted above) applies to it like every JSON surface.

    // ---- Scenario 25/26: UI parity (0 template changes expected) ----

    public function testTwigGatesKeyOnCanImportExportFlag(): void
    {
        $source = file_get_contents(FS_FOLDER . self::TWIG_FILE);
        $this->assertNotFalse($source);

        $this->assertSame(
            2,
            substr_count($source, '{% if fsc.can_import_export %}'),
            'The Excel UI must be gated exactly on fsc.can_import_export (menu + modals)'
        );

        // Menu gate (scenario 25/26 surface 1): Excel dropdown inside the gate.
        $menuGatePos = (int) strpos($source, '{% if fsc.can_import_export %}');
        $menuPos = strpos($source, 'excel-menu');
        $this->assertGreaterThan($menuGatePos, $menuPos, 'Excel menu must render inside the can_import_export gate');

        // Modals gate (surface 2): both Excel partials inside the second gate.
        $modalsGatePos = (int) strrpos($source, '{% if fsc.can_import_export %}');
        $exportPos = strpos($source, "include 'partials/articulos/modal_exportar_excel.html.twig'");
        $importPos = strpos($source, "include 'partials/articulos/modal_importar_excel_wizard.html.twig'");
        $this->assertGreaterThan($modalsGatePos, $exportPos, 'Export modal must render inside the can_import_export gate');
        $this->assertGreaterThan($modalsGatePos, $importPos, 'Import modal must render inside the can_import_export gate');
    }

    // ---- Per-row gate + denied-row CSV (R-CEXC-003; scenarios 18-19) ----

    public function testDenyRowWritesCsvRowAndBumpsStats(): void
    {
        $handle = fopen('php://temp', 'w+');
        $this->assertNotFalse($handle);
        $stats = $this->freshStats();

        catalogo_excel_wizard_deny_row($handle, 'REF-X', 'editor sin asignación', $stats);

        rewind($handle);
        $line = (string) fgets($handle);
        fclose($handle);

        $this->assertSame(
            "permiso_denegado;REF-X;\"editor sin asignación\"\n",
            $line,
            'Denied row must be appended to the discarded CSV as permiso_denegado;referencia;detalle (AD-8); '
            . 'fields with spaces are quoted by the shared catalogoFputcsvSafe infra'
        );
        $this->assertSame(1, $stats['descartadas'], 'Denied row must count as descartadas');
        $this->assertSame(1, $stats['errores'], 'Denied row must count as errores');
    }

    public function testDenyRowSanitizesFormulaPrefixedReason(): void
    {
        $handle = fopen('php://temp', 'w+');
        $this->assertNotFalse($handle);
        $stats = $this->freshStats();

        catalogo_excel_wizard_deny_row($handle, 'REF-X', '=HYPERLINK("http://evil.example")', $stats);

        rewind($handle);
        $line = (string) fgets($handle);
        fclose($handle);

        $parsed = str_getcsv($line, ';');
        $this->assertSame('permiso_denegado', $parsed[0] ?? null, 'CSV row must keep the permiso_denegado motive');
        $this->assertSame('REF-X', $parsed[1] ?? null, 'CSV row must keep the row referencia');
        $this->assertStringStartsWith(
            "'=",
            $parsed[2] ?? '',
            'CSV formula injection in the denial reason must be neutralized (catalogoFputcsvSafe)'
        );
        $this->assertSame(1, $stats['descartadas']);
        $this->assertSame(1, $stats['errores']);
    }

    public function testRowHookGatesBeforeCreateAndUpdateBranches(): void
    {
        $source = file_get_contents(FS_FOLDER . self::DISPATCH_FILE);
        $this->assertNotFalse($source);

        $rowHookStart = strpos($source, '$rowHook = static function');
        $this->assertNotFalse($rowHookStart, 'rowHook must exist');
        $rowHookEnd = strpos($source, '};', $rowHookStart);
        $rowHookBody = (string) substr($source, $rowHookStart, $rowHookEnd - $rowHookStart);

        $gatePos = strpos($rowHookBody, 'ArticuloExcelPermissionGate::check');
        $createPos = strpos($rowHookBody, 'createArticuloFromRow');
        $updatePos = strpos($rowHookBody, 'catalogoApplyWizardFields');

        $this->assertNotFalse($gatePos, 'rowHook must call ArticuloExcelPermissionGate::check');
        $this->assertNotFalse($createPos, 'create branch must exist');
        $this->assertNotFalse($updatePos, 'update branch must exist');
        $this->assertLessThan($createPos, $gatePos, 'Gate must fire before the create branch (scenario 19: before pvp mutation/save)');
        $this->assertLessThan($updatePos, $gatePos, 'Gate must fire before the update branch (scenario 19)');
    }

    public function testRowHookDenyPathSkipsRowAndContinues(): void
    {
        $source = file_get_contents(FS_FOLDER . self::DISPATCH_FILE);
        $this->assertNotFalse($source);

        $rowHookStart = strpos($source, '$rowHook = static function');
        $this->assertNotFalse($rowHookStart);
        $rowHookEnd = strpos($source, '};', $rowHookStart);
        $rowHookBody = (string) substr($source, $rowHookStart, $rowHookEnd - $rowHookStart);

        $denyCall = strpos($rowHookBody, 'catalogo_excel_wizard_deny_row');
        $this->assertNotFalse($denyCall, 'Denied row must be recorded via the CSV helper (AD-8)');
        $this->assertStringContainsString(
            'return;',
            (string) substr($rowHookBody, $denyCall, 120),
            'Deny path must skip the row (no create/update/save for it) and continue the loop (scenario 18)'
        );
    }

    private function freshStats(): array
    {
        return [
            'creados' => 0,
            'actualizados' => 0,
            'sin_cambios' => 0,
            'no_encontrados' => 0,
            'errores' => 0,
            'descartadas' => 0,
            'detalles_errores' => [],
        ];
    }

    // ---- SSE per-event re-check (R-CEXC-005; scenario 24) ----

    public function testSsePolicyRecheckThrowsAccessDeniedExceptionOnVerdictFlip(): void
    {
        // A granted user whose grant is revoked mid-stream: the re-check must
        // surface the flip as CatalogoExcelAccessDeniedException, which the
        // existing catch turns into the SSE 'error' event ending the stream.
        $policy = new ArticleExcelAccessPolicy();
        $policy->setUser($this->mockUser('pepe', false));
        $policy->setRolUserModel($this->mockRolUserModel(['pepe' => []]));
        $policy->setRolModel($this->mockRolModel(['A']));

        $this->expectException(\FSFramework\Plugins\catalogo_core\Services\CatalogoExcelAccessDeniedException::class);
        catalogo_excel_wizard_assert_policy($policy);
    }

    public function testSsePolicyRecheckCarriesDenialText(): void
    {
        $policy = new ArticleExcelAccessPolicy();
        $policy->setUser($this->mockUser('pepe', false));
        $policy->setRolUserModel($this->mockRolUserModel(['pepe' => []]));
        $policy->setRolModel($this->mockRolModel(['A']));

        try {
            catalogo_excel_wizard_assert_policy($policy);
            $this->fail('Revoked user must fail the per-event re-check');
        } catch (\FSFramework\Plugins\catalogo_core\Services\CatalogoExcelAccessDeniedException $e) {
            $this->assertNotSame('', (string) $e->getMessage(), 'Denial exception must carry the user-facing denial text');
        }
    }

    public function testSsePolicyRecheckPassesWhenStillAllowed(): void
    {
        $GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY] = 'A';
        $policy = new ArticleExcelAccessPolicy();
        $policy->setUser($this->mockUser('pepe', false));
        $policy->setRolUserModel($this->mockRolUserModel(['pepe' => ['A']]));
        $policy->setRolModel($this->mockRolModel(['A']));

        catalogo_excel_wizard_assert_policy($policy);
        // No exception = the stream event may be emitted (any throw fails this test).
        $this->addToAssertionCount(1);
    }

    public function testSseRecheckWiredIntoProgressCallbackAndComplete(): void
    {
        $source = file_get_contents(FS_FOLDER . self::DISPATCH_FILE);
        $this->assertNotFalse($source);

        // The shared progressCallback must re-check the policy per stream event
        // before emitting progress (embedded & standalone share this callback).
        $pcStart = strpos($source, '$progressCallback = static function');
        $this->assertNotFalse($pcStart, 'progressCallback must exist');
        $pcEnd = (int) strpos($source, '};', $pcStart);
        $pcBody = (string) substr($source, $pcStart, $pcEnd - $pcStart);
        $this->assertStringContainsString(
            'catalogo_excel_wizard_assert_policy()',
            $pcBody,
            'progressCallback must re-check the policy per stream event (R-CEXC-005)'
        );

        // The re-check must also run BEFORE the 'complete' event is emitted.
        $assertPos = strpos($source, 'catalogo_excel_wizard_assert_policy()');
        $completePos = strpos($source, 'saveProgress($progressFile, \'complete\'');
        $this->assertNotFalse($assertPos, 'Re-check helper must exist');
        $this->assertNotFalse($completePos, 'Complete event must be emitted');
        $this->assertLessThan(
            $completePos,
            $assertPos,
            'The final re-check must run before the complete event (design flowchart b)'
        );
    }

    // ---- Scenario 23 (deferred from PR1 via the 400-line escape hatch): ----
    // ---- SSE denial at stream start, before any import event is emitted ----

    public function testSseStreamStartDeniedBeforeAnyImportEvent(): void
    {
        // Standalone SSE flow: the policy gate must precede the first import
        // event emission (scenario 23 — denial at stream start).
        $source = file_get_contents(FS_FOLDER . self::DISPATCH_FILE);
        $this->assertNotFalse($source);

        $gatePos = strpos($source, 'catalogo_excel_wizard_policy_denial()');
        $startEventPos = strpos($source, "sendEvent('start'");
        $this->assertNotFalse($gatePos, 'Standalone runner must apply the policy gate');
        $this->assertNotFalse($startEventPos, 'Import start event must be emitted by the wizard flow');
        $this->assertLessThan(
            $startEventPos,
            $gatePos,
            'Policy denial must run before any SSE start event is emitted (scenario 23)'
        );

        // Embedded SSE flow: excel_import_sse is gated in processExcelAction
        // BEFORE the wizard handler can run — the denial precedes the switch,
        // so a denied user never reaches handleExcelImportSse/handleCatalogoStart
        // and no import event is emitted.
        $controllerSource = file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);
        $this->assertNotFalse($controllerSource);
        $denialPos = strpos($controllerSource, 'emitExcelAccessDenied');
        $sseCasePos = strpos($controllerSource, "case 'excel_import_sse'");
        $this->assertNotFalse($denialPos, 'Embedded denial emitter must exist');
        $this->assertNotFalse($sseCasePos, 'excel_import_sse case must exist');
        $this->assertLessThan(
            $sseCasePos,
            $denialPos,
            'Embedded SSE denial must precede the excel_import_sse case (no import events before denial)'
        );
    }

    // ---- Fakes (ArticlePermissionListenerTest pattern) ----

    /**
     * Extracts a class method's source (start marker up to the next method
     * declaration) so assertions stay method-scoped.
     */
    private function methodSource(string $source, string $startMarker): string
    {
        $start = strpos($source, $startMarker);
        if ($start === false) {
            return '';
        }

        $rest = (string) substr($source, $start + strlen($startMarker));
        $boundaries = array_filter(
            [strpos($rest, "\n    private function"), strpos($rest, "\n    public function")],
            static fn ($pos): bool => $pos !== false
        );
        $end = $boundaries === [] ? strlen($rest) : (int) min($boundaries);

        return (string) substr($rest, 0, $end);
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

    private function mockRolUserModel(array $rolesByNick): \fs_rol_user
    {
        return new class($rolesByNick) extends \fs_rol_user {
            public function __construct(private readonly array $rolesByNick)
            {
            }

            public function all_from_user($nick)
            {
                $list = [];
                foreach ($this->rolesByNick[$nick] ?? [] as $codrol) {
                    $row = new \stdClass();
                    $row->codrol = $codrol;
                    $list[] = $row;
                }

                return $list;
            }
        };
    }

    private function mockRolModel(array $existingCodrols): \fs_rol
    {
        return new class($existingCodrols) extends \fs_rol {
            public function __construct(private readonly array $existingCodrols)
            {
            }

            public function all()
            {
                $list = [];
                foreach ($this->existingCodrols as $codrol) {
                    $rol = new \stdClass();
                    $rol->codrol = $codrol;
                    $rol->descripcion = $codrol;
                    $list[] = $rol;
                }

                return $list;
            }
        };
    }
}
