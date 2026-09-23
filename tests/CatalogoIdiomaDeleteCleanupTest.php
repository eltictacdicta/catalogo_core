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

use FSFramework\model\catalogo_idioma;
use PHPUnit\Framework\TestCase;
use Tests\CatalogoCore\Support\FakeCatalogoIdioma;
use Tests\CatalogoCore\Support\IdiomaRegistryFake;

require_once __DIR__ . '/Support/IdiomaRegistryFake.php';
require_once __DIR__ . '/Support/FakeCatalogoIdioma.php';

/**
 * GDI-03 — last-language guard and delete cleanup (D-01, D-02).
 */
final class CatalogoIdiomaDeleteCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
        // `catalogo_idioma::delete()` delegates the search-cache obligation to
        // `articulo::invalidate_search_cache()` (GDI-08), so the article model
        // must be loadable when the delete path reaches it.
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
    }

    public function test_deleting_the_last_language_is_refused(): void
    {
        $db = new IdiomaRegistryFake([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => false],
        ]);
        $model = $this->registry($db);
        $model->codidioma = 'es';
        $model->por_defecto = false;

        $this->assertFalse($model->delete());
        $this->assertNotEmpty($model->errors);
        $this->assertArrayHasKey('es', $db->idiomas, 'the last language must survive');
        $this->assertSame([], $db->executed, 'a refused delete must emit no statement');
    }

    public function test_deleting_a_language_removes_its_descriptions(): void
    {
        $db = new IdiomaRegistryFake(
            [
                ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
                ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
            ],
            [
                ['referencia' => 'ART1', 'codidioma' => 'es', 'descripcion' => 'Hola'],
                ['referencia' => 'ART1', 'codidioma' => 'en', 'descripcion' => 'Hello'],
            ]
        );
        $model = $this->registry($db);
        $model->codidioma = 'en';
        $model->por_defecto = false;

        $this->assertTrue($model->delete());
        $this->assertArrayNotHasKey('en', $db->idiomas);
        $this->assertTrue($db->committed);

        $remaining = array_values(array_filter(
            $db->descripciones,
            static fn (array $row): bool => (string) $row['codidioma'] === 'en'
        ));
        $this->assertSame([], $remaining, 'the deleted language must leave no readable description row');

        $spanish = array_values(array_filter(
            $db->descripciones,
            static fn (array $row): bool => (string) $row['codidioma'] === 'es'
        ));
        $this->assertCount(1, $spanish, 'the other language rows must be untouched');
        $this->assertSame(['es'], $db->activeDefaults(), 'the default invariant must survive the delete');
    }

    private function registry(IdiomaRegistryFake $db): catalogo_idioma
    {
        return (new FakeCatalogoIdioma())->useFakeDb($db);
    }
}
