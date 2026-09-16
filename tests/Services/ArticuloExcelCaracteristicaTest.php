<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\ArticuloExcelExportService;
use FSFramework\Plugins\catalogo_core\Services\ArticuloExcelImportWizardService;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/base/fs_core_log.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/ArticuloExcelRowUpdater.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/ArticuloExcelExportService.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/ArticuloExcelImportWizardService.php';

/**
 * Contract tests for the dynamic feature columns (CAR-17).
 *
 * Export: `EXPORT_HEADERS` stays byte-identical; only `exportable`
 * definitions append a column. Import: the field catalog/mapping exposes
 * `importable` fields additively, and a workbook without them imports
 * unchanged.
 */
final class ArticuloExcelCaracteristicaTest extends TestCase
{
    private function exportService(): ArticuloExcelExportService
    {
        return new class() extends ArticuloExcelExportService {
            protected function feature_values(array $refs, string $codtarifa, array $exportable): array
            {
                return ['REF-1' => ['medidas' => 'XL', 'en_catalogo' => '1']];
            }
        };
    }

    public function test_export_appends_feature_columns_only(): void
    {
        $articulos = [[
            'referencia' => 'REF-1',
            'descripcion' => 'Artículo',
            'pvp' => 10.0,
            'codfamilia' => 'F1',
            'codfabricante' => '',
            'codimpuesto' => 'IVA21',
            'bloqueado' => false,
        ]];

        $plain = $this->exportService()->buildSpreadsheet($articulos);
        $this->assertSame(
            ArticuloExcelExportService::EXPORT_HEADERS,
            [
                $plain->getActiveSheet()->getCell('A1')->getValue(),
                $plain->getActiveSheet()->getCell('B1')->getValue(),
                $plain->getActiveSheet()->getCell('C1')->getValue(),
                $plain->getActiveSheet()->getCell('D1')->getValue(),
                $plain->getActiveSheet()->getCell('E1')->getValue(),
                $plain->getActiveSheet()->getCell('F1')->getValue(),
                $plain->getActiveSheet()->getCell('G1')->getValue(),
            ],
            'base headers must stay byte-identical'
        );
        $this->assertSame('G', $plain->getActiveSheet()->getHighestColumn(), 'no feature column without exportable defs');

        $exportable = [
            ['codigo' => 'medidas', 'nombre' => 'Medidas', 'tipo' => 'string'],
        ];
        $withFeature = $this->exportService()->buildSpreadsheet($articulos, false, 'T1', $exportable);

        $sheet = $withFeature->getActiveSheet();
        $this->assertSame('Medidas', $sheet->getCell('H1')->getValue(), 'the exportable column is appended');
        $this->assertSame('XL', $sheet->getCell('H2')->getValue());
        $this->assertSame('H', $sheet->getHighestColumn());
    }

    public function test_export_orders_feature_columns_by_orden(): void
    {
        $articulos = [[
            'referencia' => 'REF-1',
            'descripcion' => 'Artículo',
            'pvp' => 10.0,
            'codfamilia' => 'F1',
            'codfabricante' => '',
            'codimpuesto' => 'IVA21',
            'bloqueado' => false,
        ]];

        // Supplied second-then-first: the export layer owns the `orden`
        // contract, it must not depend on the caller's array order.
        $exportable = [
            ['codigo' => 'en_catalogo', 'nombre' => 'En Catálogo', 'tipo' => 'bool', 'orden' => 20],
            ['codigo' => 'medidas', 'nombre' => 'Medidas', 'tipo' => 'string', 'orden' => 10],
        ];

        $sheet = $this->exportService()
            ->buildSpreadsheet($articulos, false, 'T1', $exportable)
            ->getActiveSheet();

        $this->assertSame('Medidas', $sheet->getCell('H1')->getValue(), 'orden 10 comes first');
        $this->assertSame('XL', $sheet->getCell('H2')->getValue());
        $this->assertSame('En Catálogo', $sheet->getCell('I1')->getValue(), 'orden 20 comes second');
        $this->assertSame('Sí', $sheet->getCell('I2')->getValue(), 'the bool rendering follows the sorted column');
        $this->assertSame('I', $sheet->getHighestColumn());
    }

    public function test_import_field_catalog_is_additive_and_byte_identical_base(): void
    {
        $service = new ArticuloExcelImportWizardService([]);

        $this->assertSame(
            ArrayKeys::of(ArticuloExcelImportWizardService::FIELD_CATALOG),
            ArrayKeys::of($service->fieldCatalog([])),
            'the base field catalog must stay byte-identical without importable defs'
        );

        $definitions = [
            ['codigo' => 'en_catalogo', 'nombre' => 'En Catálogo', 'tipo' => 'bool'],
            ['codigo' => 'medidas', 'nombre' => 'Medidas', 'tipo' => 'string'],
        ];
        $catalog = $service->fieldCatalog($definitions);

        $this->assertArrayHasKey('en_catalogo', $catalog);
        $this->assertArrayHasKey('medidas', $catalog);
        $this->assertTrue($catalog['en_catalogo']['feature']);
        $this->assertSame('en_catalogo', $catalog['en_catalogo']['column']);
        $this->assertSame('En Catálogo', $catalog['en_catalogo']['label']);
        $this->assertContains('en catálogo', $catalog['en_catalogo']['aliases']);

        $options = $service->fieldOptions($definitions);
        $this->assertContains('__ignorar__', array_column($options, 'value'));
        $this->assertContains('en_catalogo', array_column($options, 'value'));
        $this->assertContains('medidas', array_column($options, 'value'));

        $mapping = ArticuloExcelImportWizardService::suggestMapping(
            ['Referencia', 'En Catálogo', 'Medidas'],
            ['en_catalogo' => ['aliases' => ['en catálogo', 'en_catalogo']]]
        );
        $this->assertSame('referencia', $mapping[0]);
        $this->assertSame('en_catalogo', $mapping[1], 'a feature header maps to its codigo');
    }

    public function test_a_feature_definition_cannot_shadow_a_base_field_key(): void
    {
        $definitions = [
            ['codigo' => 'pvp', 'nombre' => 'Precio de tarifa', 'tipo' => 'string'],
            ['codigo' => 'en_catalogo', 'nombre' => 'En Catálogo', 'tipo' => 'bool'],
        ];
        $service = new ArticuloExcelImportWizardService($definitions);

        // fieldCatalog(): the base entry stays authoritative on a collision and
        // a non-colliding definition is still exposed additively.
        $catalog = $service->fieldCatalog($definitions);
        $this->assertSame(
            ArticuloExcelImportWizardService::FIELD_CATALOG['pvp'],
            $catalog['pvp'],
            'CAR-17: a colliding codigo must not replace the base catalog entry'
        );
        $this->assertArrayNotHasKey('feature', $catalog['pvp'], 'the base entry must stay a base entry');
        $this->assertSame('Precio', $catalog['pvp']['label']);
        $this->assertTrue($catalog['en_catalogo']['feature'], 'a free codigo stays an additive feature field');

        // fieldOptions(): exactly one option per colliding value, carrying the
        // base label; the feature label must never replace it.
        $options = $service->fieldOptions($definitions);
        $pvpOptions = array_values(array_filter(
            $options,
            static fn (array $option): bool => $option['value'] === 'pvp'
        ));
        $this->assertCount(1, $pvpOptions, 'a colliding codigo must not duplicate the base option');
        $this->assertSame('Precio', $pvpOptions[0]['label'], 'the base label stays authoritative');
        $this->assertContains('en_catalogo', array_column($options, 'value'));

        // extra_field_aliases(): the colliding definition contributes no alias
        // block, so the base field stays the only match for its headers.
        $aliases = $this->extraFieldAliases($service);
        $this->assertArrayNotHasKey('pvp', $aliases, 'CAR-17: the base field owns its aliases');
        $this->assertArrayHasKey('en_catalogo', $aliases);

        $mapping = ArticuloExcelImportWizardService::suggestMapping(
            ['Precio', 'Precio de tarifa'],
            $aliases
        );
        $this->assertSame('pvp', $mapping[0], 'the base alias still maps to the base field');
        $this->assertSame('__ignorar__', $mapping[1], 'the colliding definition must not claim headers');
    }

    public function test_unmapped_or_empty_feature_column_is_a_no_op(): void
    {
        $service = new ArticuloExcelImportWizardService([]);

        $this->assertSame([], $service->persist_feature_values([], 'REF-1', 'T1'), 'an empty row persists nothing');

        $mapped = ['referencia' => 'REF-1', 'en_catalogo' => ''];
        $this->assertSame([], $service->persist_feature_values($mapped, 'REF-1', 'T1'), 'an empty feature value is a no-op');
    }

    /**
     * Reads the private `extra_field_aliases()` seam so the surface stays
     * testable without widening its visibility.
     *
     * @return array<string, array<string, mixed>>
     */
    private function extraFieldAliases(ArticuloExcelImportWizardService $service): array
    {
        $method = new \ReflectionMethod(ArticuloExcelImportWizardService::class, 'extra_field_aliases');
        $method->setAccessible(true);

        /** @var array<string, array<string, mixed>> $aliases */
        $aliases = $method->invoke($service);

        return $aliases;
    }
}

/**
 * Small helper: ordered key list of an array.
 */
final class ArrayKeys
{
    /** @param array<string, mixed> $array @return list<string> */
    public static function of(array $array): array
    {
        return array_keys($array);
    }
}
