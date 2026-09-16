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
 * CAR-12 — D12 opcional visibility derivation.
 *
 * An opcional owns no visibility flag. Its effective catalog/tarifa
 * visibility is the **existential union** over its parents' effective feature
 * value for the selected tarifa:
 *
 *  - article-attached (direct `catalogo_articulo_opcional` or via
 *    `catalogo_articulo_opcional_grupo`) → the parent article's effective value;
 *  - family-assigned (`catalogo_opcional_familias`) → the family's effective
 *    value (family walk with `madre`);
 *  - unassigned → the global scope value only;
 *  - several parents → visible when **any** parent is visible.
 *
 * The resolver is exercised DB-free: an anonymous subclass overrides the
 * model/seam methods and answers from in-memory maps; the parent discovery
 * goes through a spy `db()` that also counts queries.
 */
final class OpcionalVisibilityDerivationTest extends TestCase
{
    private const CARACTERISTICA_ID = 20;

    /** @var array<string, array<string, array<string, array<int, array<string, mixed>>>>> */
    private array $rows = [];

    /** @var array<string, string> */
    private array $familias = [];

    /** @var array<int, string> */
    private array $catalog = [201 => '1', 202 => '0'];

    /** @var array<string, string> */
    private array $articuloFamilias = [];

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
        $articuloFamilias = &$this->articuloFamilias;

        return new class($db, $rows, $familias, $catalog, $articuloFamilias) extends \FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver {
            private const CARACTERISTICA_ID = 20;

            public function __construct(
                private OpcionalVisibilitySpyDb $spy,
                private array &$rows,
                private array &$familias,
                private array &$catalog,
                private array &$articuloFamilias
            ) {
            }

            protected function db()
            {
                return $this->spy;
            }

            protected function definition_model()
            {
                return new OpcionalVisibilityFakeDefinitionModel([[
                    'id' => self::CARACTERISTICA_ID,
                    'codigo' => 'en_catalogo',
                    'nombre' => 'En Catalogo',
                    'tipo' => 'bool',
                    'activo' => true,
                    'valor_defecto' => null,
                ]]);
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
                return $this->articuloFamilias[$referencia] ?? null;
            }
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function scopeRow(?int $idValor = null, ?string $valor = null, bool $custom = false): array
    {
        return ['id_valor' => $idValor, 'valor' => $valor, 'custom' => $custom];
    }

    private function boolRow(bool $visible): array
    {
        return $visible ? $this->scopeRow(201, null, false) : $this->scopeRow(202, null, false);
    }

    // =====================================================================
    // CAR-12 — the four derivation scenarios
    // =====================================================================

    public function test_opcional_follows_its_single_parent_product(): void
    {
        $db = new OpcionalVisibilitySpyDb(articles: [7 => ['REF-1']]);
        $this->rows = [
            'articulo' => ['T1' => ['REF-1' => [self::CARACTERISTICA_ID => $this->boolRow(false)]]],
        ];
        $resolver = $this->buildResolver($db);

        $this->assertFalse(
            $resolver->resolve_opcional_visibility(7, 'T1', 'en_catalogo'),
            'the opcional must inherit its parent product FALSE value'
        );

        // The parent product is set TRUE: the opcional becomes visible.
        $this->rows['articulo']['T1']['REF-1'][self::CARACTERISTICA_ID] = $this->boolRow(true);

        $this->assertTrue($resolver->resolve_opcional_visibility(7, 'T1', 'en_catalogo'));
    }

    public function test_multi_parent_existential_union(): void
    {
        $db = new OpcionalVisibilitySpyDb(articles: [7 => ['REF-A', 'REF-B']]);
        $this->rows = [
            'articulo' => [
                'T1' => [
                    'REF-A' => [self::CARACTERISTICA_ID => $this->boolRow(false)],
                    'REF-B' => [self::CARACTERISTICA_ID => $this->boolRow(true)],
                ],
            ],
        ];
        $resolver = $this->buildResolver($db);

        $this->assertTrue(
            $resolver->resolve_opcional_visibility(7, 'T1', 'en_catalogo'),
            'any visible parent makes the opcional visible'
        );
    }

    public function test_multi_parent_all_false_is_not_visible(): void
    {
        $db = new OpcionalVisibilitySpyDb(articles: [7 => ['REF-A', 'REF-B']]);
        $this->rows = [
            'articulo' => [
                'T1' => [
                    'REF-A' => [self::CARACTERISTICA_ID => $this->boolRow(false)],
                    'REF-B' => [self::CARACTERISTICA_ID => $this->boolRow(false)],
                ],
            ],
        ];
        $resolver = $this->buildResolver($db);

        $this->assertFalse($resolver->resolve_opcional_visibility(7, 'T1', 'en_catalogo'));
    }

    public function test_family_assigned_opcional_uses_the_family_value(): void
    {
        $db = new OpcionalVisibilitySpyDb(families: [7 => ['F1']]);
        $this->rows = [
            'familia' => ['T1' => ['F1' => [self::CARACTERISTICA_ID => $this->boolRow(false)]]],
            'global' => ['T2' => [self::CARACTERISTICA_ID => $this->boolRow(true)]],
        ];
        $resolver = $this->buildResolver($db);

        $this->assertFalse(
            $resolver->resolve_opcional_visibility(7, 'T1', 'en_catalogo'),
            'the assigned family value drives the opcional for T1'
        );
        $this->assertTrue(
            $resolver->resolve_opcional_visibility(7, 'T2', 'en_catalogo'),
            'another tarifa is unaffected by the T1 family row'
        );
    }

    public function test_unassigned_opcional_follows_the_global_value(): void
    {
        $db = new OpcionalVisibilitySpyDb();
        $this->rows = [
            'global' => [
                'T1' => [self::CARACTERISTICA_ID => $this->boolRow(true)],
                'T3' => [self::CARACTERISTICA_ID => $this->boolRow(false)],
            ],
        ];
        $resolver = $this->buildResolver($db);

        $this->assertTrue($resolver->resolve_opcional_visibility(7, 'T1', 'en_catalogo'));
        $this->assertFalse($resolver->resolve_opcional_visibility(7, 'T3', 'en_catalogo'));
        $this->assertNull(
            $resolver->resolve_opcional_visibility(7, 'T9', 'en_catalogo'),
            'no global row means "no value", never FALSE'
        );
    }

    // =====================================================================
    // Group parents, family walk, mixed absence
    // =====================================================================

    public function test_grouped_opcional_uses_the_group_article_parents(): void
    {
        $db = new OpcionalVisibilitySpyDb(
            groups: [7 => 3],
            groupArticles: [3 => ['REF-G']]
        );
        $this->rows = [
            'articulo' => ['T1' => ['REF-G' => [self::CARACTERISTICA_ID => $this->boolRow(true)]]],
        ];
        $resolver = $this->buildResolver($db);

        $this->assertTrue(
            $resolver->resolve_opcional_visibility(7, 'T1', 'en_catalogo'),
            'a grouped opcional is article-attached through its group relation'
        );
    }

    public function test_article_parent_seeds_the_family_walk(): void
    {
        $db = new OpcionalVisibilitySpyDb(articles: [7 => ['REF-1']]);
        $this->articuloFamilias = ['REF-1' => 'F2'];
        $this->familias = ['F2' => 'F1'];
        $this->rows = [
            'familia' => ['T1' => ['F1' => [self::CARACTERISTICA_ID => $this->boolRow(true)]]],
        ];
        $resolver = $this->buildResolver($db);

        $this->assertTrue(
            $resolver->resolve_opcional_visibility(7, 'T1', 'en_catalogo'),
            'the article scope walk reaches the article family and its madre'
        );
    }

    public function test_a_present_false_parent_wins_over_an_absent_parent(): void
    {
        $db = new OpcionalVisibilitySpyDb(articles: [7 => ['REF-A', 'REF-B']]);
        $this->rows = [
            'articulo' => ['T1' => ['REF-A' => [self::CARACTERISTICA_ID => $this->boolRow(false)]]],
        ];
        $resolver = $this->buildResolver($db);

        $this->assertFalse(
            $resolver->resolve_opcional_visibility(7, 'T1', 'en_catalogo'),
            'absence is not TRUE: a present FALSE parent yields FALSE'
        );
    }

    public function test_unknown_definition_yields_no_value(): void
    {
        $db = new OpcionalVisibilitySpyDb(articles: [7 => ['REF-1']]);
        $resolver = $this->buildResolver($db);

        $this->assertNull($resolver->resolve_opcional_visibility(7, 'T1', 'desconocida'));
    }

    // =====================================================================
    // Batch API + bounded query count
    // =====================================================================

    public function test_batch_resolution_matches_the_single_resolution(): void
    {
        $db = new OpcionalVisibilitySpyDb(articles: [7 => ['REF-A'], 8 => ['REF-B']]);
        $this->rows = [
            'articulo' => [
                'T1' => [
                    'REF-A' => [self::CARACTERISTICA_ID => $this->boolRow(false)],
                    'REF-B' => [self::CARACTERISTICA_ID => $this->boolRow(true)],
                ],
            ],
        ];
        $resolver = $this->buildResolver($db);

        $batch = $resolver->resolve_opcionales_visibility([7, 8], 'T1', 'en_catalogo');

        $this->assertSame([7 => false, 8 => true], $batch);
    }

    public function test_query_count_is_bounded_and_independent_of_the_page_size(): void
    {
        $single = new OpcionalVisibilitySpyDb(articles: [7 => ['REF-A']]);
        $this->buildResolver($single)->resolve_opcionales_visibility([7], 'T1', 'en_catalogo');

        $page = new OpcionalVisibilitySpyDb(articles: [7 => ['REF-A'], 8 => ['REF-B'], 9 => ['REF-C']]);
        $this->buildResolver($page)->resolve_opcionales_visibility([7, 8, 9], 'T1', 'en_catalogo');

        $this->assertSame(
            count($single->selectStatements),
            count($page->selectStatements),
            'the parent discovery query count must not depend on the number of opcionales'
        );
        $this->assertLessThanOrEqual(4, count($page->selectStatements));
    }

    // =====================================================================
    // The D12 rule is stated explicitly in the code
    // =====================================================================

    public function test_the_existential_union_rule_is_documented_on_the_resolver(): void
    {
        $source = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaResolver.php'
        );

        $this->assertStringContainsString('existential union', $source);
        $this->assertMatchesRegularExpression(
            '/any[^\n]*parent[^\n]*visible/i',
            $source,
            'the resolver must state that any visible parent makes the opcional visible'
        );
    }
}
