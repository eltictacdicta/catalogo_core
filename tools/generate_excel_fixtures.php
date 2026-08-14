<?php
declare(strict_types=1);

/**
 * ONE-SHOT fixture generator for catalogo_core Excel wizard tests.
 * Run: ddev exec php plugins/catalogo_core/tools/generate_excel_fixtures.php
 */

define('FS_FOLDER', dirname(__DIR__, 3));

require_once FS_FOLDER . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$fixtureDir = FS_FOLDER . '/plugins/catalogo_core/tests/fixtures/excel-wizard';
if (!is_dir($fixtureDir)) {
    mkdir($fixtureDir, 0755, true);
}

$headers = ['Referencia', 'Descripción', 'Precio'];

function writeFixture(string $path, array $headers, array $dataRows): void
{
    $ss = new Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Artículos');
    foreach ($headers as $i => $h) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValue($col . '1', $h);
    }
    foreach ($dataRows as $r => $row) {
        foreach ($row as $c => $val) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1);
            $sheet->setCellValue($col . ($r + 2), $val);
        }
    }
    (new Xlsx($ss))->save($path);
    echo "Wrote: {$path}\n";
}

writeFixture($fixtureDir . '/basic-3col.xlsx', $headers, [
    ['ART-001', 'Producto uno', '10,50'],
    ['ART-002', 'Producto dos', '25'],
]);

writeFixture($fixtureDir . '/empty.xlsx', $headers, []);

echo "Done.\n";
