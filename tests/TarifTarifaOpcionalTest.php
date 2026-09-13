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
 * Unit 1 contract for the per-(tarifa, opcional) master state
 * (spec "Per-tarifa opcional master table" + "Master state precedence").
 *
 * The master is a standalone fs_model keyed by `(codtarifa, id_opcional)`.
 * `effective()` must return the master values when a row exists and must
 * inherit `tarif_opcional_ext`/defaults (activa=TRUE, orden=0) without
 * persisting when it does not. All DB access goes through a spy, and the
 * protected `ext_defaults()` seam keeps lazy inheritance testable with no
 * live database.
 */
final class TarifaOpcionalSpyDb
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

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $this->execStatements[] = trim((string) $sql);

        return true;
    }
}

final class TarifTarifaOpcionalTest extends TestCase
{
    private const MODEL_RELATIVE = 'plugins/catalogo_core/model/tarif_tarifa_opcional.php';

    private const XML_RELATIVE = 'plugins/catalogo_core/model/table/tarif_tarifa_opcional.xml';

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
     * Builds the master model on a spy DB. The anonymous subclass accepts
     * either the spy itself or a hydrated row array (as returned by
     * `get()`/`all_from_tarifa()` via `new static()`), and can stub the
     * `ext_defaults()` inheritance seam.
     *
     * @param array{en_catalogo: bool, en_tarifa: bool}|null $defaults
     */
    private function buildModel(TarifaOpcionalSpyDb $db, ?array $defaults = null): object
    {
        return new class($db, $defaults) extends \FSFramework\model\tarif_tarifa_opcional {
            private ?array $defaults;

            public function __construct($dbOrData = false, ?array $defaults = null)
            {
                $this->table_name = 'tarif_tarifa_opcional';
                $this->defaults = $defaults;

                if ($dbOrData instanceof TarifaOpcionalSpyDb) {
                    $this->db = $dbOrData;
                    $this->codtarifa = null;
                    $this->id_opcional = null;
                    $this->en_catalogo = true;
                    $this->en_tarifa = false;
                    $this->activa = true;
                    $this->orden = 0;

                    return;
                }

                $this->db = null;
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

    private function assertNotNullColumn(string $xml, string $name, string $type): void
    {
        $this->assertMatchesRegularExpression(
            '/<nombre>' . preg_quote($name, '/') . '<\/nombre>\s*'
            . '<tipo>' . preg_quote($type, '/') . '<\/tipo>\s*'
            . '<nulo>NO<\/nulo>/i',
            $xml,
            'column ' . $name . ' must be ' . $type . ' NOT NULL'
        );
    }

    private function assertColumnWithDefault(string $xml, string $name, string $type, string $default): void
    {
        $this->assertMatchesRegularExpression(
            '/<nombre>' . preg_quote($name, '/') . '<\/nombre>\s*'
            . '<tipo>' . preg_quote($type, '/') . '<\/tipo>\s*'
            . '<nulo>NO<\/nulo>\s*'
            . '<defecto>' . preg_quote($default, '/') . '<\/defecto>/i',
            $xml,
            'column ' . $name . ' must be ' . $type . ' NOT NULL DEFAULT ' . $default
        );
    }

    private function masterState(bool $activa, bool $enCatalogo, bool $enTarifa, int $orden): array
    {
        return [
            'activa' => $activa,
            'en_catalogo' => $enCatalogo,
            'en_tarifa' => $enTarifa,
            'orden' => $orden,
            'source' => 'master',
        ];
    }

    // =====================================================================
    // Schema: composite PK and cascading FKs
    // =====================================================================

    public function test_master_xml_declares_composite_pk_and_cascading_fks(): void
    {
        $path = FS_FOLDER . '/' . self::XML_RELATIVE;
        $this->assertFileExists($path);
        $xml = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression(
            '/PRIMARY KEY\s*\(\s*codtarifa\s*,\s*id_opcional\s*\)/i',
            $xml,
            'the master PK must be (codtarifa, id_opcional)'
        );
        $this->assertMatchesRegularExpression(
            '/FOREIGN KEY\s*\(\s*codtarifa\s*\)\s*REFERENCES\s+tarif_tarifas\s*\(\s*codtarifa\s*\)\s*'
            . 'ON DELETE CASCADE ON UPDATE CASCADE/i',
            $xml,
            'codtarifa must cascade from tarif_tarifas'
        );
        $this->assertMatchesRegularExpression(
            '/FOREIGN KEY\s*\(\s*id_opcional\s*\)\s*REFERENCES\s+catalogo_opcionales\s*\(\s*id\s*\)\s*'
            . 'ON DELETE CASCADE ON UPDATE CASCADE/i',
            $xml,
            'id_opcional must cascade from catalogo_opcionales'
        );
    }

    public function test_master_xml_declares_not_null_defaults(): void
    {
        $xml = (string) file_get_contents(FS_FOLDER . '/' . self::XML_RELATIVE);

        $this->assertNotNullColumn($xml, 'codtarifa', 'character varying(20)');
        $this->assertNotNullColumn($xml, 'id_opcional', 'integer');
        $this->assertColumnWithDefault($xml, 'en_catalogo', 'boolean', 'TRUE');
        $this->assertColumnWithDefault($xml, 'en_tarifa', 'boolean', 'FALSE');
        $this->assertColumnWithDefault($xml, 'activa', 'boolean', 'TRUE');
        $this->assertColumnWithDefault($xml, 'orden', 'integer', '0');
    }

    // =====================================================================
    // CRUD
    // =====================================================================

    public function test_get_returns_the_master_row(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $db->rows = [[
            'codtarifa' => 'T1',
            'id_opcional' => 5,
            'en_catalogo' => '1',
            'en_tarifa' => '0',
            'activa' => '1',
            'orden' => 3,
        ]];
        $model = $this->buildModel($db);

        $row = $model->get('T1', 5);

        $this->assertInstanceOf(\FSFramework\model\tarif_tarifa_opcional::class, $row);
        $this->assertSame('T1', $row->codtarifa);
        $this->assertSame(5, $row->id_opcional);
        $this->assertTrue($row->en_catalogo);
        $this->assertFalse($row->en_tarifa);
        $this->assertSame(3, $row->orden);
        $this->assertStringContainsString("codtarifa = 'T1'", $db->selectStatements[0]);
        $this->assertStringContainsString('id_opcional = 5', $db->selectStatements[0]);
    }

    public function test_get_returns_false_when_the_row_is_missing(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $db->rows = [];
        $model = $this->buildModel($db);

        $this->assertFalse($model->get('T1', 5));
    }

    public function test_exists_is_false_without_composite_key(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $model = $this->buildModel($db);

        $this->assertFalse($model->exists());
        $this->assertSame([], $db->selectStatements, 'no query runs without a composite key');
    }

    public function test_exists_is_true_when_the_row_is_present(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $db->rows = [['codtarifa' => 'T1', 'id_opcional' => 5]];
        $model = $this->buildModel($db);
        $model->codtarifa = 'T1';
        $model->id_opcional = 5;

        $this->assertTrue($model->exists());
    }

    public function test_save_inserts_all_master_columns_when_new(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $db->rows = [];
        $model = $this->buildModel($db);
        $model->codtarifa = 'T1';
        $model->id_opcional = 5;

        $this->assertTrue($model->save());

        $inserts = $this->statementsStartingWith($db->execStatements, 'INSERT');
        $this->assertCount(1, $inserts);
        $this->assertStringContainsString('tarif_tarifa_opcional', $inserts[0]);
        $this->assertStringContainsString(
            '(codtarifa, id_opcional, en_catalogo, en_tarifa, activa, orden)',
            $inserts[0]
        );
        $this->assertStringContainsString("('T1',5,1,0,1,0)", $inserts[0]);
    }

    public function test_save_updates_when_the_row_exists(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $db->rows = [['codtarifa' => 'T1', 'id_opcional' => 5]];
        $model = $this->buildModel($db);
        $model->codtarifa = 'T1';
        $model->id_opcional = 5;
        $model->en_catalogo = false;
        $model->activa = false;
        $model->orden = 4;

        $this->assertTrue($model->save());

        $updates = $this->statementsStartingWith($db->execStatements, 'UPDATE');
        $this->assertCount(1, $updates);
        $this->assertStringContainsString('en_catalogo = 0', $updates[0]);
        $this->assertStringContainsString('activa = 0', $updates[0]);
        $this->assertStringContainsString('orden = 4', $updates[0]);
        $this->assertStringContainsString("WHERE codtarifa = 'T1'", $updates[0]);
        $this->assertStringContainsString('AND id_opcional = 5', $updates[0]);
    }

    public function test_delete_uses_the_composite_key(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $model = $this->buildModel($db);
        $model->codtarifa = 'T1';
        $model->id_opcional = 5;

        $this->assertTrue($model->delete());

        $deletes = $this->statementsStartingWith($db->execStatements, 'DELETE');
        $this->assertCount(1, $deletes);
        $this->assertStringContainsString('tarif_tarifa_opcional', $deletes[0]);
        $this->assertStringContainsString("codtarifa = 'T1'", $deletes[0]);
        $this->assertStringContainsString('id_opcional = 5', $deletes[0]);
    }

    public function test_all_from_tarifa_hydrates_rows_ordered_by_orden(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $db->rows = [
            [
                'codtarifa' => 'T1',
                'id_opcional' => 1,
                'en_catalogo' => '1',
                'en_tarifa' => '0',
                'activa' => '1',
                'orden' => 2,
            ],
            [
                'codtarifa' => 'T1',
                'id_opcional' => 2,
                'en_catalogo' => '0',
                'en_tarifa' => '1',
                'activa' => '0',
                'orden' => 1,
            ],
        ];
        $model = $this->buildModel($db);

        $list = $model->all_from_tarifa('T1');

        $this->assertCount(2, $list);
        $this->assertInstanceOf(\FSFramework\model\tarif_tarifa_opcional::class, $list[0]);
        $this->assertSame(1, $list[0]->id_opcional);
        $this->assertSame(2, $list[0]->orden);
        $this->assertFalse($list[1]->activa);
        $this->assertStringContainsString("codtarifa = 'T1'", $db->selectStatements[0]);
        $this->assertStringContainsString('ORDER BY orden ASC', $db->selectStatements[0]);
    }

    public function test_count_from_tarifa_returns_an_int(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $db->rows = [['total' => '4']];
        $model = $this->buildModel($db);

        $this->assertSame(4, $model->count_from_tarifa('T1'));
        $this->assertStringContainsString("codtarifa = 'T1'", $db->selectStatements[0]);
    }

    // =====================================================================
    // effective() / resolve_*
    // =====================================================================

    public function test_ext_defaults_is_a_protected_seam(): void
    {
        $ref = new \ReflectionMethod(\FSFramework\model\tarif_tarifa_opcional::class, 'ext_defaults');
        $this->assertTrue(
            $ref->isProtected(),
            'ext_defaults() must stay a protected seam so inheritance is unit-testable'
        );
    }

    public function test_effective_returns_the_master_row_when_present(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $db->rows = [[
            'codtarifa' => 'T1',
            'id_opcional' => 5,
            'en_catalogo' => '0',
            'en_tarifa' => '1',
            'activa' => '0',
            'orden' => 7,
        ]];
        $model = $this->buildModel($db);

        $state = $model->effective('T1', 5);

        $this->assertSame($this->masterState(false, false, true, 7), $state);
        $this->assertSame([], $db->execStatements, 'reading the master must not write');
        $this->assertCount(1, $db->selectStatements, 'a present master row needs no ext look-up');
    }

    public function test_effective_missing_row_inherits_ext_defaults_without_persisting(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $db->rows = [];
        $model = $this->buildModel($db, ['en_catalogo' => true, 'en_tarifa' => true]);

        $state = $model->effective('T1', 5);

        $this->assertSame([
            'activa' => true,
            'en_catalogo' => true,
            'en_tarifa' => true,
            'orden' => 0,
            'source' => 'inherit',
        ], $state);
        $this->assertSame([], $db->execStatements, 'a missing master row must never be persisted on read');
    }

    public function test_effective_missing_row_without_ext_row_falls_back_to_defaults(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $db->rows = [];
        $model = $this->buildModel($db);

        $state = $model->effective('T1', 5);

        $this->assertSame('inherit', $state['source']);
        $this->assertTrue($state['activa'], 'inherited activation defaults to TRUE');
        $this->assertTrue($state['en_catalogo'], 'no ext row falls back to en_catalogo = TRUE');
        $this->assertFalse($state['en_tarifa'], 'no ext row falls back to en_tarifa = FALSE');
        $this->assertSame(0, $state['orden']);
        $this->assertCount(2, $db->selectStatements, 'missing master then ext look-up');
        $this->assertStringContainsString('tarif_opcional_ext', $db->selectStatements[1]);
        $this->assertStringContainsString('id_opcional = 5', $db->selectStatements[1]);
    }

    public function test_effective_missing_row_reads_ext_flags_from_the_ext_table(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $db->selectQueue = [
            [],
            [['en_catalogo' => '1', 'en_tarifa' => '1']],
        ];
        $model = $this->buildModel($db);

        $state = $model->effective('T1', 5);

        $this->assertTrue($state['en_catalogo']);
        $this->assertTrue($state['en_tarifa']);
        $this->assertSame('inherit', $state['source']);
        $this->assertStringContainsString('tarif_opcional_ext', $db->selectStatements[1]);
    }

    public function test_resolve_activa_prefers_the_master_value(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $db->rows = [[
            'codtarifa' => 'T1',
            'id_opcional' => 5,
            'en_catalogo' => '1',
            'en_tarifa' => '0',
            'activa' => '0',
            'orden' => 0,
        ]];
        $model = $this->buildModel($db, ['en_catalogo' => true, 'en_tarifa' => true]);

        $this->assertFalse($model->resolve_activa('T1', 5));

        $inherit = $this->buildModel(new TarifaOpcionalSpyDb(), ['en_catalogo' => true, 'en_tarifa' => true]);
        $this->assertTrue($inherit->resolve_activa('T1', 5));
    }

    public function test_resolve_en_catalogo_prefers_the_master_value(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $db->rows = [[
            'codtarifa' => 'T1',
            'id_opcional' => 5,
            'en_catalogo' => '0',
            'en_tarifa' => '0',
            'activa' => '1',
            'orden' => 0,
        ]];
        $model = $this->buildModel($db, ['en_catalogo' => true, 'en_tarifa' => true]);

        $this->assertFalse(
            $model->resolve_en_catalogo('T1', 5),
            'master en_catalogo must win over the ext fallback'
        );
    }

    public function test_resolve_orden_uses_master_else_zero(): void
    {
        $db = new TarifaOpcionalSpyDb();
        $db->rows = [[
            'codtarifa' => 'T1',
            'id_opcional' => 5,
            'en_catalogo' => '1',
            'en_tarifa' => '0',
            'activa' => '1',
            'orden' => 4,
        ]];
        $model = $this->buildModel($db);

        $this->assertSame(4, $model->resolve_orden('T1', 5));

        $inherit = $this->buildModel(new TarifaOpcionalSpyDb(), ['en_catalogo' => true, 'en_tarifa' => false]);
        $this->assertSame(0, $inherit->resolve_orden('T1', 5));
    }
}
