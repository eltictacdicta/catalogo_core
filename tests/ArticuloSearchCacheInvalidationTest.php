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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;
use Tests\CatalogoCore\Support\FakeArticuloDescripcion;
use Tests\CatalogoCore\Support\IdiomaRegistryFake;

require_once __DIR__ . '/Support/IdiomaRegistryFake.php';
require_once __DIR__ . '/Support/FakeCatalogoIdioma.php';
require_once __DIR__ . '/Support/FakeArticuloDescripcion.php';

/**
 * GDI-08 — every description write invalidates the article search cache
 * (defect f, D-03).
 *
 * `articulo_descripcion::save()` / `delete()` own the search-cache obligation
 * through the single `articulo::invalidate_search_cache()` entry point. The
 * real test cache adapter is exercised (per the design's open-question
 * resolution), with the `articulo` search statics reset by Reflection.
 */
final class ArticuloSearchCacheInvalidationTest extends TestCase
{
    private const TAG = 'gdi08tornillo';
    private const CACHE_KEY = 'articulos_search_gdi08tornillo';

    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_cache.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo_descripcion.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';

        $this->resetSearchStatics();
        $this->resetCache();
    }

    protected function tearDown(): void
    {
        $cache = new \fs_cache();
        $cache->delete('articulos_searches');
        $cache->delete(self::CACHE_KEY);

        parent::tearDown();
    }

    public function test_non_default_language_write_invalidates_the_cache(): void
    {
        $cache = new \fs_cache();
        $this->seedCachedSearch($cache);

        $db = new IdiomaRegistryFake();
        $row = new FakeArticuloDescripcion([
            'referencia' => 'ART1',
            'codidioma' => 'en',
            'descripcion' => 'English text',
            'descripcion_corta' => null,
        ]);
        $row->useFakeDb($db);

        $this->assertTrue($row->save(), 'the description write must succeed');

        $this->assertSame(
            [],
            $cache->get_array(self::CACHE_KEY),
            'a non-default-language description write must invalidate the search cache'
        );
    }

    public function test_clearing_path_delete_invalidates_the_cache(): void
    {
        $cache = new \fs_cache();
        $this->seedCachedSearch($cache);

        $db = new IdiomaRegistryFake([], [[
            'referencia' => 'ART1',
            'codidioma' => 'en',
            'descripcion' => 'English text',
            'descripcion_corta' => null,
        ]]);
        $row = (new FakeArticuloDescripcion())->useFakeDb($db)->get_by_articulo_idioma('ART1', 'en');
        $row->descripcion = '';
        $row->descripcion_corta = null;

        $this->assertTrue($row->save(), 'the clearing path must succeed');

        $this->assertSame([], $db->descripciones, 'the clearing path must delete the row');
        $this->assertSame(
            [],
            $cache->get_array(self::CACHE_KEY),
            'the clearing path must invalidate the search cache'
        );
    }

    public function test_delete_invalidates_the_cache(): void
    {
        $cache = new \fs_cache();
        $this->seedCachedSearch($cache);

        $db = new IdiomaRegistryFake([], [[
            'referencia' => 'ART1',
            'codidioma' => 'en',
            'descripcion' => 'English text',
            'descripcion_corta' => null,
        ]]);
        $row = (new FakeArticuloDescripcion())->useFakeDb($db)->get_by_articulo_idioma('ART1', 'en');

        $this->assertTrue($row->delete());

        $this->assertSame(
            [],
            $cache->get_array(self::CACHE_KEY),
            'deleting a description row must invalidate the search cache'
        );
    }

    public function test_the_description_model_is_the_only_owner_of_the_table_writes(): void
    {
        $model = file_get_contents(FS_FOLDER . '/plugins/catalogo_core/model/core/articulo_descripcion.php');
        $this->assertIsString($model);
        $this->assertSame(
            2,
            substr_count($model, 'articulo::invalidate_search_cache()'),
            'save() and delete() must both invalidate the search cache'
        );

        // DML against `articulo_descripciones` is confined to the documented
        // owners: the description model, the language-delete cleanup and the
        // activation-time migration copy/purge.
        $allowlist = [
            'model/core/articulo_descripcion.php',
            'model/core/catalogo_idioma.php',
            'Services/CatalogLegacyTableMigration.php',
        ];

        $offenders = [];
        $root = FS_FOLDER . '/plugins/catalogo_core';
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($directory as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = (string) $file->getPathname();
            if (strpos($path, '/tests/') !== false || strpos($path, '/vendor/') !== false) {
                continue;
            }

            $relative = substr($path, strlen($root) + 1);
            if (in_array($relative, $allowlist, true)) {
                continue;
            }

            $source = (string) file_get_contents($path);
            $pattern = '/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+(?:d\s+FROM|FROM))\s+articulo_descripciones\b/i';
            if (preg_match($pattern, $source) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'DML against articulo_descripciones escaped the allowlist: ' . implode(', ', $offenders)
        );
    }

    private function seedCachedSearch(\fs_cache $cache): void
    {
        $cache->set('articulos_searches', [[
            'tag' => self::TAG,
            'expires' => time() + 5400,
            'count' => 1,
        ]], 5400);
        $cache->set(self::CACHE_KEY, [['referencia' => 'ART1']], 5400);

        $this->assertSame(
            [['referencia' => 'ART1']],
            $cache->get_array(self::CACHE_KEY),
            'precondition: the search result is cached'
        );
    }

    private function resetSearchStatics(): void
    {
        $ref = new \ReflectionClass(\FSFramework\model\articulo::class);
        foreach (['search_tags' => [], 'cleaned_cache' => false] as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private function resetCache(): void
    {
        \FSFramework\Cache\CacheManager::reset();

        $ref = new \ReflectionClass(\fs_cache::class);
        $prop = $ref->getProperty('cache_manager');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}
