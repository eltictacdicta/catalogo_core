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

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent;
use FSFramework\Plugins\catalogo_core\Services\FamiliaEstructuraStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\CatalogoCore\Support\FakeFamiliaEstructuraDb;
use Tests\CatalogoCore\Support\FakeFamiliaEstructuraModel;
use Tests\CatalogoCore\Support\FakeFamiliaEstructuraTable;

require_once __DIR__ . '/../Support/FakeFamiliaEstructura.php';

/**
 * Per-list family structure store (familias-jerarquia task 3.1, R-FJ-001/002).
 *
 * Contract pinned here:
 *
 *  - the structure of ONE list is read with a single batch query and cached for
 *    the request (no N+1, no sibling-list leakage);
 *  - writes upsert the canonical model (never a `tarif_*` table) and dispatch
 *    the frozen `ArticlePermissionFilterEvent` before persisting; a denied or
 *    throwing listener blocks the write (fail-closed);
 *  - the list-scoped cycle guard rejects direct, deep and self-parent loops
 *    before any write.
 */
final class FamiliaEstructuraStoreTest extends TestCase
{
    private FakeFamiliaEstructuraTable $table;

    private FakeFamiliaEstructuraDb $db;

    private ?string $previousErrorLog = null;

    protected function setUp(): void
    {
        parent::setUp();

        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_db2.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_familia_estructura.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Event/ArticlePermissionFilterEvent.php';

        FSEventDispatcher::reset();
        $this->previousErrorLog = ini_get('error_log') ?: null;
        $this->table = new FakeFamiliaEstructuraTable();
        $this->db = new FakeFamiliaEstructuraDb($this->table);
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== null) {
            ini_set('error_log', $this->previousErrorLog);
        }
        FSEventDispatcher::reset();
        parent::tearDown();
    }

    private function store(string $nick = 'juan'): FamiliaEstructuraStore
    {
        return new FamiliaEstructuraStore($this->db, $nick, new FakeFamiliaEstructuraModel($this->table));
    }

    // =====================================================================
    // Batch read + list scope (R-FJ-002, D5)
    // =====================================================================

    #[Test]
    public function rowsReadsOneListWithASingleBatchQuery(): void
    {
        $this->table->seed('L1', 'F1', null, '1');
        $this->table->seed('L1', 'F2', 'F1', '1.1');
        $this->table->seed('L2', 'F9', null, '1');
        $store = $this->store();

        $rows = $store->rows('L1');

        $this->assertCount(2, $rows, 'Only the requested list is exposed');
        $this->assertSame(1, $this->db->structureQueries(), 'The whole list is read with ONE query');

        $store->find('L1', 'F2');
        $store->rows('L1');
        $this->assertSame(1, $this->db->structureQueries(), 'The batch stays cached for the request (no N+1)');
    }

    #[Test]
    public function rowsOrdersNaturallyByChapter(): void
    {
        $this->table->seed('L1', 'F10', null, '10');
        $this->table->seed('L1', 'F2', null, '2');
        $this->table->seed('L1', 'F1', null, '1.2');

        $codes = array_map(
            static fn (object $row): string => (string) $row->codfamilia,
            $this->store()->rows('L1')
        );

        $this->assertSame(['F1', 'F2', 'F10'], $codes);
    }

    #[Test]
    public function rowsCanFilterActiveOnlyFromTheSameBatch(): void
    {
        $this->table->seed('L1', 'F1', null, '1', true);
        $this->table->seed('L1', 'F2', null, '2', false);
        $store = $this->store();

        $this->assertCount(2, $store->rows('L1'));
        $active = $store->rows('L1', true);

        $this->assertCount(1, $active);
        $this->assertSame('F1', (string) $active[0]->codfamilia);
        $this->assertSame(1, $this->db->structureQueries());
    }

    // =====================================================================
    // Upsert through the canonical model (R-FJ-002 scenario 1)
    // =====================================================================

    #[Test]
    public function saveUpdatesTheExistingRowOfTheList(): void
    {
        $this->table->seed('L1', 'F1', null, '1');
        $this->table->seed('L2', 'F1', null, '9');

        $ok = $this->store()->save('L1', 'F1', ['capitulo' => '2', 'activa' => false]);

        $this->assertTrue($ok);
        $this->assertSame('2', $this->table->get('L1', 'F1')['capitulo']);
        $this->assertFalse((bool) $this->table->get('L1', 'F1')['activa']);
        $this->assertSame('9', $this->table->get('L2', 'F1')['capitulo'], 'The sibling list is untouched');
        $this->assertSame(1, count($this->table->all('L1')), 'Upsert never duplicates the composite PK');
        $this->assertSame(1, $this->table->writes);
    }

    #[Test]
    public function saveCreatesTheRowWhenTheFamilyIsNotInTheListYet(): void
    {
        $ok = $this->store()->save('L1', 'F1', ['madre' => 'F0', 'capitulo' => '1.1']);

        $this->assertTrue($ok);
        $row = $this->table->get('L1', 'F1');
        $this->assertNotNull($row);
        $this->assertSame('F0', $row['madre']);
        $this->assertSame('1.1', $row['capitulo']);
    }

    // =====================================================================
    // Permission event on structure writes (D6)
    // =====================================================================

    #[Test]
    public function saveDispatchesTheEventWithTheFamilyAndTheList(): void
    {
        $this->table->seed('L1', 'F1', null, '1');
        $captured = null;
        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            static function (ArticlePermissionFilterEvent $event) use (&$captured): void {
                $captured = $event;
            }
        );

        $this->assertTrue($this->store()->save('L1', 'F1', ['capitulo' => '2']));

        $this->assertInstanceOf(ArticlePermissionFilterEvent::class, $captured);
        $this->assertSame(ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE, $captured->getAction());
        $this->assertSame('F1', $captured->getReferencia());
        $this->assertSame('juan', $captured->getNick());
        $this->assertSame('L1', $captured->getCodtarifa());
    }

    #[Test]
    public function deniedEventBlocksTheWriteAndReportsTheReason(): void
    {
        $this->table->seed('L1', 'F1', null, '1');
        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            static function (ArticlePermissionFilterEvent $event): void {
                $event->deny('sin permiso sobre la familia');
            }
        );
        $store = $this->store();

        $this->assertFalse($store->save('L1', 'F1', ['capitulo' => '2']));

        $this->assertSame('1', $this->table->get('L1', 'F1')['capitulo'], 'A denied event writes nothing');
        $this->assertSame(0, $this->table->writes);
        $this->assertSame('sin permiso sobre la familia', $store->lastError());
    }

    #[Test]
    public function throwingListenerDeniesFailClosed(): void
    {
        ini_set('error_log', '/dev/null');
        $this->table->seed('L1', 'F1', null, '1');
        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            static function (): never {
                throw new \RuntimeException('listener bug');
            }
        );
        $store = $this->store();

        $this->assertFalse($store->save('L1', 'F1', ['capitulo' => '2']));

        $this->assertSame(0, $this->table->writes, 'A throwing listener must deny fail-closed');
        $this->assertSame('permission filter error', $store->lastError());
    }

    // =====================================================================
    // Chapter map, add, delete
    // =====================================================================

    #[Test]
    public function saveChapterMapWritesOnlyTheChangedRows(): void
    {
        $this->table->seed('L1', 'F1', null, '1');
        $this->table->seed('L1', 'F2', null, '2');

        $ok = $this->store()->saveChapterMap('L1', ['F1' => '1', 'F2' => '2.5', 'F3' => '3']);

        $this->assertTrue($ok);
        $this->assertSame(1, $this->table->writes, 'Unchanged chapters are not rewritten');
        $this->assertSame('1', $this->table->get('L1', 'F1')['capitulo']);
        $this->assertSame('2.5', $this->table->get('L1', 'F2')['capitulo']);
    }

    #[Test]
    public function deniedChapterMapWritesNothing(): void
    {
        $this->table->seed('L1', 'F1', null, '1');
        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            static function (ArticlePermissionFilterEvent $event): void {
                $event->deny('no');
            }
        );

        $this->assertFalse($this->store()->saveChapterMap('L1', ['F1' => '9']));

        $this->assertSame('1', $this->table->get('L1', 'F1')['capitulo']);
        $this->assertSame(0, $this->table->writes, 'The chapter map is validated before any write');
    }

    #[Test]
    public function addSuggestsTheNextChapterOfTheMother(): void
    {
        $this->table->seed('L1', 'F1', null, '1');
        $this->table->seed('L1', 'F2', null, '2');

        $this->assertTrue($this->store()->add('L1', 'F3', null, ['activa' => true]));

        $this->assertSame('3', $this->table->get('L1', 'F3')['capitulo']);
        $this->assertSame(1, $this->table->writes);
    }

    #[Test]
    public function addRejectsAFamilyAlreadyPresentInTheList(): void
    {
        $this->table->seed('L1', 'F1', null, '1');

        $this->assertFalse($this->store()->add('L1', 'F1', null));
        $this->assertSame(0, $this->table->writes);
    }

    #[Test]
    public function deleteClearsChildrenAndRemovesOnlyTheListRow(): void
    {
        $this->table->seed('L1', 'F1', null, '1');
        $this->table->seed('L1', 'F2', 'F1', '1.1');
        $this->table->seed('L2', 'F1', null, '1');

        $this->assertTrue($this->store()->delete('L1', 'F1'));

        $this->assertNull($this->table->get('L1', 'F1'));
        $this->assertNull($this->table->get('L1', 'F2')['madre'], 'Orphaned children are re-rooted');
        $this->assertSame(1, $this->table->deletes);
        $this->assertNotNull($this->table->get('L2', 'F1'), 'The sibling list keeps its row');
    }

    // =====================================================================
    // List-scoped cycle guard (R-FJ-001 scenario "Cycle rejected")
    // =====================================================================

    #[Test]
    public function directCycleIsRejected(): void
    {
        $this->table->seed('L1', 'F1', null, '1');
        $this->table->seed('L1', 'F2', 'F1', '1.1');
        $this->db->madres = ['F1' => null, 'F2' => 'F1'];

        $this->expectException(\RuntimeException::class);

        $this->store()->assertAcyclicInList('L1', 'F1', 'F2');
    }

    #[Test]
    public function deepCycleIsRejected(): void
    {
        $this->table->seed('L1', 'N1', null, '1');
        $this->table->seed('L1', 'N2', 'N1', '1.1');
        $this->table->seed('L1', 'N3', 'N2', '1.1.1');
        $this->table->seed('L1', 'N4', 'N3', '1.1.1.1');

        $this->expectException(\RuntimeException::class);

        $this->store()->assertAcyclicInList('L1', 'N1', 'N4');
    }

    #[Test]
    public function selfParentIsRejected(): void
    {
        $this->table->seed('L1', 'SOLA', null, '1');

        $this->expectException(\RuntimeException::class);

        $this->store()->assertAcyclicInList('L1', 'SOLA', 'SOLA');
    }

    #[Test]
    public function validChainPassesWithoutExtraQueries(): void
    {
        $this->table->seed('L1', 'RAIZ', null, '1');
        $this->table->seed('L1', 'RAMA', 'RAIZ', '1.1');
        $this->table->seed('L1', 'HOJA', null, '2');
        $store = $this->store();
        $store->rows('L1');

        $store->assertAcyclicInList('L1', 'HOJA', 'RAMA');

        $this->assertSame(1, $this->db->structureQueries(), 'The walk reuses the cached batch');
    }

    #[Test]
    public function clearingTheMotherNeverRaises(): void
    {
        $this->table->seed('L1', 'F1', null, '1');

        $this->store()->assertAcyclicInList('L1', 'F1', null);

        $this->assertSame(0, $this->db->structureQueries(), 'Clearing the mother cannot create a cycle');
    }

    // =====================================================================
    // D6 hard rule: the canonical path never touches tarif_*
    // =====================================================================

    #[Test]
    public function storeNeverReferencesLegacyTarifTables(): void
    {
        $this->table->seed('L1', 'F1', null, '1');
        $this->table->seed('L1', 'F2', 'F1', '1.1');
        $store = $this->store();

        $store->rows('L1');
        $store->save('L1', 'F1', ['capitulo' => '2']);
        $store->saveChapterMap('L1', ['F2' => '2.1']);
        $store->delete('L1', 'F2');

        foreach ($this->db->allSql() as $sql) {
            $this->assertStringNotContainsString('tarif_', $sql, 'The canonical store must not read or write tarif_*');
        }
        $this->assertSame([], $this->db->execs, 'Writes go through the canonical model, not raw SQL');
    }
}
