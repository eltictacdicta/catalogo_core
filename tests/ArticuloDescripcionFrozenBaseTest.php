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
use Tests\CatalogoCore\Support\FakeArticulo;
use Tests\CatalogoCore\Support\IdiomaRegistryFake;

require_once __DIR__ . '/Support/IdiomaRegistryFake.php';
require_once __DIR__ . '/Support/FakeCatalogoIdioma.php';
require_once __DIR__ . '/Support/FakeArticuloDescripcion.php';
require_once __DIR__ . '/Support/FakeArticulo.php';

/**
 * GDI-06 — `articulos.descripcion` is a deprecated frozen compatibility shim
 * (D1): never mirrored, never backfilled.
 */
final class ArticuloDescripcionFrozenBaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo_descripcion.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
    }

    public function test_default_language_save_does_not_write_the_base_column(): void
    {
        $db = new IdiomaRegistryFake(
            [['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true]],
            [['referencia' => 'ART1', 'codidioma' => 'es', 'descripcion' => 'Old row']]
        );
        $art = new FakeArticulo($db, 'ART1', 'Base original');

        $this->assertTrue($art->set_descripcion_idioma('es', 'New row', 'New short'));

        $row = $db->descripciones[0];
        $this->assertSame('New row', $row['descripcion'], 'the language row carries the new text');
        $this->assertSame('New short', $row['descripcion_corta']);

        $this->assertSame('Base original', $art->descripcion, 'the frozen base column must not be mirrored');

        $baseWrites = array_values(array_filter(
            $db->executed,
            static fn (string $sql): bool => (bool) preg_match('/\barticulos\b/i', $sql)
        ));
        $this->assertSame([], $baseWrites, 'no save path may write the base column');
    }

    public function test_no_backfill_runs_at_plugin_boot(): void
    {
        // Source guard: the boot migration and the description model never read
        // the frozen base column, so no `articulo_descripciones` row can be
        // created from `articulos.descripcion`.
        $migration = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Services/CatalogLegacyTableMigration.php'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\barticulos\b/i',
            $migration,
            'no backfill may read the base column at boot'
        );

        $description = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/core/articulo_descripcion.php'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\barticulos\b/i',
            $description,
            'the description model must not touch the base column'
        );

        // Runtime guard: with no language rows, the base text stays readable
        // through the read chain and nothing is materialised.
        $db = new IdiomaRegistryFake(
            [['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true]],
            []
        );
        $art = new FakeArticulo($db, 'ART1', 'Legacy base');

        $this->assertSame('Legacy base', $art->get_descripcion_idioma());
        $this->assertSame([], $db->descripciones, 'no backfill may create a description row');
        $this->assertSame([], $db->executed);
    }
}
