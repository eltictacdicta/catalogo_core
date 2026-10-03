<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore\Controller;

use FSFramework\Plugins\catalogo_core\Services\CaracteristicaValorBatchReader;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/base/fs_core_log.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/ArticuloTarifaPrecioBatchReader.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaOwnership.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaValorBatchReader.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaResolver.php';
require_once FS_FOLDER . '/plugins/catalogo_core/extras/VentasArticulosListTrait.php';

/**
 * Contract tests for the listable feature columns (CAR-16).
 *
 * Layer 1: trait behavior with a fake batch reader (ordering, Sí/No, the
 * neutral placeholder and the no-tarifa case).
 * Layer 2: the batched reader issues a query count independent of the page
 * size.
 * Layer 3: the view source keeps the base headers and appends only inside the
 * per-tarifa block, after Catálogo and before Stock.
 */
final class ListCaracteristicasFakeBatchReader extends CaracteristicaValorBatchReader
{
    /** @var array<string, array<string, ?string>> */
    public array $values = [];

    /** @var array<int, array<string, mixed>> */
    public array $cols = [];

    public function for_referencias(
        array $refs,
        string $codtarifa,
        ?array $familias = null,
        ?array $codigos = null
    ): array {
        return $this->values;
    }

    public function columns(?array $codigos = null): array
    {
        return $this->cols;
    }
}

final class ListCaracteristicasHost
{
    use \VentasArticulosListTrait;

    public $request;

    public ListCaracteristicasFakeBatchReader $reader;

    /** @var list<string> */
    public array $activeVisibility = ['en_catalogo', 'en_tarifa'];

    public int $accessorCalls = 0;

    public function __construct()
    {
        $this->reader = new ListCaracteristicasFakeBatchReader();
        $this->b_codtarifa = 'T1';
        $this->tarifa_seleccionada = null;
    }

    protected function caracteristica_batch_reader(): CaracteristicaValorBatchReader
    {
        return $this->reader;
    }

    protected function caracteristica_resolver(): \FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver
    {
        return new ListCaracteristicasVisibilityResolver($this->activeVisibility, $this);
    }
}

/**
 * DB-free resolver double: answers the active-visibility accessor from the
 * host fixture and counts how often it is evaluated.
 */
final class ListCaracteristicasVisibilityResolver extends \FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver
{
    /**
     * @param list<string> $codigos
     */
    public function __construct(private array $codigos, private ListCaracteristicasHost $host)
    {
    }

    public function active_visibility_codigos(): array
    {
        $this->host->accessorCalls++;

        return $this->codigos;
    }
}

final class VentasArticulosListCaracteristicasTest extends TestCase
{
    private const VIEW = 'plugins/catalogo_core/View/ventas_articulos.html.twig';

    /** Pre-change base header strings, in order. */
    private const BASE_HEADERS = [
        "'reference'|trans",
        "'description'|trans",
        "'family'|trans",
        "'manufacturer'|trans",
        "'price'|trans",
        'fsc.tarifa_seleccionada.nombre',
        '>Activo<',
        '>Tarifa<',
        '>Catálogo<',
        "'stock'|trans",
        "'actions'|trans",
    ];

    private static bool $baseLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$baseLoaded = true;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // CAR-20 fail-closed default: no plugin is enabled unless a test says so.
        global $plugins;
        $plugins = [];
    }

    private function host(): ListCaracteristicasHost
    {
        return new ListCaracteristicasHost();
    }

    /**
     * Operator-created row (`origen = ''`): always active.
     *
     * @return array<string, mixed>
     */
    private static function operator_definition_row(): array
    {
        return [
            'id' => 10,
            'codigo' => 'medidas',
            'nombre' => 'Medidas',
            'tipo' => 'string',
            'orden' => 1,
            'valor_defecto' => null,
            'origen' => '',
        ];
    }

    /**
     * Plugin-owned row: inert while its owner is disabled.
     *
     * @return array<string, mixed>
     */
    private static function inert_definition_row(int $id = 20, string $codigo = 'en_catalogo'): array
    {
        return [
            'id' => $id,
            'codigo' => $codigo,
            'nombre' => 'Plugin ' . $codigo,
            'tipo' => 'bool',
            'orden' => 2,
            'valor_defecto' => null,
            'origen' => 'tarifario',
        ];
    }

    // =====================================================================
    // CAR-16 — trait behavior
    // =====================================================================

    public function test_listable_columns_are_ordered_and_render_bool_and_string(): void
    {
        $host = $this->host();
        $host->tarifa_seleccionada = (object) ['codtarifa' => 'T1', 'nombre' => 'Base', 'coddivisa' => 'EUR'];
        $host->reader->cols = [
            ['codigo' => 'en_catalogo', 'nombre' => 'En Catálogo', 'tipo' => 'bool'],
            ['codigo' => 'medidas', 'nombre' => 'Medidas', 'tipo' => 'string'],
        ];
        $host->reader->values = [
            'REF-1' => ['en_catalogo' => '1', 'medidas' => 'XL'],
            'REF-2' => ['en_catalogo' => '0', 'medidas' => null],
        ];

        $host->load_caracteristica_columns(['REF-1', 'REF-2']);

        $columns = $host->listable_caracteristicas();
        $this->assertCount(2, $columns);
        $this->assertSame('en_catalogo', $columns[0]['codigo']);
        $this->assertSame('medidas', $columns[1]['codigo']);

        $this->assertSame('Sí', $host->caracteristica_cell('REF-1', 'en_catalogo'));
        $this->assertSame('No', $host->caracteristica_cell('REF-2', 'en_catalogo'));
        $this->assertSame('XL', $host->caracteristica_cell('REF-1', 'medidas'));
        $this->assertSame('-', $host->caracteristica_cell('REF-2', 'medidas'), 'no value renders the neutral placeholder');
    }

    public function test_no_tarifa_selected_emits_no_feature_column(): void
    {
        $host = $this->host();
        $host->tarifa_seleccionada = null;
        $host->reader->cols = [['codigo' => 'en_catalogo', 'nombre' => 'En Catálogo', 'tipo' => 'bool']];

        $this->assertSame([], $host->listable_caracteristicas());
    }

    // =====================================================================
    // VCG-01 — visibilidad_activa() controller/trait seam
    // =====================================================================

    public function test_visibilidad_activa_returns_the_accessor_list(): void
    {
        $host = $this->host();
        $host->activeVisibility = ['en_catalogo', 'en_tarifa'];

        $this->assertSame(['en_catalogo', 'en_tarifa'], $host->visibilidad_activa());
    }

    public function test_visibilidad_activa_memoizes_the_accessor_once(): void
    {
        $host = $this->host();
        $host->activeVisibility = ['en_tarifa'];

        $host->visibilidad_activa();
        $host->visibilidad_activa();
        $host->visibilidad_activa();

        $this->assertSame(1, $host->accessorCalls, 'the accessor must be evaluated once per request');
    }

    public function test_visibilidad_activa_is_empty_without_active_codigos(): void
    {
        $host = $this->host();
        $host->activeVisibility = [];

        $this->assertSame([], $host->visibilidad_activa());
    }

    // =====================================================================
    // CAR-16 — one batched read
    // =====================================================================

    public function test_batched_reader_query_count_is_independent_of_page_size(): void
    {
        $small = new ListCaracteristicasQueryCountingDb(2);
        $large = new ListCaracteristicasQueryCountingDb(10);

        (new ListCaracteristicasQueryCountingReader($small))->for_referencias(['R1', 'R2'], 'T1');
        (new ListCaracteristicasQueryCountingReader($large))->for_referencias(
            ['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10'],
            'T1'
        );

        $this->assertGreaterThan(0, $small->queries, 'the reader must actually query');
        $this->assertSame(
            $small->queries,
            $large->queries,
            'the query count must not grow with N'
        );
    }

    // =====================================================================
    // CAR-16 — owner-disabled (inert) definition emits no column
    // =====================================================================

    public function test_inert_listable_definition_emits_no_column_and_base_headers_stay_intact(): void
    {
        $reader = $this->mixedReader(2, [
            self::operator_definition_row(),
            self::inert_definition_row(),
        ]);

        $codigos = array_column($reader->columns(), 'codigo');
        $this->assertSame(
            ['medidas'],
            $codigos,
            'an owner-disabled listable definition must emit no column'
        );

        // CAR-16: the base header strings and their relative order stay byte-identical.
        $view = (string) file_get_contents(FS_FOLDER . '/' . self::VIEW);
        $theadStart = strpos($view, '<thead>');
        $theadEnd = strpos($view, '</thead>');
        $this->assertNotFalse($theadStart);
        $this->assertNotFalse($theadEnd);
        $thead = substr($view, (int) $theadStart, (int) $theadEnd - (int) $theadStart);

        $positions = [];
        foreach (self::BASE_HEADERS as $header) {
            $pos = strpos($thead, $header);
            $this->assertNotFalse($pos, 'base header missing from thead: ' . $header);
            $positions[] = $pos;
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'base header relative order must not change');
    }

    public function test_enabling_the_owner_restores_the_listable_column(): void
    {
        global $plugins;
        $plugins = ['tarifario'];

        $reader = $this->mixedReader(2, [
            self::operator_definition_row(),
            self::inert_definition_row(),
        ]);

        $codigos = array_column($reader->columns(), 'codigo');
        $this->assertSame(
            ['medidas', 'en_catalogo'],
            $codigos,
            'enabling the owner must restore the column on a fresh reader instance'
        );
    }

    public function test_feature_query_count_is_constant_across_page_size_and_inert_row_count(): void
    {
        $rowsWithOneInert = [
            self::operator_definition_row(),
            self::inert_definition_row(),
        ];
        $rowsWithManyInert = [
            self::operator_definition_row(),
            self::inert_definition_row(20, 'en_catalogo'),
            self::inert_definition_row(21, 'en_tarifa'),
            self::inert_definition_row(22, 'otra_caracteristica'),
            self::inert_definition_row(23, 'otra_mas'),
        ];

        $small = $this->mixedReader(2, $rowsWithOneInert);
        $large = $this->mixedReader(10, $rowsWithOneInert);
        $inertHeavy = $this->mixedReader(2, $rowsWithManyInert);

        $small->for_referencias(['R1', 'R2'], 'T1');
        $large->for_referencias(['R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10'], 'T1');
        $inertHeavy->for_referencias(['R1', 'R2'], 'T1');

        $smallQueries = $small->queryCount();
        $this->assertGreaterThan(0, $smallQueries, 'the reader must actually query');
        $this->assertSame($smallQueries, $large->queryCount(), 'the query count must not grow with N');
        $this->assertSame(
            $smallQueries,
            $inertHeavy->queryCount(),
            'inert rows must be filtered in PHP without adding a query'
        );

        // `for_referencias()` inherits the same filter as `columns()`.
        $values = $small->for_referencias(['R1', 'R2'], 'T1');
        $this->assertSame(['medidas'], array_keys($values['R1']), 'inert codigos must be absent from the value map');
    }

    public function test_no_tarifa_selected_emits_no_feature_column_for_inert_definitions(): void
    {
        $host = $this->host();
        $host->tarifa_seleccionada = null;
        $host->reader->cols = [
            ['codigo' => 'medidas', 'nombre' => 'Medidas', 'tipo' => 'string'],
            ['codigo' => 'en_catalogo', 'nombre' => 'En Catálogo', 'tipo' => 'bool'],
        ];

        $this->assertSame(
            [],
            $host->listable_caracteristicas(),
            'no selected tarifa must emit no feature column, even with plugin-owned definitions'
        );
    }

    /**
     * @param array<int, array<string, mixed>> $definitionRows
     */
    private function mixedReader(int $pageSize, array $definitionRows): ListCaracteristicasMixedDefinitionReader
    {
        return new ListCaracteristicasMixedDefinitionReader(
            new ListCaracteristicasDefinitionRowsDb($pageSize, $definitionRows)
        );
    }

    // =====================================================================
    // CAR-16 — view source
    // =====================================================================

    public function test_base_headers_and_order_are_byte_identical(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/' . self::VIEW);
        $theadStart = strpos($view, '<thead>');
        $theadEnd = strpos($view, '</thead>');
        $this->assertNotFalse($theadStart);
        $this->assertNotFalse($theadEnd);
        $thead = substr($view, (int) $theadStart, (int) $theadEnd - (int) $theadStart);

        $positions = [];
        foreach (self::BASE_HEADERS as $header) {
            $pos = strpos($thead, $header);
            $this->assertNotFalse($pos, 'base header missing from thead: ' . $header);
            $positions[] = $pos;
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'base header relative order must not change');
    }

    public function test_feature_column_loop_sits_after_catalogo_and_before_stock(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/' . self::VIEW);

        $catalogo = strpos($view, '>Catálogo<');
        $featureTh = strpos($view, 'fsc.listable_caracteristicas()');
        $stock = strpos($view, "'stock'|trans");

        $this->assertNotFalse($catalogo);
        $this->assertNotFalse($featureTh, 'the feature column loop must exist');
        $this->assertNotFalse($stock);
        $this->assertLessThan($stock, $featureTh, 'feature columns must render before Stock');
        $this->assertGreaterThan($catalogo, $featureTh, 'feature columns must render after Catálogo');

        // Inside the per-tarifa conditional block.
        $blockStart = strpos($view, '{% if fsc.tarifa_seleccionada %}');
        $blockEnd = strpos($view, '{% endif %}', $blockStart);
        $this->assertLessThan($blockEnd, $featureTh, 'feature columns must live inside the per-tarifa block');
    }

    public function test_empty_state_colspan_matches_the_rendered_column_count(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/' . self::VIEW);

        [$fixed, $tarifaOnly] = $this->theadColumnCounts($view);

        $this->assertSame(7, $fixed, 'five base columns plus Stock and Actions');
        $this->assertSame(4, $tarifaOnly, 'Tarifa price, Activo, Tarifa and Catálogo');

        $expected = 'colspan="{{ ' . $fixed
            . ' + (fsc.tarifa_seleccionada ? ' . $tarifaOnly
            . ' + fsc.listable_caracteristicas()|length : 0) }}"';

        $this->assertStringContainsString(
            $expected,
            $view,
            'the empty-state colspan must match the rendered column count in both tarifa states'
        );
    }

    /**
     * Derives the rendered column counts from the view markup: the headers that
     * always render and the ones that only render when a tarifa is selected,
     * excluding the variable feature loop (which stays as the `length` term).
     *
     * @return array{0: int, 1: int}
     */
    private function theadColumnCounts(string $view): array
    {
        $theadStart = strpos($view, '<thead>');
        $theadEnd = strpos($view, '</thead>');
        $this->assertNotFalse($theadStart);
        $this->assertNotFalse($theadEnd);
        $thead = substr($view, (int) $theadStart, (int) $theadEnd - (int) $theadStart);

        $ifStart = strpos($thead, '{% if fsc.tarifa_seleccionada %}');
        $ifEnd = strpos($thead, '{% endif %}');
        $this->assertNotFalse($ifStart);
        $this->assertNotFalse($ifEnd);

        $before = substr($thead, 0, (int) $ifStart);
        $conditional = substr($thead, (int) $ifStart, (int) $ifEnd - (int) $ifStart);
        $after = substr($thead, (int) $ifEnd);

        $loopStart = strpos($conditional, '{% for ');
        $loopEnd = strpos($conditional, '{% endfor %}');
        $this->assertNotFalse($loopStart);
        $this->assertNotFalse($loopEnd);
        $loop = substr($conditional, (int) $loopStart, (int) $loopEnd - (int) $loopStart + strlen('{% endfor %}'));

        return [
            substr_count($before, '<th ') + substr_count($after, '<th '),
            substr_count(str_replace($loop, '', $conditional), '<th '),
        ];
    }
}

/**
 * DB double counting SELECTs and returning shape-aware rows.
 */
final class ListCaracteristicasQueryCountingDb
{
    public int $queries = 0;

    public function __construct(private int $n)
    {
    }

    public function var2str($val)
    {
        if (is_array($val)) {
            return implode(',', array_map(fn ($v) => $this->var2str($v), $val));
        }
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
        $this->queries++;
        $sql = (string) $sql;

        if (str_contains($sql, 'FROM catalogo_caracteristicas')) {
            return [['id' => 10, 'codigo' => 'en_catalogo', 'nombre' => 'En Catálogo', 'tipo' => 'bool', 'orden' => 1, 'valor_defecto' => null]];
        }
        if (str_contains($sql, 'FROM catalogo_caracteristica_valores')) {
            return [['id' => 1, 'id_caracteristica' => 10, 'valor' => '1'], ['id' => 2, 'id_caracteristica' => 10, 'valor' => '0']];
        }
        if (str_contains($sql, 'FROM articulos')) {
            $rows = [];
            for ($i = 1; $i <= $this->n; $i++) {
                $rows[] = ['referencia' => 'R' . $i, 'codfamilia' => 'F1'];
            }
            return $rows;
        }
        if (str_contains($sql, 'FROM familias')) {
            return [['codfamilia' => 'F1', 'madre' => null]];
        }
        if (str_contains($sql, 'FROM catalogo_caracteristica_articulo')) {
            return [['codtarifa' => 'T1', 'referencia' => 'R1', 'id_caracteristica' => 10, 'id_valor' => 1, 'valor' => null, 'custom' => '0']];
        }
        if (str_contains($sql, 'FROM catalogo_caracteristica_familia')) {
            return [];
        }
        if (str_contains($sql, 'FROM catalogo_caracteristica_global')) {
            return [];
        }

        return [];
    }
}

final class ListCaracteristicasQueryCountingReader extends CaracteristicaValorBatchReader
{
    public function __construct(private ListCaracteristicasQueryCountingDb $db)
    {
    }

    protected function db()
    {
        return $this->db;
    }
}

/**
 * DB double returning caller-supplied definition rows (mixed active/inert) and
 * counting every SELECT. The real ownership helper is used through the default
 * seam, so the CAR-20 filter depends on `$GLOBALS['plugins']`.
 */
final class ListCaracteristicasDefinitionRowsDb
{
    public int $queries = 0;

    /**
     * @param array<int, array<string, mixed>> $definitionRows
     */
    public function __construct(private int $n, private array $definitionRows)
    {
    }

    public function var2str($val)
    {
        if (is_array($val)) {
            return implode(',', array_map(fn ($v) => $this->var2str($v), $val));
        }
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
        $this->queries++;
        $sql = (string) $sql;

        if (str_contains($sql, 'FROM catalogo_caracteristicas')) {
            return $this->definitionRows;
        }
        if (str_contains($sql, 'FROM catalogo_caracteristica_valores')) {
            return [['id' => 1, 'id_caracteristica' => 10, 'valor' => '1']];
        }
        if (str_contains($sql, 'FROM articulos')) {
            $rows = [];
            for ($i = 1; $i <= $this->n; $i++) {
                $rows[] = ['referencia' => 'R' . $i, 'codfamilia' => 'F1'];
            }

            return $rows;
        }
        if (str_contains($sql, 'FROM familias')) {
            return [['codfamilia' => 'F1', 'madre' => null]];
        }
        if (str_contains($sql, 'FROM catalogo_caracteristica_articulo')) {
            return [];
        }
        if (str_contains($sql, 'FROM catalogo_caracteristica_familia')) {
            return [];
        }
        if (str_contains($sql, 'FROM catalogo_caracteristica_global')) {
            return [];
        }

        return [];
    }
}

final class ListCaracteristicasMixedDefinitionReader extends CaracteristicaValorBatchReader
{
    public function __construct(private ListCaracteristicasDefinitionRowsDb $db)
    {
    }

    protected function db()
    {
        return $this->db;
    }

    public function queryCount(): int
    {
        return $this->db->queries;
    }
}
