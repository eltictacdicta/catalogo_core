<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_functions.php';
require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo_rel.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional_grupo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';

/**
 * DB-free fake connection emulating the statements issued by
 * `catalogo_opcional` membership operations and their cascades.
 */
final class MembershipFakeDb extends \fs_db2
{
    /** @var array<int, array<string, mixed>> */
    public array $opcionales = [];

    /** @var array<int, array<string, mixed>> */
    public array $groups = [];

    /** @var list<array<string, mixed>> */
    public array $bridge = [];

    /** @var list<string> */
    public array $executed = [];

    /** @var list<string> */
    public array $selects = [];

    /** @var list<string> */
    public array $selectLimits = [];

    /**
     * @param array<int, array<string, mixed>> $opcionales
     * @param array<int, array<string, mixed>> $groups
     * @param list<array{0:int,1:int}>          $bridge
     */
    public function __construct(array $opcionales = [], array $groups = [], array $bridge = [])
    {
        // Deliberately skip parent::__construct(): no engine, no DB connection.
        $this->opcionales = $opcionales;
        $this->groups = $groups;
        foreach ($bridge as $pair) {
            $this->bridge[] = [
                'id' => count($this->bridge) + 1,
                'id_opcional' => (int) $pair[0],
                'id_grupo' => (int) $pair[1],
            ];
        }
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

        if (preg_match('/^SELECT \* FROM catalogo_opcional_grupos WHERE id = (\d+);?$/', $sql, $m)) {
            $id = (int) $m[1];

            return isset($this->groups[$id]) ? [$this->groups[$id]] : [];
        }

        if (preg_match(
            '/^SELECT g\.\* FROM catalogo_opcional_grupos g INNER JOIN catalogo_opcional_grupo_rel r'
            . ' ON r\.id_grupo = g\.id WHERE r\.id_opcional = (\d+) ORDER BY g\.orden ASC, g\.nombre ASC;?$/',
            $sql,
            $m
        )) {
            return $this->groupsForOpcional((int) $m[1]);
        }

        if (preg_match(
            '/^SELECT g\.nombre FROM catalogo_opcional_grupos g INNER JOIN catalogo_opcional_grupo_rel r'
            . ' ON r\.id_grupo = g\.id WHERE r\.id_opcional = (\d+) ORDER BY g\.orden ASC, g\.nombre ASC;?$/',
            $sql,
            $m
        )) {
            return array_map(
                static fn (array $row): array => ['nombre' => $row['nombre']],
                $this->groupsForOpcional((int) $m[1])
            );
        }

        if (preg_match('/^SELECT 1 FROM catalogo_opcional_grupo_rel WHERE id_opcional = (\d+) LIMIT 1;?$/', $sql, $m)) {
            return $this->hasBridge((int) $m[1]) ? [['1' => 1]] : [];
        }

        if (preg_match(
            '/^SELECT \* FROM catalogo_opcional_grupo_rel WHERE id_opcional = (\d+) AND id_grupo = (\d+);?$/',
            $sql,
            $m
        )) {
            return $this->hasPair((int) $m[1], (int) $m[2]) ? [['id_opcional' => (int) $m[1], 'id_grupo' => (int) $m[2]]] : [];
        }

        if (preg_match(
            '/^SELECT id_grupo FROM catalogo_opcional_grupo_rel WHERE id_opcional = (\d+) ORDER BY id_grupo ASC;?$/',
            $sql,
            $m
        )) {
            return array_map(
                static fn (int $id): array => ['id_grupo' => $id],
                $this->groupIds((int) $m[1])
            );
        }

        if (preg_match('/^SELECT \* FROM catalogo_opcionales WHERE id = (\d+);?$/', $sql, $m)) {
            $id = (int) $m[1];

            return isset($this->opcionales[$id]) ? [$this->opcionales[$id]] : [];
        }

        return [];
    }

    public function select_limit($sql, $limit = FS_ITEM_LIMIT, $offset = 0, $params = [])
    {
        $sql = trim((string) $sql);
        $this->selectLimits[] = $sql;

        if (preg_match(
            '/^SELECT \* FROM catalogo_opcionales o WHERE NOT EXISTS'
            . ' \(SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r\.id_opcional = o\.id\)'
            . ' ORDER BY o\.nombre ASC$/',
            $sql
        )) {
            return $this->looseOpcionales(false);
        }

        if (preg_match(
            '/^SELECT \* FROM catalogo_opcionales o WHERE o\.activo = TRUE AND NOT EXISTS'
            . ' \(SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r\.id_opcional = o\.id\)'
            . ' ORDER BY o\.nombre ASC$/',
            $sql
        )) {
            return $this->looseOpcionales(true);
        }

        if (preg_match(
            '/^SELECT \* FROM catalogo_opcionales o WHERE NOT EXISTS'
            . ' \(SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r\.id_opcional = o\.id'
            . ' AND r\.id_grupo = (\d+)\) ORDER BY o\.nombre ASC$/',
            $sql,
            $m
        )) {
            return $this->opcionalesNotInGrupo((int) $m[1]);
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $sql = trim((string) $sql);
        $this->executed[] = $sql;

        if (preg_match(
            '/^INSERT INTO catalogo_opcional_grupo_rel \(id_opcional, id_grupo\) VALUES \((\d+),(\d+)\);?$/',
            $sql,
            $m
        )) {
            if (!$this->hasPair((int) $m[1], (int) $m[2])) {
                $this->bridge[] = [
                    'id' => count($this->bridge) + 1,
                    'id_opcional' => (int) $m[1],
                    'id_grupo' => (int) $m[2],
                ];
            }

            return true;
        }

        if (preg_match(
            '/^DELETE FROM catalogo_opcional_grupo_rel WHERE id_opcional = (\d+) AND id_grupo = (\d+);?$/',
            $sql,
            $m
        )) {
            $this->bridge = array_values(array_filter(
                $this->bridge,
                static fn (array $row): bool => !((int) $row['id_opcional'] === (int) $m[1]
                    && (int) $row['id_grupo'] === (int) $m[2])
            ));

            return true;
        }

        if (preg_match('/^DELETE FROM catalogo_opcional_grupo_rel WHERE id_opcional = (\d+);?$/', $sql, $m)) {
            $this->bridge = array_values(array_filter(
                $this->bridge,
                static fn (array $row): bool => (int) $row['id_opcional'] !== (int) $m[1]
            ));

            return true;
        }

        if (preg_match('/^DELETE FROM catalogo_opcional_grupo_rel WHERE id_grupo = (\d+);?$/', $sql, $m)) {
            $this->bridge = array_values(array_filter(
                $this->bridge,
                static fn (array $row): bool => (int) $row['id_grupo'] !== (int) $m[1]
            ));

            return true;
        }

        return true;
    }

    public function lastval()
    {
        return 7;
    }

    /** @return list<array{0:int,1:int}> */
    public function pairs(): array
    {
        $pairs = [];
        foreach ($this->bridge as $row) {
            $pairs[] = [(int) $row['id_opcional'], (int) $row['id_grupo']];
        }

        return $pairs;
    }

    public function countExecuted(string $sql): int
    {
        return count(array_filter($this->executed, static fn (string $row): bool => $row === $sql));
    }

    /** @return list<string> */
    public function executedContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->executed,
            static fn (string $sql): bool => str_contains($sql, $needle)
        ));
    }

    public function hasPair(int $idOpcional, int $idGrupo): bool
    {
        foreach ($this->bridge as $row) {
            if ((int) $row['id_opcional'] === $idOpcional && (int) $row['id_grupo'] === $idGrupo) {
                return true;
            }
        }

        return false;
    }

    public function hasBridge(int $idOpcional): bool
    {
        foreach ($this->bridge as $row) {
            if ((int) $row['id_opcional'] === $idOpcional) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> */
    public function groupIds(int $idOpcional): array
    {
        $ids = [];
        foreach ($this->bridge as $row) {
            if ((int) $row['id_opcional'] === $idOpcional) {
                $ids[] = (int) $row['id_grupo'];
            }
        }
        sort($ids);

        return $ids;
    }

    /** @return list<array<string, mixed>> */
    private function groupsForOpcional(int $idOpcional): array
    {
        $rows = [];
        foreach ($this->groupIds($idOpcional) as $idGrupo) {
            if (isset($this->groups[$idGrupo])) {
                $rows[] = $this->groups[$idGrupo];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            return ((int) $a['orden'] <=> (int) $b['orden']) ?: strcmp((string) $a['nombre'], (string) $b['nombre']);
        });

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function looseOpcionales(bool $onlyActive): array
    {
        $rows = [];
        foreach ($this->opcionales as $id => $row) {
            if ($onlyActive && !$row['activo']) {
                continue;
            }
            if ($this->hasBridge((int) $id)) {
                continue;
            }
            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['nombre'], (string) $b['nombre']));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function opcionalesNotInGrupo(int $idGrupo): array
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
}

/**
 * `catalogo_opcional` double backed by {@see MembershipFakeDb} with the
 * model-factory seams pointed at fake-backed collaborators.
 */
class MembershipOpcionalStub extends \FSFramework\model\catalogo_opcional
{
    public static ?MembershipFakeDb $fakeDb = null;

    public function __construct($data = false)
    {
        parent::__construct($data);
        $this->db = self::$fakeDb;
        $this->table_name = 'catalogo_opcionales';
    }

    protected function grupo_rel_model(): \FSFramework\model\catalogo_opcional_grupo_rel
    {
        return new MembershipBridgeModel(self::$fakeDb);
    }

    protected function articulo_opcional_model(): \FSFramework\model\catalogo_articulo_opcional
    {
        return new MembershipDirectRelModel(self::$fakeDb);
    }
}

/**
 * Bridge double sharing the fake connection.
 */
final class MembershipBridgeModel extends \FSFramework\model\catalogo_opcional_grupo_rel
{
    public function __construct(MembershipFakeDb $db)
    {
        $this->db = $db;
        $this->table_name = 'catalogo_opcional_grupo_rel';
        $this->id = null;
        $this->id_opcional = null;
        $this->id_grupo = null;
    }
}

/**
 * Direct article↔opcional relation double sharing the fake connection.
 */
final class MembershipDirectRelModel extends \FSFramework\model\catalogo_articulo_opcional
{
    public function __construct(MembershipFakeDb $db)
    {
        $this->db = $db;
        $this->table_name = 'catalogo_articulo_opcional';
    }
}

/**
 * `catalogo_opcional_grupo` double for the AD-7 group cascade.
 */
final class MembershipGrupoStub extends \FSFramework\model\catalogo_opcional_grupo
{
    public static ?MembershipFakeDb $fakeDb = null;

    public function __construct($data = false)
    {
        parent::__construct($data);
        $this->db = self::$fakeDb;
    }

    protected function grupo_rel_model(): \FSFramework\model\catalogo_opcional_grupo_rel
    {
        return new MembershipBridgeModel(self::$fakeDb);
    }

    protected function articulo_opcional_grupo_model(): \FSFramework\model\catalogo_articulo_opcional_grupo
    {
        return new MembershipArticuloGrupoModel(self::$fakeDb);
    }
}

/**
 * Article↔group bridge double sharing the fake connection.
 */
final class MembershipArticuloGrupoModel extends \FSFramework\model\catalogo_articulo_opcional_grupo
{
    public function __construct(MembershipFakeDb $db)
    {
        $this->db = $db;
        $this->table_name = 'catalogo_articulo_opcional_grupo';
    }
}

/**
 * DB-free contract tests for the WU-2 membership API on
 * `FSFramework\model\catalogo_opcional` (OPG-04, OPG-05, OPG-08).
 *
 * Stage-safe B1: the legacy `id_grupo` property/column is still dual-written
 * so unmigrated readers keep working; the bridge is the membership source.
 */
final class CatalogoOpcionalMembershipTest extends TestCase
{
    private const MODEL_RELATIVE = 'plugins/catalogo_core/model/core/catalogo_opcional.php';

    private function opcionalRow(int $id, string $codigo, string $nombre, ?int $idGrupo = null): array
    {
        return [
            'id' => $id,
            'codigo' => $codigo,
            'nombre' => $nombre,
            'descripcion' => '',
            'precio' => 0,
            'tipo_precio' => 'fijo',
            'porcentaje' => null,
            'activo' => true,
            'id_grupo' => $idGrupo,
        ];
    }

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

    private function model(MembershipFakeDb $db, ?array $data = null): MembershipOpcionalStub
    {
        MembershipOpcionalStub::$fakeDb = $db;

        return new MembershipOpcionalStub($data);
    }

    public function test_add_to_grupo_inserts_bridge_row_and_updates_legacy_projection(): void
    {
        $db = new MembershipFakeDb(
            [7 => $this->opcionalRow(7, 'OPC1', 'Blanco')],
            [3 => $this->grupoRow(3, 'GRP0003', 'Color', 1)]
        );
        $model = $this->model($db, $this->opcionalRow(7, 'OPC1', 'Blanco'));

        $this->assertTrue($model->add_to_grupo(3));
        $this->assertSame([[7, 3]], $db->pairs());
        $this->assertSame(3, $model->id_grupo, 'the legacy projection must follow the first membership');
        $this->assertContains(
            'UPDATE catalogo_opcionales SET id_grupo = 3 WHERE id = 7;',
            $db->executed
        );
    }

    public function test_add_to_grupo_fires_direct_relation_cleanup_only_on_first_membership(): void
    {
        $db = new MembershipFakeDb(
            [7 => $this->opcionalRow(7, 'OPC1', 'Blanco')],
            [3 => $this->grupoRow(3, 'GRP0003', 'Color', 1), 4 => $this->grupoRow(4, 'GRP0004', 'Acabado', 2)]
        );
        $model = $this->model($db, $this->opcionalRow(7, 'OPC1', 'Blanco'));

        $this->assertTrue($model->add_to_grupo(3));
        $this->assertSame(
            1,
            $db->countExecuted('DELETE FROM catalogo_articulo_opcional WHERE id_opcional = 7;'),
            'the first membership must drop the direct article relations'
        );

        $this->assertTrue($model->add_to_grupo(4));
        $this->assertSame(
            1,
            $db->countExecuted('DELETE FROM catalogo_articulo_opcional WHERE id_opcional = 7;'),
            'a second membership must not refire the direct-relation cleanup'
        );
        $this->assertSame([[7, 3], [7, 4]], $db->pairs());
    }

    public function test_add_to_grupo_rejects_an_unknown_group(): void
    {
        $db = new MembershipFakeDb([7 => $this->opcionalRow(7, 'OPC1', 'Blanco')]);
        $model = $this->model($db, $this->opcionalRow(7, 'OPC1', 'Blanco'));

        $this->assertFalse($model->add_to_grupo(99));
        $this->assertSame([], $db->pairs());
    }

    public function test_remove_from_grupo_int_deletes_only_that_row_and_never_restores_direct_relations(): void
    {
        $db = new MembershipFakeDb(
            [7 => $this->opcionalRow(7, 'OPC1', 'Blanco', 3)],
            [3 => $this->grupoRow(3, 'GRP0003', 'Color', 1), 4 => $this->grupoRow(4, 'GRP0004', 'Acabado', 2)],
            [[7, 3], [7, 4]]
        );
        $model = $this->model($db, $this->opcionalRow(7, 'OPC1', 'Blanco', 3));

        $this->assertTrue($model->remove_from_grupo(3));
        $this->assertSame([[7, 4]], $db->pairs());
        $this->assertSame(4, $model->id_grupo, 'the projection must move to the remaining membership');
        $this->assertSame(
            0,
            count($db->executedContaining('DELETE FROM catalogo_articulo_opcional')),
            'removing a membership must never recreate/restore direct article relations'
        );
    }

    public function test_legacy_no_arg_remove_from_grupo_clears_the_projected_group(): void
    {
        $db = new MembershipFakeDb(
            [7 => $this->opcionalRow(7, 'OPC1', 'Blanco', 3)],
            [3 => $this->grupoRow(3, 'GRP0003', 'Color', 1)],
            [[7, 3]]
        );
        $model = $this->model($db, $this->opcionalRow(7, 'OPC1', 'Blanco', 3));

        $this->assertTrue($model->remove_from_grupo());
        $this->assertSame([], $db->pairs());
        $this->assertNull($model->id_grupo);
        $this->assertContains(
            'UPDATE catalogo_opcionales SET id_grupo = NULL WHERE id = 7;',
            $db->executed
        );
    }

    public function test_set_grupos_diffs_the_current_set(): void
    {
        $db = new MembershipFakeDb(
            [7 => $this->opcionalRow(7, 'OPC1', 'Blanco', 3)],
            [],
            [[7, 3], [7, 4]]
        );
        $model = $this->model($db, $this->opcionalRow(7, 'OPC1', 'Blanco', 3));

        $this->assertTrue($model->set_grupos([4, 5]));
        $this->assertSame([[7, 4], [7, 5]], $db->pairs(), 'only the unchecked membership is removed and the new one added');
    }

    public function test_set_grupos_fires_the_first_membership_cleanup_once(): void
    {
        $db = new MembershipFakeDb([7 => $this->opcionalRow(7, 'OPC1', 'Blanco')]);
        $model = $this->model($db, $this->opcionalRow(7, 'OPC1', 'Blanco'));

        $this->assertTrue($model->set_grupos([3, 4]));
        $this->assertSame(
            1,
            $db->countExecuted('DELETE FROM catalogo_articulo_opcional WHERE id_opcional = 7;')
        );

        $this->assertTrue($model->set_grupos([3, 4, 5]));
        $this->assertSame(
            1,
            $db->countExecuted('DELETE FROM catalogo_articulo_opcional WHERE id_opcional = 7;'),
            'the 0 -> n cleanup must fire exactly once'
        );
    }

    public function test_is_grouped_reflects_bridge_membership(): void
    {
        $looseDb = new MembershipFakeDb([7 => $this->opcionalRow(7, 'OPC1', 'Blanco')]);
        $loose = $this->model($looseDb, $this->opcionalRow(7, 'OPC1', 'Blanco'));
        $this->assertFalse($loose->is_grouped());

        $groupedDb = new MembershipFakeDb(
            [7 => $this->opcionalRow(7, 'OPC1', 'Blanco')],
            [],
            [[7, 3]]
        );
        $grouped = $this->model($groupedDb, $this->opcionalRow(7, 'OPC1', 'Blanco'));
        $this->assertTrue($grouped->is_grouped());
    }

    public function test_grupo_ids_and_grupos_labels_are_bridge_backed(): void
    {
        $db = new MembershipFakeDb(
            [7 => $this->opcionalRow(7, 'OPC1', 'Blanco')],
            [3 => $this->grupoRow(3, 'GRP0003', 'Color', 1), 4 => $this->grupoRow(4, 'GRP0004', 'Acabado', 2)],
            [[7, 3], [7, 4]]
        );
        $model = $this->model($db, $this->opcionalRow(7, 'OPC1', 'Blanco'));

        $this->assertSame([3, 4], $model->grupo_ids());
        $this->assertSame(['Color', 'Acabado'], $model->grupos_labels());
    }

    public function test_get_grupos_orders_by_group_orden_then_name(): void
    {
        $db = new MembershipFakeDb(
            [7 => $this->opcionalRow(7, 'OPC1', 'Blanco')],
            [3 => $this->grupoRow(3, 'GRP0003', 'Color', 1), 4 => $this->grupoRow(4, 'GRP0004', 'Acabado', 2)],
            [[7, 3], [7, 4]]
        );
        $model = $this->model($db, $this->opcionalRow(7, 'OPC1', 'Blanco'));

        $this->assertSame(
            ['Color', 'Acabado'],
            array_map(static fn ($grupo): string => (string) $grupo->nombre, $model->get_grupos())
        );
        $this->assertNotSame([], array_filter(
            $db->selects,
            static fn (string $sql): bool => str_contains($sql, 'ORDER BY g.orden ASC, g.nombre ASC')
        ));
    }

    public function test_all_sin_grupo_uses_the_bridge_anti_join(): void
    {
        $db = new MembershipFakeDb(
            [
                7 => $this->opcionalRow(7, 'OPC7', 'Agrupado'),
                8 => $this->opcionalRow(8, 'OPC8', 'Suelto'),
            ],
            [],
            [[7, 3]]
        );
        $model = $this->model($db, $this->opcionalRow(0, 'OPC0', ''));

        $this->assertSame([8], array_map(static fn ($row): int => (int) $row->id, $model->all_sin_grupo()));
        $this->assertNotSame([], array_filter(
            $db->selectLimits,
            static fn (string $sql): bool => str_contains(
                $sql,
                'NOT EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r.id_opcional = o.id)'
            )
        ));
    }

    public function test_all_activos_sin_grupo_filters_inactive_loose_rows(): void
    {
        $activo = $this->opcionalRow(8, 'OPC8', 'Suelto');
        $inactivo = $this->opcionalRow(9, 'OPC9', 'Inactivo');
        $inactivo['activo'] = false;

        $db = new MembershipFakeDb(
            [
                7 => $this->opcionalRow(7, 'OPC7', 'Agrupado'),
                8 => $activo,
                9 => $inactivo,
            ],
            [],
            [[7, 3]]
        );
        $model = $this->model($db, $this->opcionalRow(0, 'OPC0', ''));

        $this->assertSame([8], array_map(static fn ($row): int => (int) $row->id, $model->all_activos_sin_grupo()));
    }

    public function test_all_not_in_grupo_offers_members_of_other_groups_and_loose_rows(): void
    {
        $db = new MembershipFakeDb(
            [
                7 => $this->opcionalRow(7, 'OPC7', 'EnGrupo3'),
                8 => $this->opcionalRow(8, 'OPC8', 'EnGrupo4'),
                9 => $this->opcionalRow(9, 'OPC9', 'Suelto'),
            ],
            [],
            [[7, 3], [8, 4]]
        );
        $model = $this->model($db, $this->opcionalRow(0, 'OPC0', ''));

        $this->assertSame(
            [8, 9],
            array_map(static fn ($row): int => (int) $row->id, $model->all_not_in_grupo(3))
        );
    }

    public function test_where_id_grupo_uses_exists_and_not_exists_on_the_bridge(): void
    {
        $db = new MembershipFakeDb();
        MembershipOpcionalStub::$fakeDb = $db;
        $model = new class() extends MembershipOpcionalStub {
            public function exposed($id_grupo, $alias = 'o'): string
            {
                return $this->where_id_grupo($id_grupo, $alias);
            }
        };

        $this->assertSame('', $model->exposed(''));
        $this->assertSame(
            'NOT EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r.id_opcional = o.id)',
            $model->exposed('0')
        );
        $this->assertSame(
            'EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r.id_opcional = o.id AND r.id_grupo = 5)',
            $model->exposed('5')
        );
        $this->assertSame(
            'EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r.id_opcional = o.id AND r.id_grupo = 5)',
            $model->exposed("5 OR 1=1; DROP TABLE catalogo_opcionales")
        );
    }

    public function test_legacy_shims_delegate_to_the_bridge(): void
    {
        $db = new MembershipFakeDb(
            [7 => $this->opcionalRow(7, 'OPC1', 'Blanco', 3)],
            [3 => $this->grupoRow(3, 'GRP0003', 'Color', 1), 4 => $this->grupoRow(4, 'GRP0004', 'Acabado', 2)],
            [[7, 3]]
        );
        $model = $this->model($db, $this->opcionalRow(7, 'OPC1', 'Blanco', 3));

        $this->assertSame('Color', $model->etiqueta_grupo());
        $this->assertSame('Color', (string) $model->get_grupo()->nombre);

        $this->assertTrue($model->assign_to_grupo(4));
        $this->assertSame([[7, 3], [7, 4]], $db->pairs());
    }

    public function test_save_dual_writes_the_legacy_column_and_the_bridge(): void
    {
        $row = $this->opcionalRow(7, 'OPC1', 'Blanco');
        $db = new MembershipFakeDb(
            [7 => $row],
            [3 => $this->grupoRow(3, 'GRP0003', 'Color', 1)]
        );
        $model = $this->model($db, $row);
        $model->id_grupo = 3;

        $this->assertTrue($model->save());

        $saveUpdates = array_values(array_filter(
            $db->executed,
            static fn (string $sql): bool => str_starts_with($sql, 'UPDATE catalogo_opcionales SET codigo = ')
        ));
        $this->assertCount(1, $saveUpdates);
        $this->assertStringContainsString('id_grupo = 3', $saveUpdates[0], 'legacy column must keep being written');
        $this->assertSame([[7, 3]], $db->pairs(), 'the bridge must be reconciled with the legacy value');
        $this->assertSame(
            1,
            $db->countExecuted('DELETE FROM catalogo_articulo_opcional WHERE id_opcional = 7;'),
            'the grouped => no direct relations side effect must be preserved'
        );
    }

    public function test_save_with_no_projected_group_clears_the_bridge(): void
    {
        $row = $this->opcionalRow(7, 'OPC1', 'Blanco', 3);
        $db = new MembershipFakeDb(
            [7 => $row],
            [],
            [[7, 3]]
        );
        $model = $this->model($db, $row);
        $model->id_grupo = null;

        $this->assertTrue($model->save());
        $this->assertSame([], $db->pairs(), 'an ungroup signal must keep both sources in agreement');
    }

    public function test_delete_cascades_the_bridge_rows(): void
    {
        $row = $this->opcionalRow(7, 'OPC1', 'Blanco', 3);
        $db = new MembershipFakeDb(
            [7 => $row],
            [],
            [[7, 3], [7, 4], [8, 3]]
        );
        $model = $this->model($db, $row);

        $this->assertTrue($model->delete());
        $this->assertSame([[8, 3]], $db->pairs(), 'only the deleted opcional memberships are removed');
        $this->assertContains('DELETE FROM catalogo_opcionales WHERE id = 7;', $db->executed);
    }

    public function test_group_delete_cascades_its_memberships_and_keeps_the_legacy_column_consistent(): void
    {
        $db = new MembershipFakeDb([], [3 => $this->grupoRow(3, 'GRP0003', 'Color', 1)], [[7, 3], [8, 3], [8, 5]]);
        MembershipGrupoStub::$fakeDb = $db;
        $grupo = new MembershipGrupoStub($this->grupoRow(3, 'GRP0003', 'Color', 1));

        $this->assertTrue($grupo->delete());

        $this->assertSame([[8, 5]], $db->pairs(), 'only the deleted group memberships are removed');
        $this->assertContains('UPDATE catalogo_opcionales SET id_grupo = NULL WHERE id_grupo = 3;', $db->executed);
        $this->assertContains('DELETE FROM catalogo_articulo_opcional_grupo WHERE id_grupo = 3;', $db->executed);
        $this->assertContains('DELETE FROM catalogo_opcional_grupos WHERE id = 3;', $db->executed);
    }

    public function test_loose_and_membership_predicates_are_bridge_backed_in_source(): void
    {
        $path = FS_FOLDER . '/' . self::MODEL_RELATIVE;
        $this->assertFileExists($path);
        $source = (string) file_get_contents($path);

        $allSinGrupo = $this->methodBody($source, 'all_sin_grupo');
        $this->assertStringContainsString('catalogo_opcional_grupo_rel', $allSinGrupo);
        $this->assertStringNotContainsString('id_grupo', $allSinGrupo, 'the loose list must not read the frozen column');

        $where = $this->methodBody($source, 'where_id_grupo');
        $this->assertStringContainsString('catalogo_opcional_grupo_rel', $where);
        $this->assertStringNotContainsString(
            "\$alias . '.id_grupo'",
            $where,
            'the filter must not build a predicate on the frozen catalogo_opcionales.id_grupo column'
        );
        $this->assertStringContainsString('r.id_grupo', $where, 'the membership test must hit the bridge column');
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
