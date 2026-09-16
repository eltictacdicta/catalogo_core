<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * A DB-free fs_db2 double with an in-memory table store.
 *
 * It understands exactly the two statements `copy_from_tarifa()` is
 * specified to emit (design §11): `DELETE FROM <t> WHERE codtarifa = <x>`
 * and `INSERT INTO <t> (...) SELECT ... FROM <t> WHERE codtarifa = <y>`.
 * Every call is recorded so the tests can prove the transactional
 * contract (both statements with `$transaction = false`).
 */
final class CaracteristicaCloneFakeDb
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables = [];

    /** @var list<array{sql: string, transaction: mixed}> */
    public array $statements = [];

    public bool $beginOk = true;

    public bool $execOk = true;

    /** 1-based execution-call index that must fail, or null when none fails. */
    public ?int $failAtCall = null;

    public bool $commitOk = true;

    public int $rollbacks = 0;

    private int $execCalls = 0;

    private bool $inTransaction = false;

    /** @var array<string, list<array<string, mixed>>> */
    private array $snapshot = [];

    public function var2str($val): string
    {
        if ($val === null) {
            return 'NULL';
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function begin_transaction(): bool
    {
        if (!$this->beginOk) {
            return false;
        }

        $this->inTransaction = true;
        $this->snapshot = $this->tables;

        return true;
    }

    public function commit(): bool
    {
        if (!$this->commitOk) {
            return false;
        }

        $this->inTransaction = false;
        $this->snapshot = [];

        return true;
    }

    public function rollback(): bool
    {
        $this->rollbacks++;
        if ($this->inTransaction) {
            $this->tables = $this->snapshot;
            $this->inTransaction = false;
        }
        $this->snapshot = [];

        return true;
    }

    public function exec($sql, $transaction = true, $params = [], $batch = false)
    {
        $sql = trim((string) $sql);
        $this->execCalls++;
        $this->statements[] = ['sql' => $sql, 'transaction' => $transaction];

        if (!$this->execOk || $this->execCalls === $this->failAtCall) {
            return false;
        }

        if (preg_match('/^DELETE FROM (\w+) WHERE codtarifa = \'([^\']*)\'/i', $sql, $m)) {
            $table = $m[1];
            $destino = $m[2];
            $this->tables[$table] = array_values(array_filter(
                $this->tables[$table] ?? [],
                static fn(array $row): bool => (string) ($row['codtarifa'] ?? '') !== $destino
            ));

            return true;
        }

        if (preg_match('/^INSERT INTO (\w+) \(([^)]*)\) SELECT (.+) FROM (\w+) WHERE codtarifa = \'([^\']*)\'/is', $sql, $m)) {
            $columns = array_map('trim', explode(',', $m[2]));
            $selects = array_map('trim', explode(',', $m[3]));
            foreach ($this->tables[$m[4]] ?? [] as $row) {
                if ((string) ($row['codtarifa'] ?? '') !== $m[5]) {
                    continue;
                }
                $new = [];
                foreach ($columns as $index => $column) {
                    $part = $selects[$index] ?? $column;
                    if (str_starts_with($part, "'")) {
                        $new[$column] = trim($part, "'");
                    } else {
                        $new[$column] = $row[$part] ?? null;
                    }
                }
                $this->tables[$m[1]][] = $new;
            }

            return true;
        }

        return true;
    }
}

/**
 * Contract tests for CAR-10 — `copy_from_tarifa()` on the three scope
 * tables (design §11).
 *
 * The copy is authoritative: the destination rows are deleted and
 * repopulated from the origin inside one transaction, the origin is left
 * untouched, an equal origin/destination pair is a no-op and
 * `coddivisa` is never part of any emitted statement
 * (`R-TAR-CUR-008`).
 */
final class CaracteristicaCloneTest extends TestCase
{
    private static bool $baseLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_db2.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_valor.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/caracteristica_scope_value.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_global.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_familia.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_articulo.php';
        self::$baseLoaded = true;
    }

    // =====================================================================
    // Model doubles (parent constructor skipped, DB swapped for the fake)
    // =====================================================================

    private function articuloModel(CaracteristicaCloneFakeDb $db): object
    {
        return new class($db) extends \FSFramework\model\catalogo_caracteristica_articulo {
            public function __construct($db)
            {
                parent::__construct(false);
                $this->db = $db;
            }
        };
    }

    private function familiaModel(CaracteristicaCloneFakeDb $db): object
    {
        return new class($db) extends \FSFramework\model\catalogo_caracteristica_familia {
            public function __construct($db)
            {
                parent::__construct(false);
                $this->db = $db;
            }
        };
    }

    private function globalModel(CaracteristicaCloneFakeDb $db): object
    {
        return new class($db) extends \FSFramework\model\catalogo_caracteristica_global {
            public function __construct($db)
            {
                parent::__construct(false);
                $this->db = $db;
            }
        };
    }

    /**
     * @return array<string, object>
     */
    private function models(CaracteristicaCloneFakeDb $db): array
    {
        return [
            'articulo' => $this->articuloModel($db),
            'familia' => $this->familiaModel($db),
            'global' => $this->globalModel($db),
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function seed(): array
    {
        return [
            'catalogo_caracteristica_articulo' => [
                ['codtarifa' => 'T1', 'referencia' => 'REF-1', 'id_caracteristica' => 10, 'id_valor' => 1, 'valor' => null, 'custom' => false],
                ['codtarifa' => 'T1', 'referencia' => 'REF-2', 'id_caracteristica' => 10, 'id_valor' => 2, 'valor' => null, 'custom' => false],
            ],
            'catalogo_caracteristica_familia' => [
                ['codtarifa' => 'T1', 'codfamilia' => 'F1', 'id_caracteristica' => 11, 'id_valor' => null, 'valor' => 'XL', 'custom' => true],
            ],
            'catalogo_caracteristica_global' => [
                ['codtarifa' => 'T1', 'id_caracteristica' => 12, 'id_valor' => 1, 'valor' => null, 'custom' => false],
            ],
        ];
    }

    // =====================================================================
    // CAR-10 scenario 1 — the copy carries the three scopes
    // =====================================================================

    public function test_copy_carries_the_three_scopes_and_leaves_the_origin_untouched(): void
    {
        $db = new CaracteristicaCloneFakeDb();
        $db->tables = $this->seed();

        foreach ($this->models($db) as $model) {
            $this->assertTrue($model->copy_from_tarifa('T1', 'T2'), 'copy_from_tarifa must succeed');
        }

        $articuloDestino = array_values(array_filter(
            $db->tables['catalogo_caracteristica_articulo'],
            static fn(array $row): bool => (string) $row['codtarifa'] === 'T2'
        ));
        $this->assertSame(
            [
                ['codtarifa' => 'T2', 'referencia' => 'REF-1', 'id_caracteristica' => 10, 'id_valor' => 1, 'valor' => null, 'custom' => false],
                ['codtarifa' => 'T2', 'referencia' => 'REF-2', 'id_caracteristica' => 10, 'id_valor' => 2, 'valor' => null, 'custom' => false],
            ],
            $articuloDestino,
            'the article scope destination must hold the origin article rows'
        );

        // Origin rows are untouched (the DELETE only targeted the destination).
        foreach ($this->seed() as $table => $rows) {
            foreach ($rows as $row) {
                $this->assertContains(
                    $row,
                    $db->tables[$table],
                    'the origin row must survive the copy in ' . $table
                );
            }
        }

        $this->assertSame(
            ['codtarifa' => 'T2', 'codfamilia' => 'F1', 'id_caracteristica' => 11, 'id_valor' => null, 'valor' => 'XL', 'custom' => true],
            $db->tables['catalogo_caracteristica_familia'][1] ?? null,
            'the family scope destination must hold the origin custom value'
        );
        $this->assertSame(
            ['codtarifa' => 'T2', 'id_caracteristica' => 12, 'id_valor' => 1, 'valor' => null, 'custom' => false],
            $db->tables['catalogo_caracteristica_global'][1] ?? null,
            'the global scope destination must hold the origin global row'
        );
    }

    public function test_every_emitted_statement_runs_inside_the_transaction(): void
    {
        $db = new CaracteristicaCloneFakeDb();
        $db->tables = $this->seed();

        $this->articuloModel($db)->copy_from_tarifa('T1', 'T2');

        $this->assertCount(2, $db->statements, 'a DELETE and an INSERT..SELECT are expected');
        $this->assertStringStartsWith('DELETE FROM catalogo_caracteristica_articulo', $db->statements[0]['sql']);
        $this->assertStringStartsWith('INSERT INTO catalogo_caracteristica_articulo', $db->statements[1]['sql']);

        foreach ($db->statements as $statement) {
            $this->assertFalse(
                $statement['transaction'],
                'both statements must run with $transaction = false (explicit commit)'
            );
        }

        foreach ($db->statements as $statement) {
            $this->assertStringNotContainsString(
                'coddivisa',
                $statement['sql'],
                'R-TAR-CUR-008: coddivisa must never be copied or altered'
            );
        }
    }

    // =====================================================================
    // CAR-10 scenario 2 — destination rows are replaced, not merged
    // =====================================================================

    public function test_destination_rows_are_replaced_not_merged(): void
    {
        $db = new CaracteristicaCloneFakeDb();
        $db->tables = $this->seed();
        $db->tables['catalogo_caracteristica_familia'][] = [
            'codtarifa' => 'T2', 'codfamilia' => 'F1', 'id_caracteristica' => 11, 'id_valor' => null, 'valor' => 'STALE', 'custom' => true,
        ];
        $db->tables['catalogo_caracteristica_familia'][] = [
            'codtarifa' => 'T2', 'codfamilia' => 'F9', 'id_caracteristica' => 11, 'id_valor' => 2, 'valor' => null, 'custom' => false,
        ];

        $this->familiaModel($db)->copy_from_tarifa('T1', 'T2');

        $destino = array_values(array_filter(
            $db->tables['catalogo_caracteristica_familia'],
            static fn(array $row): bool => (string) $row['codtarifa'] === 'T2'
        ));

        $this->assertCount(1, $destino, 'the stale destination rows must be dropped, not merged');
        $this->assertSame('XL', $destino[0]['valor'], 'the destination must carry the origin value');
        foreach ($destino as $row) {
            $this->assertNotSame('STALE', $row['valor']);
            $this->assertNotSame('F9', $row['codfamilia']);
        }
    }

    // =====================================================================
    // CAR-10 scenario 3 — same origin and destination is a no-op
    // =====================================================================

    public function test_same_origin_and_destination_is_a_noop(): void
    {
        $db = new CaracteristicaCloneFakeDb();
        $db->tables = $this->seed();
        $before = $db->tables;

        foreach ($this->models($db) as $model) {
            $this->assertTrue($model->copy_from_tarifa('DEF', 'DEF'));
        }

        $this->assertSame([], $db->statements, 'no statement may be emitted when origin === destination');
        $this->assertSame(0, $db->rollbacks);
        $this->assertSame($before, $db->tables, 'no row may be deleted or inserted');
    }

    // =====================================================================
    // Triangulation — failure paths keep the destination intact
    // =====================================================================

    public function test_transaction_begin_failure_returns_false_without_writing(): void
    {
        $db = new CaracteristicaCloneFakeDb();
        $db->tables = $this->seed();
        $db->beginOk = false;

        $this->assertFalse($this->articuloModel($db)->copy_from_tarifa('T1', 'T2'));
        $this->assertSame([], $db->statements);
        $this->assertSame($this->seed(), $db->tables);
    }

    public function test_failed_insert_rolls_back_and_restores_the_destination(): void
    {
        $db = new CaracteristicaCloneFakeDb();
        $db->tables = $this->seed();
        $db->tables['catalogo_caracteristica_articulo'][] = [
            'codtarifa' => 'T2', 'referencia' => 'KEEP', 'id_caracteristica' => 10, 'id_valor' => 1, 'valor' => null, 'custom' => false,
        ];
        $before = $db->tables;
        // The DELETE (call 1) succeeds and mutates the destination; the INSERT
        // (call 2) is the statement that fails, so the rollback is what restores
        // the destination — not the absence of a prior write.
        $db->failAtCall = 2;

        $this->assertFalse($this->articuloModel($db)->copy_from_tarifa('T1', 'T2'));
        $this->assertCount(2, $db->statements, 'the DELETE and the INSERT must both be attempted');
        $this->assertStringStartsWith('DELETE FROM catalogo_caracteristica_articulo', $db->statements[0]['sql']);
        $this->assertStringStartsWith('INSERT INTO catalogo_caracteristica_articulo', $db->statements[1]['sql']);
        $this->assertSame(1, $db->rollbacks, 'a failed statement must roll back');
        $this->assertSame($before, $db->tables, 'the destination must be restored on failure');
    }

    public function test_failed_commit_rolls_back(): void
    {
        $db = new CaracteristicaCloneFakeDb();
        $db->tables = $this->seed();
        $db->commitOk = false;

        $this->assertFalse($this->globalModel($db)->copy_from_tarifa('T1', 'T2'));
        $this->assertSame(1, $db->rollbacks);
        $this->assertSame($this->seed(), $db->tables);
    }
}
