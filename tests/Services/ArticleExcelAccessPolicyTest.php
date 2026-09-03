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

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\ArticleExcelAccessPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Policy semantics for the article Excel import/export access control
 * (R-CEXC-002, R-CEXC-006; scenarios 2, 3, 5-8 of the change spec).
 *
 * No DB: `$GLOBALS['config2']` direct writes for the setting (fs_settings get
 * is a pure array op) plus anonymous-subclass model fakes via @internal setters.
 */
#[CoversClass(ArticleExcelAccessPolicy::class)]
final class ArticleExcelAccessPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_settings.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/model/fs_rol.php';
        require_once FS_FOLDER . '/model/fs_rol_user.php';
        unset($GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY]);
        parent::tearDown();
    }

    // ---- Scenarios 2 + 3: absent / unparsable setting fails closed ----

    public function testAbsentSettingGrantsNoRoles(): void
    {
        $policy = new ArticleExcelAccessPolicy();

        $this->assertSame([], $policy->grantedRoles(), 'Absent setting must grant zero roles (fail-closed)');
    }

    public function testUnparsableSettingGrantsNoRoles(): void
    {
        $GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY] = ['not', 'a', 'string', 'list'];
        $policy = new ArticleExcelAccessPolicy();

        $this->assertSame([], $policy->grantedRoles(), 'Non-string setting value must grant zero roles');
    }

    public function testCommaOnlySettingGrantsNoRoles(): void
    {
        $GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY] = ' , ,, ';
        $policy = new ArticleExcelAccessPolicy();

        $this->assertSame([], $policy->grantedRoles(), 'A value with no parseable codes must grant zero roles');
    }

    public function testUnparsableSettingDoesNotError(): void
    {
        $GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY] = 12345;
        $policy = new ArticleExcelAccessPolicy();

        $this->assertSame([], $policy->grantedRoles(), 'Numeric setting must fail closed without error');
    }

    // ---- Scenario 7 (R-CEXC-006c): non-admin holding a granted role ----

    public function testNonAdminWithGrantedRoleIsAllowed(): void
    {
        $GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY] = 'A,B';
        $policy = new ArticleExcelAccessPolicy();
        $policy->setUser($this->mockUser('pepe', false));
        $policy->setRolUserModel($this->mockRolUserModel(['pepe' => ['A']]));
        $policy->setRolModel($this->mockRolModel(['A', 'B']));

        $this->assertTrue($policy->isAllowed(), 'Non-admin holding granted role A must be allowed');
        $this->assertSame(['A', 'B'], $policy->grantedRoles(), 'Granted list is the parsed setting ∩ fs_roles');
    }

    // ---- Scenario 5: granted role deleted from fs_roles ----

    public function testGrantedRoleDeletedFromFsRolesIsNotGranted(): void
    {
        $GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY] = 'B';
        $policy = new ArticleExcelAccessPolicy();
        $policy->setUser($this->mockUser('pepe', false));
        $policy->setRolUserModel($this->mockRolUserModel(['pepe' => ['B']]));
        $policy->setRolModel($this->mockRolModel(['A', 'C']));

        $this->assertSame([], $policy->grantedRoles(), 'Role B no longer exists ⇒ not granted (read-time intersect)');
        $this->assertFalse($policy->isAllowed(), 'User holding only the deleted role must be denied');
    }

    // ---- Scenario 6: admin dominance ----

    public function testAdminDominatesRegardlessOfGrants(): void
    {
        $policy = new ArticleExcelAccessPolicy();
        $policy->setUser($this->mockUser('admin', true));
        $policy->setRolUserModel($this->mockRolUserModel(['admin' => []]));
        $policy->setRolModel($this->mockRolModel([]));

        $this->assertTrue($policy->isAdmin(), 'Admin path must be detected');
        $this->assertTrue($policy->isAllowed(), 'Admin dominance: allowed with no setting and no roles');
    }

    public function testAdminDominatesEvenWithNoHeldRoles(): void
    {
        $GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY] = 'A';
        $policy = new ArticleExcelAccessPolicy();
        $policy->setUser($this->mockUser('admin', true));
        $policy->setRolUserModel($this->mockRolUserModel(['admin' => []]));
        $policy->setRolModel($this->mockRolModel(['A']));

        $this->assertTrue($policy->isAllowed(), 'Admin is allowed independent of grant state');
    }

    // ---- Scenario 13 support: default deny for non-admin ----

    public function testNonAdminWithoutRolesIsDeniedByDefault(): void
    {
        $policy = new ArticleExcelAccessPolicy();
        $policy->setUser($this->mockUser('pepe', false));
        $policy->setRolUserModel($this->mockRolUserModel(['pepe' => []]));
        $policy->setRolModel($this->mockRolModel(['A']));

        $this->assertFalse($policy->isAllowed(), 'Default-deny: non-admin with no setting must be denied');
    }

    public function testNonAdminHoldingUngrantedRoleIsDenied(): void
    {
        $GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY] = 'A';
        $policy = new ArticleExcelAccessPolicy();
        $policy->setUser($this->mockUser('pepe', false));
        $policy->setRolUserModel($this->mockRolUserModel(['pepe' => ['Z']]));
        $policy->setRolModel($this->mockRolModel(['A']));

        $this->assertFalse($policy->isAllowed(), 'Holding a role outside the granted list must be denied');
    }

    // ---- Scenario 8: no cross-request staleness ----

    public function testNewInstanceReevaluatesAfterRevocation(): void
    {
        $GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY] = 'A';
        $first = new ArticleExcelAccessPolicy();
        $first->setUser($this->mockUser('pepe', false));
        $first->setRolUserModel($this->mockRolUserModel(['pepe' => ['A']]));
        $first->setRolModel($this->mockRolModel(['A']));
        $this->assertTrue($first->isAllowed());

        // Grant revoked between requests.
        unset($GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY]);

        $second = new ArticleExcelAccessPolicy();
        $second->setUser($this->mockUser('pepe', false));
        $second->setRolUserModel($this->mockRolUserModel(['pepe' => ['A']]));
        $second->setRolModel($this->mockRolModel(['A']));
        $this->assertFalse($second->isAllowed(), 'A new instance (new request) must re-evaluate the setting');
    }

    // ---- Decision 3: per-instance memoization ----

    public function testVerdictIsMemoizedWithinInstance(): void
    {
        $GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY] = 'A';
        $rolUserModel = $this->mockRolUserModel(['pepe' => ['A']]);
        $policy = new ArticleExcelAccessPolicy();
        $policy->setUser($this->mockUser('pepe', false));
        $policy->setRolUserModel($rolUserModel);
        $policy->setRolModel($this->mockRolModel(['A']));

        $policy->isAllowed();
        $policy->isAllowed();

        $this->assertSame(1, $rolUserModel->calls, 'Held-roles lookup must be memoized per instance');
    }

    // ---- Denial message contract ----

    public function testDenialMessageIsUserFacingAndNonEmpty(): void
    {
        $policy = new ArticleExcelAccessPolicy();

        $message = $policy->denialMessage();
        $this->assertNotSame('', $message, 'Denial message must be non-empty');
        $this->assertStringContainsString('Excel', $message, 'Denial message must reference the Excel feature');
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
            public int $calls = 0;

            public function __construct(private readonly array $rolesByNick)
            {
            }

            public function all_from_user($nick)
            {
                $this->calls++;
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
