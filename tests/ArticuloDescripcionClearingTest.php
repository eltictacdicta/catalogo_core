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
use Tests\CatalogoCore\Support\FakeArticuloDescripcion;
use Tests\CatalogoCore\Support\IdiomaRegistryFake;

require_once __DIR__ . '/Support/IdiomaRegistryFake.php';
require_once __DIR__ . '/Support/FakeCatalogoIdioma.php';
require_once __DIR__ . '/Support/FakeArticuloDescripcion.php';
require_once __DIR__ . '/Support/FakeArticulo.php';

/**
 * GDI-07 — clearing semantics (D-11, D-12): a language's description is present
 * or absent; the clearing decision lives inside `articulo_descripcion::save()`
 * so no caller can bypass it.
 */
final class ArticuloDescripcionClearingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo_descripcion.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
    }

    public function test_both_fields_empty_deletes_the_row(): void
    {
        $db = new IdiomaRegistryFake(
            [],
            [['referencia' => 'ART1', 'codidioma' => 'es', 'descripcion' => 'Hola ES', 'descripcion_corta' => 'Corta']]
        );
        $row = (new FakeArticuloDescripcion())->useFakeDb($db)->get_by_articulo_idioma('ART1', 'es');

        $row->descripcion = '';
        $row->descripcion_corta = null;

        $this->assertTrue($row->save(), 'clearing a language must succeed');

        $this->assertSame([], $db->descripciones, 'both-empty must delete the language row');
        $deletes = array_values(array_filter(
            $db->executed,
            static fn (string $sql): bool => str_starts_with($sql, 'DELETE')
        ));
        $this->assertCount(1, $deletes, 'the clearing path must emit a DELETE');
    }

    public function test_short_description_only_row_is_preserved(): void
    {
        // LOCAL DECISION (GDI-07, R3): keeping a row when only
        // `descripcion_corta` is set has no external evidence — PrestaShop
        // updates the `_lang` row in place and Odoo drops the language key. The
        // maintainer owns this choice.
        $db = new IdiomaRegistryFake(
            [],
            [['referencia' => 'ART1', 'codidioma' => 'es', 'descripcion' => 'Hola ES', 'descripcion_corta' => null]]
        );
        $row = (new FakeArticuloDescripcion())->useFakeDb($db)->get_by_articulo_idioma('ART1', 'es');

        $row->descripcion = '';
        $row->descripcion_corta = 'Solo corta';

        $this->assertTrue($row->save());

        $this->assertCount(1, $db->descripciones, 'a short-description-only row must survive');
        $this->assertSame('', $db->descripciones[0]['descripcion']);
        $this->assertSame('Solo corta', $db->descripciones[0]['descripcion_corta']);
    }

    public function test_empty_description_is_accepted_by_validation(): void
    {
        $row = new FakeArticuloDescripcion([
            'referencia' => 'ART1',
            'codidioma' => 'es',
            'descripcion' => '',
            'descripcion_corta' => null,
        ]);

        $this->assertTrue($row->test(), 'an empty description must be accepted so the clearing path can proceed');
        $this->assertSame([], $row->errors);
    }

    public function test_empty_pair_with_no_existing_row_is_a_noop(): void
    {
        $db = new IdiomaRegistryFake();
        $row = (new FakeArticuloDescripcion([
            'referencia' => 'ART1',
            'codidioma' => 'es',
            'descripcion' => '',
            'descripcion_corta' => null,
        ]))->useFakeDb($db);

        $this->assertTrue($row->save(), 'nothing to clear is not an error');
        $this->assertSame([], $db->executed, 'an absent row must emit no statement');
    }

    public function test_a_cleared_language_falls_back_at_read_time(): void
    {
        $db = new IdiomaRegistryFake(
            [['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true]],
            [['referencia' => 'ART1', 'codidioma' => 'es', 'descripcion' => 'Hola ES', 'descripcion_corta' => null]]
        );
        $art = new FakeArticulo($db, 'ART1', 'Base');

        $row = (new FakeArticuloDescripcion())->useFakeDb($db)->get_by_articulo_idioma('ART1', 'es');
        $row->descripcion = '';
        $row->descripcion_corta = null;
        $this->assertTrue($row->save());

        $this->assertSame('Base', $art->get_descripcion_idioma('es'), 'the fallback supplies the text');
        $this->assertSame([], $db->descripciones, 'no empty row may be materialised');
    }
}
