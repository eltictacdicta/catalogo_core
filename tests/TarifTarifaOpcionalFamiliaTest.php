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
 * Per-tarifa family activation contract for the moved
 * `tarif_tarifa_opcional_familia` (spec "Per-tarifa activation and order with
 * inheritance", design D6).
 *
 * The override table is keyed by `(codtarifa, id_opcional, codfamilia)`: a
 * missing row inherits the base relation as active, an explicit
 * `activo = FALSE` row disables that tarifa only. The tests use a spy DB and
 * a base-relation seam so no live database row is written.
 */
final class FamiliaQuerySpyDb
{
    /** @var list<string> */
    public array $execStatements = [];

    /** @var list<string> */
    public array $selectStatements = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

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

        return $this->rows;
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $this->execStatements[] = trim((string) $sql);

        return true;
    }
}

final class TarifTarifaOpcionalFamiliaTest extends TestCase
{
    private const MODEL_RELATIVE = 'plugins/catalogo_core/model/tarif_tarifa_opcional_familia.php';

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
     * @param bool $baseExists controls the base global relation seam
     */
    private function buildModel(FamiliaQuerySpyDb $db, bool $baseExists): object
    {
        return new class($db, $baseExists) extends \FSFramework\model\tarif_tarifa_opcional_familia {
            private bool $baseExists;

            public function __construct($db, bool $baseExists)
            {
                $this->db = $db;
                $this->table_name = 'tarif_tarifa_opcional_familia';
                $this->baseExists = $baseExists;
            }

            protected function base_relation_exists($id_opcional, $codfamilia): bool
            {
                return $this->baseExists;
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

    private function assertInheritanceSeamExists(): void
    {
        if (!method_exists(\FSFramework\model\tarif_tarifa_opcional_familia::class, 'base_relation_exists')) {
            self::fail('missing inheritance seam: tarif_tarifa_opcional_familia::base_relation_exists()');
        }
    }

    // =====================================================================
    // Inheritance: missing row => active
    // =====================================================================

    public function test_is_activo_inherits_active_when_no_per_tarifa_row(): void
    {
        $this->assertInheritanceSeamExists();

        $db = new FamiliaQuerySpyDb();
        $db->rows = [];
        $model = $this->buildModel($db, true);

        $this->assertTrue($model->is_activo_en_tarifa('T1', 5, 'F1'));

        $this->assertCount(1, $db->selectStatements);
        $this->assertStringContainsString("codtarifa = 'T1'", $db->selectStatements[0]);
        $this->assertStringContainsString('id_opcional = 5', $db->selectStatements[0]);
        $this->assertStringContainsString("codfamilia = 'F1'", $db->selectStatements[0]);
    }

    public function test_explicit_false_disables_only_that_tarifa(): void
    {
        $this->assertInheritanceSeamExists();

        $disabled = new FamiliaQuerySpyDb();
        $disabled->rows = [['activo' => '0']];
        $disabledModel = $this->buildModel($disabled, true);

        $this->assertFalse(
            $disabledModel->is_activo_en_tarifa('T1', 5, 'F1'),
            'an explicit activo = FALSE row disables the relation for T1'
        );
        $this->assertStringContainsString("codtarifa = 'T1'", $disabled->selectStatements[0]);

        $other = new FamiliaQuerySpyDb();
        $other->rows = [];
        $otherModel = $this->buildModel($other, true);

        $this->assertTrue(
            $otherModel->is_activo_en_tarifa('T2', 5, 'F1'),
            'T2 has no override row and must stay inherited-active'
        );
        $this->assertStringContainsString("codtarifa = 'T2'", $other->selectStatements[0]);
    }

    public function test_base_relation_absent_is_inactive(): void
    {
        $this->assertInheritanceSeamExists();

        $db = new FamiliaQuerySpyDb();
        $db->rows = [];
        $model = $this->buildModel($db, false);

        $this->assertFalse($model->is_activo_en_tarifa('T1', 5, 'F1'));
        $this->assertSame([], $db->selectStatements, 'no per-tarifa query runs when the base relation is absent');
    }

    // =====================================================================
    // Query-level inheritance predicates
    // =====================================================================

    public function test_get_opcionales_activos_treats_a_missing_row_as_active(): void
    {
        $db = new FamiliaQuerySpyDb();
        $db->rows = [];
        $model = $this->buildModel($db, true);

        $result = $model->get_opcionales_activos('T1', 'F1');

        $this->assertSame([], $result);
        $this->assertCount(1, $db->selectStatements);
        $sql = $db->selectStatements[0];
        $this->assertStringContainsString('tof.activo IS NULL OR tof.activo = TRUE', $sql);
        $this->assertStringContainsString("tof.codtarifa = 'T1'", $sql);
        $this->assertStringContainsString("of2.codfamilia = 'F1'", $sql);
    }

    public function test_get_ids_opcionales_activos_excludes_explicit_deactivations(): void
    {
        $db = new FamiliaQuerySpyDb();
        $db->rows = [['id_opcional' => 3], ['id_opcional' => 5]];
        $model = $this->buildModel($db, true);

        $this->assertSame([3, 5], $model->get_ids_opcionales_activos('T1', 'F1'));

        $sql = $db->selectStatements[0];
        $this->assertStringContainsString('NOT EXISTS', $sql);
        $this->assertStringContainsString('tof.activo = FALSE', $sql);
        $this->assertStringContainsString("tof.codtarifa = 'T1'", $sql);
        $this->assertStringContainsString("of.codfamilia = 'F1'", $sql);
    }

    // =====================================================================
    // set_activo persists a per-tarifa override
    // =====================================================================

    public function test_set_activo_writes_a_per_tarifa_override(): void
    {
        $db = new FamiliaQuerySpyDb();
        $db->rows = [];
        $model = $this->buildModel($db, true);

        $this->assertTrue($model->set_activo('T1', 5, 'F1', false));

        $inserts = $this->statementsStartingWith($db->execStatements, 'INSERT');
        $this->assertCount(1, $inserts);
        $this->assertStringContainsString('tarif_tarifa_opcional_familia', $inserts[0]);
        $this->assertMatchesRegularExpression(
            "/VALUES\\s*\\(\\s*'T1',\\s*5,\\s*'F1',\\s*0,/",
            $inserts[0],
            'the override row must persist the explicit FALSE for the requested tarifa'
        );

        $second = new FamiliaQuerySpyDb();
        $second->rows = [];
        $secondModel = $this->buildModel($second, true);
        $secondModel->set_activo('T2', 5, 'F1', false);

        $secondInserts = $this->statementsStartingWith($second->execStatements, 'INSERT');
        $this->assertCount(1, $secondInserts);
        $this->assertStringContainsString("'T2'", $secondInserts[0]);
        $this->assertStringNotContainsString("'T1'", $secondInserts[0]);
    }
}
