<?php
declare(strict_types=1);
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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * R-MT-007: every tariff keeps a mirror row in `catalogo_listas_precio`
 * (same code, name, flags and currency) written in the same save/delete,
 * because the canonical readers and five cascading FKs live on the list table.
 */
final class TarifTarifaListMirrorTest extends TestCase
{
    private const LIST_TABLE = 'catalogo_listas_precio';

    private MirrorRecordingDb $db;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new \fs_core_log())->clear();
        $this->db = new MirrorRecordingDb();
    }

    public function test_new_tariff_inserts_its_mirror_with_the_same_data(): void
    {
        $tarifa = $this->tariff('X', 'Exportación', 'usd');

        $this->assertTrue($tarifa->save());

        $insert = $this->db->firstExecMatching('~^INSERT INTO ' . self::LIST_TABLE . '\b~');
        $this->assertNotNull($insert, 'a new tariff must create its list mirror');
        $this->assertStringContainsString("'X'", $insert);
        $this->assertStringContainsString("'Exportación'", $insert);
        $this->assertStringContainsString("'USD'", $insert);
        $this->assertMirrorWrittenAfterTariff();
    }

    public function test_editing_a_tariff_updates_its_mirror(): void
    {
        $this->db->existing('tarif_tarifas', 'X');
        $this->db->existing(self::LIST_TABLE, 'X');
        $tarifa = $this->tariff('X', 'Nuevo nombre', 'USD');

        $this->assertTrue($tarifa->save());

        $update = $this->db->firstExecMatching('~^UPDATE ' . self::LIST_TABLE . ' SET nombre\b~');
        $this->assertNotNull($update, 'an edited tariff must update its mirror');
        $this->assertStringContainsString("coddivisa = 'USD'", $update);
        $this->assertStringContainsString("nombre = 'Nuevo nombre'", $update);
        $this->assertStringContainsString("WHERE codlista = 'X'", $update);
        $this->assertNull(
            $this->db->firstExecMatching('~^INSERT INTO ' . self::LIST_TABLE . '\b~'),
            'an existing mirror must be updated, not duplicated'
        );
    }

    public function test_default_is_exclusive_in_both_tables(): void
    {
        $this->db->existing(self::LIST_TABLE, 'B');
        $tarifa = $this->tariff('B', 'Tarifa B', 'EUR');
        $tarifa->por_defecto = true;

        $this->assertTrue($tarifa->save());

        $this->assertNotNull(
            $this->db->firstExecMatching("~^UPDATE tarif_tarifas SET por_defecto = FALSE WHERE codtarifa != 'B'~"),
            'the tariff table keeps a single default'
        );
        $this->assertNotNull(
            $this->db->firstExecMatching('~^UPDATE ' . self::LIST_TABLE . " SET por_defecto = FALSE WHERE codlista != 'B'~"),
            'the list table must agree on the single default'
        );
    }

    public function test_invalid_tariff_never_touches_the_mirror(): void
    {
        $tarifa = $this->tariff('X', 'Tarifa X', 'EURO');

        $this->assertFalse($tarifa->save());
        $this->assertSame([], $this->db->statementsOn(self::LIST_TABLE));
    }

    public function test_failed_tariff_write_never_touches_the_mirror(): void
    {
        $this->db->failExecOn('tarif_tarifas');
        $tarifa = $this->tariff('X', 'Tarifa X', 'EUR');

        $this->assertFalse($tarifa->save());
        $this->assertSame([], $this->db->statementsOn(self::LIST_TABLE));
    }

    public function test_missing_list_table_degrades_to_tariff_only_save(): void
    {
        $this->db->missingTable(self::LIST_TABLE);
        $tarifa = $this->tariff('X', 'Tarifa X', 'EUR');

        $this->assertTrue($tarifa->save(), 'installs without the list table keep saving tariffs');
        $this->assertSame([], $this->db->statementsOn(self::LIST_TABLE));
    }

    public function test_delete_deactivates_a_referenced_mirror_instead_of_cascading(): void
    {
        $this->db->referenced('X');
        $tarifa = $this->tariff('X', 'Tarifa X', 'EUR');

        $this->assertTrue($tarifa->delete());

        $this->assertNotNull(
            $this->db->firstExecMatching('~^UPDATE ' . self::LIST_TABLE . " SET activa = FALSE, por_defecto = FALSE WHERE codlista = 'X'~"),
            'a referenced mirror must be kept, deactivated'
        );
        $this->assertNull(
            $this->db->firstExecMatching('~^DELETE FROM ' . self::LIST_TABLE . '\b~'),
            'deleting a referenced mirror would cascade over option prices, groups and family structure'
        );
    }

    public function test_delete_removes_an_unreferenced_mirror(): void
    {
        $tarifa = $this->tariff('X', 'Tarifa X', 'EUR');

        $this->assertTrue($tarifa->delete());

        $this->assertNotNull(
            $this->db->firstExecMatching('~^DELETE FROM ' . self::LIST_TABLE . " WHERE codlista = 'X'~")
        );
    }

    public function test_reference_probe_covers_the_five_dependent_tables(): void
    {
        $tarifa = $this->tariff('X', 'Tarifa X', 'EUR');
        $tarifa->delete();

        $probe = implode("\n", $this->db->selects);
        foreach ([
            'catalogo_articulo_precios',
            'catalogo_opcional_precios',
            'catalogo_grupo_tarifas',
            'catalogo_familia_estructura',
            'catalogo_articulo_familia_estructura',
        ] as $table) {
            $this->assertMatchesRegularExpression('~\bFROM ' . $table . '\b~', $probe, $table . ' must be probed');
        }
    }

    public function test_failed_tariff_delete_leaves_the_mirror_alone(): void
    {
        $this->db->failExecOn('tarif_tarifas');
        $tarifa = $this->tariff('X', 'Tarifa X', 'EUR');

        $this->assertFalse($tarifa->delete());
        $this->assertSame([], $this->db->statementsOn(self::LIST_TABLE));
    }

    private function tariff(string $cod, string $nombre, string $coddivisa): \FSFramework\model\tarif_tarifa
    {
        $tarifa = new \FSFramework\model\tarif_tarifa();
        $tarifa->codtarifa = $cod;
        $tarifa->nombre = $nombre;
        $tarifa->activa = true;
        $tarifa->por_defecto = false;
        $tarifa->coddivisa = $coddivisa;

        $prop = new \ReflectionProperty(\fs_model::class, 'db');
        $prop->setValue($tarifa, $this->db);

        return $tarifa;
    }

    private function assertMirrorWrittenAfterTariff(): void
    {
        $tariffAt = null;
        $mirrorAt = null;
        foreach ($this->db->execs as $i => $sql) {
            if ($tariffAt === null && preg_match('~^INSERT INTO tarif_tarifas\b~', $sql)) {
                $tariffAt = $i;
            }
            if ($mirrorAt === null && preg_match('~^INSERT INTO ' . self::LIST_TABLE . '\b~', $sql)) {
                $mirrorAt = $i;
            }
        }

        $this->assertNotNull($tariffAt);
        $this->assertNotNull($mirrorAt);
        $this->assertGreaterThan($tariffAt, $mirrorAt, 'the mirror follows a successful tariff write');
    }
}

/**
 * fs_db2 stand-in for the mirror contract: records every statement, answers
 * existence probes from a scripted set of rows and can fail writes per table.
 */
final class MirrorRecordingDb
{
    /** @var list<string> */
    public array $execs = [];

    /** @var list<string> */
    public array $selects = [];

    /** @var array<string, list<string>> table => existing codes */
    private array $rows = [];

    /** @var list<string> codes referenced by a dependent table */
    private array $referenced = [];

    /** @var list<string> */
    private array $failingTables = [];

    /** @var list<string> */
    private array $missingTables = [];

    public function existing(string $table, string $code): void
    {
        $this->rows[$table][] = $code;
    }

    public function referenced(string $code): void
    {
        $this->referenced[] = $code;
    }

    public function failExecOn(string $table): void
    {
        $this->failingTables[] = $table;
    }

    public function missingTable(string $table): void
    {
        $this->missingTables[] = $table;
    }

    public function connected(): bool
    {
        return true;
    }

    public function escape_string(string $str): string
    {
        return addslashes($str);
    }

    public function var2str($val)
    {
        if (is_null($val)) {
            return 'NULL';
        }
        if (is_bool($val)) {
            return $val ? 'TRUE' : 'FALSE';
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function table_exists($name, $list = false)
    {
        return !in_array($name, $this->missingTables, true);
    }

    public function select($sql, $params = [])
    {
        $sql = (string) $sql;
        $this->selects[] = $sql;

        if (preg_match('~^SELECT 1 FROM catalogo_\w+ WHERE codlista = \'([^\']*)\'~', $sql, $m)) {
            return in_array($m[1], $this->referenced, true) ? [['1' => 1]] : [];
        }

        if (preg_match('~FROM (\w+) WHERE (?:codtarifa|codlista) = \'([^\']*)\'~', $sql, $m)) {
            return in_array($m[2], $this->rows[$m[1]] ?? [], true) ? [['code' => $m[2]]] : [];
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $sql = (string) $sql;
        $this->execs[] = $sql;

        foreach ($this->failingTables as $table) {
            if (preg_match('~^(?:INSERT INTO|UPDATE|DELETE FROM) ' . $table . '\b~', $sql)) {
                return false;
            }
        }

        return true;
    }

    public function firstExecMatching(string $pattern): ?string
    {
        foreach ($this->execs as $sql) {
            if (preg_match($pattern, $sql)) {
                return $sql;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function statementsOn(string $table): array
    {
        return array_values(array_filter(
            array_merge($this->selects, $this->execs),
            static fn (string $sql): bool => (bool) preg_match('~\b' . $table . '\b~', $sql)
        ));
    }
}
