<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\ArticuloExcelExportService;
use FSFramework\Plugins\catalogo_core\Services\ArticuloExcelImportWizardService;
use FSFramework\Plugins\catalogo_core\Services\ArticuloExcelRowUpdater;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\CatalogoCore\Support\FakeArticulo;
use Tests\CatalogoCore\Support\IdiomaRegistryFake;

require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/base/fs_core_log.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/ArticuloExcelRowUpdater.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/ArticuloExcelExportService.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/ArticuloExcelImportWizardService.php';
require_once __DIR__ . '/../Support/FakeArticulo.php';

/**
 * Per-language Excel export/import (D-07 / D8 / R4 / R5).
 *
 * The base header list, the base field catalog and the base persistence rules
 * stay byte-identical: the locale columns are additive and the base `Descripción`
 * cell resolves the configured default language. Deactivated or unknown locale
 * suffixes are ignored and never create or mutate a `catalogo_idiomas` row.
 */
#[CoversClass(ArticuloExcelExportService::class)]
#[CoversClass(ArticuloExcelImportWizardService::class)]
#[CoversClass(ArticuloExcelRowUpdater::class)]
final class ArticuloExcelIdiomasTest extends TestCase
{
    /** @var list<array{codidioma: string, nombre: string}> */
    private const ACTIVE = [
        ['codidioma' => 'es', 'nombre' => 'Español'],
        ['codidioma' => 'en', 'nombre' => 'English'],
    ];

    // =====================================================================
    // Export Excel
    // =====================================================================

    public function test_export_base_headers_stay_byte_identical_with_locale_columns(): void
    {
        $sheet = (new ArticuloExcelExportService())
            ->buildSpreadsheet($this->exportRows(), false, '', [], self::ACTIVE, 'es')
            ->getActiveSheet();

        $base = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $column) {
            $base[] = $sheet->getCell($column . '1')->getValue();
        }

        $this->assertSame(ArticuloExcelExportService::EXPORT_HEADERS, $base);
    }

    public function test_export_locale_columns_append_in_deterministic_order_and_omit_inactive(): void
    {
        // The caller supplies the ACTIVE languages only (`all_activos()`): a
        // deactivated `fr` is therefore never handed to the export layer.
        $sheet = (new ArticuloExcelExportService())
            ->buildSpreadsheet($this->exportRows(), false, '', [], self::ACTIVE, 'es')
            ->getActiveSheet();

        $this->assertSame('descripcion_es', $sheet->getCell('H1')->getValue());
        $this->assertSame('descripcion_corta_es', $sheet->getCell('I1')->getValue());
        $this->assertSame('descripcion_en', $sheet->getCell('J1')->getValue());
        $this->assertSame('descripcion_corta_en', $sheet->getCell('K1')->getValue());
        $this->assertSame('K', $sheet->getHighestColumn());

        $headers = [];
        for ($index = 1; $index <= 11; $index++) {
            $headers[] = $sheet->getCell(Coordinate::stringFromColumnIndex($index) . '1')->getValue();
        }
        $this->assertNotContains('descripcion_fr', $headers, 'a deactivated language must be omitted');
        $this->assertNotContains('descripcion_corta_fr', $headers, 'a deactivated language must be omitted');
    }

    public function test_export_orders_the_default_language_first(): void
    {
        // `en` is the configured default while `es` sorts first alphabetically:
        // the order is default-first, not alphabetical.
        $idiomas = [
            ['codidioma' => 'es', 'nombre' => 'Español'],
            ['codidioma' => 'en', 'nombre' => 'English'],
        ];
        $sheet = (new ArticuloExcelExportService())
            ->buildSpreadsheet($this->exportRows(), false, '', [], $idiomas, 'en')
            ->getActiveSheet();

        $this->assertSame('descripcion_en', $sheet->getCell('H1')->getValue());
        $this->assertSame('descripcion_corta_en', $sheet->getCell('I1')->getValue());
        $this->assertSame('descripcion_es', $sheet->getCell('J1')->getValue());
        $this->assertSame('descripcion_corta_es', $sheet->getCell('K1')->getValue());
    }

    public function test_export_writes_each_language_text_under_its_suffix(): void
    {
        $db = new IdiomaRegistryFake(
            [
                ['codidioma' => 'es', 'por_defecto' => true],
                ['codidioma' => 'en'],
            ],
            [
                ['referencia' => 'ART1', 'codidioma' => 'es', 'descripcion' => 'Texto ES', 'descripcion_corta' => 'Corta ES'],
                ['referencia' => 'ART1', 'codidioma' => 'en', 'descripcion' => 'Text EN', 'descripcion_corta' => 'Short EN'],
            ]
        );
        $art = new FakeArticulo($db, 'ART1', 'legacy base text');

        $sheet = (new ArticuloExcelExportService())
            ->buildSpreadsheet([$art], false, '', [], self::ACTIVE, 'es')
            ->getActiveSheet();

        // GDI-10 Excel leg: the base `Descripción` cell resolves the configured default.
        $this->assertSame('Texto ES', $sheet->getCell('B2')->getValue());
        $this->assertSame('Texto ES', $sheet->getCell('H2')->getValue());
        $this->assertSame('Corta ES', $sheet->getCell('I2')->getValue());
        $this->assertSame('Text EN', $sheet->getCell('J2')->getValue());
        $this->assertSame('Short EN', $sheet->getCell('K2')->getValue());
    }

    // =====================================================================
    // Import wizard 3 pasos
    // =====================================================================

    public function test_target_language_defaults_to_the_configured_default_and_explicit_overrides(): void
    {
        $this->assertSame(
            'es',
            ArticuloExcelImportWizardService::resolveTargetCodidioma('', self::ACTIVE, 'es'),
            'an absent target falls back to the configured default'
        );
        $this->assertSame(
            'en',
            ArticuloExcelImportWizardService::resolveTargetCodidioma('en', self::ACTIVE, 'es'),
            'an explicit target overrides the configured default'
        );
        $this->assertSame(
            'es',
            ArticuloExcelImportWizardService::resolveTargetCodidioma('zz', self::ACTIVE, 'es'),
            'an unknown target falls back to the configured default'
        );
        $this->assertSame(
            'es',
            ArticuloExcelImportWizardService::resolveTargetCodidioma('fr', self::ACTIVE, 'es'),
            'an inactive target falls back to the configured default'
        );
    }

    public function test_language_field_catalog_is_additive_and_empty_without_languages(): void
    {
        $this->assertSame(
            [],
            ArticuloExcelImportWizardService::languageFieldCatalog([]),
            'no languages means no additive field'
        );

        $catalog = ArticuloExcelImportWizardService::languageFieldCatalog(self::ACTIVE);

        $this->assertArrayHasKey('descripcion_es', $catalog);
        $this->assertArrayHasKey('descripcion_corta_es', $catalog);
        $this->assertArrayHasKey('descripcion_en', $catalog);
        $this->assertArrayHasKey('descripcion_corta_en', $catalog);
        $this->assertSame('Descripción (Español)', $catalog['descripcion_es']['label']);
        $this->assertSame('descripcion_en', $catalog['descripcion_en']['column']);

        $options = (new ArticuloExcelImportWizardService([]))->fieldOptions([], self::ACTIVE);
        $this->assertContains('descripcion_es', array_column($options, 'value'));
        $this->assertContains('descripcion_corta_en', array_column($options, 'value'));
    }

    public function test_language_field_aliases_feed_the_suggested_mapping(): void
    {
        $extra = [];
        foreach (ArticuloExcelImportWizardService::languageFieldCatalog(self::ACTIVE) as $field => $info) {
            $extra[$field] = ['aliases' => $info['aliases']];
        }

        $mapping = ArticuloExcelImportWizardService::suggestMapping(
            ['Referencia', 'descripcion_en', 'descripcion_corta_es'],
            $extra
        );

        $this->assertSame('referencia', $mapping[0]);
        $this->assertSame('descripcion_en', $mapping[1]);
        $this->assertSame('descripcion_corta_es', $mapping[2]);
    }

    public function test_unknown_locale_column_is_ignored_without_touching_the_registry(): void
    {
        // `zz` has no `catalogo_idiomas` row: it must be ignored, never an error
        // and never a language creation.
        $pair = ArticuloExcelImportWizardService::resolveLocalePair(
            ['descripcion_zz' => 'texto'],
            self::ACTIVE,
            'es'
        );

        $this->assertNull($pair, 'an unknown locale suffix must be ignored');
        $this->assertArrayNotHasKey(
            'descripcion_zz',
            ArticuloExcelImportWizardService::languageFieldCatalog(self::ACTIVE),
            'an unknown locale suffix must not be offered as a field'
        );
    }

    public function test_deactivated_language_column_is_ignored(): void
    {
        // `fr` is deactivated: it is not among the active languages supplied.
        $pair = ArticuloExcelImportWizardService::resolveLocalePair(
            ['descripcion_fr' => 'Bonjour'],
            self::ACTIVE,
            'es'
        );

        $this->assertNull($pair, 'a deactivated language column must be ignored');
    }

    public function test_legacy_workbook_without_locale_columns_writes_no_description_row(): void
    {
        $pair = ArticuloExcelImportWizardService::resolveLocalePair(
            ['referencia' => 'ART1', 'descripcion' => 'Base text', 'pvp' => '10,50'],
            self::ACTIVE,
            'es'
        );

        $this->assertNull($pair, 'a base-only mapping must not write a description row');
    }

    public function test_locale_pair_resolves_the_target_first_then_the_default_order(): void
    {
        // Several mapped locale columns: the target's own suffix wins.
        $pair = ArticuloExcelImportWizardService::resolveLocalePair(
            ['descripcion_es' => 'ES', 'descripcion_en' => 'EN'],
            self::ACTIVE,
            'en'
        );
        $this->assertSame(['descripcion' => 'EN', 'descripcion_corta' => null], $pair);

        // Without the target's own column, the first mapped locale in
        // default-first-then-`codidioma` order wins.
        $pair = ArticuloExcelImportWizardService::resolveLocalePair(
            ['descripcion_en' => 'EN'],
            self::ACTIVE,
            'es'
        );
        $this->assertSame(['descripcion' => 'EN', 'descripcion_corta' => null], $pair);
    }

    public function test_mapped_but_empty_locale_pair_resolves_to_the_clearing_values(): void
    {
        // `apply()` drops empty cells, so the caller re-adds a mapped-but-empty
        // locale field as ''. That presence is what drives the clearing path.
        $pair = ArticuloExcelImportWizardService::resolveLocalePair(
            ['descripcion_en' => '', 'descripcion_corta_en' => ''],
            self::ACTIVE,
            'en'
        );

        $this->assertSame(['descripcion' => '', 'descripcion_corta' => ''], $pair);
    }

    // =====================================================================
    // Persistencia
    // =====================================================================

    public function test_mapped_pair_persists_on_the_target_language_row(): void
    {
        $db = new IdiomaRegistryFake(
            [
                ['codidioma' => 'es', 'por_defecto' => true],
                ['codidioma' => 'en'],
            ],
            [
                ['referencia' => 'ART1', 'codidioma' => 'en', 'descripcion' => 'Old EN', 'descripcion_corta' => 'Old short'],
            ]
        );
        $art = new FakeArticulo($db, 'ART1', '');

        $changed = ArticuloExcelRowUpdater::applyDescripcionIdioma('en', 'Text EN', 'Short EN', $art);

        $this->assertTrue($changed);
        $this->assertCount(1, $db->descripciones);
        $this->assertSame('en', $db->descripciones[0]['codidioma']);
        $this->assertSame('Text EN', $db->descripciones[0]['descripcion']);
        $this->assertSame('Short EN', $db->descripciones[0]['descripcion_corta']);
    }

    public function test_empty_mapped_pair_deletes_the_language_row(): void
    {
        $db = new IdiomaRegistryFake(
            [
                ['codidioma' => 'es', 'por_defecto' => true],
                ['codidioma' => 'en'],
            ],
            [
                ['referencia' => 'ART1', 'codidioma' => 'en', 'descripcion' => 'Text EN', 'descripcion_corta' => 'Short EN'],
            ]
        );
        $art = new FakeArticulo($db, 'ART1', '');

        $changed = ArticuloExcelRowUpdater::applyDescripcionIdioma('en', '', '', $art);

        $this->assertTrue($changed);
        $this->assertSame([], $db->descripciones, 'an empty mapped pair must delete the language row');
    }

    public function test_an_unmapped_component_preserves_the_current_value(): void
    {
        $db = new IdiomaRegistryFake(
            [
                ['codidioma' => 'es', 'por_defecto' => true],
                ['codidioma' => 'en'],
            ],
            [
                ['referencia' => 'ART1', 'codidioma' => 'en', 'descripcion' => 'Old EN', 'descripcion_corta' => 'Old short'],
            ]
        );
        $art = new FakeArticulo($db, 'ART1', '');

        // Only the short description is mapped: the full text must survive.
        $changed = ArticuloExcelRowUpdater::applyDescripcionIdioma('en', null, 'New short', $art);

        $this->assertTrue($changed);
        $this->assertSame('Old EN', $db->descripciones[0]['descripcion']);
        $this->assertSame('New short', $db->descripciones[0]['descripcion_corta']);
    }

    public function test_the_base_column_is_never_written_by_the_language_path(): void
    {
        $db = new IdiomaRegistryFake(
            [['codidioma' => 'es', 'por_defecto' => true]],
            [['referencia' => 'ART1', 'codidioma' => 'es', 'descripcion' => 'Old ES', 'descripcion_corta' => null]]
        );
        $art = new FakeArticulo($db, 'ART1', 'legacy base text');

        ArticuloExcelRowUpdater::applyDescripcionIdioma('es', 'Nuevo ES', 'Corta ES', $art);

        $this->assertSame('legacy base text', $art->descripcion, 'the frozen base shim must never be written');
        $this->assertSame('Nuevo ES', $db->descripciones[0]['descripcion'], 'the language row owns the text');
    }

    public function test_the_import_dispatch_reads_the_target_and_never_persists_a_language_row(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/process_excel_wizard_dispatch.php');

        $this->assertStringContainsString(
            'target_codidioma',
            $source,
            'the dispatch must accept the explicit target language (R5)'
        );
        $this->assertStringContainsString(
            'resolveLocalePair',
            $source,
            'the dispatch must route the mapped locale columns through the resolver'
        );
        $this->assertStringNotContainsString(
            '$idiomaModel->save(',
            $source,
            'the import path must never create or mutate a language row'
        );
    }

    public function test_a_description_write_never_mutates_the_language_registry(): void
    {
        $db = new IdiomaRegistryFake(
            [
                ['codidioma' => 'es', 'por_defecto' => true],
                ['codidioma' => 'en'],
            ],
            []
        );
        $before = $db->idiomas;
        $art = new FakeArticulo($db, 'ART1', '');

        ArticuloExcelRowUpdater::applyDescripcionIdioma('en', 'Text EN', 'Short EN', $art);

        $this->assertSame($before, $db->idiomas, 'a description write must never create or mutate a language row');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportRows(): array
    {
        return [[
            'referencia' => 'ART1',
            'descripcion' => 'Base text',
            'descripcion_es' => 'Texto ES',
            'descripcion_corta_es' => 'Corta ES',
            'descripcion_en' => 'Text EN',
            'descripcion_corta_en' => 'Short EN',
            'pvp' => 10.0,
            'codfamilia' => 'F1',
            'codfabricante' => '',
            'codimpuesto' => 'IVA21',
            'bloqueado' => false,
        ]];
    }
}
