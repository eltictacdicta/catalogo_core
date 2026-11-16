<?php
declare(strict_types=1);
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\CatalogoCore;

use FSFramework\Translation\FSTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The plugin catalogs ship under the base language (`messages.es.yaml`,
 * `messages.en.yaml`), like the core and every other plugin. The active
 * locale may carry a region (`es_ES` is the framework default), so the
 * catalog must resolve through the translator fallback and never silently
 * drop to the default language.
 */
final class TranslationResolutionTest extends TestCase
{
    /** @var list<string> keys present in both shipped catalogs */
    private const KEYS = [
        'catalogo-core-familias-tree-title',
        'catalogo-core-catalogo-view-title',
        'catalogo-core-lista-empty',
    ];

    protected function setUp(): void
    {
        FSTranslator::reset();
        FSTranslator::initialize(FS_FOLDER);
        FSTranslator::loadPluginTranslations('catalogo_core', FS_FOLDER . '/plugins/catalogo_core');
    }

    /** @return array<string, array{string}> */
    public static function locales(): array
    {
        return [
            'framework default' => ['es_ES'],
            'base spanish' => ['es'],
            'english region' => ['en_EN'],
            'base english' => ['en'],
        ];
    }

    /**
     * Every locale variant must reach the shipped catalog instead of
     * returning the literal key.
     */
    #[DataProvider('locales')]
    public function testShippedCatalogsResolveForEveryLocaleVariant(string $locale): void
    {
        FSTranslator::setLocale($locale);

        foreach (self::KEYS as $key) {
            $value = FSTranslator::trans($key);
            $this->assertNotSame($key, $value, sprintf('%s is missing from the %s catalog', $key, $locale));
            $this->assertNotSame('', trim($value), sprintf('%s is empty in the %s catalog', $key, $locale));
        }
    }

    public function testEnglishIsNotTheSpanishCatalog(): void
    {
        $key = 'catalogo-core-familias-tree-title';

        FSTranslator::setLocale('es_ES');
        $spanish = FSTranslator::trans($key);

        FSTranslator::setLocale('en_EN');
        $english = FSTranslator::trans($key);

        $this->assertNotSame($spanish, $english, 'English must resolve its own catalog, not fall back to Spanish');
    }
}
