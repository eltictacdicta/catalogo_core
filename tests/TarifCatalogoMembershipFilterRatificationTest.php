<?php
/**
 * This file is part of catalogo_core
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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// The doubles below extend these legacy models at file scope, so the classes
// must exist before PHPUnit loads this file.
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa_articulo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa_familia.php';

/**
 * Ratifies the membership filters that `caracteristicas-post-soak-drop` (C2.2)
 * will rewrite before the clause-2 column drop.
 *
 * The change recorded a prerequisite that nothing froze: the DEV-17 rewrite
 * moves these filters off the legacy `tarif_*` columns onto the canonical
 * feature tables, and the change referenced a
 * `TarifCatalogoMembershipFilterRatificationTest` that did not exist. This is
 * that contract.
 *
 * What is ratified here is the CURRENT source, not the desired one: which table
 * each helper reads and which flag it filters on. When C2.2 lands, these
 * assertions MUST fail first and be updated deliberately, together with the
 * rewrite — that is the point. Nothing here should be "fixed" by loosening an
 * assertion.
 *
 * Verified surface (see the premise audit in the change's tasks.md):
 *
 * | Helper | File | Reads |
 * |---|---|---|
 * | `all_en_catalogo()` | model/tarif_tarifa_articulo.php:360 | `tarif_tarifa_articulo.en_catalogo` |
 * | `count_en_catalogo()` | model/tarif_tarifa_articulo.php:457 | `tarif_tarifa_articulo.en_catalogo` |
 * | `count_en_tarifa()` | model/tarif_tarifa_articulo.php:440 | `tarif_tarifa_articulo.en_tarifa` |
 * | `all_en_catalogo_from_tarifa()` | model/tarif_tarifa_familia.php:429 | `tarif_tarifa_familia.en_catalogo` + `activa` |
 *
 * Strategy: the models are instantiated through subclasses that skip the
 * database-bound constructor and inject a recording double, so the assertions
 * are DB-free and read the SQL the helpers actually emit.
 */
final class TarifCatalogoMembershipFilterRatificationTest extends TestCase
{
    #[Test]
    public function allEnCatalogoFiltersTheCatalogFlagOfTheLegacyArticleTable(): void
    {
        $db = new MembershipRecordingDb([]);
        $model = new RatifiableTarifArticulo($db);

        $model->all_en_catalogo('T1', 10, 25);

        $sql = $db->lastSelectLimitSql;
        $this->assertNotSame('', $sql, 'all_en_catalogo must page through select_limit');
        $this->assertStringContainsString('tarif_tarifa_articulo', $sql);
        $this->assertStringContainsString("codtarifa = 'T1'", $sql);
        $this->assertMatchesRegularExpression(
            '/en_catalogo\s*=\s*TRUE/',
            $sql,
            'the catalogue membership flag is the ratified filter'
        );
        $this->assertStringNotContainsString('en_tarifa', $sql, 'the catalogue listing must not filter on the tariff flag');
        $this->assertMatchesRegularExpression('/ORDER BY\s+ta\.orden ASC,\s*a\.referencia ASC/', $sql);
        $this->assertSame([25, 10], [$db->lastLimit, $db->lastOffset], 'limit and offset must reach the driver');
    }

    #[Test]
    public function countEnCatalogoCountsTheCatalogFlag(): void
    {
        $db = new MembershipRecordingDb([['total' => 7]]);
        $model = new RatifiableTarifArticulo($db);

        $this->assertSame(7, $model->count_en_catalogo('T1'));
        $this->assertStringContainsString('tarif_tarifa_articulo', $db->lastSelectSql);
        $this->assertMatchesRegularExpression('/en_catalogo\s*=\s*TRUE/', $db->lastSelectSql);
        $this->assertStringContainsString("codtarifa = 'T1'", $db->lastSelectSql);
    }

    #[Test]
    public function countEnTarifaCountsTheTariffFlag(): void
    {
        $db = new MembershipRecordingDb([['total' => 3]]);
        $model = new RatifiableTarifArticulo($db);

        $this->assertSame(3, $model->count_en_tarifa('T1'));
        $this->assertStringContainsString('tarif_tarifa_articulo', $db->lastSelectSql);
        $this->assertMatchesRegularExpression('/en_tarifa\s*=\s*TRUE/', $db->lastSelectSql);
        $this->assertStringNotContainsString('en_catalogo', $db->lastSelectSql, 'the tariff counter must not use the catalogue flag');
    }

    #[Test]
    public function allEnCatalogoFromTarifaReadsActiveLegacyFamilyRows(): void
    {
        $db = new MembershipRecordingDb([]);
        $model = new RatifiableTarifFamilia($db);

        $this->assertSame([], $model->all_en_catalogo_from_tarifa('T1'));

        $sql = $db->lastSelectSql;
        $this->assertStringContainsString('tarif_tarifa_familia', $sql);
        $this->assertStringContainsString("codtarifa = 'T1'", $sql);
        $this->assertMatchesRegularExpression('/activa\s*=\s*TRUE/', $sql, 'inactive families are excluded by the ratified filter');
        $this->assertMatchesRegularExpression('/en_catalogo\s*=\s*TRUE/', $sql);
        $this->assertMatchesRegularExpression('/ORDER BY\s+tf\.orden ASC,\s*tf\.capitulo ASC/', $sql);
    }
}

/**
 * Records the SQL each membership helper emits, without touching a database.
 */
final class MembershipRecordingDb
{
    public string $lastSelectSql = '';
    public string $lastSelectLimitSql = '';
    public int $lastLimit = 0;
    public int $lastOffset = 0;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(private array $rows)
    {
    }

    public function var2str($value): string
    {
        return "'" . addslashes((string) $value) . "'";
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $this->lastSelectSql = $sql;

        return $this->rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function select_limit(string $sql, int $limit, int $offset = 0): array
    {
        $this->lastSelectLimitSql = $sql;
        $this->lastLimit = $limit;
        $this->lastOffset = $offset;

        return $this->rows;
    }
}

/**
 * Skips the DB-bound constructor and injects the recorder.
 */
final class RatifiableTarifArticulo extends \FSFramework\model\tarif_tarifa_articulo
{
    public function __construct(MembershipRecordingDb $db)
    {
        $this->db = $db;
        $this->table_name = 'tarif_tarifa_articulo';
    }

    protected function default_codidioma(): string
    {
        return 'es_ES';
    }
}

/**
 * Same seam for the family-side helper.
 */
final class RatifiableTarifFamilia extends \FSFramework\model\tarif_tarifa_familia
{
    public function __construct(MembershipRecordingDb $db)
    {
        $this->db = $db;
        $this->table_name = 'tarif_tarifa_familia';
    }
}
