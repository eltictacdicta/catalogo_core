<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
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

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\FamiliaEstructuraMigration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\CatalogoCore\Fixtures\FamiliaEstructuraMigrationFakeDb;

/**
 * Contract tests for the non-destructive family-structure migration (D3,
 * R-FJ-005): FK-safe step order, dual-engine idempotent inserts, zero
 * destructive statements, orphan rows skipped by the inner join, and an early
 * return when the legacy source table is absent.
 */
final class FamiliaEstructuraMigrationTest extends TestCase
{
    private ?string $errorLogPath = null;

    /** @var string|false */
    private $previousErrorLog = false;

    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/plugins/catalogo_core/tests/fixtures/familia_estructura_migration_fake_db.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/FamiliaEstructuraMigration.php';

        // PHPUnit turns a bare error_log() call into a test error; redirect it
        // to a file so the service can log and tests can assert the content.
        $this->errorLogPath = tempnam(sys_get_temp_dir(), 'familia_migration_log_');
        $this->previousErrorLog = ini_set('error_log', (string) $this->errorLogPath);
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
        }
        if ($this->errorLogPath !== null && is_file($this->errorLogPath)) {
            @unlink($this->errorLogPath);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function seededTables(): array
    {
        return [
            'catalogo_listas_precio' => [
                ['codlista' => 'L1', 'nombre' => 'Lista 1', 'activa' => 1],
            ],
            'tarif_tarifa_familia' => [
                [
                    'codtarifa' => 'L1', 'codfamilia' => 'F1', 'madre' => null,
                    'capitulo' => 'Capitulo', 'nivel' => 'N1', 'en_catalogo' => 1,
                    'en_tarifa' => 0, 'activa' => 1, 'orden' => 1,
                ],
                [
                    'codtarifa' => 'L1', 'codfamilia' => 'F2', 'madre' => 'F1',
                    'capitulo' => '', 'nivel' => 'N2', 'en_catalogo' => 1,
                    'en_tarifa' => 1, 'activa' => 1, 'orden' => 2,
                ],
                [
                    'codtarifa' => 'ORPHAN', 'codfamilia' => 'F3', 'madre' => null,
                    'capitulo' => '', 'nivel' => 'N3', 'en_catalogo' => 1,
                    'en_tarifa' => 0, 'activa' => 1, 'orden' => 3,
                ],
            ],
            'tarif_tarifa_articulo' => [
                [
                    'codtarifa' => 'L1', 'referencia' => 'A1', 'codfamilia' => 'F1',
                    'en_tarifa' => 1, 'en_catalogo' => 1, 'orden' => 1,
                ],
                [
                    'codtarifa' => 'ORPHAN', 'referencia' => 'A2', 'codfamilia' => 'F3',
                    'en_tarifa' => 1, 'en_catalogo' => 1, 'orden' => 2,
                ],
            ],
            'catalogo_familia_estructura' => [],
            'catalogo_articulo_familia_estructura' => [],
            'familias' => [],
        ];
    }

    private function firstIndexContaining(FamiliaEstructuraMigrationFakeDb $db, string $needle): int
    {
        foreach ($db->executed as $index => $sql) {
            if (str_contains($sql, $needle)) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * @param callable(): void $run
     */
    private function captureErrorLog(callable $run): string
    {
        if ($this->errorLogPath !== null) {
            file_put_contents($this->errorLogPath, '');
        }

        $run();

        return $this->errorLogPath !== null && is_file($this->errorLogPath)
            ? (string) file_get_contents($this->errorLogPath)
            : '';
    }

    #[Test]
    public function copiesFamiliesBeforeArticlesInForeignKeyOrder(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb($this->seededTables());

        FamiliaEstructuraMigration::migrateIfNeeded($db, 'mysql');

        $familyStep = $this->firstIndexContaining($db, 'INTO catalogo_familia_estructura');
        $articleStep = $this->firstIndexContaining($db, 'INTO catalogo_articulo_familia_estructura');

        $this->assertNotSame(-1, $familyStep, 'the family-structure copy must run');
        $this->assertNotSame(-1, $articleStep, 'the article-family copy must run');
        $this->assertLessThan(
            $articleStep,
            $familyStep,
            'families must be copied before article assignments (FK order)'
        );
    }

    #[Test]
    public function copiesValidRowsAndMapsCodtarifaToCodlista(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb($this->seededTables());

        FamiliaEstructuraMigration::migrateIfNeeded($db, 'mysql');

        $families = $db->tables['catalogo_familia_estructura'];
        $this->assertCount(2, $families, 'the orphan codtarifa row must be skipped');
        $this->assertSame('L1', $families[0]['codlista']);
        $this->assertSame('F1', $families[0]['codfamilia']);
        $this->assertSame('F1', $families[1]['madre']);

        $articles = $db->tables['catalogo_articulo_familia_estructura'];
        $this->assertCount(1, $articles, 'the orphan article row must be skipped');
        $this->assertSame('L1', $articles[0]['codlista']);
        $this->assertSame('A1', $articles[0]['referencia']);
        $this->assertSame('F1', $articles[0]['codfamilia']);
    }

    #[Test]
    public function mysqlUsesInsertIgnoreForIdempotency(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb($this->seededTables());

        FamiliaEstructuraMigration::migrateIfNeeded($db, 'mysql');

        $familySql = $db->executedContaining('INTO catalogo_familia_estructura')[0] ?? '';
        $articleSql = $db->executedContaining('INTO catalogo_articulo_familia_estructura')[0] ?? '';

        $this->assertStringStartsWith('INSERT IGNORE INTO catalogo_familia_estructura', $familySql);
        $this->assertStringStartsWith('INSERT IGNORE INTO catalogo_articulo_familia_estructura', $articleSql);
        $this->assertStringNotContainsString('ON CONFLICT', $familySql);
    }

    #[Test]
    public function postgresUsesOnConflictDoNothingForIdempotency(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb($this->seededTables());

        FamiliaEstructuraMigration::migrateIfNeeded($db, 'postgresql');

        $familySql = $db->executedContaining('INTO catalogo_familia_estructura')[0] ?? '';
        $articleSql = $db->executedContaining('INTO catalogo_articulo_familia_estructura')[0] ?? '';

        $this->assertStringContainsString('ON CONFLICT (codlista, codfamilia) DO NOTHING', $familySql);
        $this->assertStringContainsString('ON CONFLICT (codlista, referencia) DO NOTHING', $articleSql);
        $this->assertStringNotContainsString('INSERT IGNORE', $familySql);
        $this->assertStringNotContainsString('INSERT IGNORE', $articleSql);
    }

    #[Test]
    public function generatedSqlIsNonDestructive(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb($this->seededTables());

        FamiliaEstructuraMigration::migrateIfNeeded($db, 'mysql');

        $this->assertNotEmpty($db->executed, 'the migration must emit copy statements');
        foreach ($db->executed as $sql) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(DELETE|DROP|UPDATE|TRUNCATE|ALTER)\b/i',
                $sql,
                'the migration must never destroy or mutate legacy data: ' . $sql
            );
        }
    }

    #[Test]
    public function legacySourceTablesAreNeverTouched(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb($this->seededTables());
        $legacyFamilies = $db->tables['tarif_tarifa_familia'];
        $legacyArticles = $db->tables['tarif_tarifa_articulo'];

        FamiliaEstructuraMigration::migrateIfNeeded($db, 'mysql');

        $this->assertSame($legacyFamilies, $db->tables['tarif_tarifa_familia']);
        $this->assertSame($legacyArticles, $db->tables['tarif_tarifa_articulo']);
    }

    #[Test]
    public function orphanRowsAreSkippedByTheInnerJoinAndCounted(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb($this->seededTables());

        $log = $this->captureErrorLog(static function () use ($db): void {
            FamiliaEstructuraMigration::migrateIfNeeded($db, 'mysql');
        });

        $familySql = $db->executedContaining('INTO catalogo_familia_estructura')[0] ?? '';
        $articleSql = $db->executedContaining('INTO catalogo_articulo_familia_estructura')[0] ?? '';

        $this->assertStringContainsString(
            'INNER JOIN catalogo_listas_precio l ON l.codlista = t.codtarifa',
            $familySql
        );
        $this->assertStringContainsString(
            'INNER JOIN catalogo_listas_precio l ON l.codlista = t.codtarifa',
            $articleSql
        );
        $this->assertStringContainsString(
            '[catalogo_core] familia-estructura migration: 2 orphan rows skipped',
            $log,
            'the skipped orphan count must be reported through error_log'
        );
    }

    #[Test]
    public function returnsEarlyWhenLegacyFamilyTableIsMissing(): void
    {
        $tables = $this->seededTables();
        unset($tables['tarif_tarifa_familia']);
        $db = new FamiliaEstructuraMigrationFakeDb($tables);

        $log = $this->captureErrorLog(static function () use ($db): void {
            FamiliaEstructuraMigration::migrateIfNeeded($db, 'mysql');
        });

        $this->assertSame([], $db->executed, 'no statement must run without the legacy source table');
        $this->assertSame(0, $db->transactions['begin'], 'no transaction must be opened');
        $this->assertStringNotContainsString('familia-estructura migration', $log);
    }

    #[Test]
    public function runsOneTransactionPerStep(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb($this->seededTables());

        FamiliaEstructuraMigration::migrateIfNeeded($db, 'mysql');

        $this->assertSame(2, $db->transactions['begin'], 'one transaction per copied table');
        $this->assertSame(2, $db->transactions['commit']);
        $this->assertSame(0, $db->transactions['rollback']);
    }

    #[Test]
    public function failedStepRollsBackAndTheNextStepStillRuns(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb($this->seededTables());
        $db->throwOn = 'INTO catalogo_familia_estructura';

        $log = $this->captureErrorLog(static function () use ($db): void {
            FamiliaEstructuraMigration::migrateIfNeeded($db, 'mysql');
        });

        $this->assertSame(1, $db->transactions['rollback'], 'a throwing step must roll back');
        $this->assertSame(2, $db->transactions['begin'], 'the next step must still be attempted');
        $this->assertSame(
            1,
            count($db->executedContaining('INTO catalogo_articulo_familia_estructura')),
            'the article copy must still run after a failed family copy'
        );
        $this->assertSame([], $db->tables['catalogo_familia_estructura']);
        $this->assertStringContainsString('familia-estructura migration step failed', $log);
    }
}
