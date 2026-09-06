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

namespace Tests\CatalogoCore\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Split of tarifario's FamiliaOverrideRemovalTest (S25/S26 familias half):
 * after absorbing the familias-tarifa package, catalogo_core owns the
 * familias/base-resolution assertions.
 *
 * PR2c3 regression: the plugin tree must NOT declare a global-namespace
 * `familia` override (AD-6). Global `familia` resolution is DETERMINISTIC:
 * the model autoloader aliases it to catalogo_core's namespaced base
 * (FSFramework\model\familia) regardless of plugin activation order.
 *
 * State guards preserved verbatim from the tarifario original: setUp saves
 * $GLOBALS['plugins'] + clears the autoloader cache; tearDown restores +
 * clears; registerAutoloaderWithPlugins() drives both activation orders.
 *
 * Scenarios: S25 (deterministic base resolution), S26 (audited call sites).
 */
final class FamiliaTarifaResolutionTest extends TestCase
{
    /** @var mixed previous value of $GLOBALS['plugins'] */
    private $previousPlugins;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousPlugins = $GLOBALS['plugins'] ?? null;

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_model_autoloader.php';

        // Stale class maps would resurrect the deleted override path (R1):
        // clear before every resolution so the scan is cold and deterministic.
        \fs_model_autoloader::clearCache();
    }

    protected function tearDown(): void
    {
        \fs_model_autoloader::clearCache();
        if ($this->previousPlugins === null) {
            unset($GLOBALS['plugins']);
        } else {
            $GLOBALS['plugins'] = $this->previousPlugins;
        }
        parent::tearDown();
    }

    /** Registers the model autoloader cold with the given activation order. */
    private function registerAutoloaderWithPlugins(array $order): void
    {
        $GLOBALS['plugins'] = $order;
        \fs_model_autoloader::clearCache();
        \fs_model_autoloader::register();
        // register() no-ops if another test already registered the autoloader
        // (with a different plugin list) — refreshModelDirs() is the documented
        // mid-request rebuild, so modelDirs always matches the order under test.
        \fs_model_autoloader::refreshModelDirs();
    }

    /** S25 contract shared by both activation orders. */
    private function assertResolvesToDeterministicBase(): void
    {
        // Triggers the model autoloader → class_alias to the namespaced base.
        $this->assertTrue(class_exists('familia'), 'Global familia must resolve via the autoloader');
        $this->assertTrue(is_a('familia', 'FSFramework\model\familia', true), 'Global familia must BE the namespaced base class');
        $this->assertFalse(property_exists('familia', 'capitulo'), 'Base resolution must not carry the tarif_familia extension fields');
        $this->assertNotContains('FSFramework\model\tarif_familia', class_parents('familia') ?: [], 'Base resolution must not inherit the extension join chain');
    }

    // S25 — deterministic base resolution regardless of plugin activation order.

    public function test_global_familia_resolves_to_base_with_tarifario_first(): void
    {
        $this->registerAutoloaderWithPlugins(['tarifario', 'catalogo_core']);
        $this->assertResolvesToDeterministicBase();
    }

    public function test_global_familia_resolves_to_base_with_catalogo_core_first(): void
    {
        $this->registerAutoloaderWithPlugins(['catalogo_core', 'tarifario']);
        $this->assertResolvesToDeterministicBase();
    }

    // S25 — the extended class keeps its identity for tarifario-internal use.

    public function test_tarif_familia_remains_loadable_with_extension_surface(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_familia.php';

        $this->assertTrue(class_exists('FSFramework\model\tarif_familia', false));
        $this->assertSame('FSFramework\model\familia', get_parent_class('FSFramework\model\tarif_familia'));
        $this->assertTrue(property_exists('FSFramework\model\tarif_familia', 'capitulo'), 'The ext class keeps capitulo/nivel for internal use');
    }

    // S26 — audited call sites behave per the AD-6 audit decision.

    public function test_tarif_familias_call_site_keeps_base_semantics(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/controller/tarif_familias.php');

        $this->assertStringContainsString(
            "require_once 'plugins/catalogo_core/model/core/familia.php'",
            $src,
            'The explicit base require proves base intent (AD-6 site 1)'
        );
        $this->assertStringContainsString('new \familia()', $src, 'Call site keeps global base instantiation');
    }
}
