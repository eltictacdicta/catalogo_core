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
}
