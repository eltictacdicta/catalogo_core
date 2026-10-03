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
 */
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Read-only Excel export for opcionales (OVE-05 / AD-10).
 *
 * Columns carry every new field: `cantidad_min`/`cantidad_max`, `imagen`, the
 * per-language `nombre_<codidioma>`/`descripcion_<codidioma>` pairs (default
 * language first) plus group memberships, family assignments and per-lista
 * prices. The base header block mirrors the article export, and the locale
 * block mirrors `ArticuloExcelExportService::descriptionHeaders()` ordering.
 *
 * The service only reads: resolving a row never issues a write. It reuses the
 * opcional domain models when it is handed model objects.
 */
class OpcionalExcelExportService
{
    /** @var string[] Base columns; locatable, group, family and price columns follow. */
    public const EXPORT_HEADERS = [
        'Código',
        'Nombre',
        'Descripción',
        'Precio',
        'Tipo Precio',
        'Porcentaje',
        'Cantidad Mín',
        'Cantidad Máx',
        'Imagen',
    ];

    /** @var string[] Columns appended after the base block and before the locale block. */
    public const RELATION_HEADERS = ['Grupos', 'Familias'];

    /**
     * @param array<int, object|array<string, mixed>> $idiomas active languages
     *        (`all_activos()`); a deactivated language is never handed in
     * @param string $codidioma_defecto configured default language code
     * @param list<string> $codlistas price-list codes to emit (one `Precio <cod>` column each)
     * @return list<string>
     */
    public function headers(array $idiomas, string $codidioma_defecto = '', array $codlistas = []): array
    {
        $headers = self::EXPORT_HEADERS;
        foreach (self::RELATION_HEADERS as $header) {
            $headers[] = $header;
        }
        foreach (self::ordered_codlistas($codlistas) as $codlista) {
            $headers[] = 'Precio ' . $codlista;
        }
        foreach (self::ordered_codes($idiomas, $codidioma_defecto) as $codigo) {
            $headers[] = 'nombre_' . $codigo;
            $headers[] = 'descripcion_' . $codigo;
        }

        return $headers;
    }

    /**
     * @param array<int, object|array<string, mixed>> $opcionales
     * @param array<int, object|array<string, mixed>> $idiomas active languages
     * @param list<string> $codlistas price-list codes to emit
     */
    public function buildSpreadsheet(
        array $opcionales,
        bool $includeExampleRow = false,
        array $idiomas = [],
        string $codidioma_defecto = '',
        array $codlistas = []
    ): Spreadsheet {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Opcionales');

        $codlistas = self::ordered_codlistas($codlistas);
        $localeCodes = self::ordered_codes($idiomas, $codidioma_defecto);
        $headers = $this->headers($idiomas, $codidioma_defecto, $codlistas);

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

        $row = 2;
        if ($includeExampleRow && count($opcionales) === 0) {
            $example = [
                'codigo' => 'EJEMPLO-001',
                'nombre' => 'Opcional de ejemplo',
                'descripcion' => '',
                'precio' => 1.0,
                'tipo_precio' => 'fijo',
                'porcentaje' => '',
                'cantidad_min' => 1,
                'cantidad_max' => 1,
                'imagen' => '',
                'grupos' => [],
                'familias' => [],
            ];
            $this->writeRow($sheet, $row, $example, $localeCodes, $codlistas);
            $row++;
        }

        foreach ($opcionales as $opcional) {
            $this->writeRow($sheet, $row, $this->row_from_opcional($opcional, $codlistas), $localeCodes, $codlistas);
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(50);

        return $spreadsheet;
    }

    /**
     * Normalizes one opcional (model object or plain row array) into the export
     * row shape. Read-only: it never calls a write method.
     *
     * @param object|array<string, mixed> $opcional
     * @param list<string> $codlistas
     * @return array<string, mixed>
     */
    public function row_from_opcional($opcional, array $codlistas = []): array
    {
        if (is_array($opcional)) {
            return $this->normalize_array_row($opcional, $codlistas);
        }

        $codigo = (string) ($opcional->codigo ?? '');

        $row = [
            'codigo' => $codigo,
            'nombre' => (string) ($opcional->nombre ?? ''),
            'descripcion' => (string) ($opcional->descripcion ?? ''),
            'precio' => (float) ($opcional->precio ?? 0),
            'tipo_precio' => (string) ($opcional->tipo_precio ?? 'fijo'),
            'porcentaje' => $opcional->porcentaje ?? '',
            'cantidad_min' => (int) ($opcional->cantidad_min ?? 1),
            'cantidad_max' => (int) ($opcional->cantidad_max ?? 1),
            'imagen' => (string) ($opcional->imagen ?? ''),
            'grupos' => $this->read_grupos($opcional),
            'familias' => $this->read_familias($opcional),
        ];

        foreach ($this->idioma_codes_of($opcional) as $codidioma) {
            $row['nombre_' . $codidioma] = (string) $opcional->get_nombre_idioma($codidioma);
            $row['descripcion_' . $codidioma] = (string) $opcional->get_descripcion_idioma($codidioma);
        }

        foreach ($codlistas as $codlista) {
            $row['precio_' . $codlista] = $this->read_precio_lista($opcional, $codlista);
        }

        return $row;
    }

    /**
     * Language codes materialised on the opcional (model objects expose
     * `get_idiomas()`); plain rows carry no row metadata, so the caller must
     * pass their locale keys directly through the array path.
     *
     * @param object $opcional
     * @return list<string>
     */
    private function idioma_codes_of($opcional): array
    {
        if (!method_exists($opcional, 'get_idiomas')) {
            return [];
        }

        $codes = [];
        foreach ((array) $opcional->get_idiomas() as $row) {
            $codigo = is_object($row)
                ? (string) ($row->codidioma ?? '')
                : (string) ($row['codidioma'] ?? '');
            if ($codigo !== '') {
                $codes[$codigo] = $codigo;
            }
        }

        return array_values($codes);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $codlistas
     * @return array<string, mixed>
     */
    private function normalize_array_row(array $row, array $codlistas): array
    {
        $normalized = [
            'codigo' => (string) ($row['codigo'] ?? ''),
            'nombre' => (string) ($row['nombre'] ?? ''),
            'descripcion' => (string) ($row['descripcion'] ?? ''),
            'precio' => (float) ($row['precio'] ?? 0),
            'tipo_precio' => (string) ($row['tipo_precio'] ?? 'fijo'),
            'porcentaje' => $row['porcentaje'] ?? '',
            'cantidad_min' => (int) ($row['cantidad_min'] ?? 1),
            'cantidad_max' => (int) ($row['cantidad_max'] ?? 1),
            'imagen' => (string) ($row['imagen'] ?? ''),
            'grupos' => $this->to_list($row['grupos'] ?? $row['grupos_labels'] ?? []),
            'familias' => $this->to_list($row['familias'] ?? []),
        ];

        foreach ($codlistas as $codlista) {
            $normalized['precio_' . $codlista] = (float) ($row['precio_' . $codlista] ?? 0);
        }

        // Preserve the caller-supplied locale pair columns verbatim.
        foreach ($row as $key => $value) {
            if (preg_match('/^(nombre|descripcion)_(.+)$/', (string) $key)) {
                $normalized[(string) $key] = (string) $value;
            }
        }

        return $normalized;
    }

    /**
     * @param object $opcional
     * @return list<string>
     */
    private function read_grupos($opcional): array
    {
        if (method_exists($opcional, 'grupos_labels')) {
            return $this->to_list($opcional->grupos_labels());
        }

        if (method_exists($opcional, 'get_grupos')) {
            return $this->to_list($opcional->get_grupos());
        }

        return [];
    }

    /**
     * @param object $opcional
     * @return list<string>
     */
    private function read_familias($opcional): array
    {
        if (!method_exists($opcional, 'get_familias')) {
            return [];
        }

        $list = [];
        foreach ((array) $opcional->get_familias() as $familia) {
            $codigo = is_object($familia)
                ? (string) ($familia->codfamilia ?? '')
                : (string) $familia;
            if ($codigo !== '') {
                $list[] = $codigo;
            }
        }

        return $list;
    }

    /**
     * @param object $opcional
     */
    private function read_precio_lista($opcional, string $codlista): float
    {
        if ($codlista === '') {
            return 0.0;
        }

        if (!method_exists($opcional, 'precio_en_lista')) {
            return (float) ($opcional->precio ?? 0);
        }

        return (float) $opcional->precio_en_lista($codlista);
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function to_list($value): array
    {
        $list = [];
        foreach ((array) $value as $item) {
            if (is_object($item)) {
                $item = $item->nombre ?? $item->codigo ?? $item->codfamilia ?? '';
            }
            $item = trim((string) $item);
            if ($item !== '') {
                $list[] = $item;
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * Normalizes and orders price-list codes (deterministic, `ksort`).
     *
     * @param list<string> $codlistas
     * @return list<string>
     */
    private static function ordered_codlistas(array $codlistas): array
    {
        $codes = [];
        foreach ($codlistas as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code !== '') {
                $codes[$code] = $code;
            }
        }
        ksort($codes, SORT_STRING);

        return array_values($codes);
    }

    /**
     * Normalizes and orders the language codes: the configured default first,
     * then the remaining languages by `codidioma` (mirrors
     * `ArticuloExcelExportService::orderedCodes()`).
     *
     * @param array<int, object|array<string, mixed>> $idiomas
     * @return list<string>
     */
    private static function ordered_codes(array $idiomas, string $codidioma_defecto): array
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
     * @param array<string, mixed> $row
     * @param list<string> $localeCodes
     * @param list<string> $codlistas
     */
    private function writeRow(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $row,
        array $data,
        array $localeCodes,
        array $codlistas
    ): void {
        $values = [
            (string) $data['codigo'],
            (string) $data['nombre'],
            (string) $data['descripcion'],
            (float) $data['precio'],
            (string) $data['tipo_precio'],
            $data['porcentaje'] === '' || $data['porcentaje'] === null ? '' : (float) $data['porcentaje'],
            (int) $data['cantidad_min'],
            (int) $data['cantidad_max'],
            (string) $data['imagen'],
            implode(', ', (array) $data['grupos']),
            implode(', ', (array) $data['familias']),
        ];

        foreach ($codlistas as $codlista) {
            $values[] = (float) ($data['precio_' . $codlista] ?? 0);
        }

        foreach ($localeCodes as $codigo) {
            $values[] = (string) ($data['nombre_' . $codigo] ?? '');
            $values[] = (string) ($data['descripcion_' . $codigo] ?? '');
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
