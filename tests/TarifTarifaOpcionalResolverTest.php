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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Resolver tag-intersection contract for the moved
 * `tarif_tarifa_opcional_resolver` (spec "Resolver applies tag intersection
 * rules", design D6).
 *
 * The effective article set includes untagged opcionales unconditionally and
 * tagged opcionales only when their tag set intersects the article tags. The
 * pure filter is exercised directly; the wiring is pinned by source contract.
 */
final class TarifTarifaOpcionalResolverTest extends TestCase
{
    private const RESOLVER_RELATIVE = 'plugins/catalogo_core/model/tarif_tarifa_opcional_resolver.php';

    private static bool $baseLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/' . self::RESOLVER_RELATIVE;
        self::$baseLoaded = true;
    }

    private function resolverSource(): string
    {
        $path = FS_FOLDER . '/' . self::RESOLVER_RELATIVE;
        if (!is_file($path)) {
            self::fail('missing catalogo_core path: ' . self::RESOLVER_RELATIVE);
        }

        return (string) file_get_contents($path);
    }

    private function buildResolver(): object
    {
        return new class() extends \FSFramework\model\tarif_tarifa_opcional_resolver {
            public function __construct()
            {
                // Database-free: only the pure filter seam is exercised.
            }
        };
    }

    private function invokePrivate(object $subject, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($subject, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($subject, $args);
    }

    /**
     * @param array<int, object> $opcionales
     * @return array<int, int>
     */
    private function ids(array $opcionales): array
    {
        return array_map(static fn(object $opcional): int => (int) $opcional->id, $opcionales);
    }

    private function assertFilterSeamExists(): void
    {
        if (!method_exists(\FSFramework\model\tarif_tarifa_opcional_resolver::class, 'filtrar_por_etiquetas')) {
            self::fail('missing resolver seam: tarif_tarifa_opcional_resolver::filtrar_por_etiquetas()');
        }
    }

    // =====================================================================
    // Pure tag-intersection filter
    // =====================================================================

    public function test_untagged_opcionales_are_included_and_tagged_only_on_intersection(): void
    {
        $this->assertFilterSeamExists();

        $untagged = (object) ['id' => 1];
        $taggedMatching = (object) ['id' => 2];
        $taggedOther = (object) ['id' => 3];

        $result = $this->invokePrivate($this->buildResolver(), 'filtrar_por_etiquetas', [
            [$untagged, $taggedMatching, $taggedOther],
            ['ROJO'],
            [2 => ['ROJO'], 3 => ['AZUL']],
        ]);

        $this->assertSame([1, 2], $this->ids($result));
    }

    public function test_tagged_opcionales_are_excluded_when_the_article_has_no_tags(): void
    {
        $this->assertFilterSeamExists();

        $untagged = (object) ['id' => 1];
        $tagged = (object) ['id' => 2];

        $result = $this->invokePrivate($this->buildResolver(), 'filtrar_por_etiquetas', [
            [$untagged, $tagged],
            [],
            [2 => ['ROJO']],
        ]);

        $this->assertSame([1], $this->ids($result));
    }

    public function test_every_opcional_without_a_tag_entry_is_treated_as_untagged(): void
    {
        $this->assertFilterSeamExists();

        $a = (object) ['id' => 7];
        $b = (object) ['id' => 9];

        $result = $this->invokePrivate($this->buildResolver(), 'filtrar_por_etiquetas', [
            [$a, $b],
            ['ROJO'],
            [],
        ]);

        $this->assertSame([7, 9], $this->ids($result));
    }

    // =====================================================================
    // Wiring contract
    // =====================================================================

    public function test_get_opcionales_activos_articulo_applies_the_filter_helper(): void
    {
        $src = $this->resolverSource();

        $this->assertStringContainsString('filtrar_por_etiquetas(', $src);
        $this->assertStringContainsString('get_etiquetas_opcional(', $src);
        $this->assertStringContainsString('familia_tiene_etiquetas(', $src);
        $this->assertStringContainsString(
            'return $opcionales;',
            $src,
            'a familia without tags must return the full active set'
        );
    }
}
