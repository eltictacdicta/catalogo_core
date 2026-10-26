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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Translation contract of the absorbed family/catalog UI (familias-jerarquia
 * task 4.3, D7 / R-FJ-008).
 *
 * Two rules: every new UI string carries the `catalogo-core-` plugin prefix, and
 * every key the new templates use resolves in both shipped locales (es_ES and
 * en_EN, the fallback). Existing unprefixed keys belong to the pre-existing
 * catalog pages and are deliberately out of scope: only the templates this
 * capability introduces are audited.
 */
final class TranslationAuditTest extends TestCase
{
    private const LOCALES = ['es_ES', 'en_EN'];

    /**
     * D7 seed set (tasks.md 4.3). Shape keys added ahead of the deferred tree
     * editor tasks are included on purpose: the set ships complete.
     */
    private const SEED_KEYS = [
        'catalogo-core-familias-tree-title',
        'catalogo-core-lista-selector',
        'catalogo-core-estructura-capitulo',
        'catalogo-core-estructura-orden',
        'catalogo-core-estructura-en-catalogo',
        'catalogo-core-estructura-en-tarifa',
        'catalogo-core-estructura-activa',
        'catalogo-core-ciclo-error',
        'catalogo-core-articulos-asignacion-tab',
        'catalogo-core-catalogo-view-title',
        'catalogo-core-catalogo-view-empty',
    ];

    /** Templates introduced by this capability (extended by the deferred tree UI tasks). */
    private const NEW_TEMPLATES = [
        '/View/ventas_catalogo_view.html.twig',
    ];

    #[Test]
    #[DataProvider('provideSeedKeys')]
    public function seedKeyResolvesInEveryLocale(string $key): void
    {
        $this->assertStringStartsWith('catalogo-core-', $key, 'Seed keys must carry the plugin prefix');

        foreach (self::LOCALES as $locale) {
            $messages = $this->messages($locale);
            $this->assertArrayHasKey($key, $messages, sprintf('%s is missing from %s', $key, $locale));
            $this->assertNotSame('', trim((string) $messages[$key]), sprintf('%s is empty in %s', $key, $locale));
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideSeedKeys(): array
    {
        $cases = [];
        foreach (self::SEED_KEYS as $key) {
            $cases[$key] = [$key];
        }

        return $cases;
    }

    #[Test]
    public function everyKeyUsedByTheNewTemplatesIsPrefixedAndResolves(): void
    {
        $used = [];
        foreach (self::NEW_TEMPLATES as $relative) {
            foreach ($this->keysUsedBy(FS_FOLDER . '/plugins/catalogo_core' . $relative) as $key) {
                $used[$key] = true;
            }
        }

        $this->assertNotSame([], $used, 'The new templates must not ship hardcoded UI copy');

        foreach (array_keys($used) as $key) {
            $this->assertStringStartsWith(
                'catalogo-core-',
                $key,
                sprintf('Template key "%s" must carry the plugin prefix (R-FJ-008)', $key)
            );

            foreach (self::LOCALES as $locale) {
                $this->assertArrayHasKey(
                    $key,
                    $this->messages($locale),
                    sprintf('Template key "%s" does not resolve in %s', $key, $locale)
                );
            }
        }
    }

    #[Test]
    public function newTemplatesExistToBeAudited(): void
    {
        foreach (self::NEW_TEMPLATES as $relative) {
            $this->assertFileExists(FS_FOLDER . '/plugins/catalogo_core' . $relative);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function messages(string $locale): array
    {
        $path = FS_FOLDER . '/plugins/catalogo_core/translations/messages.' . $locale . '.yaml';
        $this->assertFileExists($path, sprintf('Locale file %s must exist', $locale));

        $parsed = Yaml::parseFile($path);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @return list<string> translation keys referenced from a template
     */
    private function keysUsedBy(string $path): array
    {
        $source = (string) file_get_contents($path);
        preg_match_all("/'([a-z0-9\-]+)'\s*\|\s*trans/", $source, $piped);
        preg_match_all("/trans\(\s*'([^']+)'/", $source, $called);

        return array_values(array_unique(array_merge($piped[1], $called[1])));
    }
}
