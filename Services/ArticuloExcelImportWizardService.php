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

require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaResolver.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaValorStore.php';

/**
 * Transport-only Excel import wizard for catalogo_core articles.
 *
 * `FIELD_CATALOG` stays byte-identical; `importable` feature definitions are
 * exposed additively through the instance `fieldCatalog()` / `fieldOptions()`
 * surfaces and the `suggestMapping()` extra fields (CAR-17). Feature
 * persistence goes through the value store and never alters the base row.
 */
class ArticuloExcelImportWizardService
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

    /** @var object|null Memoized store shared by every row of one import run. */
    private $valor_store_instance;

    /**
     * @param array<int, array<string, mixed>>|null $importable importable feature definitions;
     *        null loads them lazily on first use.
     */
    public function __construct(private ?array $importable = null)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function importable(): array
    {
        if ($this->importable === null) {
            $this->importable = $this->load_importable_definitions();
        }

        return $this->importable;
    }

    /**
     * The base field catalog plus one entry per importable feature definition.
     *
     * A definition whose `codigo` collides with a base field key is skipped: the
     * base entry stays authoritative, so no feature can shadow the base
     * matching/persistence (CAR-17).
     *
     * @param array<int, array<string, mixed>> $importable
     * @return array<string, array<string, mixed>>
     */
    public function fieldCatalog(array $importable = []): array
    {
        $catalog = self::FIELD_CATALOG;
        foreach ($importable as $definition) {
            if (self::feature_collides_with_base($definition)) {
                continue;
            }
            $codigo = (string) $definition['codigo'];
            $nombre = (string) ($definition['nombre'] ?? $codigo);
            $catalog[$codigo] = [
                'label' => $nombre,
                'column' => $codigo,
                'type' => (string) ($definition['tipo'] ?? 'string'),
                'aliases' => [mb_strtolower($nombre), $codigo],
                'feature' => true,
            ];
        }

        return $catalog;
    }

    /**
     * True when a definition must be skipped because its `codigo` is already a
     * base field key (or is empty). The base field catalog is authoritative on
     * every wizard surface: `fieldCatalog()`, `fieldOptions()`,
     * `extra_field_aliases()` and `persist_feature_values()` (CAR-17).
     *
     * @param array<string, mixed> $definition
     */
    private static function feature_collides_with_base(array $definition): bool
    {
        $codigo = (string) ($definition['codigo'] ?? '');

        return $codigo === '' || array_key_exists($codigo, self::FIELD_CATALOG);
    }

    /**
     * @param string[] $headers
     * @param array<string, array<string, mixed>> $extraFields feature fields keyed by codigo
     * @return array<int,string>
     */
    public static function suggestMapping(array $headers, array $extraFields = []): array
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

            if ($matched === self::IGNORE_SENTINEL) {
                foreach ($extraFields as $fieldName => $info) {
                    $aliases = array_map(
                        static fn ($alias): string => mb_strtolower(trim((string) $alias)),
                        (array) ($info['aliases'] ?? [])
                    );
                    if (in_array($normalized, $aliases, true)) {
                        $matched = (string) $fieldName;
                        break;
                    }
                }
            }

            $result[$colIdx] = $matched;
        }

        return $result;
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    public function fieldOptions(array $importable = []): array
    {
        $options = [
            ['value' => self::IGNORE_SENTINEL, 'label' => 'Ignorar esta columna'],
        ];
        foreach (self::FIELD_CATALOG as $fieldName => $info) {
            $options[] = ['value' => $fieldName, 'label' => $info['label']];
        }
        foreach ($importable as $definition) {
            if (self::feature_collides_with_base($definition)) {
                continue;
            }
            $options[] = [
                'value' => (string) $definition['codigo'],
                'label' => (string) ($definition['nombre'] ?? $definition['codigo']),
            ];
        }

        return $options;
    }

    /**
     * Persists the mapped feature columns for a row (CAR-17). An unmapped or
     * empty feature column is a no-op; a rejected value reports an explicit
     * motivo without dropping the base persistence.
     *
     * @param array<string,string> $mappedRow
     * @return list<string> errors (empty when nothing was rejected)
     */
    public function persist_feature_values(array $mappedRow, string $referencia, string $codtarifa): array
    {
        if ($this->importable() === [] || $referencia === '' || $codtarifa === '') {
            return [];
        }

        $errors = [];
        $store = $this->valor_store();
        $importable = [];
        foreach ($this->importable() as $definition) {
            if (self::feature_collides_with_base($definition)) {
                continue;
            }
            $importable[(string) $definition['codigo']] = $definition;
        }

        foreach ($importable as $codigo => $definition) {
            if ($codigo === '' || !array_key_exists($codigo, $mappedRow)) {
                continue;
            }

            $raw = trim((string) $mappedRow[$codigo]);
            if ($raw === '') {
                continue;
            }

            if ((string) ($definition['tipo'] ?? '') === 'bool') {
                $ok = $store->assign_with_dual_write('articulo', $codtarifa, ['referencia' => $referencia], $codigo, $this->parse_bool($raw));
            } else {
                $ok = $store->assign_custom('articulo', $codtarifa, ['referencia' => $referencia], $codigo, $raw);
            }

            if (!$ok) {
                $errors[] = 'No se pudo guardar la característica ' . $codigo . ' de ' . $referencia . '.';
            }
        }

        return $errors;
    }

    private function parse_bool(string $raw): bool
    {
        $normalized = mb_strtolower($raw);

        return in_array($normalized, ['1', 'sí', 'si', 'true', 'x', 'yes'], true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function load_importable_definitions(): array
    {
        if (!class_exists(CaracteristicaResolver::class)) {
            return [];
        }

        try {
            return (new CaracteristicaResolver())->importable_definitions();
        } catch (\Throwable $e) {
            // Cold DB / missing schema: feature columns are optional, degrade to the base catalog.
            return [];
        }
    }

    /**
     * Memoized value-store accessor: one store instance serves every row of a
     * single import run, so the per-row feature writes reuse the same store
     * instead of rebuilding it (CAR-17).
     *
     * @return object
     */
    protected function valor_store()
    {
        if ($this->valor_store_instance === null) {
            $this->valor_store_instance = $this->create_valor_store();
        }

        return $this->valor_store_instance;
    }

    /**
     * Store factory seam (unit tests inject a DB-free stub).
     *
     * @return object
     */
    protected function create_valor_store()
    {
        return new CaracteristicaValorStore();
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
            'suggested_mapping' => self::suggestMapping($headers, $this->extra_field_aliases()),
            'field_options' => $this->fieldOptions($this->importable()),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function extra_field_aliases(): array
    {
        $extra = [];
        foreach ($this->importable() as $definition) {
            if (self::feature_collides_with_base($definition)) {
                continue;
            }
            $codigo = (string) $definition['codigo'];
            $nombre = (string) ($definition['nombre'] ?? $codigo);
            $extra[$codigo] = ['aliases' => [mb_strtolower($nombre), $codigo]];
        }

        return $extra;
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
