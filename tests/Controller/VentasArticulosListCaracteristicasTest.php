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
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaValorBatchReader.php';
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

    private function host(): ListCaracteristicasHost
    {
        return new ListCaracteristicasHost();
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
