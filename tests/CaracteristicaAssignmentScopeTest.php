<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the assignment scopes (CAR-09).
 *
 * A shared in-memory row store backs both the value store (write) and the
 * resolver (read), so the tests prove end-to-end that global/family assignment
 * is lazy and single-row while a product assignment overrides it.
 */
final class CaracteristicaScopeFakeRow
{
    public $id_valor = null;

    public $valor = null;

    public $custom = false;

    public function __construct(array $data)
    {
        $this->id_valor = $data['id_valor'] ?? null;
        $this->valor = $data['valor'] ?? null;
        $this->custom = (bool) ($data['custom'] ?? false);
    }

    public function delete(): bool
    {
        return true;
    }
}

final class CaracteristicaScopeFakeDefinitionModel
{
    /** @param array<int, array<string, mixed>> $definitions */
    public function __construct(private array $definitions)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(bool $onlyActive = false): array
    {
        return $this->definitions;
    }
}

final class CaracteristicaScopeFakeModel
{
    /** @param array<string, array<string, array<string, array<int, array<string, mixed>>>>> $rows */
    public function __construct(
        private array &$rows,
        private string $scope,
        private array $keyColumns
    ) {
    }

    public $codtarifa = null;

    public $codfamilia = null;

    public $referencia = null;

    public $id_caracteristica = null;

    public $id_valor = null;

    public $valor = null;

    public $custom = false;

    public function key_columns(): array
    {
        return $this->keyColumns;
    }

    private function keyValue(): string
    {
        if ($this->keyColumns === []) {
            return '';
        }

        return (string) $this->{$this->keyColumns[0]};
    }

    public function get($codtarifa, array $key = [], $idCaracteristica = null)
    {
        $bucket = $this->scope === 'global'
            ? ($this->rows['global'][(string) $codtarifa][(int) $idCaracteristica] ?? null)
            : ($this->rows[$this->scope][(string) $codtarifa][(string) ($key[$this->keyColumns[0]] ?? '')][(int) $idCaracteristica] ?? null);

        return $bucket === null ? false : new CaracteristicaScopeFakeRow($bucket);
    }

    public function save(): bool
    {
        $triad = [
            'id_valor' => $this->id_valor,
            'valor' => $this->valor,
            'custom' => $this->custom,
        ];

        if ($this->scope === 'global') {
            $this->rows['global'][(string) $this->codtarifa][(int) $this->id_caracteristica] = $triad;
        } else {
            $this->rows[$this->scope][(string) $this->codtarifa][$this->keyValue()][(int) $this->id_caracteristica] = $triad;
        }

        return true;
    }

    public function delete(): bool
    {
        return true;
    }
}

final class CaracteristicaAssignmentScopeTest extends TestCase
{
    /** @var array<string, array<string, array<string, array<int, array<string, mixed>>>>> */
    private array $rows = [];

    /** @var array<string, string> */
    private array $familias = [];

    private static bool $baseLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaResolver.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaValorStore.php';
        self::$baseLoaded = true;
    }

    private function buildStore(): object
    {
        $rows = &$this->rows;

        return new class($rows) extends \FSFramework\Plugins\catalogo_core\Services\CaracteristicaValorStore {
            public function __construct(private array &$rows)
            {
            }

            protected function definitions(): array
            {
                return ['en_catalogo' => ['id' => 10, 'codigo' => 'en_catalogo', 'tipo' => 'bool']];
            }

            protected function scope_model(string $scope)
            {
                $keys = match ($scope) {
                    'familia' => ['codfamilia'],
                    'articulo' => ['referencia'],
                    default => [],
                };

                return new CaracteristicaScopeFakeModel($this->rows, $scope, $keys);
            }

            protected function catalog_value_id(int $idCaracteristica, string $valor): ?int
            {
                return $valor === '1' ? 1 : 2;
            }
        };
    }

    private function buildResolver(): object
    {
        $rows = &$this->rows;
        $familias = &$this->familias;

        return new class($rows, $familias) extends \FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver {
            public function __construct(private array &$rows, private array &$familias)
            {
            }

            protected function definition_model()
            {
                return new CaracteristicaScopeFakeDefinitionModel([
                    ['id' => 10, 'codigo' => 'en_catalogo', 'nombre' => 'En Catálogo', 'tipo' => 'bool', 'activo' => true],
                ]);
            }

            protected function scope_model(string $scope)
            {
                $keys = match ($scope) {
                    'familia' => ['codfamilia'],
                    'articulo' => ['referencia'],
                    default => [],
                };

                return new CaracteristicaScopeFakeModel($this->rows, $scope, $keys);
            }

            protected function familia_madre(string $codfamilia): ?string
            {
                return $this->familias[$codfamilia] ?? null;
            }

            protected function catalog_value(int $idValor): ?string
            {
                return $idValor === 1 ? '1' : '0';
            }
        };
    }

    private function countRows(string $scope): int
    {
        $count = 0;

        if ($scope === 'global') {
            foreach ($this->rows['global'] ?? [] as $perTarifa) {
                if (is_array($perTarifa) && isset($perTarifa[10])) {
                    $count++;
                }
            }

            return $count;
        }

        foreach ($this->rows[$scope] ?? [] as $perTarifa) {
            foreach ($perTarifa as $perKey) {
                if (is_array($perKey) && isset($perKey[10])) {
                    $count++;
                }
            }
        }

        return $count;
    }

    // =====================================================================
    // CAR-09
    // =====================================================================

    public function test_global_assignment_stores_one_row(): void
    {
        $store = $this->buildStore();
        $resolver = $this->buildResolver();

        $this->assertTrue($store->assign_bool('global', 'T2', [], 'en_catalogo', true));

        $this->assertSame(1, $this->countRows('global'), 'exactly one global row exists');
        $this->assertArrayNotHasKey('articulo', $this->rows, 'no article rows may be created');
        $this->assertTrue($resolver->resolve_bool('en_catalogo', 'T2', 'REF-1'));
    }

    public function test_family_assignment_covers_descendants_lazily(): void
    {
        $this->familias = ['F2' => 'F1'];
        $store = $this->buildStore();
        $resolver = $this->buildResolver();

        $this->assertTrue($store->assign_bool('familia', 'T2', ['codfamilia' => 'F1'], 'en_catalogo', true));

        $this->assertSame(1, $this->countRows('familia'));
        $this->assertTrue(
            $resolver->resolve_bool('en_catalogo', 'T2', 'REF-1', 'F2'),
            'a descendant subfamily resolves the parent-family value'
        );
        $this->assertArrayNotHasKey('articulo', $this->rows, 'resolution must not create article rows');
    }

    public function test_product_assignment_overrides_family_and_global(): void
    {
        $this->familias = [];
        $store = $this->buildStore();
        $resolver = $this->buildResolver();

        $store->assign_bool('global', 'T2', [], 'en_catalogo', false);
        $store->assign_bool('familia', 'T2', ['codfamilia' => 'F1'], 'en_catalogo', false);
        $store->assign_bool('articulo', 'T2', ['referencia' => 'REF-1'], 'en_catalogo', true);

        $this->assertTrue($resolver->resolve_bool('en_catalogo', 'T2', 'REF-1', 'F1'), 'the product wins for REF-1');
        $this->assertFalse(
            $resolver->resolve_bool('en_catalogo', 'T2', 'REF-2', 'F1'),
            'other products keep the family value'
        );
    }
}
