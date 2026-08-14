<?php
declare(strict_types=1);

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\ArticuloExcelImportWizardService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArticuloExcelImportWizardService::class)]
final class ArticuloExcelImportWizardServiceTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../fixtures/excel-wizard';

    public function testSuggestMappingMapsStandardHeaders(): void
    {
        $headers = ['Referencia', 'Descripción', 'Precio'];
        $mapping = ArticuloExcelImportWizardService::suggestMapping($headers);

        $this->assertSame('referencia', $mapping[0]);
        $this->assertSame('descripcion', $mapping[1]);
        $this->assertSame('pvp', $mapping[2]);
    }

    public function testSuggestMappingUnknownHeaderIsIgnore(): void
    {
        $mapping = ArticuloExcelImportWizardService::suggestMapping(['Columna rara']);
        $this->assertSame(ArticuloExcelImportWizardService::IGNORE_SENTINEL, $mapping[0]);
    }

    public function testFieldOptionsStartsWithIgnore(): void
    {
        $service = new ArticuloExcelImportWizardService();
        $options = $service->fieldOptions();
        $this->assertSame(ArticuloExcelImportWizardService::IGNORE_SENTINEL, $options[0]['value']);
    }

    public function testPreviewReadsFixture(): void
    {
        $path = self::FIXTURE_DIR . '/basic-3col.xlsx';
        if (!is_file($path)) {
            $this->markTestSkipped('Fixture missing — run tools/generate_excel_fixtures.php');
        }

        $service = new ArticuloExcelImportWizardService();
        $preview = $service->preview($path, 'Artículos', 5);

        $this->assertSame(['Referencia', 'Descripción', 'Precio'], $preview['headers']);
        $this->assertCount(2, $preview['rows']);
        $this->assertSame('referencia', $preview['suggested_mapping'][0]);
    }

    public function testApplyInvokesRowHook(): void
    {
        $path = self::FIXTURE_DIR . '/basic-3col.xlsx';
        if (!is_file($path)) {
            $this->markTestSkipped('Fixture missing — run tools/generate_excel_fixtures.php');
        }

        $service = new ArticuloExcelImportWizardService();
        $seen = [];
        $mapping = ArticuloExcelImportWizardService::suggestMapping(['Referencia', 'Descripción', 'Precio']);

        $result = $service->apply(
            $path,
            'Artículos',
            $mapping,
            static function (array $row, int $idx) use (&$seen): void {
                $seen[] = ['row' => $row, 'idx' => $idx];
            },
            static function (): void {
            }
        );

        $this->assertSame(2, $result['processed']);
        $this->assertSame('ART-001', $seen[0]['row']['referencia']);
        $this->assertSame('10,50', $seen[0]['row']['pvp']);
    }

    public function testCreateArticuloFromRowUsesDefaultTaxWhenMissing(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';

        $art = ArticuloExcelImportWizardService::createArticuloFromRow(
            ['descripcion' => 'Producto test'],
            false,
            'IVA21'
        );

        $this->assertSame('IVA21', $art->codimpuesto);
    }

    public function testCreateArticuloFromRowPrefersMappedTaxOverDefault(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';

        $art = ArticuloExcelImportWizardService::createArticuloFromRow(
            ['descripcion' => 'Producto test', 'codimpuesto' => 'IVA10'],
            false,
            'IVA21'
        );

        $this->assertSame('IVA10', $art->codimpuesto);
    }
}
