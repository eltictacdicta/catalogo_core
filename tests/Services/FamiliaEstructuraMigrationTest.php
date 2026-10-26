<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\FamiliaEstructuraMigration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fake `fs_db2` for the family-structure migration: answers only the
 * introspection and orphan-count queries the service issues, and records every
 * statement so the SQL contract (order, dialect, non-destructiveness) can be
 * asserted without a live database.
 */
final class FamiliaEstructuraMigrationFakeDb extends \fs_db2
{
    /** @var array<string, bool> */
    public array $tables = [];

    /** @var list<string> */
    public array $executed = [];

    /** @var list<string> */
    public array $transactions = [];

    public int $listOrphansFamilia = 0;

    public int $listOrphansArticulo = 0;

    public int $assignmentOrphans = 0;

    /** @var string|null */
    public ?string $failOn = null;

    /**
     * @param list<string> $tables
     */
    public function __construct(array $tables = [])
    {
        // Deliberately skip parent::__construct(): no engine, no connection.
        $this->tables = array_fill_keys($tables, true);
    }

    public function var2str($val)
    {
        if ($val === null) {
            return 'NULL';
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function select($sql, $params = [])
    {
        $sql = trim($sql);

        if (preg_match("/^SHOW TABLES LIKE '(.+?)';?$/i", $sql, $matches)) {
            return isset($this->tables[$matches[1]]) ? [['Tables_in_db' => $matches[1]]] : [];
        }

        if (str_contains($sql, 'FROM catalogo_familia_estructura e')) {
            return [['total' => $this->assignmentOrphans]];
        }

        if (str_contains($sql, 'FROM tarif_tarifa_familia t')) {
            return [['total' => $this->listOrphansFamilia]];
        }

        if (str_contains($sql, 'FROM tarif_tarifa_articulo t')) {
            return [['total' => $this->listOrphansArticulo]];
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        if ($this->failOn !== null && str_contains($sql, $this->failOn)) {
            throw new \RuntimeException('forced failure for ' . $this->failOn);
        }

        $this->executed[] = trim($sql);

        return true;
    }

    public function begin_transaction()
    {
        $this->transactions[] = 'begin';

        return true;
    }

    public function commit()
    {
        $this->transactions[] = 'commit';

        return true;
    }

    public function rollback()
    {
        $this->transactions[] = 'rollback';

        return true;
    }

    /**
     * @return list<string>
     */
    public function inserts(): array
    {
        return array_values(array_filter(
            $this->executed,
            static fn (string $sql): bool => stripos($sql, 'INSERT') === 0
        ));
    }
}

/**
 * Contract test for the one-way legacy copy (D3, R-FJ-005): FK order,
 * idempotent dialect-native inserts, no destructive statement, orphan skip via
 * the price-list join, and a clean early return on fresh installs.
 */
final class FamiliaEstructuraMigrationTest extends TestCase
{
    private const LEGACY_TABLES = [
        'tarif_tarifa_familia',
        'tarif_tarifa_articulo',
        'catalogo_listas_precio',
        'catalogo_familia_estructura',
        'catalogo_articulo_familia_estructura',
    ];

    private ?string $logFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'familia_estructura_log_');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_restore('error_log');

        if ($this->logFile !== null && is_file($this->logFile)) {
            @unlink($this->logFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function familyStepRunsBeforeArticleStep(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb(self::LEGACY_TABLES);

        FamiliaEstructuraMigration::migrateIfNeeded($db);

        $inserts = $db->inserts();
        $this->assertCount(2, $inserts, 'exactly one set-based insert statement per copied table');
        $this->assertStringContainsString(
            'catalogo_familia_estructura',
            $inserts[0],
            'the family structure must be copied before the article assignments (FK order, R-FJ-005)'
        );
        $this->assertStringContainsString(
            'catalogo_articulo_familia_estructura',
            $inserts[1],
            'the article assignment copy must be the second step (FK order, R-FJ-005)'
        );
    }

    #[Test]
    public function mysqlDialectUsesInsertIgnore(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb(self::LEGACY_TABLES);

        FamiliaEstructuraMigration::migrateIfNeeded($db);

        foreach ($db->inserts() as $sql) {
            $this->assertStringStartsWith(
                'INSERT IGNORE INTO ',
                $sql,
                'the MySQL path must be idempotent via INSERT IGNORE'
            );
        }
    }

    #[Test]
    public function postgresDialectUsesOnConflictDoNothing(): void
    {
        $families = FamiliaEstructuraMigration::familiesInsertSql(true);
        $articles = FamiliaEstructuraMigration::articlesInsertSql(true);

        $this->assertStringContainsString(
            'ON CONFLICT (codlista, codfamilia) DO NOTHING;',
            $families,
            'the PostgreSQL family copy must be idempotent on the composite PK'
        );
        $this->assertStringContainsString(
            'ON CONFLICT (codlista, referencia) DO NOTHING;',
            $articles,
            'the PostgreSQL assignment copy must be idempotent on the composite PK'
        );
        $this->assertStringNotContainsString('INSERT IGNORE', $families . $articles);
    }

    #[Test]
    public function generatedSqlIsNeverDestructive(): void
    {
        $statements = [
            FamiliaEstructuraMigration::familiesInsertSql(true),
            FamiliaEstructuraMigration::familiesInsertSql(false),
            FamiliaEstructuraMigration::articlesInsertSql(true),
            FamiliaEstructuraMigration::articlesInsertSql(false),
        ];

        $db = new FamiliaEstructuraMigrationFakeDb(self::LEGACY_TABLES);
        $db->listOrphansFamilia = 1;
        $db->listOrphansArticulo = 1;
        $db->assignmentOrphans = 1;
        FamiliaEstructuraMigration::migrateIfNeeded($db);

        $statements = array_merge($statements, $db->executed);

        foreach ($statements as $sql) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(DELETE|DROP|UPDATE)\b/i',
                $sql,
                'the migration must never delete, drop or update: ' . $sql
            );
        }
    }

    #[Test]
    public function copiesSkipOrphansThroughThePriceListJoin(): void
    {
        foreach ([true, false] as $postgres) {
            $families = FamiliaEstructuraMigration::familiesInsertSql($postgres);
            $articles = FamiliaEstructuraMigration::articlesInsertSql($postgres);

            $this->assertStringContainsString(
                'INNER JOIN catalogo_listas_precio l ON l.codlista = t.codtarifa',
                $families,
                'the family copy must inner-join the canonical price lists (orphan skip)'
            );
            $this->assertStringContainsString(
                'INNER JOIN catalogo_listas_precio l ON l.codlista = t.codtarifa',
                $articles,
                'the assignment copy must inner-join the canonical price lists (orphan skip)'
            );
        }
    }

    #[Test]
    public function legacyColumnsAreCopiedVerbatim(): void
    {
        $families = FamiliaEstructuraMigration::familiesInsertSql(false);
        $articles = FamiliaEstructuraMigration::articlesInsertSql(false);

        $this->assertMatchesRegularExpression(
            '/INSERT IGNORE INTO catalogo_familia_estructura '
            . '\(codlista, codfamilia, madre, capitulo, nivel, en_catalogo, en_tarifa, activa, orden\) '
            . 'SELECT t\.codtarifa, t\.codfamilia, t\.madre, t\.capitulo, t\.nivel, '
            . 't\.en_catalogo, t\.en_tarifa, t\.activa, t\.orden /',
            $families,
            'code fields must be renamed and every other value copied verbatim (no reinterpretation, D2)'
        );

        $this->assertMatchesRegularExpression(
            '/INSERT IGNORE INTO catalogo_articulo_familia_estructura '
            . '\(codlista, referencia, codfamilia, en_tarifa, en_catalogo, orden\) '
            . 'SELECT t\.codtarifa, t\.referencia, t\.codfamilia, t\.en_tarifa, t\.en_catalogo, t\.orden /',
            $articles,
            'article assignments must be copied verbatim as well'
        );
    }

    #[Test]
    public function returnsEarlyWhenTheLegacyFamilyTableIsMissing(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb([
            'tarif_tarifa_articulo',
            'catalogo_listas_precio',
            'catalogo_familia_estructura',
            'catalogo_articulo_familia_estructura',
        ]);

        FamiliaEstructuraMigration::migrateIfNeeded($db);

        $this->assertSame([], $db->executed, 'fresh installs must not touch the legacy tables');
        $this->assertSame([], $db->transactions, 'a skipped migration must not open a transaction');
    }

    #[Test]
    public function skipsAStepWhenItsCanonicalTableIsMissing(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb([
            'tarif_tarifa_familia',
            'tarif_tarifa_articulo',
            'catalogo_listas_precio',
            'catalogo_articulo_familia_estructura',
        ]);

        FamiliaEstructuraMigration::migrateIfNeeded($db);

        $inserts = $db->inserts();
        $this->assertCount(1, $inserts, 'the missing canonical table must skip only its own step');
        $this->assertStringContainsString('catalogo_articulo_familia_estructura', $inserts[0]);
    }

    #[Test]
    public function logsOrphanCounts(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb(self::LEGACY_TABLES);
        $db->listOrphansFamilia = 2;
        $db->assignmentOrphans = 3;

        FamiliaEstructuraMigration::migrateIfNeeded($db);

        $log = (string) file_get_contents((string) $this->logFile);
        $this->assertStringContainsString(
            '[catalogo_core] familia-estructura migration: 2 orphan rows skipped (unmatched codlista)',
            $log,
            'orphan family rows must be reported in the migration log (R-FJ-005)'
        );
        $this->assertStringContainsString(
            '[catalogo_core] familia-estructura migration: 3 orphan rows skipped (no family structure row for list)',
            $log,
            'assignments without a migrated structure row must be reported too'
        );
    }

    #[Test]
    public function doesNotLogWhenThereAreNoOrphans(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb(self::LEGACY_TABLES);

        FamiliaEstructuraMigration::migrateIfNeeded($db);

        $log = (string) @file_get_contents((string) $this->logFile);
        $this->assertStringNotContainsString('orphan rows skipped', $log);
    }

    #[Test]
    public function failedStepRollsBackAndTheNextStepStillRuns(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb(self::LEGACY_TABLES);
        $db->failOn = 'catalogo_familia_estructura (codlista';

        FamiliaEstructuraMigration::migrateIfNeeded($db);

        $this->assertSame(
            ['begin', 'rollback', 'begin', 'commit'],
            $db->transactions,
            'each step owns its transaction; a failed step rolls back and the next one is still attempted'
        );

        $inserts = $db->inserts();
        $this->assertCount(1, $inserts);
        $this->assertStringContainsString('catalogo_articulo_familia_estructura', $inserts[0]);

        $log = (string) file_get_contents((string) $this->logFile);
        $this->assertStringContainsString('familia-estructura migration step familia failed', $log);
    }
}
