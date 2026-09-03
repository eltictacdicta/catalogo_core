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

    // ---- Fakes (ArticlePermissionListenerTest pattern) ----

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
