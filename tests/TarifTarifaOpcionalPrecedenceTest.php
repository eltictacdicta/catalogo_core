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
 * Spy DB for the precedence contract. Mirrors the Unit 1 spy: `exec` records
 * write statements and returns true, `select` drains a queue before falling
 * back to the shared `rows` fixture.
 */
final class TarifaOpcionalPrecedenceSpyDb
{
    /** @var list<string> */
    public array $execStatements = [];

    /** @var list<string> */
    public array $selectStatements = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<list<array<string, mixed>>> */
    public array $selectQueue = [];

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

    public function select_limit($sql, $limit = 50, $offset = 0)
    {
        $this->selectStatements[] = trim((string) $sql);

        return $this->rows;
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $this->execStatements[] = trim((string) $sql);

        return true;
    }
}

/**
 * Unit 2 precedence contract for the per-(tarifa, opcional) master state
 * (spec "Master state precedence" + "Master lifecycle: seed, lazy inherit,
 * copy").
 *
 * The master `activa` is the sole activation source: a price row can never
 * activate an opcional, and a missing master row lazily inherits the
 * `tarif_opcional_ext` flags without persisting. The first explicit toggle
 * creates the master row, and from then on the master value wins.
 */
final class TarifTarifaOpcionalPrecedenceTest extends TestCase
{
    private const MODEL_RELATIVE = 'plugins/catalogo_core/model/tarif_tarifa_opcional.php';

    private const FAMILIA_RELATIVE = 'plugins/catalogo_core/model/tarif_tarifa_opcional_familia.php';

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
        require_once FS_FOLDER . '/' . self::FAMILIA_RELATIVE;
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional.php';
        self::$baseLoaded = true;
    }

    /**
     * Builds the master model on a spy DB. The anonymous subclass keeps the
     * spy attached to rows produced through `new static()` (get()/insert path)
     * so the public toggles can be exercised without a live database.
     *
     * @param array{en_catalogo: bool, en_tarifa: bool}|null $defaults
     */
    private function buildModel(TarifaOpcionalPrecedenceSpyDb $db, ?array $defaults = null): object
    {
        return new class($db, $db, $defaults) extends \FSFramework\model\tarif_tarifa_opcional {
            public static ?object $spyDb = null;

            public static ?array $spyDefaults = null;

            private ?array $defaults;

            public function __construct($dbOrData = false, ?object $spy = null, ?array $defaults = null)
            {
                $this->table_name = 'tarif_tarifa_opcional';

                if ($spy !== null) {
                    self::$spyDb = $spy;
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
        };
    }

    /**
     * Builds the scoped family override model (existing resolver, read-only in
     * this change) so the master `orden` default can be compared against the
     * family-level override.
     */
    private function buildFamiliaModel(TarifaOpcionalPrecedenceSpyDb $db): object
    {
        return new class($db) extends \FSFramework\model\tarif_tarifa_opcional_familia {
            public function __construct($db)
            {
                $this->table_name = 'tarif_tarifa_opcional_familia';
                $this->db = $db;
                $this->codtarifa = null;
                $this->id_opcional = null;
                $this->codfamilia = null;
                $this->activo = true;
                $this->orden = 0;
            }
        };
    }

    /**
     * Builds the `tarif_opcional` list model on the spy DB so the
     * `solo_activos` predicate can be inspected without a live database. The
     * constructor is skipped because the real one reaches the DB through
     * `catalogo_opcional`/extension hydration.
     */
    private function buildOpcionalListModel(TarifaOpcionalPrecedenceSpyDb $db): object
    {
        return new class($db) extends \FSFramework\model\tarif_opcional {
            public function __construct($spyDb)
            {
                $this->table_name = 'catalogo_opcionales';
                $this->ext_table = 'tarif_opcional_ext';
                $this->db = $spyDb;
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

    /**
     * @return array<string, mixed>
     */
    private function masterRow(string $activa, string $enCatalogo = '1', string $enTarifa = '0', int $orden = 0): array
    {
        return [
            'codtarifa' => 'T1',
            'id_opcional' => 5,
            'en_catalogo' => $enCatalogo,
            'en_tarifa' => $enTarifa,
            'activa' => $activa,
            'orden' => $orden,
        ];
    }

    // =====================================================================
    // Master wins — activation, catalog flag
    // =====================================================================

    public function test_master_activa_is_authoritative_and_price_rows_never_activate(): void
    {
        $inactiveDb = new TarifaOpcionalPrecedenceSpyDb();
        $inactiveDb->rows = [$this->masterRow('0')];
        $inactive = $this->buildModel($inactiveDb, ['en_catalogo' => true, 'en_tarifa' => true]);

        $this->assertFalse(
            $inactive->resolve_activa('T1', 5),
            'master activa=FALSE must win even when the ext flags say otherwise'
        );
        $this->assertStringNotContainsString(
            'precio',
            strtolower(implode(' ', $inactiveDb->selectStatements)),
            'price rows must never participate in activation resolution'
        );

        $activeDb = new TarifaOpcionalPrecedenceSpyDb();
        $activeDb->rows = [$this->masterRow('1')];
        $active = $this->buildModel($activeDb, ['en_catalogo' => true, 'en_tarifa' => true]);

        $this->assertTrue(
            $active->resolve_activa('T1', 5),
            'master activa=TRUE is active without any price row'
        );
    }

    public function test_master_en_catalogo_wins_over_the_ext_fallback(): void
    {
        $db = new TarifaOpcionalPrecedenceSpyDb();
        $db->rows = [$this->masterRow('1', '0')];
        $model = $this->buildModel($db, ['en_catalogo' => true, 'en_tarifa' => true]);

        $this->assertFalse(
            $model->resolve_en_catalogo('T1', 5),
            'master en_catalogo=FALSE must win over ext en_catalogo=TRUE'
        );

        $db->rows = [$this->masterRow('1', '1')];
        $this->assertTrue($model->resolve_en_catalogo('T1', 5));
    }

    // =====================================================================
    // Missing row lazy-inherits (never persists)
    // =====================================================================

    public function test_missing_row_inherits_ext_flags_without_persisting(): void
    {
        $db = new TarifaOpcionalPrecedenceSpyDb();
        $db->selectQueue = [
            [],
            [['en_catalogo' => '1', 'en_tarifa' => '1']],
        ];
        $model = $this->buildModel($db);

        $state = $model->effective('T1', 5);

        $this->assertSame('inherit', $state['source']);
        $this->assertTrue($state['activa'], 'a missing master row inherits activa=TRUE');
        $this->assertTrue($state['en_catalogo']);
        $this->assertTrue($state['en_tarifa']);
        $this->assertSame([], $db->execStatements, 'lazy inheritance must never write');
    }

    public function test_missing_row_without_ext_row_falls_back_to_master_defaults(): void
    {
        $db = new TarifaOpcionalPrecedenceSpyDb();
        $db->rows = [];
        $model = $this->buildModel($db);

        $state = $model->effective('T1', 5);

        $this->assertSame('inherit', $state['source']);
        $this->assertTrue($state['activa']);
        $this->assertTrue($state['en_catalogo']);
        $this->assertFalse($state['en_tarifa']);
        $this->assertSame(0, $state['orden']);
        $this->assertSame([], $db->execStatements);
    }

    // =====================================================================
    // Order: master default, scoped family override
    // =====================================================================

    public function test_master_orden_is_the_default_and_missing_inherits_zero(): void
    {
        $db = new TarifaOpcionalPrecedenceSpyDb();
        $db->rows = [$this->masterRow('1', '1', '0', 7)];
        $model = $this->buildModel($db);

        $this->assertSame(7, $model->resolve_orden('T1', 5));

        $inheritDb = new TarifaOpcionalPrecedenceSpyDb();
        $inheritDb->rows = [];
        $inherit = $this->buildModel($inheritDb, ['en_catalogo' => true, 'en_tarifa' => false]);

        $this->assertSame(0, $inherit->resolve_orden('T1', 5));
    }

    public function test_scoped_family_orden_overrides_the_master_default(): void
    {
        $db = new TarifaOpcionalPrecedenceSpyDb();
        $db->rows = [];
        $familia = $this->buildFamiliaModel($db);

        $this->assertSame([], $familia->get_opcionales_activos('T1', 'FAM1'));

        $sql = $this->normalizeSql($db->selectStatements[0]);
        $this->assertStringContainsString(
            'COALESCE(tof.orden, 999999) as orden_tarifa',
            $sql,
            'the scoped family orden must drive ordering ahead of the name fallback'
        );
        $this->assertStringContainsString(
            'ORDER BY orden_tarifa ASC, o.nombre ASC',
            $sql,
            'the scoped orden overrides the alphabetical default'
        );
    }

    // =====================================================================
    // First explicit toggle — master wins over the inherited default
    // =====================================================================

    public function test_first_explicit_toggle_creates_the_master_row_and_overrides_inherited_activation(): void
    {
        $db = new TarifaOpcionalPrecedenceSpyDb();
        $db->rows = [];
        $db->selectQueue = [
            [],
            [['en_catalogo' => '1', 'en_tarifa' => '1']],
        ];
        $model = $this->buildModel($db);

        $this->assertTrue(
            $model->set_activa('T1', 5, false),
            'the first explicit toggle must create the master row'
        );

        $inserts = $this->statementsStartingWith($db->execStatements, 'INSERT');
        $this->assertCount(1, $inserts);
        $this->assertStringContainsString(
            "('T1',5,1,1,0,0)",
            $this->normalizeSql($inserts[0]),
            'the created row must inherit ext flags and set activa=FALSE'
        );

        // Simulate the row now persisted: master activa=FALSE must win over
        // the inheritance that previously resolved as active.
        $db->rows = [$this->masterRow('0', '1', '1')];
        $this->assertFalse(
            $model->resolve_activa('T1', 5),
            'after the explicit toggle the master activa is authoritative'
        );
    }

    // =====================================================================
    // solo_activos list/count repoint: master activa, never price rows
    // =====================================================================

    public function test_search_solo_activos_filters_by_master_activa_not_price_rows(): void
    {
        $db = new TarifaOpcionalPrecedenceSpyDb();
        $model = $this->buildOpcionalListModel($db);

        $model->search('', 0, '', 'T1', true);

        $this->assertNotEmpty($db->selectStatements, 'search() must issue a query');
        $sql = $this->normalizeSql($db->selectStatements[0]);

        $this->assertStringContainsString('NOT EXISTS', $sql);
        $this->assertStringContainsString('tarif_tarifa_opcional', $sql);
        $this->assertStringContainsString("tto.codtarifa = 'T1'", $sql);
        $this->assertStringContainsString('tto.id_opcional = o.id', $sql);
        $this->assertStringContainsString(
            'tto.activa = FALSE',
            $sql,
            'solo_activos must drop only opcionales explicitly set activa=FALSE'
        );
        $this->assertStringNotContainsString(
            'catalogo_opcional_precio',
            $sql,
            'price-row presence must never activate an opcional'
        );
    }

    public function test_count_filtered_solo_activos_filters_by_master_activa_not_price_rows(): void
    {
        $db = new TarifaOpcionalPrecedenceSpyDb();
        $model = $this->buildOpcionalListModel($db);

        $model->count_filtered('', '', 'T1', true);

        $this->assertNotEmpty($db->selectStatements, 'count_filtered() must issue a query');
        $sql = $this->normalizeSql($db->selectStatements[0]);

        $this->assertStringContainsString('NOT EXISTS', $sql);
        $this->assertStringContainsString('tarif_tarifa_opcional', $sql);
        $this->assertStringContainsString("tto.codtarifa = 'T1'", $sql);
        $this->assertStringContainsString('tto.activa = FALSE', $sql);
        $this->assertStringNotContainsString(
            'catalogo_opcional_precio',
            $sql,
            'the filtered count must never join the price table for activation'
        );
    }

    public function test_search_without_solo_activos_skips_the_master_activation_predicate(): void
    {
        $db = new TarifaOpcionalPrecedenceSpyDb();
        $model = $this->buildOpcionalListModel($db);

        $model->search('', 0, 'FAM1', 'T1', false);

        $this->assertNotEmpty($db->selectStatements, 'search() must issue a query');
        $sql = $this->normalizeSql($db->selectStatements[0]);

        $this->assertStringNotContainsString(
            'NOT EXISTS',
            $sql,
            'the master activation filter belongs to solo_activos only'
        );
        $this->assertStringNotContainsString(
            'tto.activa',
            $sql,
            'without solo_activos the master must never filter rows by activation'
        );
    }

    // =====================================================================
    // Order consumer: master default + scoped family override (AD4)
    // =====================================================================

    public function test_per_tarifa_listing_orders_by_master_orden_default(): void
    {
        $db = new TarifaOpcionalPrecedenceSpyDb();
        $model = $this->buildOpcionalListModel($db);

        $model->search('', 0, '', 'T1', false);

        $this->assertNotEmpty($db->selectStatements, 'search() must issue a query');
        $sql = $this->normalizeSql($db->selectStatements[0]);

        $this->assertStringContainsString(
            'LEFT JOIN tarif_tarifa_opcional mto',
            $sql,
            'the per-tarifa listing must join the master to read its default orden'
        );
        $this->assertStringContainsString(
            'COALESCE(mto.orden, 999999) AS orden_tarifa',
            $sql,
            'the master orden is the default order source'
        );
        $this->assertStringContainsString(
            'ORDER BY orden_tarifa ASC, o.nombre ASC',
            $sql,
            'the listing must order by the master default before the name fallback'
        );
    }

    public function test_per_tarifa_listing_scoped_family_orden_overrides_the_master_default(): void
    {
        $db = new TarifaOpcionalPrecedenceSpyDb();
        $model = $this->buildOpcionalListModel($db);

        $model->search('', 0, 'FAM1', 'T1', false);

        $this->assertNotEmpty($db->selectStatements, 'search() must issue a query');
        $sql = $this->normalizeSql($db->selectStatements[0]);

        $this->assertStringContainsString(
            "LEFT JOIN tarif_tarifa_opcional_familia fto ON fto.codtarifa = 'T1'"
            . " AND fto.id_opcional = o.id AND fto.codfamilia = 'FAM1'",
            $sql,
            'the scoped family order must join the per-tarifa family override'
        );
        $this->assertStringContainsString(
            'COALESCE(fto.orden, mto.orden, 999999) AS orden_tarifa',
            $sql,
            'the scoped family orden overrides the master default, which overrides the fallback'
        );
        $this->assertStringContainsString(
            'ORDER BY orden_tarifa ASC, o.nombre ASC',
            $sql,
            'the computed effective order drives the listing'
        );
    }
}
