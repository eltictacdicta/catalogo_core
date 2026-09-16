<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the single write path (CAR-08) and the read-through flag
 * helper.
 *
 * DB-free: the store runs against a recording fake scope model, so the tests
 * prove the first write materializes exactly one row for the requested tarifa
 * and never fans out.
 */
final class CaracteristicaStoreFakeScopeModel
{
    /** @var list<array<string, mixed>> */
    public array $saved = [];

    public $codtarifa = null;

    public $codfamilia = null;

    public $referencia = null;

    public $id_caracteristica = null;

    public $id_valor = null;

    public $valor = null;

    public $custom = false;

    public function __construct(private array $keyColumns)
    {
    }

    public function key_columns(): array
    {
        return $this->keyColumns;
    }

    public function save(): bool
    {
        $row = [
            'codtarifa' => $this->codtarifa,
            'id_caracteristica' => $this->id_caracteristica,
            'id_valor' => $this->id_valor,
            'valor' => $this->valor,
            'custom' => $this->custom,
        ];
        foreach ($this->keyColumns as $column) {
            $row[$column] = $this->{$column};
        }
        $this->saved[] = $row;

        return true;
    }

    public function delete(): bool
    {
        return true;
    }
}

final class CaracteristicaValorStoreTest extends TestCase
{
    /** @var list<CaracteristicaStoreFakeScopeModel> */
    private array $models = [];

    private static bool $baseLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaConfig.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaValorStore.php';
        self::$baseLoaded = true;
    }

    private function buildStore(): object
    {
        $models = &$this->models;

        return new class($models) extends \FSFramework\Plugins\catalogo_core\Services\CaracteristicaValorStore {
            public function __construct(private array &$models)
            {
            }

            protected function definitions(): array
            {
                return [
                    'en_catalogo' => ['id' => 10, 'codigo' => 'en_catalogo', 'tipo' => 'bool'],
                    'medidas' => ['id' => 11, 'codigo' => 'medidas', 'tipo' => 'string'],
                ];
            }

            protected function scope_model(string $scope)
            {
                $keys = match ($scope) {
                    'familia' => ['codfamilia'],
                    'articulo' => ['referencia'],
                    default => [],
                };
                $model = new CaracteristicaStoreFakeScopeModel($keys);
                $this->models[] = $model;

                return $model;
            }

            protected function catalog_value_id(int $idCaracteristica, string $valor): ?int
            {
                return $valor === '1' ? 1 : 2;
            }
        };
    }

    // =====================================================================
    // CAR-08 — lazy DEF inheritance with first-write materialization
    // =====================================================================

    public function test_first_write_materializes_only_the_requested_tarifa(): void
    {
        $store = $this->buildStore();

        $store->assign_bool('articulo', 'T2', ['referencia' => 'REF-1'], 'en_catalogo', true);

        $this->assertCount(1, $this->models, 'exactly one scope model is written');
        $rows = $this->models[0]->saved;
        $this->assertCount(1, $rows, 'exactly one row materialized');
        $this->assertSame('T2', $rows[0]['codtarifa'], 'only the requested tarifa is written');
        $this->assertSame('REF-1', $rows[0]['referencia']);
        $this->assertSame(1, $rows[0]['id_valor']);
        $this->assertNull($rows[0]['valor']);
        $this->assertFalse($rows[0]['custom']);
    }

    public function test_no_fan_out_to_other_tarifas_or_scopes(): void
    {
        $store = $this->buildStore();

        // T3 keeps inheriting DEF: the write for T2 never touches another tarifa.
        $store->assign_custom('familia', 'T2', ['codfamilia' => 'F1'], 'medidas', 'XL');

        $this->assertCount(1, $this->models);
        $rows = $this->models[0]->saved;
        $this->assertCount(1, $rows);
        $this->assertSame('T2', $rows[0]['codtarifa']);
        $this->assertSame('XL', $rows[0]['valor']);
        $this->assertTrue($rows[0]['custom'], 'a custom write derives custom = TRUE');
        $this->assertNull($rows[0]['id_valor']);

        foreach ($rows as $row) {
            $this->assertNotSame('T3', $row['codtarifa'], 'no other tarifa may be written');
        }
    }

    public function test_unknown_definition_writes_nothing(): void
    {
        $store = $this->buildStore();

        $this->assertFalse($store->assign_bool('articulo', 'T2', ['referencia' => 'REF-1'], 'desconocida', true));
        $this->assertSame([], $this->models, 'an unknown codigo writes nothing');
    }

    // =====================================================================
    // Read-through flag helper
    // =====================================================================

    public function test_read_through_defaults_to_false_and_reads_the_constant(): void
    {
        $config = \FSFramework\Plugins\catalogo_core\Services\CaracteristicaConfig::class;

        $this->assertSame('FS_CATALOGO_CARACTERISTICAS_READ_THROUGH', $config::READ_THROUGH_FLAG);

        if (defined($config::READ_THROUGH_FLAG)) {
            $this->assertSame((bool) constant($config::READ_THROUGH_FLAG), $config::read_through());
        } else {
            $this->assertFalse($config::read_through(), 'undefined constant resolves to FALSE');
        }
    }
}
