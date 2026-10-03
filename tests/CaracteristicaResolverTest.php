<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the read path (CAR-04 empty-vs-absent, CAR-06 precedence,
 * CAR-07 reads never persist).
 *
 * The resolver is exercised DB-free: an anonymous subclass overrides the
 * model/seam methods and answers from in-memory maps. No SQL is issued.
 */
final class CaracteristicaResolverFakeDefinitionModel
{
    /** Definition-table read counter (query-count proxy; reset per test). */
    public static int $allCalls = 0;

    /** @param array<int, array<string, mixed>> $definitions */
    public function __construct(private array $definitions)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(bool $onlyActive = false): array
    {
        self::$allCalls++;

        if (!$onlyActive) {
            return $this->definitions;
        }

        return array_values(array_filter(
            $this->definitions,
            static fn (array $def): bool => (bool) ($def['activo'] ?? true)
        ));
    }
}

final class CaracteristicaResolverFakeScopeModel
{
    public function __construct(private array &$rows, private string $scope)
    {
    }

    public function get($codtarifa, array $key = [], $idCaracteristica = null)
    {
        if ($this->scope === 'global') {
            return $this->rows['global'][(string) $codtarifa][(int) $idCaracteristica] ?? false;
        }

        $keyColumn = $this->scope === 'familia' ? 'codfamilia' : 'referencia';
        $keyValue = (string) ($key[$keyColumn] ?? '');

        return $this->rows[$this->scope][(string) $codtarifa][$keyValue][(int) $idCaracteristica] ?? false;
    }
}

final class CaracteristicaResolverTest extends TestCase
{
    /** @var array<string, array<string, array<string, array<int, array<string, mixed>>>>> */
    private array $rows = [];

    /** @var array<string, string> */
    private array $familias = [];

    /** @var array<int, string> */
    private array $catalog = [1 => '1', 2 => '0'];

    /** @var list<string> */
    private array $writes = [];

    private static bool $baseLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaOwnership.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaResolver.php';
        self::$baseLoaded = true;
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @param array<int, string>               $enabledPlugins enabled set for the ownership seam
     */
    private function buildResolver(array $definitions, array $enabledPlugins = []): object
    {
        $rows = &$this->rows;
        $familias = &$this->familias;
        $catalog = &$this->catalog;
        $writes = &$this->writes;

        return new class($definitions, $rows, $familias, $catalog, $writes, $enabledPlugins) extends \FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver {
            /** @param array<int, string> $enabledPlugins */
            public function __construct(
                private array $defs,
                private array &$rows,
                private array &$familias,
                private array &$catalog,
                private array &$writes,
                private array $enabledPlugins
            ) {
            }

            protected function definition_model()
            {
                return new CaracteristicaResolverFakeDefinitionModel($this->defs);
            }

            protected function ownership()
            {
                $enabled = $this->enabledPlugins;

                return new class($enabled) extends \FSFramework\Plugins\catalogo_core\Services\CaracteristicaOwnership {
                    /** @param array<int, string> $enabled */
                    public function __construct(private array $enabled)
                    {
                    }

                    /** @return array<int, string> */
                    protected function enabled_plugins(): array
                    {
                        return $this->enabled;
                    }
                };
            }

            protected function scope_model(string $scope)
            {
                return new CaracteristicaResolverFakeScopeModel($this->rows, $scope);
            }

            protected function familia_madre(string $codfamilia): ?string
            {
                return $this->familias[$codfamilia] ?? null;
            }

            protected function catalog_value(int $idValor): ?string
            {
                return $this->catalog[$idValor] ?? null;
            }
        };
    }

    private function definition(string $codigo, string $tipo = 'string', ?string $default = null): array
    {
        return [
            'id' => $codigo === 'color' ? 10 : 11,
            'codigo' => $codigo,
            'nombre' => ucfirst($codigo),
            'tipo' => $tipo,
            'activo' => true,
            'valor_defecto' => $default,
        ];
    }

    /** @param array<string, mixed> $row */
    private function scopeRow(?int $idValor = null, ?string $valor = null, bool $custom = false): array
    {
        return ['id_valor' => $idValor, 'valor' => $valor, 'custom' => $custom];
    }

    // =====================================================================
    // CAR-06 — precedence
    // =====================================================================

    public function test_product_scope_wins_over_family_and_global(): void
    {
        $this->rows = [
            'articulo' => ['T1' => ['REF-1' => [10 => $this->scopeRow(1, null, false)]]],
            'familia' => ['T1' => ['F1' => [10 => $this->scopeRow(2, null, false)]]],
            'global' => ['T1' => [10 => $this->scopeRow(null, 'global', true)]],
        ];
        $resolver = $this->buildResolver([$this->definition('color')]);

        $this->assertSame('1', $resolver->resolve('color', 'T1', 'REF-1', 'F1'));
    }

    public function test_nearest_family_ancestor_wins(): void
    {
        $this->familias = ['F2' => 'F1'];
        $this->rows = [
            'familia' => [
                'T1' => [
                    'F2' => [10 => $this->scopeRow(null, 'hija', true)],
                    'F1' => [10 => $this->scopeRow(null, 'madre', true)],
                ],
            ],
        ];
        $resolver = $this->buildResolver([$this->definition('color')]);

        $this->assertSame('hija', $resolver->resolve('color', 'T1', null, 'F2'));

        // Removing the own-family row resolves the madre value.
        unset($this->rows['familia']['T1']['F2']);
        $this->assertSame('madre', $resolver->resolve('color', 'T1', null, 'F2'));
    }

    public function test_requested_tarifa_row_beats_the_def_row(): void
    {
        $this->rows = [
            'articulo' => [
                'T2' => ['REF-1' => [10 => $this->scopeRow(null, 'T2', true)]],
                'DEF' => ['REF-1' => [10 => $this->scopeRow(null, 'DEF', true)]],
            ],
        ];
        $resolver = $this->buildResolver([$this->definition('color')]);

        $this->assertSame('T2', $resolver->resolve('color', 'T2', 'REF-1'));
        $this->assertSame('DEF', $resolver->resolve('color', 'T9', 'REF-1'));
    }

    public function test_scope_precedence_beats_tarifa_fallback(): void
    {
        $this->rows = [
            'articulo' => ['DEF' => ['REF-1' => [10 => $this->scopeRow(null, 'art-def', true)]]],
            'global' => ['T2' => [10 => $this->scopeRow(null, 'global-t2', true)]],
        ];
        $resolver = $this->buildResolver([$this->definition('color')]);

        $this->assertSame('art-def', $resolver->resolve('color', 'T2', 'REF-1'));
    }

    public function test_valor_defecto_is_the_terminal_fallback(): void
    {
        $resolver = $this->buildResolver([$this->definition('color', 'string', 'X')]);
        $this->assertSame('X', $resolver->resolve('color', 'T1', 'REF-1'));

        $emptyDefault = $this->buildResolver([$this->definition('color', 'string', '')]);
        $this->assertNull($emptyDefault->resolve('color', 'T1', 'REF-1'), 'empty default is "no value", never FALSE');

        $nullDefault = $this->buildResolver([$this->definition('color', 'string', null)]);
        $this->assertNull($nullDefault->resolve('color', 'T1', 'REF-1'));
    }

    public function test_absent_family_does_not_block_the_global_fallback(): void
    {
        $this->rows = ['global' => ['T1' => [10 => $this->scopeRow(null, 'g', true)]]];
        $resolver = $this->buildResolver([$this->definition('color')]);

        $this->assertSame('g', $resolver->resolve('color', 'T1', null, null));
    }

    public function test_unknown_or_inactive_definition_resolves_to_no_value(): void
    {
        $resolver = $this->buildResolver([$this->definition('color')]);
        $this->assertNull($resolver->resolve('desconocida', 'T1', 'REF-1'));

        $inactive = $this->definition('color');
        $inactive['activo'] = false;
        $resolver = $this->buildResolver([$inactive]);
        $this->assertNull($resolver->resolve('color', 'T1', 'REF-1'));
    }

    // =====================================================================
    // CAR-20 / CAR-06 — owner-disabled definitions reuse the missing path
    // =====================================================================

    public function test_owner_disabled_definition_resolves_like_a_missing_or_inactive_one(): void
    {
        $rows = [
            'articulo' => ['T1' => ['REF-1' => [10 => $this->scopeRow(null, 'X', true)]]],
        ];
        $this->rows = $rows;

        $inert = $this->definition('color') + ['origen' => 'tarifario'];
        $inactive = $this->definition('inactiva');
        $inactive['activo'] = false;

        // 'otra' is simply missing; 'tarifario' is disabled (empty enabled set).
        $resolver = $this->buildResolver([$inert, $inactive], []);

        $this->assertNull($resolver->resolve('color', 'T1', 'REF-1'), 'inert == no value');
        $this->assertNull($resolver->resolve('otra', 'T1', 'REF-1'), 'missing == no value');
        $this->assertNull($resolver->resolve('inactiva', 'T1', 'REF-1'), 'inactive == no value');
        $this->assertNull($resolver->resolve_bool('color', 'T1', 'REF-1'));

        $this->assertSame($rows, $this->rows, 'no value row may be created or modified by a read');
        $this->assertSame([], $this->writes);
    }

    public function test_owner_disabled_definition_is_absent_from_the_flagged_helpers(): void
    {
        $flagged = static fn (string $codigo, string $origen): array => [
            'id' => 10,
            'codigo' => $codigo,
            'nombre' => ucfirst($codigo),
            'tipo' => 'bool',
            'activo' => true,
            'importable' => true,
            'exportable' => true,
            'listable' => true,
            'valor_defecto' => null,
            'origen' => $origen,
        ];

        $inert = $this->buildResolver([$flagged('apagada', 'tarifario')], []);
        $this->assertSame([], $inert->listable_definitions(), 'inert emits no listable column');
        $this->assertSame([], $inert->importable_definitions(), 'inert adds no import field');
        $this->assertSame([], $inert->exportable_definitions(), 'inert adds no export column');

        $enabled = $this->buildResolver([$flagged('encendida', 'tarifario')], ['tarifario']);
        $this->assertCount(1, $enabled->listable_definitions(), 'the enabled owner is listable');
        $this->assertCount(1, $enabled->importable_definitions(), 'the enabled owner is importable');
        $this->assertCount(1, $enabled->exportable_definitions(), 'the enabled owner is exportable');
    }

    public function test_operator_owned_definition_stays_active_with_an_empty_registry(): void
    {
        $resolver = $this->buildResolver([$this->definition('color', 'string', 'Z')], []);

        $this->assertArrayHasKey('color', $resolver->definitions(), 'origen "" is the unconditional exception');
        $this->assertSame('Z', $resolver->resolve('color', 'T1', 'REF-1'));
    }

    // =====================================================================
    // VCG-01 — active visibility codigos derived from definitions() (CAR-20)
    // =====================================================================

    public function test_active_visibility_codigos_returns_only_enabled_owner_codigos(): void
    {
        $catalogo = $this->definition('en_catalogo', 'bool') + ['origen' => 'tarifario'];
        $tarifa = $this->definition('en_tarifa', 'bool') + ['origen' => 'tarifario'];

        $resolver = $this->buildResolver([$catalogo, $tarifa], ['tarifario']);

        $this->assertSame(
            ['en_catalogo', 'en_tarifa'],
            $resolver->active_visibility_codigos(),
            'the enabled owner returns exactly its two visibility codigos, in canonical order'
        );
        $this->assertTrue($resolver->is_visibility_active('en_catalogo'));
        $this->assertTrue($resolver->is_visibility_active('en_tarifa'));
    }

    public function test_active_visibility_codigos_empty_when_owner_disabled(): void
    {
        $catalogo = $this->definition('en_catalogo', 'bool') + ['origen' => 'tarifario'];
        $tarifa = $this->definition('en_tarifa', 'bool') + ['origen' => 'tarifario'];

        $resolver = $this->buildResolver([$catalogo, $tarifa], []);

        $this->assertSame([], $resolver->active_visibility_codigos(), 'a disabled owner hides both codigos');
        $this->assertFalse($resolver->is_visibility_active('en_catalogo'));
        $this->assertFalse($resolver->is_visibility_active('en_tarifa'));
    }

    public function test_active_visibility_codigos_ignores_operator_owned_rows(): void
    {
        // An operator-owned codigo is active for CAR-20 but is not a visibility codigo.
        $operator = $this->definition('medidas', 'string');
        $enabled = $this->definition('en_catalogo', 'bool') + ['origen' => 'tarifario'];

        $resolver = $this->buildResolver([$operator, $enabled], ['tarifario']);

        $this->assertSame(
            ['en_catalogo'],
            $resolver->active_visibility_codigos(),
            'only the canonical visibility codigos are returned; other active rows are ignored'
        );
    }

    public function test_is_visibility_active_rejects_unknown_and_inert_codigos(): void
    {
        $inert = $this->definition('en_tarifa', 'bool') + ['origen' => 'tarifario'];
        $operator = $this->definition('medidas', 'string');

        $disabled = $this->buildResolver([$inert, $operator], []);
        $this->assertFalse($disabled->is_visibility_active('en_tarifa'), 'an inert definition is not active');
        $this->assertFalse($disabled->is_visibility_active('en_catalogo'), 'a missing definition is not active');
        $this->assertFalse($disabled->is_visibility_active('medidas'), 'a non-visibility codigo is never active');
        $this->assertFalse($disabled->is_visibility_active('desconocido'), 'an unknown codigo is never active');

        $enabled = $this->buildResolver([$inert, $operator], ['tarifario']);
        $this->assertTrue($enabled->is_visibility_active('en_tarifa'));
        $this->assertFalse($enabled->is_visibility_active('en_catalogo'));
        $this->assertFalse($enabled->is_visibility_active('medidas'));
    }

    public function test_active_visibility_codigos_reads_definitions_once(): void
    {
        CaracteristicaResolverFakeDefinitionModel::$allCalls = 0;

        $catalogo = $this->definition('en_catalogo', 'bool') + ['origen' => 'tarifario'];
        $tarifa = $this->definition('en_tarifa', 'bool') + ['origen' => 'tarifario'];
        $resolver = $this->buildResolver([$catalogo, $tarifa], ['tarifario']);

        $this->assertSame(['en_catalogo', 'en_tarifa'], $resolver->active_visibility_codigos());
        $this->assertSame(
            1,
            CaracteristicaResolverFakeDefinitionModel::$allCalls,
            'one definition read serves the whole request'
        );

        $resolver->active_visibility_codigos();
        $resolver->is_visibility_active('en_tarifa');
        $resolver->is_visibility_active('en_catalogo');

        $this->assertSame(
            1,
            CaracteristicaResolverFakeDefinitionModel::$allCalls,
            'the accessor reuses the memoized definitions() map: no extra query'
        );
    }

    // =====================================================================
    // CAR-04 — empty vs absent
    // =====================================================================

    public function test_empty_string_and_absence_stay_distinguishable(): void
    {
        $this->rows = [
            'articulo' => ['T1' => ['REF-1' => [10 => $this->scopeRow(null, '', true)]]],
        ];
        $resolver = $this->buildResolver([$this->definition('color')]);

        $this->assertSame('', $resolver->resolve('color', 'T1', 'REF-1'), 'a stored empty string is a value');
        $this->assertNull($resolver->resolve('color', 'T1', 'REF-2'), 'no row is "no value"');
    }

    // =====================================================================
    // CAR-07 — reads never persist
    // =====================================================================

    public function test_resolution_issues_no_writes(): void
    {
        $this->rows = ['global' => ['T1' => [10 => $this->scopeRow(null, 'g', true)]]];
        $resolver = $this->buildResolver([$this->definition('color')]);

        $resolver->resolve('color', 'T1', 'REF-1');
        $resolver->resolve('color', 'T2', 'REF-1');
        $resolver->resolve_bool('color', 'T3');

        $this->assertSame([], $this->writes, 'resolving must never write');
    }

    public function test_reading_does_not_materialize_an_inherited_value(): void
    {
        $this->rows = ['articulo' => ['DEF' => ['REF-1' => [10 => $this->scopeRow(null, 'DEF', true)]]]];
        $resolver = $this->buildResolver([$this->definition('color')]);

        $this->assertSame('DEF', $resolver->resolve('color', 'T2', 'REF-1'));
        $this->assertArrayNotHasKey('T2', $this->rows['articulo'], 'no T2 row may be created by a read');
        $this->assertSame(
            ['DEF' => ['REF-1' => [10 => $this->scopeRow(null, 'DEF', true)]]],
            $this->rows['articulo']
        );
    }
}
