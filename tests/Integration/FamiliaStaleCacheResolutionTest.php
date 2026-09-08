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
 * Coverage backfill for CDM-06 S2 — "Stale caches cannot resurrect the
 * override" (openspec/changes/retire-tarif-familia-ext/specs/catalog-domain-models/spec.md).
 *
 * Companion to FamiliaTarifaResolutionTest (S1: cold deterministic base
 * resolution in both plugin orders). This file simulates the POISONED legs
 * S1 never exercises:
 * 1. an in-memory class map still carrying the deleted `familia` override
 *    path (plugins/tarifario/model/familia.php, deleted by change
 *    2026-09-03-tarifario-catalogo-hook-integration) — the autoloader must
 *    evict the dead entry and re-resolve to the catalogo_core base;
 * 2. a real poisoned `tmp/*model_class_map.php` file mapping `familia` to an
 *    inactive plugin — validateCache() must discard the whole cache file.
 *
 * Can-fail contract (no production change is covered by this file — it pins
 * existing behavior): the final-resolution assertions go through the real
 * fs_model_autoloader::loadClass()/validateCache() code, so if stale-entry
 * eviction or cache validation ever regressed (e.g. trusting the map
 * blindly), the map would keep the wrong path or resolution would leave the
 * base — the identity and repaired-map assertions fail.
 *
 * The temp cache file lives outside the repo and is removed in tearDown
 * (no residue); the autoloader state (classMap/cacheFile/plugins) is saved
 * and restored around every test.
 */
final class FamiliaStaleCacheResolutionTest extends TestCase
{
    /** @var mixed previous value of $GLOBALS['plugins'] */
    private $previousPlugins;
    /** @var mixed previous fs_model_autoloader::$classMap */
    private $savedClassMap;
    /** @var mixed previous fs_model_autoloader::$cacheFile */
    private $savedCacheFile;
    /** @var mixed previous fs_model::$checked_tables */
    private $savedCheckedTables;
    private string $tempCacheFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousPlugins = $GLOBALS['plugins'] ?? null;

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_model_autoloader.php';

        $refMap = new \ReflectionProperty(\fs_model_autoloader::class, 'classMap');
        $refMap->setAccessible(true);
        $this->savedClassMap = $refMap->getValue();

        $refCache = new \ReflectionProperty(\fs_model_autoloader::class, 'cacheFile');
        $refCache->setAccessible(true);
        $this->savedCacheFile = $refCache->getValue();

        // Redirect the cache file at a private temp path so any saveCache()
        // write during the test can never leave residue inside the repo tmp/.
        $this->tempCacheFile = (string) tempnam(sys_get_temp_dir(), 'fam_stale_cache_');
        $refCache->setValue(null, $this->tempCacheFile);

        // new \familia() runs the fs_model table check; seeding checked_tables
        // keeps every assertion in this file DB-free.
        $refTables = new \ReflectionProperty(\fs_model::class, 'checked_tables');
        $refTables->setAccessible(true);
        $this->savedCheckedTables = $refTables->getValue();
        $refTables->setValue(null, array_values(array_unique(array_merge(
            is_array($this->savedCheckedTables) ? $this->savedCheckedTables : [],
            ['familias']
        ))));
    }

    protected function tearDown(): void
    {
        \fs_model_autoloader::clearCache();
        @unlink($this->tempCacheFile);

        $refCache = new \ReflectionProperty(\fs_model_autoloader::class, 'cacheFile');
        $refCache->setAccessible(true);
        $refCache->setValue(null, $this->savedCacheFile);

        $refMap = new \ReflectionProperty(\fs_model_autoloader::class, 'classMap');
        $refMap->setAccessible(true);
        $refMap->setValue(null, $this->savedClassMap);

        $refTables = new \ReflectionProperty(\fs_model::class, 'checked_tables');
        $refTables->setAccessible(true);
        $refTables->setValue(null, $this->savedCheckedTables);

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
        // register() no-ops once registered; refreshModelDirs() is the
        // documented mid-request rebuild, so modelDirs always matches the
        // plugin list under test (same pattern as FamiliaTarifaResolutionTest).
        \fs_model_autoloader::refreshModelDirs();
    }

    /** Shared S2 contract: the global name must resolve to the catalogo_core base. */
    private function assertResolvesToDeterministicBase(): void
    {
        $this->assertTrue(
            is_a('familia', 'FSFramework\model\familia', true),
            'With a poisoned class map, global familia must still resolve to the catalogo_core base'
        );
        $this->assertSame(
            'FSFramework\model\familia',
            get_class(new \familia()),
            'Instantiating the global familia after cache poisoning must yield the base implementation'
        );
        $this->assertFalse(
            property_exists('familia', 'capitulo'),
            'Base resolution must not carry the tarif_familia extension fields'
        );
    }

    /**
     * Leg 1 — the in-memory map still carries the DELETED override path (the
     * exact stale entry a runtime cache could hold after
     * 2026-09-03-tarifario-catalogo-hook-integration). loadClass() must evict
     * the dead entry and re-resolve to the base.
     */
    public function test_stale_map_entry_for_deleted_override_path_resolves_to_base(): void
    {
        $this->registerAutoloaderWithPlugins(['tarifario', 'catalogo_core']);

        $refMap = new \ReflectionProperty(\fs_model_autoloader::class, 'classMap');
        $refMap->setAccessible(true);
        $refMap->setValue(null, ['familia' => FS_FOLDER . '/plugins/tarifario/model/familia.php']);
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/tarifario/model/familia.php',
            'Precondition: the tarifario override file must stay deleted'
        );

        $this->assertTrue(
            \fs_model_autoloader::loadClass('familia'),
            'Resolution must survive a stale map entry pointing at the deleted override file'
        );
        $this->assertResolvesToDeterministicBase();

        $map = $refMap->getValue();
        $this->assertSame(
            FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php',
            $map['familia'] ?? null,
            'The stale entry must be evicted and replaced by the real base path'
        );
    }

    /**
     * Leg 2 — a real poisoned cache FILE mapping familia to an inactive
     * plugin: loadCache() reads it, validateCache() must discard the file,
     * and resolution must still land on the base.
     */
    public function test_poisoned_cache_file_for_inactive_plugin_is_discarded(): void
    {
        $this->registerAutoloaderWithPlugins(['tarifario', 'catalogo_core']);

        $poisonPath = FS_FOLDER . '/plugins/some_inactive_plugin/model/familia.php';
        file_put_contents(
            $this->tempCacheFile,
            "<?php\n// Model class map cache - poisoned fixture\nreturn " . var_export(['familia' => $poisonPath], true) . ";\n"
        );

        $refMap = new \ReflectionProperty(\fs_model_autoloader::class, 'classMap');
        $refMap->setAccessible(true);

        $loadCache = new \ReflectionMethod(\fs_model_autoloader::class, 'loadCache');
        $loadCache->setAccessible(true);
        $loadCache->invoke(null);
        $this->assertSame(
            ['familia' => $poisonPath],
            $refMap->getValue(),
            'Precondition: loadCache() must have loaded the poisoned fixture'
        );

        $validateCache = new \ReflectionMethod(\fs_model_autoloader::class, 'validateCache');
        $validateCache->setAccessible(true);
        $validateCache->invoke(null);

        $this->assertFileDoesNotExist(
            $this->tempCacheFile,
            'validateCache() must unlink a cache file holding an inactive-plugin path'
        );
        $this->assertSame([], $refMap->getValue(), 'validateCache() must empty the poisoned map');

        $this->assertTrue(
            \fs_model_autoloader::loadClass('familia'),
            'Resolution must survive a discarded poisoned cache file'
        );
        $this->assertResolvesToDeterministicBase();
    }
}
