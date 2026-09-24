<?php
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\model\catalogo_opcional;
use FSFramework\model\catalogo_opcional_grupo;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_functions.php';
require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo_rel.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_precio.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional_grupo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
require_once FS_FOLDER . '/plugins/tpvmod/lib/tpvmod_opcionales.php';

/**
 * DB-free fake connection emulating the group/articulo/opcional reads that
 * resolve membership through the `catalogo_opcional_grupo_rel` bridge (OPG-06,
 * OPG-07, OPG-09). Mirrors the fake-db style of `CatalogoOpcionalMembershipTest`.
 */
final class GrupoReaderFakeDb extends \fs_db2
{
    /** @var array<int, array<string, mixed>> id => opcional row */
    public array $opcionales = [];

    /** @var array<int, array<string, mixed>> id => group row */
    public array $groups = [];

    /** @var list<array{id_opcional:int, id_grupo:int}> bridge pairs */
    public array $bridge = [];

    /** @var array<string, list<int>> referencia => direct id_opcional */
    public array $articuloOpcional = [];

    /** @var array<string, list<int>> referencia => id_grupo */
    public array $articuloGrupo = [];

    /** @var list<string> */
    public array $selects = [];

    /** @var list<string> */
    public array $selectLimits = [];

    /**
     * @param array<int, array<string, mixed>> $opcionales
     * @param array<int, array<string, mixed>> $groups
     * @param list<array{0:int,1:int}>         $bridge
     * @param array<string, list<int>>         $articuloOpcional
     * @param array<string, list<int>>         $articuloGrupo
     */
    public function __construct(
        array $opcionales = [],
        array $groups = [],
        array $bridge = [],
        array $articuloOpcional = [],
        array $articuloGrupo = []
    ) {
        // Deliberately skip parent::__construct(): no engine, no DB connection.
        $this->opcionales = $opcionales;
        $this->groups = $groups;
        foreach ($bridge as $pair) {
            $this->bridge[] = ['id_opcional' => (int) $pair[0], 'id_grupo' => (int) $pair[1]];
        }
        $this->articuloOpcional = $articuloOpcional;
        $this->articuloGrupo = $articuloGrupo;
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
        $sql = trim((string) $sql);
        $this->selects[] = $sql;

        if (preg_match(
            '/^SELECT o\.\* FROM catalogo_opcionales o INNER JOIN catalogo_opcional_grupo_rel r'
            . ' ON r\.id_opcional = o\.id WHERE r\.id_grupo = (\d+) AND o\.activo = TRUE'
            . ' ORDER BY o\.nombre ASC;?$/',
            $sql,
            $m
        )) {
            return $this->opcionalesInGroup((int) $m[1], true);
        }

        if (preg_match(
            '/^SELECT o\.\* FROM catalogo_opcionales o INNER JOIN catalogo_opcional_grupo_rel r'
            . ' ON r\.id_opcional = o\.id WHERE r\.id_grupo = (\d+)'
            . ' ORDER BY o\.nombre ASC;?$/',
            $sql,
            $m
        )) {
            return $this->opcionalesInGroup((int) $m[1], false);
        }

        if (preg_match('/^SELECT COUNT\(\*\) as total FROM catalogo_opcional_grupo_rel WHERE id_grupo = (\d+);?$/', $sql, $m)) {
            return [['total' => $this->countInGroup((int) $m[1])]];
        }

        if (preg_match(
            "/^SELECT g\.\*, ag\.obligatorio AS obligatorio_en_articulo FROM catalogo_opcional_grupos g"
            . ' INNER JOIN catalogo_articulo_opcional_grupo ag ON g\.id = ag\.id_grupo'
            . " WHERE ag\.referencia = '([^']*)' AND g\.activo = TRUE"
            . ' ORDER BY g\.orden ASC, g\.nombre ASC;?$/',
            $sql,
            $m
        )) {
            return $this->groupsForArticulo($m[1]);
        }

        if (preg_match(
            "/^SELECT o\.\*, ao\.obligatorio AS obligatorio_en_articulo FROM catalogo_opcionales o"
            . ' INNER JOIN catalogo_articulo_opcional ao ON o\.id = ao\.id_opcional'
            . " WHERE ao\.referencia = '([^']*)'"
            . ' AND NOT EXISTS \(SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r\.id_opcional = o\.id\)'
            . ' ORDER BY o\.nombre ASC;?$/',
            $sql,
            $m
        )) {
            return $this->directLooseOpcionales($m[1]);
        }

        if (preg_match(
            '/^SELECT id_opcional, id_grupo FROM catalogo_opcional_grupo_rel WHERE id_opcional IN \(([^)]*)\);?$/',
            $sql,
            $m
        )) {
            return $this->bridgeRowsFor($m[1]);
        }

        return [];
    }

    public function select_limit($sql, $limit = FS_ITEM_LIMIT, $offset = 0, $params = [])
    {
        $sql = trim((string) $sql);
        $this->selectLimits[] = $sql;

        if (preg_match(
            '/^SELECT \* FROM catalogo_opcionales o WHERE NOT EXISTS'
            . ' \(SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r\.id_opcional = o\.id'
            . ' AND r\.id_grupo = (\d+)\) ORDER BY o\.nombre ASC$/',
            $sql,
            $m
        )) {
            return $this->notInGroup((int) $m[1]);
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        return true;
    }

    public function lastval()
    {
        return 1;
    }

    /** @return list<array<string, mixed>> */
    private function opcionalesInGroup(int $idGrupo, bool $onlyActive): array
    {
        $rows = [];
        foreach ($this->bridge as $pair) {
            if ($pair['id_grupo'] !== $idGrupo || !isset($this->opcionales[$pair['id_opcional']])) {
                continue;
            }
            $row = $this->opcionales[$pair['id_opcional']];
            if ($onlyActive && !$row['activo']) {
                continue;
            }
            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['nombre'], (string) $b['nombre']));

        return $rows;
    }

    private function countInGroup(int $idGrupo): int
    {
        $total = 0;
        foreach ($this->bridge as $pair) {
            if ($pair['id_grupo'] === $idGrupo) {
                $total++;
            }
        }

        return $total;
    }

    /** @return list<array<string, mixed>> */
    private function groupsForArticulo(string $referencia): array
    {
        $rows = [];
        foreach ($this->articuloGrupo[$referencia] ?? [] as $idGrupo) {
            if (!isset($this->groups[$idGrupo]) || !$this->groups[$idGrupo]['activo']) {
                continue;
            }
            $row = $this->groups[$idGrupo];
            $row['obligatorio_en_articulo'] = false;
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function directLooseOpcionales(string $referencia): array
    {
        $rows = [];
        foreach ($this->articuloOpcional[$referencia] ?? [] as $idOpcional) {
            if (!isset($this->opcionales[$idOpcional]) || $this->hasBridge($idOpcional)) {
                continue;
            }
            $row = $this->opcionales[$idOpcional];
            $row['obligatorio_en_articulo'] = false;
            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['nombre'], (string) $b['nombre']));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function bridgeRowsFor(string $inList): array
    {
        $ids = [];
        foreach (explode(',', $inList) as $part) {
            $value = trim($part, " \t\n\r\0\x0B'\"");
            if ($value !== '' && ctype_digit($value)) {
                $ids[] = (int) $value;
            }
        }

        $rows = [];
        foreach ($this->bridge as $pair) {
            if (!in_array($pair['id_opcional'], $ids, true)) {
                continue;
            }
            $rows[] = ['id_opcional' => $pair['id_opcional'], 'id_grupo' => $pair['id_grupo']];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function notInGroup(int $idGrupo): array
    {
        $rows = [];
        foreach ($this->opcionales as $id => $row) {
            if ($this->hasPair((int) $id, $idGrupo)) {
                continue;
            }
            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['nombre'], (string) $b['nombre']));

        return $rows;
    }

    private function hasBridge(int $idOpcional): bool
    {
        foreach ($this->bridge as $pair) {
            if ($pair['id_opcional'] === $idOpcional) {
                return true;
            }
        }

        return false;
    }

    private function hasPair(int $idOpcional, int $idGrupo): bool
    {
        foreach ($this->bridge as $pair) {
            if ($pair['id_opcional'] === $idOpcional && $pair['id_grupo'] === $idGrupo) {
                return true;
            }
        }

        return false;
    }
}

/**
 * `catalogo_opcional_grupo` double over {@see GrupoReaderFakeDb}.
 */
final class GrupoReaderGrupoStub extends \FSFramework\model\catalogo_opcional_grupo
{
    public static ?GrupoReaderFakeDb $fakeDb = null;

    public function __construct($data = false)
    {
        parent::__construct($data);
        $this->db = self::$fakeDb;
    }
}

/**
 * `catalogo_opcional` double for the group-editor available list (OPG-07).
 */
final class GrupoReaderOpcionalStub extends \FSFramework\model\catalogo_opcional
{
    public static ?GrupoReaderFakeDb $fakeDb = null;

    public function __construct($data = false)
    {
        parent::__construct($data);
        $this->db = self::$fakeDb;
        $this->table_name = 'catalogo_opcionales';
    }
}

/**
 * Bridge model double for the rows-per-pair assertions (OPG-03).
 */
final class GrupoReaderBridgeStub extends \FSFramework\model\catalogo_opcional_grupo_rel
{
    public function __construct(GrupoReaderFakeDb $db)
    {
        $this->db = $db;
        $this->table_name = 'catalogo_opcional_grupo_rel';
        $this->id = null;
        $this->id_opcional = null;
        $this->id_grupo = null;
    }
}

/**
 * Article↔group bridge double: runs the real query against the fake and
 * re-wraps each group so its own reads share the fake connection.
 */
final class GrupoReaderArticuloGrupoStub extends \FSFramework\model\catalogo_articulo_opcional_grupo
{
    public function __construct(GrupoReaderFakeDb $db)
    {
        $this->db = $db;
        $this->table_name = 'catalogo_articulo_opcional_grupo';
    }

    public function get_grupos_from_articulo(string $referencia): array
    {
        $grupos = parent::get_grupos_from_articulo($referencia);
        $out = [];
        foreach ($grupos as $grupo) {
            GrupoReaderGrupoStub::$fakeDb = $this->db;
            $out[] = new GrupoReaderGrupoStub([
                'id' => $grupo->id,
                'codigo' => $grupo->codigo,
                'nombre' => $grupo->nombre,
                'exclusivo' => $grupo->exclusivo,
                'activo' => $grupo->activo,
                'orden' => $grupo->orden,
            ]);
        }

        return $out;
    }
}

/**
 * `catalogo_articulo_opcional` double exposing the group-relation seam.
 */
final class GrupoReaderDirectRelStub extends \FSFramework\model\catalogo_articulo_opcional
{
    public function __construct(private GrupoReaderFakeDb $fake)
    {
        $this->db = $fake;
        $this->table_name = 'catalogo_articulo_opcional';
    }

    protected function articulo_opcional_grupo_model(): \FSFramework\model\catalogo_articulo_opcional_grupo
    {
        return new GrupoReaderArticuloGrupoStub($this->fake);
    }
}

final class CatalogoOpcionalGrupoTest extends TestCase
{
    /** @return array<string, mixed> */
    private function opcionalRow(int $id, string $codigo, string $nombre, bool $activo = true): array
    {
        return [
            'id' => $id,
            'codigo' => $codigo,
            'nombre' => $nombre,
            'descripcion' => '',
            'precio' => 0,
            'tipo_precio' => catalogo_opcional::TIPO_PRECIO_FIJO,
            'porcentaje' => null,
            'activo' => $activo,
            'id_grupo' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function grupoRow(int $id, string $codigo, string $nombre, int $orden): array
    {
        return [
            'id' => $id,
            'codigo' => $codigo,
            'nombre' => $nombre,
            'exclusivo' => true,
            'activo' => true,
            'orden' => $orden,
        ];
    }

    private function grupo(GrupoReaderFakeDb $db, int $id): GrupoReaderGrupoStub
    {
        GrupoReaderGrupoStub::$fakeDb = $db;

        return new GrupoReaderGrupoStub($db->groups[$id]);
    }

    public function testGrupoModelGeneratesCodigoPrefix(): void
    {
        $grupo = new catalogo_opcional_grupo();
        $this->assertStringStartsWith('GRP', $grupo->get_new_codigo());
    }

    public function testUrlNuevoEnGrupoIncludesQueryParam(): void
    {
        $opcional = new catalogo_opcional();
        $this->assertSame(
            'index.php?page=ventas_opcional&id_grupo=3',
            $opcional->url_nuevo_en_grupo(3)
        );
    }

    public function testTpvmodBuildOpcionalItemIncludesGroupMetadata(): void
    {
        $opcional = new catalogo_opcional([
            'id' => 10,
            'codigo' => 'OPC0010',
            'nombre' => 'Rojo',
            'descripcion' => 'Rojo',
            'precio' => 5,
            'tipo_precio' => catalogo_opcional::TIPO_PRECIO_FIJO,
            'porcentaje' => null,
            'activo' => true,
            'id_grupo' => 2,
        ]);

        $grupo = new catalogo_opcional_grupo([
            'id' => 2,
            'codigo' => 'GRP0002',
            'nombre' => 'Color',
            'exclusivo' => true,
            'activo' => true,
            'orden' => 1,
        ]);

        $item = tpvmod_build_opcional_item($opcional, 100.0, 'DEF', $grupo);

        $this->assertSame(2, $item['grupo_id']);
        $this->assertSame('Color', $item['grupo_nombre']);
        $this->assertTrue($item['grupo_exclusivo']);
    }

    public function testTpvmodOpcionalesForArticuloReturnsGroupedPayloadWhenEmpty(): void
    {
        $this->assertSame(
            ['grupos' => [], 'sueltos' => [], 'codfamilia' => ''],
            tpvmod_opcionales_for_articulo('', 100.0)
        );
    }

    // =====================================================================
    // OPG-06 — group master variant list is bridge-backed
    // =====================================================================

    public function test_multi_group_opcional_appears_under_each_group(): void
    {
        $db = new GrupoReaderFakeDb(
            opcionales: [7 => $this->opcionalRow(7, 'OPC7', 'Blanco')],
            groups: [1 => $this->grupoRow(1, 'GRP0001', 'Color', 1), 2 => $this->grupoRow(2, 'GRP0002', 'Acabado', 2)],
            bridge: [[7, 1], [7, 2]]
        );

        $this->assertSame([7], $this->ids($this->grupo($db, 1)->get_opcionales()));
        $this->assertSame([7], $this->ids($this->grupo($db, 2)->get_opcionales()));
        $this->assertSame(1, $this->grupo($db, 1)->count_opcionales());
        $this->assertSame(1, $this->grupo($db, 2)->count_opcionales());
    }

    public function test_activos_read_is_bridge_backed(): void
    {
        $db = new GrupoReaderFakeDb(
            opcionales: [7 => $this->opcionalRow(7, 'OPC7', 'Blanco')],
            groups: [1 => $this->grupoRow(1, 'GRP0001', 'Color', 1), 2 => $this->grupoRow(2, 'GRP0002', 'Acabado', 2)],
            bridge: [[7, 1], [7, 2]]
        );

        $this->assertSame([7], $this->ids($this->grupo($db, 1)->get_opcionales_activos()));
        $this->assertSame([7], $this->ids($this->grupo($db, 2)->get_opcionales_activos()));

        foreach ($db->selects as $sql) {
            if (str_contains($sql, 'catalogo_opcional_grupo_rel r ON r.id_opcional = o.id')) {
                return;
            }
        }

        self::fail('the active variant read must join catalogo_opcional_grupo_rel, not the frozen column');
    }

    // =====================================================================
    // OPG-07 — group editor available opcionales
    // =====================================================================

    public function test_available_list_offers_member_of_another_group(): void
    {
        $db = new GrupoReaderFakeDb(
            opcionales: [7 => $this->opcionalRow(7, 'OPC7', 'Blanco')],
            bridge: [[7, 2]]
        );
        GrupoReaderOpcionalStub::$fakeDb = $db;
        $model = new GrupoReaderOpcionalStub();

        $this->assertSame([7], $this->ids($model->all_not_in_grupo(1)));
    }

    public function test_available_list_excludes_current_member(): void
    {
        $db = new GrupoReaderFakeDb(
            opcionales: [7 => $this->opcionalRow(7, 'OPC7', 'Blanco')],
            bridge: [[7, 1]]
        );
        GrupoReaderOpcionalStub::$fakeDb = $db;
        $model = new GrupoReaderOpcionalStub();

        $this->assertSame([], $this->ids($model->all_not_in_grupo(1)));
    }

    // =====================================================================
    // OPG-09 — single charge / group-scoped presentation
    // =====================================================================

    public function test_resolved_for_sale_read_yields_one_occurrence(): void
    {
        $db = new GrupoReaderFakeDb(
            opcionales: [7 => $this->opcionalRow(7, 'OPC7', 'Blanco')],
            groups: [1 => $this->grupoRow(1, 'GRP0001', 'Color', 1), 2 => $this->grupoRow(2, 'GRP0002', 'Acabado', 2)],
            bridge: [[7, 1], [7, 2]],
            articuloOpcional: ['ART' => [7]],
            articuloGrupo: ['ART' => [1, 2]]
        );

        $resolved = (new GrupoReaderDirectRelStub($db))->get_opcionales_from_articulo('ART');

        $this->assertSame([7], $this->ids($resolved), 'the resolved-for-sale read must dedupe by opcional id');
    }

    public function test_group_scoped_presentation_lists_each_group(): void
    {
        $db = new GrupoReaderFakeDb(
            opcionales: [7 => $this->opcionalRow(7, 'OPC7', 'Blanco')],
            groups: [1 => $this->grupoRow(1, 'GRP0001', 'Color', 1), 2 => $this->grupoRow(2, 'GRP0002', 'Acabado', 2)],
            bridge: [[7, 1], [7, 2]]
        );

        $this->assertSame([7], $this->ids($this->grupo($db, 1)->get_opcionales_activos()));
        $this->assertSame([7], $this->ids($this->grupo($db, 2)->get_opcionales_activos()));
    }

    // =====================================================================
    // OPG-03 — one bridge row per pair
    // =====================================================================

    public function test_bridge_map_returns_one_entry_per_pair(): void
    {
        $db = new GrupoReaderFakeDb(bridge: [[7, 1], [7, 2], [8, 1]]);

        $map = (new GrupoReaderBridgeStub($db))->map_for_opcionales([7, 8]);

        $this->assertSame([1, 2], $map[7]);
        $this->assertSame([1], $map[8]);
    }

    // =====================================================================
    // Dependent readers no longer read the frozen column
    // =====================================================================

    public function test_familia_propagation_iterates_every_group(): void
    {
        $source = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_familia.php'
        );

        foreach (['add_with_propagation', 'remove_with_propagation'] as $method) {
            $body = $this->methodBody($source, $method);
            $this->assertStringContainsString(
                'grupo_ids()',
                $body,
                $method . ' must iterate every membership of the opcional'
            );
            $this->assertStringNotContainsString(
                '->id_grupo',
                $body,
                $method . ' must not read the frozen single-valued column'
            );
        }
    }

    public function test_available_list_reader_is_bridge_backed_in_source(): void
    {
        $source = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php'
        );
        $body = $this->methodBody($source, 'loadOpcionalesDisponibles');

        $this->assertStringContainsString(
            'all_activos_sin_grupo(',
            $body,
            'the available list must delegate to the bridge anti-join'
        );
        $this->assertStringNotContainsString('id_grupo', $body);
    }

    /**
     * @param array<int, object> $models
     * @return list<int>
     */
    private function ids(array $models): array
    {
        return array_map(static fn ($model): int => (int) $model->id, $models);
    }

    private function methodBody(string $source, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{/';
        if (!preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
            self::fail('missing method: ' . $method);
        }

        $start = $matches[0][1] + strlen($matches[0][0]);
        $depth = 1;
        $length = strlen($source);
        for ($i = $start; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }

        self::fail('unterminated method body: ' . $method);
    }
}
