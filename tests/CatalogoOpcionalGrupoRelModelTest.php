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

/**
 * DB-free contract tests for the M:N bridge model
 * `FSFramework\model\catalogo_opcional_grupo_rel` (OPG-03).
 *
 * The fake DB emulates only the statements the model issues. The DDL source
 * contract asserts the create statements the migration emits for both
 * dialects, including the absence of a DB-level foreign key.
 */
final class OpcionalGrupoRelFakeDb extends \fs_db2
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<string> */
    public array $executed = [];

    public int $selectCount = 0;

    private int $nextId = 1;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(array $rows = [])
    {
        // Deliberately skip parent::__construct(): no engine, no DB connection.
        $this->rows = $rows;
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
        $this->selectCount++;
        $sql = trim((string) $sql);

        $pair = '/^SELECT \* FROM catalogo_opcional_grupo_rel'
            . ' WHERE id_opcional = (\d+) AND id_grupo = (\d+);?$/';
        if (preg_match($pair, $sql, $m)) {
            foreach ($this->rows as $row) {
                if ((int) $row['id_opcional'] === (int) $m[1] && (int) $row['id_grupo'] === (int) $m[2]) {
                    return [$row];
                }
            }

            return [];
        }

        $ids = '/^SELECT id_grupo FROM catalogo_opcional_grupo_rel'
            . ' WHERE id_opcional = (\d+) ORDER BY id_grupo ASC;?$/';
        if (preg_match($ids, $sql, $m)) {
            $out = [];
            foreach ($this->rows as $row) {
                if ((int) $row['id_opcional'] === (int) $m[1]) {
                    $out[] = (int) $row['id_grupo'];
                }
            }
            sort($out);

            return array_map(static fn (int $grupo): array => ['id_grupo' => $grupo], $out);
        }

        $map = '/^SELECT id_opcional, id_grupo FROM catalogo_opcional_grupo_rel'
            . ' WHERE id_opcional IN \(([\d,]+)\);?$/';
        if (preg_match($map, $sql, $m)) {
            $wanted = array_map('intval', explode(',', $m[1]));
            $out = [];
            foreach ($this->rows as $row) {
                if (in_array((int) $row['id_opcional'], $wanted, true)) {
                    $out[] = [
                        'id_opcional' => (int) $row['id_opcional'],
                        'id_grupo' => (int) $row['id_grupo'],
                    ];
                }
            }

            return $out;
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
            $this->rows[] = [
                'id' => $this->nextId++,
                'id_opcional' => (int) $m[1],
                'id_grupo' => (int) $m[2],
            ];

            return true;
        }

        if (preg_match(
            '/^DELETE FROM catalogo_opcional_grupo_rel WHERE id_opcional = (\d+) AND id_grupo = (\d+);?$/',
            $sql,
            $m
        )) {
            $this->rows = array_values(array_filter(
                $this->rows,
                static fn (array $row): bool => !((int) $row['id_opcional'] === (int) $m[1]
                    && (int) $row['id_grupo'] === (int) $m[2])
            ));

            return true;
        }

        if (preg_match('/^DELETE FROM catalogo_opcional_grupo_rel WHERE id_opcional = (\d+);?$/', $sql, $m)) {
            $this->rows = array_values(array_filter(
                $this->rows,
                static fn (array $row): bool => (int) $row['id_opcional'] !== (int) $m[1]
            ));

            return true;
        }

        if (preg_match('/^DELETE FROM catalogo_opcional_grupo_rel WHERE id_grupo = (\d+);?$/', $sql, $m)) {
            $this->rows = array_values(array_filter(
                $this->rows,
                static fn (array $row): bool => (int) $row['id_grupo'] !== (int) $m[1]
            ));

            return true;
        }

        return true;
    }

    public function lastval()
    {
        return $this->nextId - 1;
    }
}

final class CatalogoOpcionalGrupoRelModelTest extends TestCase
{
    private const MIGRATION_RELATIVE = 'plugins/catalogo_core/Services/CatalogLegacyTableMigration.php';

    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo_rel.php';
        require_once FS_FOLDER . '/' . self::MIGRATION_RELATIVE;
    }

    private function makeModel(OpcionalGrupoRelFakeDb $db): \FSFramework\model\catalogo_opcional_grupo_rel
    {
        return new class($db) extends \FSFramework\model\catalogo_opcional_grupo_rel {
            public function __construct(OpcionalGrupoRelFakeDb $db)
            {
                $this->db = $db;
                $this->table_name = 'catalogo_opcional_grupo_rel';
                $this->id = null;
                $this->id_opcional = null;
                $this->id_grupo = null;
            }
        };
    }

    private function migrationSource(): string
    {
        $path = FS_FOLDER . '/' . self::MIGRATION_RELATIVE;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** Extracts a method body by brace matching from its signature. */
    private function methodSource(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            self::fail('missing migration method: ' . $signature);
        }

        $open = (int) strpos($src, '{', (int) $start);
        $depth = 0;
        $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, (int) $start, $i - $start + 1);
                }
            }
        }

        self::fail('method body is not terminated: ' . $signature);
    }

    public function test_add_inserts_a_single_bridge_row(): void
    {
        $db = new OpcionalGrupoRelFakeDb();
        $model = $this->makeModel($db);

        $this->assertTrue($model->add(7, 3));
        $this->assertCount(1, $db->rows);
        $this->assertSame(7, $db->rows[0]['id_opcional']);
        $this->assertSame(3, $db->rows[0]['id_grupo']);
        $this->assertCount(1, array_filter(
            $db->executed,
            static fn (string $sql): bool => stripos($sql, 'INSERT INTO catalogo_opcional_grupo_rel') === 0
        ));
    }

    public function test_add_is_idempotent_for_the_same_pair(): void
    {
        $db = new OpcionalGrupoRelFakeDb();
        $model = $this->makeModel($db);

        $this->assertTrue($model->add(7, 3));
        $this->assertTrue($model->add(7, 3));

        $this->assertCount(1, $db->rows, 'the second identical pair must be a no-op');
        $this->assertCount(1, array_filter(
            $db->executed,
            static fn (string $sql): bool => stripos($sql, 'INSERT INTO catalogo_opcional_grupo_rel') === 0
        ));
    }

    public function test_remove_deletes_only_that_pair(): void
    {
        $db = new OpcionalGrupoRelFakeDb([
            ['id' => 1, 'id_opcional' => 7, 'id_grupo' => 3],
            ['id' => 2, 'id_opcional' => 7, 'id_grupo' => 4],
        ]);
        $model = $this->makeModel($db);

        $this->assertTrue($model->remove(7, 3));
        $this->assertCount(1, $db->rows);
        $this->assertSame(4, $db->rows[0]['id_grupo']);
    }

    public function test_exists_relation_reports_pair_membership(): void
    {
        $db = new OpcionalGrupoRelFakeDb([
            ['id' => 1, 'id_opcional' => 7, 'id_grupo' => 3],
        ]);
        $model = $this->makeModel($db);

        $this->assertTrue($model->exists_relation(7, 3));
        $this->assertFalse($model->exists_relation(7, 4));
    }

    public function test_group_ids_for_opcional_returns_the_group_list(): void
    {
        $db = new OpcionalGrupoRelFakeDb([
            ['id' => 1, 'id_opcional' => 7, 'id_grupo' => 4],
            ['id' => 2, 'id_opcional' => 7, 'id_grupo' => 3],
            ['id' => 3, 'id_opcional' => 8, 'id_grupo' => 9],
        ]);
        $model = $this->makeModel($db);

        $this->assertSame([3, 4], $model->group_ids_for_opcional(7));
        $this->assertSame([9], $model->group_ids_for_opcional(8));
        $this->assertSame([], $model->group_ids_for_opcional(99));
    }

    public function test_map_for_opcionales_returns_one_list_per_opcional_with_a_single_query(): void
    {
        $db = new OpcionalGrupoRelFakeDb([
            ['id' => 1, 'id_opcional' => 7, 'id_grupo' => 3],
            ['id' => 2, 'id_opcional' => 7, 'id_grupo' => 4],
            ['id' => 3, 'id_opcional' => 8, 'id_grupo' => 5],
            ['id' => 4, 'id_opcional' => 99, 'id_grupo' => 6],
        ]);
        $model = $this->makeModel($db);
        $db->selectCount = 0;

        $map = $model->map_for_opcionales([7, 8, 0, -2]);

        $this->assertSame([7 => [3, 4], 8 => [5]], $map);
        $this->assertSame(1, $db->selectCount, 'map_for_opcionales must issue exactly one query');
    }

    public function test_map_for_opcionales_without_ids_makes_no_query(): void
    {
        $db = new OpcionalGrupoRelFakeDb();
        $model = $this->makeModel($db);
        $db->selectCount = 0;

        $this->assertSame([], $model->map_for_opcionales([0, -1, null]));
        $this->assertSame(0, $db->selectCount);
    }

    public function test_delete_all_from_opcional_clears_only_that_opcional(): void
    {
        $db = new OpcionalGrupoRelFakeDb([
            ['id' => 1, 'id_opcional' => 7, 'id_grupo' => 3],
            ['id' => 2, 'id_opcional' => 7, 'id_grupo' => 4],
            ['id' => 3, 'id_opcional' => 8, 'id_grupo' => 5],
        ]);
        $model = $this->makeModel($db);

        $this->assertTrue($model->delete_all_from_opcional(7));
        $this->assertCount(1, $db->rows);
        $this->assertSame(8, $db->rows[0]['id_opcional']);
    }

    public function test_delete_all_from_grupo_clears_only_that_group(): void
    {
        $db = new OpcionalGrupoRelFakeDb([
            ['id' => 1, 'id_opcional' => 7, 'id_grupo' => 3],
            ['id' => 2, 'id_opcional' => 8, 'id_grupo' => 3],
            ['id' => 3, 'id_opcional' => 8, 'id_grupo' => 5],
        ]);
        $model = $this->makeModel($db);

        $this->assertTrue($model->delete_all_from_grupo(3));
        $this->assertCount(1, $db->rows);
        $this->assertSame(5, $db->rows[0]['id_grupo']);
    }

    public function test_bridge_ddl_declares_pk_and_unique_without_foreign_keys(): void
    {
        $method = $this->methodSource(
            $this->migrationSource(),
            'function migrateOpcionalGroupRelations('
        );

        $this->assertMatchesRegularExpression(
            '/CREATE TABLE catalogo_opcional_grupo_rel \(.*?id serial NOT NULL.*?PRIMARY KEY \(id\),.*?UNIQUE \(id_opcional, id_grupo\)/s',
            $method,
            'PostgreSQL create statement must declare the serial PK and the UNIQUE pair'
        );
        $this->assertMatchesRegularExpression(
            '/CREATE TABLE IF NOT EXISTS catalogo_opcional_grupo_rel \(.*?AUTO_INCREMENT.*?PRIMARY KEY \(id\),.*?UNIQUE KEY catalogo_opcional_grupo_rel_unique \(id_opcional, id_grupo\)/s',
            $method,
            'MySQL create statement must declare the autoincrement PK and the UNIQUE pair'
        );

        $this->assertStringNotContainsString('FOREIGN KEY', $method);
        $this->assertStringNotContainsString('REFERENCES', $method);
        $this->assertStringNotContainsString('ON DELETE CASCADE', $method);
    }

    public function test_bridge_xml_matches_the_plugin_bridge_convention(): void
    {
        $path = FS_FOLDER . '/plugins/catalogo_core/model/table/catalogo_opcional_grupo_rel.xml';
        $this->assertFileExists($path);
        $xml = (string) file_get_contents($path);

        $this->assertStringContainsString('<nombre>id_opcional</nombre>', $xml);
        $this->assertStringContainsString('<nombre>id_grupo</nombre>', $xml);
        $this->assertStringContainsString('PRIMARY KEY (id)', $xml);
        $this->assertStringContainsString('UNIQUE (id_opcional, id_grupo)', $xml);
        $this->assertStringNotContainsString('FOREIGN KEY', $xml);
    }
}
