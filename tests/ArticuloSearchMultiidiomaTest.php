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
use Tests\CatalogoCore\Support\FakeSearchArticulo;
use Tests\CatalogoCore\Support\SearchCacheFake;
use Tests\CatalogoCore\Support\SearchCatalogFake;

require_once __DIR__ . '/Support/SearchCatalogFake.php';
require_once __DIR__ . '/Support/SearchCacheFake.php';
require_once __DIR__ . '/Support/FakeSearchArticulo.php';

/**
 * GDI-09 — language-agnostic article search (D-04, D11).
 *
 * Article search deliberately matches `articulos.descripcion` OR any
 * `articulo_descripciones` row, instead of scoping to one language/store view
 * like every retrieved system does. The deviation is isolated in one predicate
 * carrying its rationale, so a later contributor cannot "fix" it silently.
 */
final class ArticuloSearchMultiidiomaTest extends TestCase
{
    private const BUILDER_PATH = '/plugins/catalogo_core/Services/ArticuloSearchQueryBuilder.php';

    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo_descripcion.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';

        $this->resetSearchStatics();
    }

    public function test_a_term_that_lives_only_in_a_non_default_language_is_findable(): void
    {
        $db = new SearchCatalogFake();
        $db->addArticulo('ART1', 'Base text');
        $db->addDescripcion('ART1', 'en', 'Solo ingles');

        $articulo = new FakeSearchArticulo($db, new SearchCacheFake());
        $results = $articulo->search('ingles');

        $this->assertCount(1, $results, 'a term living only in a translation must be findable');
        $this->assertSame('ART1', $results[0]->referencia);

        $sql = $db->queries[0];
        $this->assertStringContainsString('SELECT DISTINCT a.* FROM articulos a', $sql);
        $this->assertStringContainsString('LEFT JOIN articulo_descripciones d', $sql);
        $this->assertStringContainsString("lower(a.descripcion) LIKE '%ingles%'", $sql);
        $this->assertStringContainsString("lower(d.descripcion) LIKE '%ingles%'", $sql);
        $this->assertStringContainsString('ORDER BY a.referencia ASC', $sql);
    }

    public function test_a_cleared_translation_stops_matching(): void
    {
        $db = new SearchCatalogFake();
        $db->addArticulo('ART1', 'Base text');
        $db->addDescripcion('ART1', 'en', 'Solo ingles');

        $articulo = new FakeSearchArticulo($db, new SearchCacheFake());
        $this->assertCount(1, $articulo->search('ingles'), 'precondition: the translation is findable');

        // Clearing a language's pair removes its row (GDI-07); the read state of
        // the language-agnostic predicate is what this test asserts.
        $db->clearDescripcion('ART1', 'en');

        $this->assertSame([], $articulo->search('ingles'), 'a cleared translation must stop matching');
        $this->assertStringContainsString("lower(d.descripcion) LIKE '%ingles%'", $db->queries[1]);
    }

    public function test_exactly_one_language_agnostic_predicate_exists_and_carries_the_deviation_note(): void
    {
        $source = file_get_contents(FS_FOLDER . self::BUILDER_PATH);
        $this->assertIsString($source);

        // Exactly one predicate definition and exactly one place emits the
        // translation-table leg: the single-word, multi-word and numeric paths
        // all share the same helper.
        $this->assertSame(1, substr_count($source, 'function languageDescriptionPredicate('));
        $this->assertSame(1, substr_count($source, 'lower(d.descripcion)'));
        $this->assertSame(1, substr_count($source, 'function languageDescriptionJoin('));
        $this->assertStringContainsString(
            'LEFT JOIN articulo_descripciones d ON d.referencia = a.referencia',
            $source
        );

        // The deviation note is inline and discoverable: it names the systems
        // that scope search, the reason this fork does not, and forbids a
        // silent "fix" into a locale-scoped search.
        $this->assertStringContainsString('DELIBERATE DEVIATION', $source);
        $this->assertStringContainsString('GDI-09', $source);
        $this->assertStringContainsString('Do NOT', $source);
        $this->assertStringContainsString('locale-scoped', $source);
        $this->assertStringContainsString('PrestaShop', $source);
        $this->assertStringContainsString('Magento', $source);

        // No unqualified base-column description predicate may survive.
        $this->assertDoesNotMatchRegularExpression('/[^a-z.]lower\(descripcion\)/', $source);
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
}
