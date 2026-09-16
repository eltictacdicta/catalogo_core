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
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\ArticuloExcelImportWizardService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

/**
 * Delta spec `articulos-excel-import-export` → Persistencia (WU-4 fix-up).
 *
 * Scenario: "Invalid feature value does not corrupt the base save". When the
 * feature store rejects a value, the wizard MUST surface an explicit motivo for
 * that feature and the base article persistence MUST stay intact: the base
 * article row is still built and saved, and no exception escapes.
 *
 * The combined workflow is driven through the importer entry point `apply()`
 * with the per-row shape CAR-17 defines for the import dispatch — persist the
 * feature values, then create and save the base article. The service is
 * exercised DB-free through its `valor_store()` seam and the base article
 * `save()` is a counting double.
 */
final class ArticuloExcelFeaturePersistenciaTest extends TestCase
{
    private const IMPORTER = '/plugins/catalogo_core/model/core/articulo.php';

    private const SHEET_NAME = 'Artículos';

    /** @var list<string> */
    private array $tempFixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(\articulo::class, false)) {
            require_once FS_FOLDER . self::IMPORTER;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFixtures as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFixtures = [];

        parent::tearDown();
    }

    public function test_rejected_feature_value_reports_a_motivo(): void
    {
        $service = $this->serviceWithStore(new FailingFeatureStore());

        $errors = $service->persist_feature_values(['en_tarifa' => '1'], 'REF-1', 'T1');

        $this->assertCount(1, $errors, 'a rejected write must be reported once');
        $this->assertSame(
            'No se pudo guardar la característica en_tarifa de REF-1.',
            $errors[0],
            'the motivo must name the feature and the article'
        );
    }

    public function test_rejected_feature_value_does_not_corrupt_the_base_save(): void
    {
        $service = $this->serviceWithStore(new FailingFeatureStore(), [
            ['codigo' => 'en_tarifa', 'tipo' => 'bool'],
        ]);

        $path = $this->writeImportFixture(
            ['Referencia', 'Descripción', 'en_tarifa'],
            ['REF-1', 'Producto con característica inválida', '1']
        );

        $featureErrors = [];
        $baseSaves = [];

        // The combined per-row workflow CAR-17 defines: persist the feature
        // values first, then create and save the base article.
        $rowHook = function (array $mappedRow, int $rowIdx) use ($service, &$featureErrors, &$baseSaves): void {
            $referencia = trim((string) ($mappedRow['referencia'] ?? ''));
            $featureErrors = array_merge(
                $featureErrors,
                $service->persist_feature_values($mappedRow, $referencia, 'T1')
            );

            $articulo = $this->baseArticleSpy($mappedRow);
            $articulo->save();
            $baseSaves[] = $articulo;
        };

        $result = $service->apply(
            $path,
            self::SHEET_NAME,
            [0 => 'referencia', 1 => 'descripcion', 2 => 'en_tarifa'],
            $rowHook,
            static function (): void {
            }
        );

        $this->assertSame(1, $result['processed'], 'the fixture row must reach the combined workflow');

        // The feature write failed exactly once ...
        $this->assertCount(1, $featureErrors, 'exactly one feature error must be reported');
        $this->assertSame(
            'No se pudo guardar la característica en_tarifa de REF-1.',
            $featureErrors[0]
        );

        // ... and the base article save still ran exactly once with the mapped row.
        $this->assertCount(1, $baseSaves, 'the base article save must still run once');
        $this->assertSame(1, $baseSaves[0]->saveCalls, 'articulo::save() must be called exactly once');
        $this->assertSame('REF-1', $baseSaves[0]->referencia);
        $this->assertSame('Producto con característica inválida', $baseSaves[0]->descripcion);
        $this->assertSame('IVA21', $baseSaves[0]->codimpuesto);
    }

    public function test_accepted_feature_value_reports_no_motivo(): void
    {
        $service = $this->serviceWithStore(new RecordingFeatureStore());

        $errors = $service->persist_feature_values(['en_tarifa' => '1'], 'REF-1', 'T1');

        $this->assertSame([], $errors, 'a successful write must report no motivo');
        $this->assertSame([['articulo', 'T1', ['referencia' => 'REF-1'], 'en_tarifa', true]], $service->store->calls);
    }

    public function test_blank_feature_value_is_a_no_op(): void
    {
        $store = new RecordingFeatureStore();
        $service = $this->serviceWithStore($store);

        $errors = $service->persist_feature_values(['en_tarifa' => '   '], 'REF-1', 'T1');

        $this->assertSame([], $errors);
        $this->assertSame([], $store->calls, 'an empty cell must not write anything');
    }

    public function test_a_definition_colliding_with_a_base_field_writes_no_feature_value(): void
    {
        $store = new RecordingFeatureStore();
        $service = $this->serviceWithStore($store, [
            ['codigo' => 'pvp', 'tipo' => 'string'],
            ['codigo' => 'en_tarifa', 'tipo' => 'bool'],
        ]);

        $errors = $service->persist_feature_values(['pvp' => '10', 'en_tarifa' => '1'], 'REF-1', 'T1');

        $this->assertSame([], $errors);
        $this->assertSame(
            [['articulo', 'T1', ['referencia' => 'REF-1'], 'en_tarifa', true]],
            $store->calls,
            'CAR-17: a codigo that collides with a base field must not persist a feature value'
        );
    }

    public function test_the_value_store_is_created_once_and_reused(): void
    {
        $service = new class () extends ArticuloExcelImportWizardService {
            public int $factoryCalls = 0;

            public function store(): object
            {
                return $this->valor_store();
            }

            protected function create_valor_store()
            {
                $this->factoryCalls++;

                return new RecordingFeatureStore();
            }
        };

        $first = $service->store();
        $second = $service->store();

        $this->assertSame(
            $first,
            $second,
            'valor_store() must memoize: the same store instance must serve the whole import run'
        );
        $this->assertSame(1, $service->factoryCalls, 'the store factory must run exactly once');
    }

    public function test_every_imported_row_reuses_the_same_store_instance(): void
    {
        $service = new class ([['codigo' => 'zzz_memoization_probe', 'tipo' => 'bool']]) extends ArticuloExcelImportWizardService {
            public int $factoryCalls = 0;

            public ?object $created = null;

            public function __construct(array $importable)
            {
                parent::__construct($importable);
            }

            protected function create_valor_store()
            {
                $this->factoryCalls++;

                return $this->created = new RecordingFeatureStore();
            }
        };

        for ($row = 1; $row <= 3; $row++) {
            $service->persist_feature_values(['zzz_memoization_probe' => '1'], 'REF-' . $row, 'T1');
        }

        $this->assertSame(1, $service->factoryCalls, 'N imported rows must build the store only once');
        $this->assertNotNull($service->created, 'the memoized store must be the one every row writes through');
        $this->assertCount(3, $service->created->calls, 'every row must still write exactly once');
    }

    /**
     * @param object $store
     * @param array<int, array<string, mixed>> $importable
     */
    private function serviceWithStore(
        object $store,
        array $importable = [['codigo' => 'en_tarifa', 'tipo' => 'bool']]
    ): ArticuloExcelImportWizardService {
        return new class ($importable, $store) extends ArticuloExcelImportWizardService {
            public object $store;

            public function __construct(array $importable, object $store)
            {
                parent::__construct($importable);
                $this->store = $store;
            }

            protected function valor_store()
            {
                return $this->store;
            }
        };
    }

    /**
     * Base article double: the row still comes from the real
     * `createArticuloFromRow()` factory and only `save()` is replaced, so the
     * combined workflow stays DB-free while still counting the base persist.
     *
     * @param array<string, string> $mappedRow
     */
    private function baseArticleSpy(array $mappedRow): object
    {
        return new class (ArticuloExcelImportWizardService::createArticuloFromRow($mappedRow, false, 'IVA21')) extends \FSFramework\model\articulo {
            public int $saveCalls = 0;

            public function __construct(\FSFramework\model\articulo $source)
            {
                // Skip the fs_model DB constructor: only the mapped row matters.
                foreach (get_object_vars($source) as $property => $value) {
                    $this->$property = $value;
                }
            }

            public function save(): bool
            {
                $this->saveCalls++;

                return true;
            }
        };
    }

    /**
     * Writes a one-row workbook for `apply()` and tracks it for tearDown.
     *
     * @param list<string> $headers
     * @param list<string> $row
     */
    private function writeImportFixture(array $headers, array $row): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_NAME);

        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . '1', $header);
        }
        foreach ($row as $index => $value) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . '2', $value);
        }

        // No placeholder file: the writer creates the workbook and tearDown
        // removes exactly this path.
        $path = sys_get_temp_dir() . '/catalogo_core_features_' . bin2hex(random_bytes(8)) . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->tempFixtures[] = $path;

        return $path;
    }
}

/**
 * Store double rejecting every write.
 */
final class FailingFeatureStore
{
    public function assign_with_dual_write(string $scope, string $codtarifa, array $key, string $codigo, bool $valor): bool
    {
        return false;
    }

    public function assign_custom(string $scope, string $codtarifa, array $key, string $codigo, string $valor): bool
    {
        return false;
    }
}

/**
 * Store double accepting every write and recording the exact call shape.
 */
final class RecordingFeatureStore
{
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>, 3: string, 4: mixed}> */
    public array $calls = [];

    public function assign_with_dual_write(string $scope, string $codtarifa, array $key, string $codigo, bool $valor): bool
    {
        $this->calls[] = [$scope, $codtarifa, $key, $codigo, $valor];

        return true;
    }

    public function assign_custom(string $scope, string $codtarifa, array $key, string $codigo, string $valor): bool
    {
        $this->calls[] = [$scope, $codtarifa, $key, $codigo, $valor];

        return true;
    }
}
