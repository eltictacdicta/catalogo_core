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

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Transport-only Excel import wizard for catalogo_core articles.
 */
final class ArticuloExcelImportWizardService
{
    public const IGNORE_SENTINEL = '__ignorar__';

    public const GRANULARITY_THRESHOLD = 10000;

    public const GRANULARITY_LARGE_STEP = 1000;

    /** @var string[] */
    public const CACHE_KEYS_TO_INVALIDATE = ['articulo_search'];

    public const DEFAULT_CODIMPUESTO = 'IVA21';

    public const FIELD_CATALOG = [
        'referencia' => [
            'label' => 'Referencia',
            'column' => 'referencia',
            'type' => 'string(18)',
            'req_create' => false,
            'aliases' => ['referencia', 'ref', 'codigo', 'código', 'codigo (no editar)'],
        ],
        'descripcion' => [
            'label' => 'Descripción',
            'column' => 'descripcion',
            'type' => 'text',
            'req_create' => true,
            'aliases' => ['descripción', 'descripcion', 'desc', 'description'],
        ],
        'pvp' => [
            'label' => 'Precio',
            'column' => 'pvp',
            'type' => 'double',
            'req_create' => false,
            'aliases' => ['precio', 'pvp', 'price'],
        ],
        'codfamilia' => [
            'label' => 'Cód. Familia',
            'column' => 'codfamilia',
            'type' => 'string(8)',
            'req_create' => false,
            'aliases' => ['cód. familia', 'cod. familia', 'cod familia', 'codfamilia', 'familia codigo'],
        ],
        'codfabricante' => [
            'label' => 'Cód. Fabricante',
            'column' => 'codfabricante',
            'type' => 'string(8)',
            'req_create' => false,
            'aliases' => ['cód. fabricante', 'cod. fabricante', 'cod fabricante', 'codfabricante', 'fabricante'],
        ],
        'codimpuesto' => [
            'label' => 'Impuesto',
            'column' => 'codimpuesto',
            'type' => 'string(10)',
            'req_create' => false,
            'aliases' => ['impuesto', 'codimpuesto', 'iva', 'tax'],
        ],
        'bloqueado' => [
            'label' => 'Bloqueado',
            'column' => 'bloqueado',
            'type' => 'bool',
            'req_create' => false,
            'aliases' => ['bloqueado', 'blocked', 'obsoleto'],
        ],
    ];

    /**
     * @param string[] $headers
     * @return array<int,string>
     */
    public static function suggestMapping(array $headers): array
    {
        $result = [];
        foreach ($headers as $colIdx => $header) {
            $normalized = mb_strtolower(trim((string) $header));
            $matched = self::IGNORE_SENTINEL;
            foreach (self::FIELD_CATALOG as $fieldName => $info) {
                if (in_array($normalized, $info['aliases'], true)) {
                    $matched = $fieldName;
                    break;
                }
            }
            $result[$colIdx] = $matched;
        }

        return $result;
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    public function fieldOptions(): array
    {
        $options = [
            ['value' => self::IGNORE_SENTINEL, 'label' => 'Ignorar esta columna'],
        ];
        foreach (self::FIELD_CATALOG as $fieldName => $info) {
            $options[] = ['value' => $fieldName, 'label' => $info['label']];
        }

        return $options;
    }

    /**
     * @return string[]
     */
    public function extractHeaders(string $filePath, string $sheetName): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (!$sheet) {
            throw new \RuntimeException("Sheet '{$sheetName}' not found in '{$filePath}'");
        }

        return $this->rowToStrings($sheet, 1);
    }

    /**
     * @return array{headers:string[],rows:array<int,array<int,string>>,suggested_mapping:array<int,string>,field_options:array<int,array{value:string,label:string}>}
     */
    public function preview(string $filePath, string $sheetName, int $maxRows = 10): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (!$sheet) {
            throw new \RuntimeException("Sheet '{$sheetName}' not found in '{$filePath}'");
        }

        $headers = $this->rowToStrings($sheet, 1);
        $highestRow = (int) $sheet->getHighestRow();
        $rows = [];
        $rowsToRead = min($maxRows, max(0, $highestRow - 1));
        for ($r = 2; $r <= 1 + $rowsToRead; $r++) {
            $rows[] = $this->rowToStrings($sheet, $r);
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'suggested_mapping' => self::suggestMapping($headers),
            'field_options' => $this->fieldOptions(),
        ];
    }

    /**
     * @param array<int,string> $mapping
     * @param callable(array<string,string>,int):void $rowHook
     * @param callable(string,string,int):void $progressCallback
     * @return array{processed:int,skipped:int,error:?string}
     */
    public function apply(
        string $filePath,
        string $sheetName,
        array $mapping,
        callable $rowHook,
        callable $progressCallback
    ): array {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (!$sheet) {
            throw new \RuntimeException("Sheet '{$sheetName}' not found in '{$filePath}'");
        }

        $headers = $this->rowToStrings($sheet, 1);
        $highestRow = (int) $sheet->getHighestRow();
        $totalDataRows = max(0, $highestRow - 1);
        $useCoarseGranularity = $totalDataRows >= self::GRANULARITY_THRESHOLD;

        $processed = 0;
        $skipped = 0;

        for ($r = 2; $r <= $highestRow; $r++) {
            $rawRow = $this->rowToStrings($sheet, $r);
            $mappedRow = $this->applyMapping($rawRow, $headers, $mapping);

            if ($this->isEmptyMappedRow($mappedRow)) {
                $skipped++;
            } else {
                $rowHook($mappedRow, $r);
                $processed++;
            }

            $emitProgress = !$useCoarseGranularity
                || ($processed % self::GRANULARITY_LARGE_STEP === 0)
                || $r === $highestRow;
            if ($emitProgress) {
                $percent = $totalDataRows > 0
                    ? (int) min(100, round(($r - 1) / $totalDataRows * 100))
                    : 100;
                $progressCallback('apply', "Row {$r} of {$highestRow}", $percent);
            }
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'error' => null,
        ];
    }

    /**
     * @param array<string,string> $row
     */
    public static function createArticuloFromRow(
        array $row,
        bool $roundPrice = false,
        string $defaultCodimpuesto = self::DEFAULT_CODIMPUESTO
    ): \FSFramework\model\articulo {
        $art = new \FSFramework\model\articulo();
        $ref = trim((string) ($row['referencia'] ?? ''));
        $art->referencia = $ref !== '' ? $ref : $art->get_new_referencia();
        $art->descripcion = trim((string) ($row['descripcion'] ?? ''));

        if (isset($row['pvp']) && $row['pvp'] !== '') {
            if ($roundPrice) {
                $art->set_pvp(ArticuloExcelRowUpdater::normalizePrice((string) $row['pvp'], true));
            } else {
                $art->pvp = ArticuloExcelRowUpdater::parsePrice((string) $row['pvp']);
                $art->factualizado = date('d-m-Y');
            }
        }

        if (!empty($row['codfamilia'])) {
            $art->codfamilia = trim((string) $row['codfamilia']);
        }
        if (!empty($row['codfabricante'])) {
            $art->codfabricante = trim((string) $row['codfabricante']);
        }
        if (!empty($row['codimpuesto'])) {
            $art->codimpuesto = trim((string) $row['codimpuesto']);
        } elseif (trim($defaultCodimpuesto) !== '') {
            $art->codimpuesto = trim($defaultCodimpuesto);
        }
        if (isset($row['bloqueado']) && $row['bloqueado'] !== '') {
            $art->bloqueado = ArticuloExcelRowUpdater::parseBool((string) $row['bloqueado']);
            if ($art->bloqueado) {
                $art->publico = false;
            }
        }

        return $art;
    }

    /**
     * @return string[]
     */
    private function rowToStrings(Worksheet $sheet, int $row): array
    {
        $highestCol = $sheet->getHighestColumn();
        $highestColIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
        $cells = [];
        for ($c = 1; $c <= $highestColIdx; $c++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $value = $sheet->getCell($colLetter . $row)->getValue();
            $cells[] = $value === null ? '' : trim((string) $value);
        }

        return $cells;
    }

    /**
     * @param string[] $rawRow
     * @param string[] $headers
     * @param array<int,string> $mapping
     * @return array<string,string>
     */
    private function applyMapping(array $rawRow, array $headers, array $mapping): array
    {
        $result = [];
        foreach ($headers as $colIdx => $header) {
            $fieldName = $mapping[$colIdx] ?? self::IGNORE_SENTINEL;
            if ($fieldName === self::IGNORE_SENTINEL) {
                continue;
            }
            $value = $rawRow[$colIdx] ?? '';
            if ($value === '') {
                continue;
            }
            $result[$fieldName] = $value;
        }

        return $result;
    }

    /**
     * @param array<string,string> $mappedRow
     */
    private function isEmptyMappedRow(array $mappedRow): bool
    {
        return count($mappedRow) === 0;
    }
}
