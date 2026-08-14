<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds article Excel exports for catalogo_core.
 */
final class ArticuloExcelExportService
{
    /** @var string[] */
    public const EXPORT_HEADERS = [
        'Referencia',
        'Descripción',
        'Precio',
        'Cód. Familia',
        'Cód. Fabricante',
        'Impuesto',
        'Bloqueado',
    ];

    /**
     * @param \FSFramework\model\articulo[] $articulos
     */
    public function buildSpreadsheet(array $articulos, bool $includeExampleRow = false): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Artículos');

        foreach (self::EXPORT_HEADERS as $col => $header) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . '1';
            $sheet->setCellValue($cell, $header);
        }

        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count(self::EXPORT_HEADERS));
        $sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $row = 2;
        if ($includeExampleRow && count($articulos) === 0) {
            $this->writeRow($sheet, $row, [
                'referencia' => 'EJEMPLO-001',
                'descripcion' => 'Artículo de ejemplo',
                'pvp' => 10.50,
                'codfamilia' => '',
                'codfabricante' => '',
                'codimpuesto' => '',
                'bloqueado' => false,
            ]);
            $row++;
        }

        foreach ($articulos as $art) {
            $this->writeRow($sheet, $row, $art);
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(50);
        $sheet->getColumnDimension('C')->setWidth(14);

        return $spreadsheet;
    }

    /**
     * @param \FSFramework\model\articulo|array<string,mixed> $art
     */
    private function writeRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, $art): void
    {
        if ($art instanceof \FSFramework\model\articulo) {
            $values = [
                (string) $art->referencia,
                (string) $art->descripcion,
                (float) $art->pvp,
                (string) ($art->codfamilia ?? ''),
                (string) ($art->codfabricante ?? ''),
                (string) ($art->codimpuesto ?? ''),
                $art->bloqueado ? 'Sí' : 'No',
            ];
        } else {
            $values = [
                (string) ($art['referencia'] ?? ''),
                (string) ($art['descripcion'] ?? ''),
                (float) ($art['pvp'] ?? 0),
                (string) ($art['codfamilia'] ?? ''),
                (string) ($art['codfabricante'] ?? ''),
                (string) ($art['codimpuesto'] ?? ''),
                !empty($art['bloqueado']) ? 'Sí' : 'No',
            ];
        }

        foreach ($values as $col => $value) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . $row;
            $sheet->setCellValue($cell, $value);
        }
    }

    public function sendDownload(Spreadsheet $spreadsheet, string $filename): void
    {
        @ini_set('display_errors', '0');
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
    }
}
