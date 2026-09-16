<?php
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
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * catalogo-render-hooks delta (WU-4, task 2.12).
 *
 * Every frozen marker passes a `caracteristicas` map (definition `codigo` =>
 * effective value for the host entity and the selected tarifa) resolved through
 * the `caracteristicas-producto` resolver with a batched read: never per
 * definition and never persisting.
 *
 * The contract is exercised DB-free through the host controller's overridable
 * resolver/batch-reader seams, and the authored marker calls are asserted
 * against the real view source.
 */
final class CaracteristicaHookContextTest extends TestCase
{
    private const VIEW_DIR = '/plugins/catalogo_core/View';

    /** The four frozen markers that must carry the feature context. */
    private const FROZEN_MARKERS = [
        'ventas_articulo.html.twig' => ['ventas_articulo_tabs_after', 'ventas_articulo_tab_pane_after'],
        'ventas_opcional.html.twig' => ['ventas_opcional_tabs_after', 'ventas_opcional_tab_pane_after'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('FSFramework\\Plugins\\catalogo_core\\Controller\\VentasArticulo', false)) {
            require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php';
        }
    }

    // =====================================================================
    // Context map — batched, keyed by codigo, never persisting
    // =====================================================================

    public function test_context_returns_the_batched_codigo_to_value_map(): void
    {
        $reader = new HookContextFakeBatchReader([
            'REF-1' => ['en_catalogo' => '1', 'en_tarifa' => '0'],
        ]);
        $controller = $this->makeHost($reader);

        $map = $controller->caracteristicas_context('REF-1', 'FAM-1', 'T1');

        $this->assertSame(['en_catalogo' => '1', 'en_tarifa' => '0'], $map);
        $this->assertSame('REF-1', $reader->calls[0]['refs'][0], 'the host referencia must be read');
        $this->assertSame('T1', $reader->calls[0]['codtarifa'], 'the selected tarifa must scope the read');
        $this->assertSame(['REF-1' => 'FAM-1'], $reader->calls[0]['familias'], 'the host familia seeds the family walk');
    }

    public function test_context_reads_batch_only_once_for_the_two_host_markers(): void
    {
        $reader = new HookContextFakeBatchReader([
            'REF-9' => ['en_catalogo' => '0'],
        ]);
        $controller = $this->makeHost($reader);

        $first = $controller->caracteristicas_context('REF-9', null, 'T2');
        $second = $controller->caracteristicas_context('REF-9', null, 'T2');

        $this->assertSame('0', $first['en_catalogo']);
        $this->assertSame($first, $second);
        $this->assertCount(1, $reader->calls, 'the map must be memoized: one batched read per host view');
    }

    public function test_context_without_an_article_identity_is_present_but_empty(): void
    {
        $reader = new HookContextFakeBatchReader([]);
        $controller = $this->makeHost($reader);

        $map = $controller->caracteristicas_context(null, null, 'T1');

        $this->assertSame([], $map, 'a host without an article identity still passes the key');
        $this->assertCount(0, $reader->calls, 'no query may be issued without an article identity');
    }

    // =====================================================================
    // Authored markers
    // =====================================================================

    public function test_every_frozen_marker_passes_the_feature_context(): void
    {
        $declared = 0;
        foreach (self::FROZEN_MARKERS as $view => $markers) {
            $source = $this->markerLines($view);

            foreach ($markers as $marker) {
                $this->assertArrayHasKey($marker, $source, "Marker {$marker} must be declared in {$view}");
                $this->assertMatchesRegularExpression(
                    "/\\{'fsc': fsc, 'user': user, 'empresa': empresa, 'i18n': i18n, 'caracteristicas': fsc\\.caracteristicas_context\\(\\)\\}/",
                    $source[$marker],
                    "Marker {$marker} must pass the feature context after the four frozen keys"
                );
                $declared++;
            }
        }

        $this->assertSame(4, $declared, 'all four frozen markers must carry the feature context');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @return array<string, string> marker name => source line */
    private function markerLines(string $view): array
    {
        $path = FS_FOLDER . self::VIEW_DIR . '/' . $view;
        $this->assertFileExists($path, "missing host view: {$path}");

        $markers = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match_all("/render_hook\\('([^']+)'/", $line, $matches)) {
                foreach ($matches[1] as $name) {
                    $markers[$name] = $line;
                }
            }
        }

        return $markers;
    }

    private function makeHost(HookContextFakeBatchReader $reader): object
    {
        return new class ($reader) extends \FSFramework\Plugins\catalogo_core\Controller\VentasArticulo {
            public function __construct(private HookContextFakeBatchReader $reader)
            {
            }

            protected function caracteristica_resolver()
            {
                return new HookContextFakeResolver(['en_catalogo' => ['id' => 1], 'en_tarifa' => ['id' => 2]]);
            }

            protected function caracteristica_batch_reader()
            {
                return $this->reader;
            }
        };
    }
}

/**
 * Definitions-only resolver double: the context map only needs the codigo keys.
 */
final class HookContextFakeResolver
{
    /** @param array<string, mixed> $definitions */
    public function __construct(private array $definitions)
    {
    }

    /** @return array<string, mixed> */
    public function definitions(bool $onlyActive = true): array
    {
        return $this->definitions;
    }
}

/**
 * Records every batched read so the test can prove one call and the exact key.
 */
final class HookContextFakeBatchReader
{
    /** @var list<array{refs: array<int, string>, codtarifa: string, familias: ?array, codigos: ?array}> */
    public array $calls = [];

    /** @param array<string, array<string, ?string>> $rows */
    public function __construct(private array $rows)
    {
    }

    /**
     * @param array<int, string> $refs
     * @param array<string, string>|null $familias
     * @param array<int, string>|null $codigos
     * @return array<string, array<string, ?string>>
     */
    public function for_referencias(array $refs, string $codtarifa, ?array $familias = null, ?array $codigos = null): array
    {
        $this->calls[] = [
            'refs' => array_values($refs),
            'codtarifa' => $codtarifa,
            'familias' => $familias,
            'codigos' => $codigos,
        ];

        $result = [];
        foreach ($refs as $ref) {
            if (isset($this->rows[$ref])) {
                $result[$ref] = $this->rows[$ref];
            }
        }

        return $result;
    }
}
