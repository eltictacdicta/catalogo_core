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

use FSFramework\Plugins\catalogo_core\Services\FamiliaTreeGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fake `fs_db2` that resolves `familias.madre` from an in-memory chain so the
 * cycle walk can be exercised without a database.
 */
final class FamiliaTreeGuardFakeDb extends \fs_db2
{
    /** @var array<string, string|null> */
    public array $madres = [];

    /** @var list<string> */
    public array $queries = [];

    /**
     * @param array<string, string|null> $madres
     */
    public function __construct(array $madres = [])
    {
        // Deliberately skip parent::__construct(): no engine, no connection.
        $this->madres = $madres;
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
        $this->queries[] = $sql;

        if (!preg_match("/codfamilia\s*=\s*'([^']*)'/", $sql, $matches)) {
            return [];
        }

        $codfamilia = $matches[1];
        if (!array_key_exists($codfamilia, $this->madres)) {
            return [];
        }

        return [['madre' => $this->madres[$codfamilia]]];
    }
}

/**
 * Cycle guard for the family tree re-parenting path (R-FJ-001): a save that
 * would make a family a descendant of itself must abort before any write.
 */
final class FamiliaTreeGuardTest extends TestCase
{
    #[Test]
    public function directCycleIsRejected(): void
    {
        // A is currently the mother of B; re-parenting A under B closes a cycle.
        $db = new FamiliaTreeGuardFakeDb([
            'MADRE' => null,
            'HIJA' => 'MADRE',
        ]);

        $this->expectException(\RuntimeException::class);

        FamiliaTreeGuard::assertAcyclic($db, 'MADRE', 'HIJA');
    }

    #[Test]
    public function deepCycleIsRejected(): void
    {
        $db = new FamiliaTreeGuardFakeDb([
            'N1' => null,
            'N2' => 'N1',
            'N3' => 'N2',
            'N4' => 'N3',
        ]);

        $this->expectException(\RuntimeException::class);

        FamiliaTreeGuard::assertAcyclic($db, 'N1', 'N4');
    }

    #[Test]
    public function selfParentIsRejected(): void
    {
        $db = new FamiliaTreeGuardFakeDb([
            'SOLA' => null,
        ]);

        $this->expectException(\RuntimeException::class);

        FamiliaTreeGuard::assertAcyclic($db, 'SOLA', 'SOLA');
    }

    #[Test]
    public function validChainPasses(): void
    {
        $db = new FamiliaTreeGuardFakeDb([
            'RAIZ' => null,
            'RAMA' => 'RAIZ',
            'HOJA' => null,
        ]);

        FamiliaTreeGuard::assertAcyclic($db, 'HOJA', 'RAMA');

        $this->assertNotSame([], $db->queries, 'the guard must walk the mother chain');
    }

    #[Test]
    public function nullOrEmptyMotherPassesWithoutWalking(): void
    {
        $db = new FamiliaTreeGuardFakeDb([
            'HOJA' => 'RAIZ',
        ]);

        FamiliaTreeGuard::assertAcyclic($db, 'HOJA', null);
        FamiliaTreeGuard::assertAcyclic($db, 'HOJA', '');

        $this->assertSame([], $db->queries, 'clearing the mother cannot create a cycle');
    }

    #[Test]
    public function unknownMotherPasses(): void
    {
        $db = new FamiliaTreeGuardFakeDb([
            'HOJA' => null,
        ]);

        FamiliaTreeGuard::assertAcyclic($db, 'HOJA', 'NO-EXISTE');

        $this->assertNotSame([], $db->queries);
    }

    #[Test]
    public function existingCycleNotReachingTheFamilyIsRejected(): void
    {
        // A↔B is already corrupt data; nesting HOJA under A cannot be proven acyclic.
        $db = new FamiliaTreeGuardFakeDb([
            'A' => 'B',
            'B' => 'A',
            'HOJA' => null,
        ]);

        $this->expectException(\RuntimeException::class);

        FamiliaTreeGuard::assertAcyclic($db, 'HOJA', 'A');
    }

    #[Test]
    public function emptyFamilyCodeIsRejected(): void
    {
        $db = new FamiliaTreeGuardFakeDb([]);

        $this->expectException(\RuntimeException::class);

        FamiliaTreeGuard::assertAcyclic($db, '', 'MADRE');
    }
}
