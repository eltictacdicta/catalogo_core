<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Services\OpcionalCsvExportService;
use FSFramework\Plugins\catalogo_core\Services\OpcionalCsvFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpcionalCsvExportService::class)]
final class OpcionalCsvExportServiceTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function opcionales(): array
    {
        return [
            1 => ['codigo' => 'aaa111', 'nombre' => 'Parrilla (502X07)', 'idiomas' => ['en_GB' => 'Wire shelf', 'fr_FR' => 'Grille'],
                'es_porcentaje' => false, 'valor' => 41.0, 'imagen' => null],
            2 => ['codigo' => 'bbb222', 'nombre' => '115V/1ph/60Hz', 'idiomas' => [],
                'es_porcentaje' => true, 'valor' => 3.0, 'imagen' => 'kit cajones.jpg'],
            3 => ['codigo' => 'ccc333', 'nombre' => 'Sin coste', 'idiomas' => [],
                'es_porcentaje' => false, 'valor' => 0.0, 'imagen' => null],
        ];
    }

    public function testBuildRowsMapsEveryColumn(): void
    {
        $service = new OpcionalCsvExportService();
        $rows = $service->buildRows([
            ['sku' => 'BMPP1500II', 'id_opcional' => 1, 'cantidad_max' => 6],
            ['sku' => 'BMPP1500II', 'id_opcional' => 2, 'cantidad_max' => 1],
            ['sku' => 'BMPP1500II', 'id_opcional' => 3, 'cantidad_max' => 2],
        ], $this->opcionales(), 'https://x.test/');

        $this->assertSame([
            'sku_producto' => 'BMPP1500II', 'codigo_opcional' => '502X07',
            'nombre_es' => 'Parrilla', 'nombre_en' => 'Wire shelf', 'nombre_fr' => 'Grille',
            'tipo_precio' => 'fixed', 'precio' => '41.0', 'maximo_por_mueble' => '6', 'imagen' => '',
        ], $rows[0]);

        $this->assertSame('bbb222', $rows[1]['codigo_opcional']);
        $this->assertSame('percent', $rows[1]['tipo_precio']);
        $this->assertSame('3.0', $rows[1]['precio']);
        $this->assertSame('https://x.test/imgs/opcionales/kit%20cajones.jpg', $rows[1]['imagen']);

        $this->assertSame('free', $rows[2]['tipo_precio']);
        $this->assertSame('0', $rows[2]['precio']);
        $this->assertSame('2', $rows[2]['maximo_por_mueble']);
    }

    public function testCollidingExtractedCodesFallBackToRealCode(): void
    {
        $service = new OpcionalCsvExportService();
        $codes = $service->resolveCodigos([
            5 => ['codigo' => 'real5', 'nombre' => 'B (X1)'],
            2 => ['codigo' => 'real2', 'nombre' => 'A (X1)'],
        ]);

        $this->assertSame('X1', $codes[2], 'el de menor id conserva el código extraído');
        $this->assertSame('real5', $codes[5]);
    }

    public function testWriteCsvUsesTheReferenceLayoutAndNeutralisesFormulas(): void
    {
        $service = new OpcionalCsvExportService();
        $stream = fopen('php://memory', 'w+');
        $service->writeCsv($stream, [[
            'sku_producto' => 'A', 'codigo_opcional' => 'X', 'nombre_es' => '=1+1', 'nombre_en' => 'a,b',
            'nombre_fr' => 'c', 'tipo_precio' => 'fixed', 'precio' => '1.0', 'maximo_por_mueble' => '1', 'imagen' => '',
        ]]);
        rewind($stream);
        $csv = stream_get_contents($stream);

        $this->assertStringStartsWith(implode(',', OpcionalCsvFormat::COLUMNS) . "\n", $csv);
        $this->assertStringContainsString("A,X,'=1+1,\"a,b\",c,fixed,1.0,1,\n", $csv);
    }

    public function testExportPipelineUsesSeams(): void
    {
        $service = new class extends OpcionalCsvExportService {
            protected function loadAssignments(string $codtarifa): array
            {
                return [['sku' => 'S1', 'id_opcional' => 1, 'cantidad_max' => 4]];
            }

            protected function loadOpcionales(string $codtarifa, array $ids): array
            {
                return [1 => ['codigo' => 'c1', 'nombre' => 'Uno', 'idiomas' => [], 'es_porcentaje' => false, 'valor' => 5.5, 'imagen' => null]];
            }
        };

        $stream = fopen('php://memory', 'w+');
        $this->assertSame(1, $service->export('T1', $stream));
        rewind($stream);
        $this->assertStringContainsString("S1,c1,Uno,Uno,Uno,fixed,5.5,4,\n", (string) stream_get_contents($stream));
    }
}
