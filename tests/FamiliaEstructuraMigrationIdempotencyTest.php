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

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Services\FamiliaEstructuraMigration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\CatalogoCore\Fixtures\FamiliaEstructuraMigrationFakeDb;

/**
 * Round-trip idempotency seam for R-FJ-005 (tasks 2.4 / 5.2).
 *
 * Seeds legacy rows (including one orphan `codtarifa`), runs the migration
 * twice, and proves: canonical counts stay stable, the legacy tables are
 * untouched, the orphan is skipped and logged, and every step commits.
 */
final class FamiliaEstructuraMigrationIdempotencyTest extends TestCase
{
    private ?string $errorLogPath = null;

    /** @var string|false */
    private $previousErrorLog = false;

    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/plugins/catalogo_core/tests/fixtures/familia_estructura_migration_fake_db.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/FamiliaEstructuraMigration.php';

        $this->errorLogPath = tempnam(sys_get_temp_dir(), 'familia_idempotency_log_');
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
                    'capitulo' => 'Cap', 'nivel' => 'N1', 'en_catalogo' => 1,
                    'en_tarifa' => 0, 'activa' => 1, 'orden' => 1,
                ],
                [
                    'codtarifa' => 'L1', 'codfamilia' => 'F2', 'madre' => 'F1',
                    'capitulo' => '', 'nivel' => 'N2', 'en_catalogo' => 1,
                    'en_tarifa' => 1, 'activa' => 1, 'orden' => 2,
                ],
                // Orphan: no catalogo_listas_precio row for this codtarifa.
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
            ],
            'catalogo_familia_estructura' => [],
            'catalogo_articulo_familia_estructura' => [],
        ];
    }

    #[Test]
    public function secondRunKeepsCanonicalCountsStableAndLeavesLegacyTablesUntouched(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb($this->seededTables());
        $legacyFamilies = $db->tables['tarif_tarifa_familia'];
        $legacyArticles = $db->tables['tarif_tarifa_articulo'];

        FamiliaEstructuraMigration::migrateIfNeeded($db, 'mysql');

        $familiesAfterFirstRun = $db->tables['catalogo_familia_estructura'];
        $articlesAfterFirstRun = $db->tables['catalogo_articulo_familia_estructura'];
        $this->assertCount(2, $familiesAfterFirstRun);
        $this->assertCount(1, $articlesAfterFirstRun);

        FamiliaEstructuraMigration::migrateIfNeeded($db, 'mysql');

        $this->assertSame(
            $familiesAfterFirstRun,
            $db->tables['catalogo_familia_estructura'],
            'a second run must insert no duplicate family structure rows'
        );
        $this->assertSame(
            $articlesAfterFirstRun,
            $db->tables['catalogo_articulo_familia_estructura'],
            'a second run must insert no duplicate article assignment rows'
        );
        $this->assertSame($legacyFamilies, $db->tables['tarif_tarifa_familia']);
        $this->assertSame($legacyArticles, $db->tables['tarif_tarifa_articulo']);
        $this->assertSame(4, $db->transactions['begin'], 'two steps per run');
        $this->assertSame(4, $db->transactions['commit']);
        $this->assertSame(0, $db->transactions['rollback']);
    }

    #[Test]
    public function orphanCodtarifaIsSkippedAndReportedOnEveryRun(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb($this->seededTables());

        FamiliaEstructuraMigration::migrateIfNeeded($db, 'mysql');
        FamiliaEstructuraMigration::migrateIfNeeded($db, 'mysql');

        $this->assertSame(
            ['F1', 'F2'],
            array_column($db->tables['catalogo_familia_estructura'], 'codfamilia'),
            'the orphan F3 must never reach the canonical table'
        );

        $log = $this->errorLogPath !== null ? (string) file_get_contents($this->errorLogPath) : '';
        $this->assertSame(
            2,
            substr_count($log, '[catalogo_core] familia-estructura migration: 1 orphan rows skipped'),
            'each run must report the one skipped orphan'
        );
    }

    #[Test]
    public function repeatedRunsNeverEmitDestructiveStatements(): void
    {
        $db = new FamiliaEstructuraMigrationFakeDb($this->seededTables());

        FamiliaEstructuraMigration::migrateIfNeeded($db, 'mysql');
        FamiliaEstructuraMigration::migrateIfNeeded($db, 'mysql');

        $this->assertNotEmpty($db->executed);
        foreach ($db->executed as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\b(DELETE|DROP|UPDATE|TRUNCATE)\b/i', $sql);
        }
    }
}
