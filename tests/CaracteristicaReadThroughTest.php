<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Services\ArticuloTarifaPrecioBatchReader;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaValorBatchReader;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/base/fs_core_log.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/ArticuloTarifaPrecioBatchReader.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaValorBatchReader.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaConfig.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaResolver.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaValorStore.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/ArticuloExcelImportWizardService.php';
require_once FS_FOLDER . '/plugins/catalogo_core/extras/VentasArticulosListTrait.php';

/**
 * Contract tests for the read-through flag and the dual-write soak (CAR-14).
 *
 * DB-free: the list trait runs against fake batch readers and the store
 * against a fake scope model + fake DB, so both branches of the flag and the
 * legacy materialization are observable as real state instead of SQL strings
 * alone.
 */
final class ReadThroughLegacyReader extends ArticuloTarifaPrecioBatchReader
{
    /** @param array<string, array{precio: float, activo: bool, en_tarifa: bool, en_catalogo: bool}> $map */
    public function __construct(private array $map)
    {
    }

    public function for_referencias(array $refs, string $codtarifa): array
    {
        return $this->map;
    }
}

final class ReadThroughFeatureReader extends CaracteristicaValorBatchReader
{
    /** @var array<string, array<string, ?string>> */
    public array $map = [];

    public int $calls = 0;

    /** @var array<int, string>|null */
    public ?array $seenCodigos = null;

    public function for_referencias(
        array $refs,
        string $codtarifa,
        ?array $familias = null,
        ?array $codigos = null
    ): array {
        $this->calls++;
        $this->seenCodigos = $codigos;

        return $this->map;
    }
}

final class ReadThroughListHost
{
    use \VentasArticulosListTrait;

    public bool $readThrough = false;

    /** @var array<string, array{precio: float, activo: bool, en_tarifa: bool, en_catalogo: bool}> */
    public array $legacyMap = [];

    public ReadThroughFeatureReader $featureReader;

    public function __construct()
    {
        $this->featureReader = new ReadThroughFeatureReader();
        $this->b_codtarifa = 'T1';
        $this->tarifa_seleccionada = (object) ['codtarifa' => 'T1', 'nombre' => 'Base', 'coddivisa' => 'EUR'];
    }

    protected function articulo_precio_batch_reader(): ArticuloTarifaPrecioBatchReader
    {
        return new ReadThroughLegacyReader($this->legacyMap);
    }

    protected function caracteristica_batch_reader(): CaracteristicaValorBatchReader
    {
        return $this->featureReader;
    }

    protected function caracteristica_read_through(): bool
    {
        return $this->readThrough;
    }
}

final class ReadThroughFakeScopeModel
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

    /** @param list<string> $keyColumns */
    public function __construct(private array $keyColumns)
    {
    }

    /** @return list<string> */
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

final class ReadThroughFakeDb
{
    /** @var list<string> */
    public array $executed = [];

    public function __construct(private ReadThroughDualWriteStore $store)
    {
    }

    public function var2str($val)
    {
        if ($val === null) {
            return 'NULL';
        }
        if (is_bool($val)) {
            return $val ? '1' : '0';
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function select($sql, $params = [])
    {
        if (preg_match('/^SELECT 1 FROM ([a-zA-Z0-9_]+) WHERE /', trim((string) $sql), $m)) {
            return ($this->store->existing[$m[1]] ?? false) ? [['1' => 1]] : [];
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $this->executed[] = trim((string) $sql);

        return true;
    }
}

final class ReadThroughDualWriteStore extends \FSFramework\Plugins\catalogo_core\Services\CaracteristicaValorStore
{
    /** @var array<string, bool> table => a matching row exists */
    public array $existing = [];

    /** @var array<string, list<string>> table => legacy columns still present */
    public array $columns = [];

    public ?string $family = null;

    /** @var list<ReadThroughFakeScopeModel> */
    public array $models = [];

    public ReadThroughFakeDb $dbInstance;

    public function __construct()
    {
        $this->dbInstance = new ReadThroughFakeDb($this);
    }

    protected function db()
    {
        return $this->dbInstance;
    }

    protected function legacy_column_exists(string $table, string $column): bool
    {
        return in_array($column, $this->columns[$table] ?? [], true);
    }

    protected function article_family(string $referencia): ?string
    {
        return $this->family;
    }

    protected function familia_madre(string $codfamilia): ?string
    {
        return $this->family;
    }

    protected function definitions(): array
    {
        return [
            'en_catalogo' => ['id' => 10, 'codigo' => 'en_catalogo', 'tipo' => 'bool'],
            'en_tarifa' => ['id' => 11, 'codigo' => 'en_tarifa', 'tipo' => 'bool'],
        ];
    }

    protected function scope_model(string $scope)
    {
        $keys = match ($scope) {
            'articulo' => ['referencia'],
            'familia' => ['codfamilia'],
            default => [],
        };
        $model = new ReadThroughFakeScopeModel($keys);
        $this->models[] = $model;

        return $model;
    }

    protected function catalog_value_id(int $idCaracteristica, string $valor): ?int
    {
        return $valor === '1' ? 1 : 2;
    }
}

final class ReadThroughImportStoreSpy
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @param array<string, mixed> $key */
    public function assign_with_dual_write(
        string $scope,
        string $codtarifa,
        array $key,
        string $codigo,
        bool $valor
    ): bool {
        $this->calls[] = compact('scope', 'codtarifa', 'key', 'codigo', 'valor');

        return true;
    }

    /** @param array<string, mixed> $key */
    public function assign_custom(string $scope, string $codtarifa, array $key, string $codigo, string $valor): bool
    {
        $this->calls[] = ['custom' => $valor] + compact('scope', 'codtarifa', 'key', 'codigo');

        return true;
    }
}

final class CaracteristicaReadThroughTest extends TestCase
{
    private function host(): ReadThroughListHost
    {
        return new ReadThroughListHost();
    }

    private function dualStore(): ReadThroughDualWriteStore
    {
        return new ReadThroughDualWriteStore();
    }

    // =====================================================================
    // CAR-14 — flag off keeps legacy behavior
    // =====================================================================

    public function test_flag_off_keeps_legacy_visibility_and_skips_the_feature_read(): void
    {
        $host = $this->host();
        $host->readThrough = false;
        $host->legacyMap = [
            'A' => ['precio' => 5.0, 'activo' => true, 'en_tarifa' => true, 'en_catalogo' => false],
        ];
        $host->featureReader->map = ['A' => ['en_tarifa' => '0', 'en_catalogo' => '1']];

        $host->load_articulo_tarifa_columns(['A']);

        $this->assertTrue($host->articulo_en_tarifa_flag('A'), 'flag off reads the legacy en_tarifa column');
        $this->assertFalse($host->articulo_en_catalogo('A'), 'flag off reads the legacy en_catalogo column');
        $this->assertSame(0, $host->featureReader->calls, 'flag off never touches the feature reader');
    }

    // =====================================================================
    // CAR-14 — flag on switches to the resolver
    // =====================================================================

    public function test_flag_on_switches_visibility_to_the_resolver(): void
    {
        $host = $this->host();
        $host->readThrough = true;
        $host->legacyMap = [
            'A' => ['precio' => 5.0, 'activo' => true, 'en_tarifa' => false, 'en_catalogo' => false],
        ];
        $host->featureReader->map = ['A' => ['en_tarifa' => '1', 'en_catalogo' => '0']];

        $host->load_articulo_tarifa_columns(['A']);

        $this->assertSame(1, $host->featureReader->calls, 'flag on reads one batched feature map');
        $this->assertSame(
            ['en_catalogo', 'en_tarifa'],
            $host->featureReader->seenCodigos,
            'only the two visibility features are resolved'
        );
        $this->assertTrue($host->articulo_en_tarifa_flag('A'), 'flag on follows the resolver value');
        $this->assertFalse($host->articulo_en_catalogo('A'), 'flag on follows the resolver value');
        $this->assertFalse($host->articulo_en_tarifa_flag('MISSING'), 'a missing resolver value resolves to FALSE');
    }

    // =====================================================================
    // CAR-14 — dual-write keeps both paths consistent
    // =====================================================================

    public function test_dual_write_articulo_writes_the_feature_and_the_legacy_columns(): void
    {
        $store = $this->dualStore();
        $store->columns = [
            'tarif_articulo_precios' => ['en_catalogo'],
            'tarif_tarifa_articulo' => ['en_catalogo'],
        ];
        $store->existing = ['tarif_articulo_precios' => true, 'tarif_tarifa_articulo' => false];
        $store->family = 'F1';

        $this->assertTrue(
            $store->assign_with_dual_write('articulo', 'T1', ['referencia' => 'A1'], 'en_catalogo', true)
        );

        $this->assertCount(1, $store->models, 'exactly one feature row is materialized');
        $this->assertSame(1, $store->models[0]->saved[0]['id_valor'], 'the feature value is stored');

        $executed = implode("\n", $store->dbInstance->executed);
        $this->assertStringContainsString(
            'UPDATE tarif_articulo_precios SET en_catalogo = 1',
            $executed,
            'an existing price row is updated in place'
        );
        $this->assertStringContainsString(
            'INSERT INTO tarif_tarifa_articulo',
            $executed,
            'a missing tarifa-article row is materialized for the equivalent value'
        );
        $this->assertStringContainsString("'F1'", $executed, "the tarifa-article insert uses the article's family");
        $this->assertStringNotContainsString('coddivisa', $executed, 'the dual-write never writes coddivisa');
        $this->assertStringNotContainsString(
            'madre',
            $executed,
            'tarif_tarifa_articulo has no `madre` column (live divergence from design §8.4)'
        );
        $this->assertStringContainsString(
            'orden',
            $executed,
            'tarif_tarifa_articulo.orden is NOT NULL and must be part of the insert'
        );
    }

    public function test_dual_write_familia_updates_and_global_is_a_noop(): void
    {
        $family = $this->dualStore();
        $family->columns = ['tarif_tarifa_familia' => ['en_tarifa']];
        $family->existing = ['tarif_tarifa_familia' => true];
        $family->family = 'MADRE-1';

        $this->assertTrue(
            $family->assign_with_dual_write('familia', 'T1', ['codfamilia' => 'F1'], 'en_tarifa', false)
        );
        $executed = implode("\n", $family->dbInstance->executed);
        $this->assertStringContainsString(
            'UPDATE tarif_tarifa_familia SET en_tarifa = 0',
            $executed,
            'the familia dual-write updates the legacy column'
        );

        $global = $this->dualStore();
        $global->columns = [
            'tarif_articulo_precios' => ['en_catalogo'],
            'tarif_tarifa_articulo' => ['en_catalogo'],
            'tarif_tarifa_familia' => ['en_catalogo'],
        ];
        $global->existing = [
            'tarif_articulo_precios' => true,
            'tarif_tarifa_articulo' => true,
            'tarif_tarifa_familia' => true,
        ];

        $this->assertTrue(
            $global->assign_with_dual_write('global', 'T1', [], 'en_catalogo', true)
        );
        $this->assertSame([], $global->dbInstance->executed, 'the global scope has no legacy representation');
    }

    public function test_dual_write_is_a_noop_when_the_legacy_column_is_gone(): void
    {
        $store = $this->dualStore();
        $store->columns = [];

        $this->assertTrue(
            $store->assign_with_dual_write('articulo', 'T1', ['referencia' => 'A1'], 'en_catalogo', true)
        );
        $this->assertSame([], $store->dbInstance->executed, 'a dropped legacy column writes nothing');
        $this->assertCount(1, $store->models, 'the feature value still persists');
    }

    // =====================================================================
    // CAR-14 — the import consumer rides the dual-write path
    // =====================================================================

    public function test_import_wizard_dual_writes_imported_bool_feature_values(): void
    {
        $spy = new ReadThroughImportStoreSpy();
        $service = new class($spy) extends \FSFramework\Plugins\catalogo_core\Services\ArticuloExcelImportWizardService {
            public function __construct(private ReadThroughImportStoreSpy $spy)
            {
                parent::__construct([
                    ['codigo' => 'en_catalogo', 'nombre' => 'En Catálogo', 'tipo' => 'bool'],
                ]);
            }

            protected function valor_store()
            {
                return $this->spy;
            }
        };

        $this->assertSame([], $service->persist_feature_values(['en_catalogo' => 'Sí'], 'A1', 'T1'));
        $this->assertCount(1, $spy->calls, 'the imported bool feature must persist once');
        $this->assertSame('articulo', $spy->calls[0]['scope']);
        $this->assertSame('en_catalogo', $spy->calls[0]['codigo']);
        $this->assertSame(['referencia' => 'A1'], $spy->calls[0]['key']);
        $this->assertTrue($spy->calls[0]['valor'], 'the imported "Sí" maps to TRUE');
    }
}
