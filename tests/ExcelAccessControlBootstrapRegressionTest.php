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
use PHPUnit\Framework\TestCase;

/**
 * Bootstrap-level regression tests for the post-verify CRITICAL class-resolution
 * bugs (catalogo-excel-access-control verify report, C-1/C-2/C-3).
 *
 * Unlike the original suite (which drives the policy through the @internal test
 * setters and pins controller wiring via source strings), these tests exercise
 * the REAL class-loading path: the policy is instantiated through the composer
 * autoloader and the setting-read path runs WITHOUT setSettingRaw(), and the
 * settings controller's private_core() executes its own file-scoped class
 * resolution. Both fatals were invisible to the original suite exactly because
 * the setters/source pins bypassed these paths (C-3).
 *
 * NOTE: this file must NEVER preload base/fs_settings.php. The C-2 regression
 * proves the policy lazy-loads fs_settings itself; preloading it here would
 * mask the bug.
 */
final class ExcelAccessControlBootstrapRegressionTest extends TestCase
{
    private const SETTINGS_CONTROLLER_FILE = '/plugins/catalogo_core/controller/catalogo_excel_settings.php';

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic fail-closed state: clean config2 for the policy key.
        unset($GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY]);
        unset($_SERVER['REQUEST_METHOD']);
        parent::tearDown();
    }

    /**
     * C-2 regression: grantedRoles() without the test setters hits the real
     * settingRaw() -> new \fs_settings() path. fs_settings is NOT part of the
     * standard bootstrap (not required by index.php/fs_app.php/Kernel.php, not
     * in the composer classmap), so the policy MUST lazy-load it itself.
     *
     * Deterministic guard: the host suite runs each test in a fresh process
     * (processIsolation=true), where fs_settings is genuinely unloaded and the
     * lazy require is the only thing standing between this call and a
     * `Class "fs_settings" not found` fatal. The root suite shares one process
     * per suite, where an earlier test may have already loaded fs_settings —
     * there the behavioral assertion still runs (fail-closed []), but the
     * class-resolution proof is the host suite's job (same layering precedent
     * as REL-4 in the verify report).
     */
    public function testGrantedRolesLazyLoadsFsSettingsWithoutTestSetters(): void
    {
        // Real autoload context: no require of fs_settings.php, no setters.
        $policy = new ArticleExcelAccessPolicy();

        $this->assertSame(
            [],
            $policy->grantedRoles(),
            'grantedRoles() with a clean config2 must fail closed to [] and must not '
            . 'die with Class "fs_settings" not found (C-2)'
        );
    }

    /**
     * C-1 regression: catalogo_excel_settings::private_core() instantiates
     * ArticleExcelAccessPolicy from the controller's global-namespace scope
     * (line 88). The class lives under
     * FSFramework\Plugins\catalogo_core\Services, so the controller file needs
     * its own import/FQCN; without it PHP dies with Class "ArticleExcelAccessPolicy"
     * not found on every GET and POST of the settings page.
     *
     * The controller constructor requires a booted Kernel and a DB connection,
     * so this test executes private_core() on a constructor-free instance via
     * reflection — the exact statement that produced the C-1 fatal — and asserts
     * no class-resolution fatal escapes.
     */
    public function testSettingsPrivateCoreResolvesPolicyClass(): void
    {
        require_once FS_FOLDER . self::SETTINGS_CONTROLLER_FILE;

        $reflection = new \ReflectionClass('catalogo_excel_settings');
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('private_core');

        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $method->invoke($controller);
        } catch (\Throwable $e) {
            $this->fail(sprintf(
                'catalogo_excel_settings::private_core() must not throw a class-resolution '
                . 'fatal (C-1: ArticleExcelAccessPolicy in global scope): %s: %s @%s:%d',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->assertIsArray(
            $controller->roles,
            'After private_core() runs, the roles list must be populated (no fatal before it)'
        );
        $this->assertSame(
            [],
            $controller->granted_codes,
            'With a clean config2 the granted codes must be empty (fail-closed)'
        );
    }

    /**
     * R-CO-005 shim: absent new-store key + legacy catalogo_excel_roles = "A"
     * must grant role A (legacy read fallback through CatalogoOptions).
     */
    public function testShimFallsBackToLegacyKeyWhenNewStoreAbsent(): void
    {
        $GLOBALS['config2']['catalogo_excel_roles'] = 'A';

        $policy = new ArticleExcelAccessPolicy();
        $policy->setExistingRoles(['A']);

        $this->assertSame(['A'], $policy->grantedRoles(), 'legacy key must be readable when the new store key is absent');
    }

    /**
     * R-CO-005 shim regression: the new store is authoritative — when
     * catalogo_core.excel_roles = "B" and legacy grants "A", only B is granted.
     */
    public function testShimNewStoreWinsOverLegacyKey(): void
    {
        $GLOBALS['config2']['catalogo_core.excel_roles'] = 'B';
        $GLOBALS['config2']['catalogo_excel_roles'] = 'A';

        $policy = new ArticleExcelAccessPolicy();
        $policy->setExistingRoles(['A', 'B']);

        $this->assertSame(['B'], $policy->grantedRoles(), 'new store key must win over the legacy key');
    }
}
