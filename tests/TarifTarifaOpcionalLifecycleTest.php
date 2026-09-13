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
 * Spy DB for the lifecycle contract. Mirrors the Unit 1/Unit 2 spy: `exec`
 * records write statements and returns true, `select` drains a queue before
 * falling back to the shared `rows` fixture.
 */
final class TarifaOpcionalLifecycleSpyDb
{
    /** @var list<string> */
    public array $execStatements = [];

    /** @var list<string> */
    public array $selectStatements = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<list<array<string, mixed>>> */
    public array $selectQueue = [];

    /** @var list<string> */
    public array $transactionStatements = [];

    /** @var list<mixed> */
    public array $execTransactionFlags = [];

    /** When true, INSERT statements fail so rollback paths can be exercised. */
    public bool $failInserts = false;

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

    public function escape_string(string $str): string
    {
        return addslashes($str);
    }

    public function select($sql, $params = [])
    {
        $this->selectStatements[] = trim((string) $sql);

        if ($this->selectQueue !== []) {
            return array_shift($this->selectQueue);
        }

        return $this->rows;
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $this->execStatements[] = trim((string) $sql);
        $this->execTransactionFlags[] = $transaction;

        if ($this->failInserts && stripos(ltrim((string) $sql), 'INSERT') === 0) {
            return false;
        }

        return true;
    }

    public function begin_transaction()
    {
        $this->transactionStatements[] = 'BEGIN';

        return true;
    }

    public function commit()
    {
        $this->transactionStatements[] = 'COMMIT';

        return true;
    }

    public function rollback()
    {
        $this->transactionStatements[] = 'ROLLBACK';

        return true;
    }
}

/**
 * Unit 2 lifecycle contract for the per-(tarifa, opcional) master state
 * (spec "Master lifecycle: seed, lazy inherit, copy").
 *
 * Covers the default-tarifa install seed, `copy_from_tarifa()` and the
 * explicit toggles that create-or-update the master row.
 */
final class TarifTarifaOpcionalLifecycleTest extends TestCase
{
    private const MODEL_RELATIVE = 'plugins/catalogo_core/model/tarif_tarifa_opcional.php';

    private static bool $baseLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/' . self::MODEL_RELATIVE;
        self::$baseLoaded = true;
    }

    /**
     * Builds the master model on a spy DB with a stubbed default-tarifa seam.
     * The stub keeps `seed_default_tarifa()`/`install()` deterministic without
     * touching the real `tarif_tarifa` model or a live database.
     *
     * @param array{en_catalogo: bool, en_tarifa: bool}|null $defaults
     */
    private function buildModel(
        TarifaOpcionalLifecycleSpyDb $db,
        string $defaultCode = '000001',
        ?array $defaults = null
    ): object {
        return new class($db, $db, $defaultCode, $defaults) extends \FSFramework\model\tarif_tarifa_opcional {
            public static ?object $spyDb = null;

            public static string $spyDefaultCode = '';

            public static ?array $spyDefaults = null;

            private ?array $defaults;

            public function __construct(
                $dbOrData = false,
                ?object $spy = null,
                ?string $defaultCode = null,
                ?array $defaults = null
            ) {
                $this->table_name = 'tarif_tarifa_opcional';

                if ($spy !== null) {
                    self::$spyDb = $spy;
                }
                if ($defaultCode !== null) {
                    self::$spyDefaultCode = $defaultCode;
                }
                if ($defaults !== null) {
                    self::$spyDefaults = $defaults;
                }

                $this->db = self::$spyDb;
                $this->defaults = self::$spyDefaults;

                $this->codtarifa = null;
                $this->id_opcional = null;
                $this->en_catalogo = true;
                $this->en_tarifa = false;
                $this->activa = true;
                $this->orden = 0;

                if (is_array($dbOrData)) {
                    $this->codtarifa = $dbOrData['codtarifa'] ?? null;
                    $this->id_opcional = isset($dbOrData['id_opcional']) ? (int) $dbOrData['id_opcional'] : null;
                    $this->en_catalogo = $this->str2bool($dbOrData['en_catalogo'] ?? false);
                    $this->en_tarifa = $this->str2bool($dbOrData['en_tarifa'] ?? false);
                    $this->activa = $this->str2bool($dbOrData['activa'] ?? false);
                    $this->orden = (int) ($dbOrData['orden'] ?? 0);
                }
            }

            protected function ext_defaults($id_opcional)
            {
                if ($this->defaults !== null) {
                    return $this->defaults;
                }

                return parent::ext_defaults($id_opcional);
            }

            protected function default_tarifa_code()
            {
                return self::$spyDefaultCode;
            }

            public function seedSql(): string
            {
                return $this->seed_default_tarifa();
            }

            public function installSql(): string
            {
                return $this->install();
            }
        };
    }

    /**
     * @param list<string> $statements
     * @return list<string>
     */
    private function statementsStartingWith(array $statements, string $prefix): array
    {
        return array_values(array_filter(
            $statements,
            static fn(string $sql): bool => str_starts_with(ltrim($sql), $prefix)
        ));
    }

    private function normalizeSql(string $sql): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($sql));
    }

    // =====================================================================
    // install() seed
    // =====================================================================

    public function test_install_seeds_the_default_tarifa_from_catalogo_opcionales_left_join_ext(): void
    {
        $db = new TarifaOpcionalLifecycleSpyDb();
        $model = $this->buildModel($db, '000001');

        $sql = $this->normalizeSql($model->installSql());

        $this->assertNotSame('', $sql, 'install() must return the default-tarifa seed');

        $this->assertStringStartsWith('INSERT INTO tarif_tarifa_opcional', $sql);
        $this->assertStringContainsString(
            '(codtarifa, id_opcional, en_catalogo, en_tarifa, activa, orden)',
            $sql
        );
        $this->assertStringContainsString(
            "SELECT '000001', o.id, COALESCE(e.en_catalogo, TRUE), COALESCE(e.en_tarifa, FALSE), TRUE, 0",
            $sql,
            'the seed must inherit the ext flags through COALESCE and default activa/orden'
        );
        $this->assertStringContainsString('FROM catalogo_opcionales o', $sql);
        $this->assertStringContainsString(
            'LEFT JOIN tarif_opcional_ext e ON o.id = e.id_opcional',
            $sql,
            'the seed must join the opcional extension table'
        );
        $this->assertStringContainsString(
            "WHERE NOT EXISTS (SELECT 1 FROM tarif_tarifa_opcional WHERE codtarifa = '000001' AND id_opcional = o.id)",
            $sql,
            'the seed must be idempotent for the default tarifa'
        );
    }

    public function test_seed_is_empty_when_there_is_no_default_tarifa(): void
    {
        $db = new TarifaOpcionalLifecycleSpyDb();
        $model = $this->buildModel($db, '');

        $this->assertSame('', $model->seedSql(), 'no default tarifa means no seed SQL');
        $this->assertSame([], $db->execStatements, 'building the seed must not execute anything');
    }

    public function test_install_keeps_the_fk_target_guards(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . '/' . self::MODEL_RELATIVE);
        $this->assertStringContainsString('class_exists(\FSFramework\model\tarif_tarifa::class)', $src);
        $this->assertStringContainsString('return $this->seed_default_tarifa();', $src);
    }

    // =====================================================================
    // copy_from_tarifa
    // =====================================================================

    public function test_copy_from_tarifa_clears_the_destination_then_copies_the_master(): void
    {
        $db = new TarifaOpcionalLifecycleSpyDb();
        $model = $this->buildModel($db);

        $this->assertTrue($model->copy_from_tarifa('T1', 'T2'));

        $deletes = $this->statementsStartingWith($db->execStatements, 'DELETE');
        $inserts = $this->statementsStartingWith($db->execStatements, 'INSERT');

        $this->assertCount(1, $deletes, 'the destination must be cleared exactly once');
        $this->assertStringContainsString('tarif_tarifa_opcional', $deletes[0]);
        $this->assertStringContainsString("codtarifa = 'T2'", $deletes[0]);

        $this->assertCount(1, $inserts, 'the copy is a single INSERT ... SELECT');
        $insertSql = $this->normalizeSql($inserts[0]);
        $this->assertStringContainsString(
            'INSERT INTO tarif_tarifa_opcional (codtarifa, id_opcional, en_catalogo, en_tarifa, activa, orden)',
            $insertSql
        );
        $this->assertStringContainsString(
            "SELECT 'T2', id_opcional, en_catalogo, en_tarifa, activa, orden FROM tarif_tarifa_opcional WHERE codtarifa = 'T1'",
            $insertSql,
            'all six master columns must be copied from the source tarifa'
        );
    }

    public function test_copy_from_tarifa_runs_the_delete_and_insert_in_one_transaction(): void
    {
        $db = new TarifaOpcionalLifecycleSpyDb();
        $model = $this->buildModel($db);

        $this->assertTrue($model->copy_from_tarifa('T1', 'T2'));

        $this->assertSame(
            ['BEGIN', 'COMMIT'],
            $db->transactionStatements,
            'the destination must only be cleared and repopulated atomically'
        );
        $this->assertSame(
            [false, false],
            array_slice($db->execTransactionFlags, 0, 2),
            'both statements must run inside the outer transaction, not auto-commit'
        );
    }

    public function test_copy_from_tarifa_rolls_back_when_the_copy_fails(): void
    {
        $db = new TarifaOpcionalLifecycleSpyDb();
        $db->failInserts = true;
        $model = $this->buildModel($db);

        $this->assertFalse(
            $model->copy_from_tarifa('T1', 'T2'),
            'a failed copy must not report success'
        );
        $this->assertSame(
            ['BEGIN', 'ROLLBACK'],
            $db->transactionStatements,
            'the cleared destination must be restored when the insert fails'
        );
        $this->assertNotContains('COMMIT', $db->transactionStatements);
    }

    public function test_copy_from_tarifa_is_a_noop_when_source_equals_destination(): void
    {
        $db = new TarifaOpcionalLifecycleSpyDb();
        $model = $this->buildModel($db);

        $this->assertTrue(
            $model->copy_from_tarifa('T1', 'T1'),
            'copying a tarifa onto itself must succeed as a no-op'
        );
        $this->assertSame(
            [],
            $db->execStatements,
            'a self-copy must not DELETE the very source rows it would re-insert'
        );
        $this->assertSame(
            [],
            $db->transactionStatements,
            'a self-copy has nothing to transact and must not open one'
        );
    }

    // =====================================================================
    // Toggles: create on first write, update afterwards
    // =====================================================================

    public function test_set_activa_creates_the_master_row_when_missing(): void
    {
        $db = new TarifaOpcionalLifecycleSpyDb();
        $db->rows = [];
        $db->selectQueue = [
            [],
            [['en_catalogo' => '1', 'en_tarifa' => '1']],
        ];
        $model = $this->buildModel($db);

        $this->assertTrue($model->set_activa('T1', 5, false));

        $inserts = $this->statementsStartingWith($db->execStatements, 'INSERT');
        $updates = $this->statementsStartingWith($db->execStatements, 'UPDATE');

        $this->assertCount(1, $inserts, 'the first toggle must create the master row');
        $this->assertCount(0, $updates);
        $this->assertStringContainsString(
            "('T1',5,1,1,0,0)",
            $this->normalizeSql($inserts[0]),
            'inherited ext flags are preserved and only activa flips'
        );
    }

    public function test_set_en_catalogo_updates_the_existing_master_row(): void
    {
        $db = new TarifaOpcionalLifecycleSpyDb();
        $db->rows = [[
            'codtarifa' => 'T1',
            'id_opcional' => 5,
            'en_catalogo' => '1',
            'en_tarifa' => '0',
            'activa' => '1',
            'orden' => 0,
        ]];
        $model = $this->buildModel($db);

        $this->assertTrue($model->set_en_catalogo('T1', 5, false));

        $inserts = $this->statementsStartingWith($db->execStatements, 'INSERT');
        $updates = $this->statementsStartingWith($db->execStatements, 'UPDATE');

        $this->assertCount(0, $inserts, 'an existing master row must not be duplicated');
        $this->assertCount(1, $updates);
        $updateSql = $this->normalizeSql($updates[0]);
        $this->assertStringContainsString('en_catalogo = 0', $updateSql);
        $this->assertStringContainsString("WHERE codtarifa = 'T1'", $updateSql);
        $this->assertStringContainsString('AND id_opcional = 5', $updateSql);
    }

    public function test_set_en_tarifa_updates_the_existing_master_row(): void
    {
        $db = new TarifaOpcionalLifecycleSpyDb();
        $db->rows = [[
            'codtarifa' => 'T1',
            'id_opcional' => 5,
            'en_catalogo' => '1',
            'en_tarifa' => '0',
            'activa' => '1',
            'orden' => 0,
        ]];
        $model = $this->buildModel($db);

        $this->assertTrue($model->set_en_tarifa('T1', 5, true));

        $updates = $this->statementsStartingWith($db->execStatements, 'UPDATE');
        $this->assertCount(1, $updates);
        $this->assertStringContainsString('en_tarifa = 1', $this->normalizeSql($updates[0]));
    }

    public function test_set_activa_updates_only_the_requested_column(): void
    {
        $db = new TarifaOpcionalLifecycleSpyDb();
        $db->rows = [[
            'codtarifa' => 'T1',
            'id_opcional' => 5,
            'en_catalogo' => '1',
            'en_tarifa' => '1',
            'activa' => '1',
            'orden' => 3,
        ]];
        $model = $this->buildModel($db);

        $this->assertTrue($model->set_activa('T1', 5, false));

        $updates = $this->statementsStartingWith($db->execStatements, 'UPDATE');
        $this->assertCount(1, $updates);
        $updateSql = $this->normalizeSql($updates[0]);
        $this->assertStringContainsString('activa = 0', $updateSql);
        $this->assertStringNotContainsString(
            'en_catalogo =',
            $updateSql,
            'a single-flag toggle must not rewrite unrelated columns with stale values'
        );
        $this->assertStringNotContainsString('en_tarifa =', $updateSql);
        $this->assertStringNotContainsString('orden =', $updateSql);
    }

    public function test_set_orden_creates_or_updates_the_master_row(): void
    {
        $createDb = new TarifaOpcionalLifecycleSpyDb();
        $createDb->rows = [];
        $createDb->selectQueue = [
            [],
            [['en_catalogo' => '1', 'en_tarifa' => '0']],
        ];
        $create = $this->buildModel($createDb);

        $this->assertTrue($create->set_orden('T1', 5, 9));
        $inserts = $this->statementsStartingWith($createDb->execStatements, 'INSERT');
        $this->assertCount(1, $inserts);
        $this->assertStringContainsString("('T1',5,1,0,1,9)", $this->normalizeSql($inserts[0]));

        $updateDb = new TarifaOpcionalLifecycleSpyDb();
        $updateDb->rows = [[
            'codtarifa' => 'T1',
            'id_opcional' => 5,
            'en_catalogo' => '1',
            'en_tarifa' => '0',
            'activa' => '1',
            'orden' => 2,
        ]];
        $update = $this->buildModel($updateDb);

        $this->assertTrue($update->set_orden('T1', 5, 4));
        $updates = $this->statementsStartingWith($updateDb->execStatements, 'UPDATE');
        $this->assertCount(1, $updates);
        $this->assertStringContainsString('orden = 4', $this->normalizeSql($updates[0]));
    }
}
