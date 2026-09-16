<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/opcional_visibility_fakes.php';

/**
 * CAR-12 — behaviour-preservation parity between the pre-change opcional flags
 * and the post-change derived visibility. This test gates the CAR-15 clause-1
 * column drop: the derived set MUST be identical to the legacy set for every
 * case (article-attached, family-assigned, unassigned, multi-parent).
 *
 * The legacy oracle is the pre-change `tarif_tarifa_opcional.effective()` value
 * (`en_catalogo` master row, else the `tarif_opcional_ext` flag, else the
 * master default TRUE). The feature values in the fixture are exactly the rows
 * `CaracteristicaBackfillMigration` materializes from those flags (CAR-13
 * e1–e3, proven by `CaracteristicaBackfillTest`), so comparing the derived map
 * against the legacy map exercises the drop's parity precondition.
 *
 * `DEF` is used as the feature tarifa because the backfill seeds opcional
 * visibility on the `DEF` tarifa (the legacy flags were tarifa-agnostic).
 */
final class OpcionalVisibilityParityTest extends TestCase
{
    private const CARACTERISTICA_ID = 20;

    private const EN_TARIFA_ID = 21;

    /** @var array<string, array<string, array<string, array<int, array<string, mixed>>>>> */
    private array $rows = [];

    /** @var array<string, string> */
    private array $familias = [];

    /** @var array<int, string> */
    private array $catalog = [201 => '1', 202 => '0'];

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
        self::$baseLoaded = true;
    }

    private function buildResolver(OpcionalVisibilitySpyDb $db): object
    {
        $rows = &$this->rows;
        $familias = &$this->familias;
        $catalog = &$this->catalog;

        return new class($db, $rows, $familias, $catalog) extends \FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver {
            private const CARACTERISTICA_ID = 20;

            private const EN_TARIFA_ID = 21;

            public function __construct(
                private OpcionalVisibilitySpyDb $spy,
                private array &$rows,
                private array &$familias,
                private array &$catalog
            ) {
            }

            protected function db()
            {
                return $this->spy;
            }

            protected function definition_model()
            {
                return new OpcionalVisibilityFakeDefinitionModel([
                    [
                        'id' => self::CARACTERISTICA_ID,
                        'codigo' => 'en_catalogo',
                        'nombre' => 'En Catalogo',
                        'tipo' => 'bool',
                        'activo' => true,
                        'valor_defecto' => null,
                    ],
                    [
                        'id' => self::EN_TARIFA_ID,
                        'codigo' => 'en_tarifa',
                        'nombre' => 'En Tarifa',
                        'tipo' => 'bool',
                        'activo' => true,
                        'valor_defecto' => null,
                    ],
                ]);
            }

            protected function scope_model(string $scope)
            {
                return new OpcionalVisibilityFakeScopeModel($this->rows, $scope);
            }

            protected function familia_madre(string $codfamilia): ?string
            {
                return $this->familias[$codfamilia] ?? null;
            }

            protected function catalog_value(int $idValor): ?string
            {
                return $this->catalog[$idValor] ?? null;
            }

            protected function articulo_family_lookup(string $referencia): ?string
            {
                return null;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function flagRow(bool $visible): array
    {
        return ['id_valor' => $visible ? 201 : 202, 'valor' => null, 'custom' => false];
    }

    /**
     * The pre-change oracle: the opcional flag as stored on the master/ext row.
     * Never NULL: the legacy surface always had a boolean value.
     *
     * @return array<int, bool>
     */
    private function legacySet(): array
    {
        return [
            1 => true,
            2 => false,
            3 => true,
            4 => false,
            5 => true,
            6 => false,
            7 => true,
            8 => false,
        ];
    }

    /**
     * Feature rows the CAR-13 backfill materializes from the legacy flags
     * above (article-attached → parent article; family-assigned → family;
     * unassigned → global). Every opcional keeps its own historical surface.
     *
     * @param array<int, bool> $legacy
     */
    private function seedBackfilledFeatureValues(array $legacy): void
    {
        $this->rows = [
            'articulo' => [
                'DEF' => [
                    'REF-A' => [self::CARACTERISTICA_ID => $this->flagRow($legacy[1])],
                    'REF-B' => [self::CARACTERISTICA_ID => $this->flagRow($legacy[2])],
                    // 7 => TRUE is only materialized on the first of its two parents.
                    'REF-M1' => [self::CARACTERISTICA_ID => $this->flagRow($legacy[7])],
                    // 8 => FALSE is materialized on both parents.
                    'REF-N1' => [self::CARACTERISTICA_ID => $this->flagRow($legacy[8])],
                    'REF-N2' => [self::CARACTERISTICA_ID => $this->flagRow($legacy[8])],
                ],
            ],
            'familia' => [
                'DEF' => [
                    'FAM-A' => [self::CARACTERISTICA_ID => $this->flagRow($legacy[3])],
                    'FAM-B' => [self::CARACTERISTICA_ID => $this->flagRow($legacy[4])],
                ],
            ],
            'global' => [
                'DEF' => [self::CARACTERISTICA_ID => $this->flagRow($legacy[5])],
            ],
        ];

        // The unassigned opcional 6 also uses the global row: give it its own
        // tarifa so the two global-driven cases stay distinguishable.
        $this->rows['global']['T6'] = [self::CARACTERISTICA_ID => $this->flagRow($legacy[6])];
    }

    private function spyDb(): OpcionalVisibilitySpyDb
    {
        return new OpcionalVisibilitySpyDb(
            articles: [
                1 => ['REF-A'],
                2 => ['REF-B'],
                7 => ['REF-M1', 'REF-M2'],
                8 => ['REF-N1', 'REF-N2'],
            ],
            families: [
                3 => ['FAM-A'],
                4 => ['FAM-B'],
            ]
        );
    }

    public function test_derived_visibility_is_identical_to_the_pre_change_flag_set(): void
    {
        $legacy = $this->legacySet();
        $this->seedBackfilledFeatureValues($legacy);
        $resolver = $this->buildResolver($this->spyDb());

        $derived = $resolver->resolve_opcionales_visibility(
            [1, 2, 3, 4, 7, 8],
            'DEF',
            'en_catalogo'
        );

        $this->assertGreaterThanOrEqual(
            6,
            count($derived),
            'the parity comparison must exercise at least 6 legacy values (not a vacuous pass)'
        );

        foreach ([1, 2, 3, 4, 7, 8] as $id) {
            $this->assertSame(
                $legacy[$id],
                $derived[$id] ?? null,
                'the derived visibility of opcional ' . $id . ' must reproduce its pre-change flag'
            );
        }

        // The unassigned opcional 5 follows the global DEF row: run it with the
        // same derived set, its own tarifa family falling back to DEF.
        $unassigned = $this->buildResolver(new OpcionalVisibilitySpyDb());
        $this->assertTrue($unassigned->resolve_opcional_visibility(5, 'DEF', 'en_catalogo'));
        $this->assertFalse($unassigned->resolve_opcional_visibility(6, 'T6', 'en_catalogo'));
        $this->assertTrue($legacy[5]);
        $this->assertFalse($legacy[6]);
    }

    public function test_a_multi_parent_opcional_keeps_the_legacy_true_value(): void
    {
        $legacy = $this->legacySet();
        $this->seedBackfilledFeatureValues($legacy);
        $resolver = $this->buildResolver($this->spyDb());

        $this->assertTrue(
            $resolver->resolve_opcional_visibility(7, 'DEF', 'en_catalogo'),
            'a TRUE legacy flag on a multi-parent opcional stays TRUE when any parent is visible'
        );
    }

    public function test_a_multi_parent_opcional_keeps_the_legacy_false_value(): void
    {
        $legacy = $this->legacySet();
        $this->seedBackfilledFeatureValues($legacy);
        $resolver = $this->buildResolver($this->spyDb());

        $this->assertFalse(
            $resolver->resolve_opcional_visibility(8, 'DEF', 'en_catalogo'),
            'a FALSE legacy flag stays FALSE when every parent is present FALSE'
        );
    }

    public function test_parity_holds_for_the_en_tarifa_flag_surface(): void
    {
        // The other dropped flag rides the same derivation: the parity argument
        // is per flag, not type-specific.
        $legacy = [1 => false, 2 => true];
        $this->rows = [
            'articulo' => [
                'DEF' => [
                    'REF-A' => [self::EN_TARIFA_ID => $this->flagRow($legacy[1])],
                    'REF-B' => [self::EN_TARIFA_ID => $this->flagRow($legacy[2])],
                ],
            ],
        ];
        $resolver = $this->buildResolver(new OpcionalVisibilitySpyDb(articles: [1 => ['REF-A'], 2 => ['REF-B']]));

        $this->assertSame(
            $legacy,
            $resolver->resolve_opcionales_visibility([1, 2], 'DEF', 'en_tarifa'),
            'the en_tarifa surface must reproduce its own legacy flag set'
        );
    }
}
