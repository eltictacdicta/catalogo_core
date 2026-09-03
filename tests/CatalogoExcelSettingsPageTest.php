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
 * Admin-only settings page for the article catalog Excel access role grants
 * (R-CEXC-002 settings UI; scenarios 1, 4, 9, 10, 11, 12).
 *
 * The page is a LEGACY fs_controller (AD-7): constructor args folder='admin',
 * admin=TRUE, shmenu=TRUE block non-admins at the framework level (scenario
 * 10) and register the page in the admin menu. POST is CSRF-checked (scenario
 * 12), input-whitelisted to the single catalogo_excel_roles field (scenario
 * 11), and persisted through fs_settings set()+save() (AD-6: split/trim/drop
 * empties/validate against fs_roles/dedupe/preserve order/implode).
 *
 * No DB: the save pipeline is exercised through the pure normalize helper plus
 * a $GLOBALS['config2'] round-trip via fs_settings (get/set are pure array
 * ops), mirroring ArticleExcelAccessPolicyTest. Structural source assertions
 * mirror VentasArticulosControllerTest.
 */
final class CatalogoExcelSettingsPageTest extends TestCase
{
    private const CONTROLLER_FILE = '/plugins/catalogo_core/controller/catalogo_excel_settings.php';
    private const VIEW_FILE = '/plugins/catalogo_core/view/catalogo_excel_settings.html.twig';

    protected function setUp(): void
    {
        parent::setUp();
        global $plugins;
        $plugins = [];
        require_once FS_FOLDER . '/base/fs_settings.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/model/fs_rol.php';
        require_once FS_FOLDER . '/model/fs_rol_user.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/ArticleExcelAccessPolicy.php';
        unset($GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['config2'][ArticleExcelAccessPolicy::SETTING_KEY]);
        parent::tearDown();
    }

    // ---- Structural: legacy controller with the admin gate (scenarios 10, menu) ----

    public function testSettingsControllerFileExists(): void
    {
        $file = FS_FOLDER . self::CONTROLLER_FILE;
        $this->assertFileExists($file, 'Legacy settings controller catalogo_excel_settings.php must exist (AD-7)');
    }

    public function testSettingsControllerExtendsFsController(): void
    {
        require_once FS_FOLDER . self::CONTROLLER_FILE;

        $reflection = new \ReflectionClass('catalogo_excel_settings');
        $this->assertTrue(
            $reflection->isSubclassOf(\fs_controller::class),
            'catalogo_excel_settings must extend the legacy fs_controller, NOT PageController (AD-7)'
        );
    }

    public function testSettingsControllerUsesAdminGateConstructorArgs(): void
    {
        $source = file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);
        $this->assertNotFalse($source);

        $this->assertMatchesRegularExpression(
            "/parent::__construct\(__CLASS__,\s*'[^']*',\s*'admin',\s*TRUE,\s*TRUE\)/",
            $source,
            'Constructor must pass folder=admin + admin=TRUE + shmenu=TRUE (framework-level admin gate, scenario 10)'
        );
        $this->assertMatchesRegularExpression(
            "/class\s+catalogo_excel_settings\s+extends\s+fs_controller\b/",
            $source,
            'The class declaration must extend the legacy fs_controller, NOT PageController '
            . '(its admin || have_access_to gate is the tautology removed, AD-7)'
        );
    }

    public function testSettingsControllerRegistersInAdminMenu(): void
    {
        $source = file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);
        $this->assertNotFalse($source);

        $this->assertMatchesRegularExpression(
            "/parent::__construct\(__CLASS__/",
            $source,
            'Page name must be __CLASS__ (page catalogo_excel_settings registers in the admin menu)'
        );
        $this->assertMatchesRegularExpression(
            "/'admin'/",
            $source,
            'Page must live in the admin menu folder'
        );
    }

    // ---- Save pipeline (AD-6): pure normalize + $GLOBALS['config2'] round-trip (scenarios 1, 9, 11) ----

    public function testNormalizeKeepsValidOrderedList(): void
    {
        require_once FS_FOLDER . self::CONTROLLER_FILE;

        $this->assertSame(
            'A,B',
            catalogo_excel_roles_normalize('A,B', ['A', 'B']),
            'A valid comma list must survive normalization unchanged'
        );
    }

    public function testNormalizeTrimsAndDropsEmpties(): void
    {
        require_once FS_FOLDER . self::CONTROLLER_FILE;

        $this->assertSame(
            'A,B',
            catalogo_excel_roles_normalize(' A ,, B , ', ['A', 'B']),
            'Normalization must trim pieces, drop empty cells and drop trailing commas (AD-6)'
        );
    }

    public function testNormalizeDropsUnknownCodrols(): void
    {
        require_once FS_FOLDER . self::CONTROLLER_FILE;

        $this->assertSame(
            'A',
            catalogo_excel_roles_normalize('A,Z', ['A', 'B']),
            'Unknown codrols must be dropped at save time (whitelist against fs_roles, scenario 11)'
        );
    }

    public function testNormalizeDeduplicatesPreservingOrder(): void
    {
        require_once FS_FOLDER . self::CONTROLLER_FILE;

        $this->assertSame(
            'B,A',
            catalogo_excel_roles_normalize('B,A,B', ['A', 'B']),
            'Normalization must dedupe preserving first-seen order (AD-6)'
        );
    }

    public function testSavePersistsListAndReadsBack(): void
    {
        require_once FS_FOLDER . self::CONTROLLER_FILE;

        // Round-trip through fs_settings over $GLOBALS['config2'] (pure array op).
        $settings = new \fs_settings();
        $settings->set(ArticleExcelAccessPolicy::SETTING_KEY, 'A,B');
        $this->assertSame(
            'A,B',
            (string) $settings->get(ArticleExcelAccessPolicy::SETTING_KEY),
            'Saved role list must read back unchanged (scenarios 1, 9)'
        );
    }

    public function testPolicyReadsBackPersistedList(): void
    {
        // The settings page persists the list; the policy (PR1) must read the
        // same key fail-closed. This proves the page save path feeds the
        // policy verdict with zero seams.
        $settings = new \fs_settings();
        $settings->set(ArticleExcelAccessPolicy::SETTING_KEY, 'A,B');

        $policy = new ArticleExcelAccessPolicy();
        $policy->setExistingRoles(['A', 'B']);

        $this->assertSame(
            ['A', 'B'],
            $policy->grantedRoles(),
            'Policy must read the persisted list through the shared SETTING_KEY'
        );
    }

    // ---- POST handling: whitelist + CSRF (scenarios 11, 12) ----

    public function testPostReadsOnlyWhitelistedField(): void
    {
        $source = file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);
        $this->assertNotFalse($source);

        $this->assertMatchesRegularExpression(
            "/\\\$_POST\['catalogo_excel_roles'\]/",
            $source,
            'POST must read the single whitelisted catalogo_excel_roles field'
        );
        $this->assertSame(
            1,
            preg_match_all("/\\\$_POST\[/", $source),
            'No other POST field may be read (input whitelist, scenario 11)'
        );
    }

    public function testPostChecksCsrfBeforePersist(): void
    {
        $source = file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);
        $this->assertNotFalse($source);

        $csrfPos = strpos($source, 'isCsrfValid()');
        $setPos = strpos($source, '->set(');
        $savePos = strpos($source, '->save()');

        $this->assertNotFalse($csrfPos, 'POST branch must call isCsrfValid() (scenario 12)');
        $this->assertNotFalse($setPos, 'Save pipeline must set the setting via fs_settings');
        $this->assertNotFalse($savePos, 'Save pipeline must persist via fs_settings save()');
        $this->assertLessThan($setPos, $csrfPos, 'CSRF check must run before the setting is written');
        $this->assertLessThan($savePos, $csrfPos, 'CSRF check must run before persistence');
    }

    // ---- View (scenario 12: CSRF in the form; no |raw with user data) ----

    public function testSettingsViewFileExists(): void
    {
        $file = FS_FOLDER . self::VIEW_FILE;
        $this->assertFileExists($file, 'Settings Twig view catalogo_excel_settings.html.twig must exist');
    }

    public function testSettingsViewIncludesCsrfField(): void
    {
        $content = file_get_contents(FS_FOLDER . self::VIEW_FILE);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            '{{ csrf_field() }}',
            $content,
            'Settings form must include csrf_field() (scenario 12)'
        );
    }

    public function testSettingsViewNoRawWithUserData(): void
    {
        $content = file_get_contents(FS_FOLDER . self::VIEW_FILE);
        $this->assertNotFalse($content);

        $this->assertStringNotContainsString(
            '|raw',
            $content,
            'Settings view must not use |raw with user data (autoescape discipline)'
        );
    }

    // ---- Scenario 4: naming discipline (grep-auditable) ----

    public function testNamingGrepAudit(): void
    {
        $files = [
            FS_FOLDER . self::CONTROLLER_FILE,
            FS_FOLDER . self::VIEW_FILE,
            __FILE__,
        ];

        // Pattern assembled via concatenation so the audit term itself never
        // appears contiguously in this test file (a self-hit would be noise).
        $pattern = 'grupo' . 'cliente' . '|grupo' . '_clientes' . '|customer' . '[_ ]?' . 'group';

        $command = 'grep -riE ' . escapeshellarg($pattern)
            . ' ' . implode(' ', array_map('escapeshellarg', $files)) . ' 2>&1';
        exec($command, $output, $exitCode);

        $this->assertSame(
            1,
            $exitCode,
            'grep must find zero hits over all new change files (naming discipline, scenario 4): '
            . implode("\n", $output)
        );
        $this->assertSame([], $output, 'No file may reference customer-group vocabulary');
    }
}