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
 * GDI-05 — description read chain: requested language -> configured default ->
 * `articulos.descripcion` -> ''. Every leg is a pure read (R2).
 *
 * The article is exercised DB-free through the language API seams and the
 * in-memory registry fake (D-09).
 */
final class ArticuloMultiidiomaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo_descripcion.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
    }

    public function test_requested_language_wins(): void
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
        $art = new FakeArticulo($db, 'ART1', 'Base');

        $this->assertSame('Hello', $art->get_descripcion_idioma('en'));
        $this->assertSame('Hola', $art->get_descripcion_idioma('es'));
    }

    public function test_fallback_uses_the_configured_default_not_a_hard_coded_es(): void
    {
        // No `por_defecto` row: resolution degrades deterministically to the
        // lowest active code ('en'), which must drive a null/absent request.
        $db = new IdiomaRegistryFake(
            [['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false]],
            [['referencia' => 'ART1', 'codidioma' => 'en', 'descripcion' => 'Hello EN']]
        );
        $art = new FakeArticulo($db, 'ART1', 'Base');

        $this->assertSame('Hello EN', $art->get_descripcion_idioma(), 'a null code resolves the configured default');
        $this->assertSame('Hello EN', $art->get_descripcion_idioma(''), 'an empty code resolves the configured default');
    }

    public function test_base_column_and_empty_terminal(): void
    {
        $db = new IdiomaRegistryFake(
            [
                ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
                ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
            ],
            []
        );

        $withBase = new FakeArticulo($db, 'ART1', 'Base text');
        $this->assertSame('Base text', $withBase->get_descripcion_idioma('fr'));

        $withoutBase = new FakeArticulo($db, 'ART2', '');
        $this->assertSame('', $withoutBase->get_descripcion_idioma('fr'));
    }

    public function test_reads_materialise_nothing(): void
    {
        $db = new IdiomaRegistryFake(
            [['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true]],
            []
        );
        $art = new FakeArticulo($db, 'ART1', 'Base');

        $art->get_descripcion_idioma('fr');
        $art->get_descripcion_idioma('es');
        $art->get_descripcion_idioma();

        $this->assertSame([], $db->descripciones, 'no leg of the read chain may create a row');
        $this->assertSame([], $db->executed, 'a read must issue no write statement');
    }

    public function test_descripcion_idioma_truncates_long_text(): void
    {
        $db = new IdiomaRegistryFake(
            [['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true]],
            []
        );
        $art = new FakeArticulo($db, 'ART2', str_repeat('a', 150));

        $this->assertSame('aaaaaaaaaa...', $art->descripcion_idioma('es', 10));
        $this->assertSame('aaaaaaaaaa...', $art->descripcion_idioma(null, 10));
    }

    public function test_get_descripcion_corta_idioma_is_same_language_only(): void
    {
        // LOCAL DECISION (D-08, R2): the short-description accessor does NOT
        // inherit the description fallback chain. It returns the requested
        // language's short text, or '' — never another language's text.
        $db = new IdiomaRegistryFake(
            [
                ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
                ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
            ],
            [
                ['referencia' => 'ART1', 'codidioma' => 'es', 'descripcion' => 'Hola', 'descripcion_corta' => 'Corta ES'],
                ['referencia' => 'ART1', 'codidioma' => 'en', 'descripcion' => 'Hello', 'descripcion_corta' => 'Short EN'],
            ]
        );
        $art = new FakeArticulo($db, 'ART1', 'Base');

        $this->assertSame('Short EN', $art->get_descripcion_corta_idioma('en'));
        $this->assertSame('Corta ES', $art->get_descripcion_corta_idioma(), 'a null code resolves the configured default');
        $this->assertSame('', $art->get_descripcion_corta_idioma('fr'), 'no cross-language fallback for the short text');
    }
}
