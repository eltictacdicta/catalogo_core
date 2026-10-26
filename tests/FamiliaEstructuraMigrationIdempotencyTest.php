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

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Services\FamiliaEstructuraMigration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration round-trip of the legacy family-structure copy against a real
 * database (R-FJ-005): seed legacy rows — including one orphan `codtarifa` —
 * run the migration twice, and verify the canonical row counts stay stable
 * while the `tarif_*` sources are left untouched.
 *
 * Fixtures are namespaced with the `TSTFJ` prefix and removed in tearDown.
 * The test is skipped with an explicit reason when no database is reachable or
 * the legacy/canonical tables are not installed.
 */
final class FamiliaEstructuraMigrationIdempotencyTest extends TestCase
{
    private const LIST = 'TSTFJ-L1';

    private const ORPHAN_LIST = 'TSTFJ-ORPH';

    private const FAMILY_1 = 'TSTFJ1';

    private const FAMILY_2 = 'TSTFJ2';

    private const ARTICLE = 'TSTFJ-ART1';

    private const REQUIRED_TABLES = [
        'familias',
        'articulos',
        'catalogo_listas_precio',
        'tarif_tarifas',
        'tarif_tarifa_familia',
        'tarif_tarifa_articulo',
    ];

    private ?\fs_db2 $db = null;

    private ?string $logFile = null;

    /** @var array<int, string>|null */
    private ?array $previousPlugins = null;

    /** @var array<string, array<string, bool>>|null */
    private ?array $canonicalSnapshot = null;

    private const CANONICAL_TABLES = [
        'catalogo_familia_estructura' => 'codfamilia',
        'catalogo_articulo_familia_estructura' => 'referencia',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_db2.php';

        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'familia_estructura_db_log_');
        ini_set('error_log', $this->logFile);

        try {
            $this->db = new \fs_db2();
            $available = $this->db->list_tables() !== false;
        } catch (\Throwable $e) {
            $this->db = null;
            $available = false;
        }

        if (!$available) {
            $this->markTestSkipped('Database not available: the migration round-trip needs a live fs_db2 connection.');
        }

        foreach (self::REQUIRED_TABLES as $table) {
            if (!$this->db->table_exists($table)) {
                $this->markTestSkipped('Missing required table "' . $table . '" — install the catalog and the legacy tariff tables.');
            }
        }

        $this->ensureCanonicalTables();
        $this->snapshotCanonicalTables();
        $this->deleteFixtures();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->restoreCanonicalTables();
            $this->deleteFixtures();
        }

        if ($this->previousPlugins !== null) {
            $GLOBALS['plugins'] = $this->previousPlugins;
        }

        ini_restore('error_log');

        if ($this->logFile !== null && is_file($this->logFile)) {
            @unlink($this->logFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function copiesLegacyRowsVerbatimAndSkipsOrphans(): void
    {
        FamiliaEstructuraMigration::migrateIfNeeded($this->db);

        $rows = $this->canonicalFamilies(self::LIST);
        $this->assertCount(1, $rows, 'the legacy family row must be copied exactly once');
        $this->assertSame(self::FAMILY_1, (string) $rows[0]['codfamilia']);
        $this->assertSame(self::FAMILY_2, (string) $rows[0]['madre'], 'madre is copied verbatim');
        $this->assertSame('CAP-TEST', (string) $rows[0]['capitulo']);
        $this->assertSame('NIVEL-TEST', (string) $rows[0]['nivel'], 'nivel is copied verbatim (no depth reinterpretation)');
        $this->assertSame('0', (string) $rows[0]['en_catalogo']);
        $this->assertSame('1', (string) $rows[0]['en_tarifa']);
        $this->assertSame('1', (string) $rows[0]['activa']);
        $this->assertSame('7', (string) $rows[0]['orden']);

        $assignments = $this->canonicalAssignments(self::LIST);
        $this->assertCount(1, $assignments, 'the legacy assignment row must be copied exactly once');
        $this->assertSame(self::ARTICLE, (string) $assignments[0]['referencia']);
        $this->assertSame(self::FAMILY_1, (string) $assignments[0]['codfamilia']);

        $this->assertCount(
            0,
            $this->canonicalFamilies(self::ORPHAN_LIST),
            'legacy rows whose codtarifa has no price list must be skipped'
        );
        $this->assertCount(0, $this->canonicalAssignments(self::ORPHAN_LIST));
    }

    #[Test]
    public function secondRunInsertsNothing(): void
    {
        FamiliaEstructuraMigration::migrateIfNeeded($this->db);

        $familiesAfterFirst = $this->tableCount('catalogo_familia_estructura');
        $assignmentsAfterFirst = $this->tableCount('catalogo_articulo_familia_estructura');

        FamiliaEstructuraMigration::migrateIfNeeded($this->db);

        $this->assertSame(
            $familiesAfterFirst,
            $this->tableCount('catalogo_familia_estructura'),
            're-running the migration must not insert (or alter) family structure rows'
        );
        $this->assertSame(
            $assignmentsAfterFirst,
            $this->tableCount('catalogo_articulo_familia_estructura'),
            're-running the migration must not insert (or alter) assignment rows'
        );
        $this->assertCount(1, $this->canonicalFamilies(self::LIST));
        $this->assertCount(1, $this->canonicalAssignments(self::LIST));
    }

    #[Test]
    public function legacyTablesKeepAllTheirRows(): void
    {
        $legacyFamilies = $this->legacyFamilyRows();
        $legacyAssignments = $this->legacyAssignmentRows();

        FamiliaEstructuraMigration::migrateIfNeeded($this->db);

        $this->assertSame(
            $legacyFamilies,
            $this->legacyFamilyRows(),
            'tarif_tarifa_familia must remain byte-for-byte untouched (non-destructive migration)'
        );
        $this->assertSame(
            $legacyAssignments,
            $this->legacyAssignmentRows(),
            'tarif_tarifa_articulo must remain byte-for-byte untouched (non-destructive migration)'
        );
    }

    #[Test]
    public function orphanIsReportedInTheMigrationLog(): void
    {
        FamiliaEstructuraMigration::migrateIfNeeded($this->db);

        $log = (string) file_get_contents((string) $this->logFile);
        $this->assertMatchesRegularExpression(
            '/\[catalogo_core\] familia-estructura migration: [1-9]\d* orphan rows skipped \(unmatched codlista\)/',
            $log,
            'the orphan legacy row must be counted and logged'
        );
    }

    // =====================================================================
    // Schema + fixtures
    // =====================================================================

    private function ensureCanonicalTables(): void
    {
        // fs_model resolves the XML schema through $GLOBALS['plugins'] using
        // root-relative paths, so the plugin must be listed before the first
        // model instantiation.
        $this->previousPlugins = is_array($GLOBALS['plugins'] ?? null) ? $GLOBALS['plugins'] : [];
        if (!in_array('catalogo_core', $this->previousPlugins, true)) {
            $GLOBALS['plugins'][] = 'catalogo_core';
        }

        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_familia_estructura.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_familia_estructura.php';

        $errors = [];

        if (!$this->db->table_exists('catalogo_familia_estructura')) {
            $errors = array_merge($errors, (new \FSFramework\model\catalogo_familia_estructura())->get_errors());
        }

        if (!$this->db->table_exists('catalogo_articulo_familia_estructura')) {
            $errors = array_merge($errors, (new \FSFramework\model\catalogo_articulo_familia_estructura())->get_errors());
        }

        foreach (['catalogo_familia_estructura', 'catalogo_articulo_familia_estructura'] as $table) {
            $this->assertTrue(
                $this->db->table_exists($table),
                $table . ' must exist before the migration round-trip'
                . ($errors === [] ? '' : ' (' . json_encode($errors) . ')')
            );
        }
    }

    private function seedFixtures(): void
    {
        $this->db->exec(
            'INSERT INTO familias (codfamilia, descripcion, madre) VALUES ('
            . $this->db->var2str(self::FAMILY_1) . ',' . $this->db->var2str('Family one fixture') . ',NULL), ('
            . $this->db->var2str(self::FAMILY_2) . ',' . $this->db->var2str('Family two fixture') . ',NULL);'
        );

        $this->db->exec(
            'INSERT INTO articulos (referencia, descripcion) VALUES ('
            . $this->db->var2str(self::ARTICLE) . ',' . $this->db->var2str('Article fixture') . ');'
        );

        $this->db->exec(
            'INSERT INTO catalogo_listas_precio (codlista, nombre, activa, por_defecto, coddivisa) VALUES ('
            . $this->db->var2str(self::LIST) . ',' . $this->db->var2str('Lista fixture') . ',1,0,' . $this->db->var2str('EUR') . ');'
        );

        // Legacy rows require a tarif_tarifas parent; the orphan one is a valid
        // legacy tariff that was never promoted to a price list.
        $this->db->exec(
            'INSERT INTO tarif_tarifas (codtarifa, nombre, activa, por_defecto, coddivisa) VALUES ('
            . $this->db->var2str(self::LIST) . ',' . $this->db->var2str('Lista fixture') . ',1,0,' . $this->db->var2str('EUR') . '), ('
            . $this->db->var2str(self::ORPHAN_LIST) . ',' . $this->db->var2str('Tarifa huerfana fixture') . ',1,0,' . $this->db->var2str('EUR') . ');'
        );

        $this->db->exec(
            'INSERT INTO tarif_tarifa_familia '
            . '(codtarifa, codfamilia, madre, capitulo, nivel, en_catalogo, en_tarifa, activa, orden) VALUES ('
            . $this->db->var2str(self::LIST) . ',' . $this->db->var2str(self::FAMILY_1) . ','
            . $this->db->var2str(self::FAMILY_2) . ',' . $this->db->var2str('CAP-TEST') . ','
            . $this->db->var2str('NIVEL-TEST') . ',0,1,1,7), ('
            . $this->db->var2str(self::ORPHAN_LIST) . ',' . $this->db->var2str(self::FAMILY_2) . ',NULL,'
            . $this->db->var2str('') . ',' . $this->db->var2str('') . ',1,0,1,0);'
        );

        $this->db->exec(
            'INSERT INTO tarif_tarifa_articulo '
            . '(codtarifa, referencia, codfamilia, en_tarifa, en_catalogo, orden) VALUES ('
            . $this->db->var2str(self::LIST) . ',' . $this->db->var2str(self::ARTICLE) . ','
            . $this->db->var2str(self::FAMILY_1) . ',1,0,3), ('
            . $this->db->var2str(self::ORPHAN_LIST) . ',' . $this->db->var2str(self::ARTICLE) . ',NULL,1,1,0);'
        );
    }

    private function deleteFixtures(): void
    {
        if ($this->db === null) {
            return;
        }

        $list = $this->db->var2str(self::LIST);
        $orphan = $this->db->var2str(self::ORPHAN_LIST);
        $family1 = $this->db->var2str(self::FAMILY_1);
        $family2 = $this->db->var2str(self::FAMILY_2);
        $article = $this->db->var2str(self::ARTICLE);

        $this->db->exec('DELETE FROM catalogo_articulo_familia_estructura WHERE codlista IN (' . $list . ',' . $orphan . ');');
        $this->db->exec('DELETE FROM catalogo_familia_estructura WHERE codlista IN (' . $list . ',' . $orphan . ');');
        $this->db->exec('DELETE FROM tarif_tarifa_articulo WHERE codtarifa IN (' . $list . ',' . $orphan . ');');
        $this->db->exec('DELETE FROM tarif_tarifa_familia WHERE codtarifa IN (' . $list . ',' . $orphan . ');');
        $this->db->exec('DELETE FROM tarif_tarifas WHERE codtarifa IN (' . $list . ',' . $orphan . ');');
        $this->db->exec('DELETE FROM catalogo_listas_precio WHERE codlista = ' . $list . ';');
        $this->db->exec('DELETE FROM articulos WHERE referencia = ' . $article . ';');
        $this->db->exec('DELETE FROM familias WHERE codfamilia IN (' . $family1 . ',' . $family2 . ');');
    }

    // =====================================================================
    // Reads
    // =====================================================================

    /**
     * The migration is global by design, so a run also copies any real legacy
     * row already present in the database. Snapshot the canonical primary keys
     * to restore the exact pre-test state in tearDown: nothing the operator did
     * not already have survives the test.
     */
    private function snapshotCanonicalTables(): void
    {
        $this->canonicalSnapshot = [];

        foreach (self::CANONICAL_TABLES as $table => $secondKey) {
            $rows = (array) $this->db->select('SELECT codlista, ' . $secondKey . ' AS second_key FROM ' . $table . ';');
            $keys = [];
            foreach ($rows as $row) {
                $keys[(string) $row['codlista'] . "\x1f" . (string) $row['second_key']] = true;
            }
            $this->canonicalSnapshot[$table] = $keys;
        }
    }

    private function restoreCanonicalTables(): void
    {
        if ($this->canonicalSnapshot === null) {
            return;
        }

        foreach (self::CANONICAL_TABLES as $table => $secondKey) {
            $known = $this->canonicalSnapshot[$table] ?? [];
            $rows = (array) $this->db->select('SELECT codlista, ' . $secondKey . ' AS second_key FROM ' . $table . ';');

            foreach ($rows as $row) {
                $key = (string) $row['codlista'] . "\x1f" . (string) $row['second_key'];
                if (isset($known[$key])) {
                    continue;
                }

                $this->db->exec(
                    'DELETE FROM ' . $table . ' WHERE codlista = ' . $this->db->var2str((string) $row['codlista'])
                    . ' AND ' . $secondKey . ' = ' . $this->db->var2str((string) $row['second_key']) . ';'
                );
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function canonicalFamilies(string $codlista): array
    {
        return (array) $this->db->select(
            'SELECT * FROM catalogo_familia_estructura WHERE codlista = ' . $this->db->var2str($codlista)
            . ' ORDER BY codfamilia;'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function canonicalAssignments(string $codlista): array
    {
        return (array) $this->db->select(
            'SELECT * FROM catalogo_articulo_familia_estructura WHERE codlista = ' . $this->db->var2str($codlista)
            . ' ORDER BY referencia;'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function legacyFamilyRows(): array
    {
        return (array) $this->db->select(
            'SELECT * FROM tarif_tarifa_familia ORDER BY codtarifa, codfamilia;'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function legacyAssignmentRows(): array
    {
        return (array) $this->db->select(
            'SELECT * FROM tarif_tarifa_articulo ORDER BY codtarifa, referencia;'
        );
    }

    private function tableCount(string $table): int
    {
        $data = (array) $this->db->select('SELECT COUNT(*) AS total FROM ' . $table . ';');

        return isset($data[0]['total']) ? (int) $data[0]['total'] : 0;
    }
}
