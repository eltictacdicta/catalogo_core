<?php
declare(strict_types=1);

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\ArticuloExcelExportService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArticuloExcelExportService::class)]
final class ArticuloExcelExportServiceTest extends TestCase
{
    public function testExportHeadersOrder(): void
    {
        $this->assertSame(
            ['Referencia', 'Descripción', 'Precio', 'Cód. Familia', 'Cód. Fabricante', 'Impuesto', 'Bloqueado'],
            ArticuloExcelExportService::EXPORT_HEADERS
        );
    }

    public function testBuildSpreadsheetWithExampleRow(): void
    {
        $service = new ArticuloExcelExportService();
        $ss = $service->buildSpreadsheet([], true);
        $sheet = $ss->getActiveSheet();
        $this->assertSame('Referencia', $sheet->getCell('A1')->getValue());
        $this->assertSame('EJEMPLO-001', $sheet->getCell('A2')->getValue());
        $this->assertSame('Artículo de ejemplo', $sheet->getCell('B2')->getValue());
    }

    public function testLocaleColumnsAreAdditiveAndLeaveTheBaseHeadersByteIdentical(): void
    {
        $idiomas = [
            ['codidioma' => 'es', 'nombre' => 'Español'],
            ['codidioma' => 'en', 'nombre' => 'English'],
        ];
        $rows = [[
            'referencia' => 'ART1',
            'descripcion' => 'Base',
            'descripcion_en' => 'Text EN',
            'pvp' => 1.0,
            'codfamilia' => '',
            'codfabricante' => '',
            'codimpuesto' => 'IVA21',
            'bloqueado' => false,
        ]];

        $sheet = (new ArticuloExcelExportService())
            ->buildSpreadsheet($rows, false, '', [], $idiomas, 'es')
            ->getActiveSheet();

        $base = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $column) {
            $base[] = $sheet->getCell($column . '1')->getValue();
        }
        $this->assertSame(ArticuloExcelExportService::EXPORT_HEADERS, $base, 'the base headers stay byte-identical');
        $this->assertSame('descripcion_en', $sheet->getCell('J1')->getValue(), 'the locale columns are additive');
        $this->assertSame('Text EN', $sheet->getCell('J2')->getValue());
    }
}
