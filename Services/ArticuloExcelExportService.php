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

require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaValorBatchReader.php';

/**
 * Builds article Excel exports for catalogo_core.
 *
 * `EXPORT_HEADERS` is byte-identical to the pre-change list; `exportable`
 * feature definitions append additive columns keyed by their `codigo`
 * (CAR-17). With no exportable definitions the emitted workbook is unchanged.
 */
class ArticuloExcelExportService
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
     * @param array<int, array<string, mixed>> $exportable
     * @param array<int, object|array<string, mixed>> $idiomas active languages
     *        (`all_activos()`); a deactivated language is never handed in
     * @param string $codidioma_defecto configured default language code
     */
    public function buildSpreadsheet(
        array $articulos,
        bool $includeExampleRow = false,
        string $codtarifa = '',
        array $exportable = [],
        array $idiomas = [],
        string $codidioma_defecto = ''
    ): Spreadsheet {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Artículos');

        // The export layer owns the `orden` contract (CAR-16/CAR-17 ordering):
        // it must not depend on the order the caller hands the definitions in.
        $exportable = $this->ordered_exportable($exportable);

        // Additive locale columns (D-07): base 7 -> locale pairs -> features.
        $localeHeaders = self::descriptionHeaders($idiomas, $codidioma_defecto);
        $localeCodes = self::orderedCodes($idiomas, $codidioma_defecto);

        $headers = self::EXPORT_HEADERS;
        foreach ($localeHeaders as $localeHeader) {
            $headers[] = $localeHeader;
        }
        foreach ($exportable as $definition) {
            $headers[] = (string) ($definition['nombre'] ?? '');
        }

        foreach ($headers as $col => $header) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . '1';
            $sheet->setCellValue($cell, $header);
        }

        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $featureValues = $exportable === []
            ? []
            : $this->feature_values($this->referencias($articulos), $codtarifa, $exportable);

        $row = 2;
        if ($includeExampleRow && count($articulos) === 0) {
            $example = [
                'referencia' => 'EJEMPLO-001',
                'descripcion' => 'Artículo de ejemplo',
                'pvp' => 10.50,
                'codfamilia' => '',
                'codfabricante' => '',
                'codimpuesto' => '',
                'bloqueado' => false,
            ];
            $this->writeRow($sheet, $row, $example);
            $this->writeLocaleCells($sheet, $row, $example, $localeCodes);
            $this->writeFeatureCells($sheet, $row, (string) $example['referencia'], $exportable, $featureValues, count($localeHeaders));
            $row++;
        }

        foreach ($articulos as $art) {
            $this->writeRow($sheet, $row, $art);
            $this->writeLocaleCells($sheet, $row, $art, $localeCodes);
            $referencia = is_array($art) ? (string) ($art['referencia'] ?? '') : (string) $art->referencia;
            $this->writeFeatureCells($sheet, $row, $referencia, $exportable, $featureValues, count($localeHeaders));
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(50);
        $sheet->getColumnDimension('C')->setWidth(14);

        return $spreadsheet;
    }

    /**
     * `exportable` definitions ordered by `orden` then `codigo` (CAR-17).
     *
     * PHP's sort is stable since 8.0, so a definition list without `orden`
     * keeps its given order.
     *
     * @param array<int, array<string, mixed>> $exportable
     * @return array<int, array<string, mixed>>
     */
    private function ordered_exportable(array $exportable): array
    {
        usort(
            $exportable,
            static function (array $left, array $right): int {
                $byOrden = ((int) ($left['orden'] ?? 0)) <=> ((int) ($right['orden'] ?? 0));

                return $byOrden !== 0
                    ? $byOrden
                    : ((string) ($left['codigo'] ?? '') <=> (string) ($right['codigo'] ?? ''));
            }
        );

        return $exportable;
    }

    /**
     * @param \FSFramework\model\articulo[] $articulos
     * @return list<string>
     */
    private function referencias(array $articulos): array
    {
        $refs = [];
        foreach ($articulos as $art) {
            $referencia = is_array($art) ? (string) ($art['referencia'] ?? '') : (string) $art->referencia;
            if ($referencia !== '') {
                $refs[] = $referencia;
            }
        }

        return $refs;
    }

    /**
     * The additive locale header list (D-07): for every supplied (active)
     * language, one `descripcion_<codidioma>` then one
     * `descripcion_corta_<codidioma>`, ordered default language first, then the
     * remaining languages by `codidioma` (deterministic).
     *
     * @param array<int, object|array<string, mixed>> $idiomas
     * @return list<string>
     */
    public static function descriptionHeaders(array $idiomas, string $codidioma_defecto): array
    {
        $headers = [];
        foreach (self::orderedCodes($idiomas, $codidioma_defecto) as $codigo) {
            $headers[] = 'descripcion_' . $codigo;
            $headers[] = 'descripcion_corta_' . $codigo;
        }

        return $headers;
    }

    /**
     * Normalizes and orders the language codes: the configured default first,
     * then the remaining languages by `codidioma`.
     *
     * @param array<int, object|array<string, mixed>> $idiomas
     * @return list<string>
     */
    private static function orderedCodes(array $idiomas, string $codidioma_defecto): array
    {
        $codes = [];
        foreach ($idiomas as $idioma) {
            $codigo = is_array($idioma)
                ? (string) ($idioma['codidioma'] ?? '')
                : (string) ($idioma->codidioma ?? '');
            if ($codigo !== '') {
                $codes[$codigo] = $codigo;
            }
        }

        ksort($codes, SORT_STRING);
        $codes = array_values($codes);

        if ($codidioma_defecto !== '' && in_array($codidioma_defecto, $codes, true)) {
            $codes = array_values(array_diff($codes, [$codidioma_defecto]));
            array_unshift($codes, $codidioma_defecto);
        }

        return $codes;
    }

    /**
     * Writes the locale pair cells between the base columns and the feature
     * columns. An `articulo` row resolves each language through the language
     * API; an array row (the example row) reads the `descripcion_<cod>` keys.
     *
     * @param \FSFramework\model\articulo|array<string,mixed> $art
     * @param list<string> $localeCodes
     */
    private function writeLocaleCells(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $row,
        $art,
        array $localeCodes
    ): void {
        $offset = count(self::EXPORT_HEADERS);
        $index = 0;
        foreach ($localeCodes as $codigo) {
            if ($art instanceof \FSFramework\model\articulo) {
                $descripcion = (string) $art->get_descripcion_idioma($codigo);
                $descripcionCorta = (string) $art->get_descripcion_corta_idioma($codigo);
            } else {
                $descripcion = (string) ($art['descripcion_' . $codigo] ?? '');
                $descripcionCorta = (string) ($art['descripcion_corta_' . $codigo] ?? '');
            }

            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($offset + $index + 1) . $row;
            $sheet->setCellValue($cell, $descripcion);
            $index++;

            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($offset + $index + 1) . $row;
            $sheet->setCellValue($cell, $descripcionCorta);
            $index++;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $exportable
     * @param array<string, array<string, ?string>> $featureValues
     */
    private function writeFeatureCells(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $row,
        string $referencia,
        array $exportable,
        array $featureValues,
        int $localeColumnCount = 0
    ): void {
        $offset = count(self::EXPORT_HEADERS) + $localeColumnCount;
        foreach ($exportable as $index => $definition) {
            $codigo = (string) ($definition['codigo'] ?? '');
            $value = $featureValues[$referencia][$codigo] ?? null;
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($offset + $index + 1) . $row;
            $sheet->setCellValue($cell, $this->render_feature_cell($definition, $value));
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function render_feature_cell(array $definition, ?string $value): string
    {
        if ($value === null) {
            return '';
        }

        if ((string) ($definition['tipo'] ?? '') === 'bool') {
            return ($value === 't' || $value === '1') ? 'Sí' : 'No';
        }

        return $value;
    }

    /**
     * Resolved feature values keyed by referencia then codigo (CAR-17 seam).
     *
     * @param list<string> $refs
     * @param array<int, array<string, mixed>> $exportable
     * @return array<string, array<string, ?string>>
     */
    protected function feature_values(array $refs, string $codtarifa, array $exportable): array
    {
        if ($refs === []) {
            return [];
        }

        $codigos = [];
        foreach ($exportable as $definition) {
            $codigos[] = (string) ($definition['codigo'] ?? '');
        }

        return (new CaracteristicaValorBatchReader())->for_referencias($refs, $codtarifa, null, $codigos);
    }

    /**
     * @param \FSFramework\model\articulo|array<string,mixed> $art
     */
    private function writeRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, $art): void
    {
        if ($art instanceof \FSFramework\model\articulo) {
            $values = [
                (string) $art->referencia,
                // GDI-10: the base `Descripción` cell resolves the configured
                // default language, never the raw frozen base column.
                (string) $art->get_descripcion_idioma(null),
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
