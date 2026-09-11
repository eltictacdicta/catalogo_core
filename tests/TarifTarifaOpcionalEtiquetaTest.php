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
 * Tag storage contract for the moved `tarif_tarifa_opcional_etiqueta`
 * (spec "Tags per (tarifa, opcional, familia)", design D5).
 *
 * The model is a standalone fs_model keyed by
 * `(codtarifa, id_opcional, codfamilia, etiqueta)`. These tests exercise the
 * replacement normalization/idempotence and the per-tuple isolation with a
 * spy DB, so no live database row is written.
 */
final class EtiquetaQuerySpyDb
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

final class TarifTarifaOpcionalEtiquetaTest extends TestCase
{
    private const MODEL_RELATIVE = 'plugins/catalogo_core/model/tarif_tarifa_opcional_etiqueta.php';

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

    private function buildModel(EtiquetaQuerySpyDb $db): object
    {
        return new class($db) extends \FSFramework\model\tarif_tarifa_opcional_etiqueta {
            public function __construct($db)
            {
                $this->db = $db;
                $this->table_name = 'tarif_tarifa_opcional_etiqueta';
            }
        };
    }

    private function invokePrivate(object $subject, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($subject, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($subject, $args);
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

    /**
     * @param list<string> $inserts
     * @return list<string>
     */
    private function insertedTags(array $inserts): array
    {
        $tags = [];
        foreach ($inserts as $sql) {
            if (preg_match("/,\\s*'([^']*)'\\s*\\)\\s*;/", $sql, $matches)) {
                $tags[] = $matches[1];
            }
        }

        return $tags;
    }

    // =====================================================================
    // Normalization seam
    // =====================================================================

    public function test_normalizer_trims_blanks_and_deduplicates(): void
    {
        if (!method_exists(\FSFramework\model\tarif_tarifa_opcional_etiqueta::class, 'normalizar_etiquetas')) {
            self::fail('missing normalization seam: tarif_tarifa_opcional_etiqueta::normalizar_etiquetas()');
        }

        $db = new EtiquetaQuerySpyDb();
        $model = $this->buildModel($db);

        $result = $this->invokePrivate($model, 'normalizar_etiquetas', [
            ['  ROJO ', 'ROJO', '', 'AZUL', 'AZUL', '   '],
        ]);

        $this->assertSame(['ROJO', 'AZUL'], array_values($result));
    }

    // =====================================================================
    // replace_etiquetas_opcional: normalized + idempotent
    // =====================================================================

    public function test_replace_etiquetas_persists_the_normalized_deduplicated_set(): void
    {
        $db = new EtiquetaQuerySpyDb();
        $db->rows = [];
        $model = $this->buildModel($db);

        $ok = $model->replace_etiquetas_opcional(
            'T1',
            5,
            'F1',
            ['  ROJO ', 'ROJO', '', 'AZUL', 'AZUL', '   ']
        );

        $this->assertTrue($ok);

        $deletes = $this->statementsStartingWith($db->execStatements, 'DELETE');
        $inserts = $this->statementsStartingWith($db->execStatements, 'INSERT');

        $this->assertCount(1, $deletes, 'replace must clear the current tuple once');
        $this->assertStringContainsString('tarif_tarifa_opcional_etiqueta', $deletes[0]);
        $this->assertStringContainsString("codtarifa = 'T1'", $deletes[0]);
        $this->assertStringContainsString('id_opcional = 5', $deletes[0]);
        $this->assertStringContainsString("codfamilia = 'F1'", $deletes[0]);

        $this->assertCount(2, $inserts, 'blanks and duplicates must collapse to two tags');
        $this->assertSame(['ROJO', 'AZUL'], $this->insertedTags($inserts));
        foreach ($inserts as $sql) {
            $this->assertStringNotContainsString("''", $sql, 'no blank tag may reach the INSERT');
        }
    }

    public function test_replace_etiquetas_is_idempotent(): void
    {
        $db = new EtiquetaQuerySpyDb();
        $db->rows = [];
        $model = $this->buildModel($db);

        $model->replace_etiquetas_opcional('T1', 5, 'F1', ['ROJO', 'AZUL']);
        $first = $this->insertedTags($this->statementsStartingWith($db->execStatements, 'INSERT'));

        $db->execStatements = [];
        $db->selectStatements = [];
        $model->replace_etiquetas_opcional('T1', 5, 'F1', ['AZUL', 'ROJO', 'ROJO']);

        $secondDeletes = $this->statementsStartingWith($db->execStatements, 'DELETE');
        $second = $this->insertedTags($this->statementsStartingWith($db->execStatements, 'INSERT'));

        $this->assertCount(1, $secondDeletes, 'a rewrite clears the tuple exactly once');
        $this->assertEqualsCanonicalizing($first, $second, 'the stored set must not change');
        $this->assertCount(2, $second);
    }

    // =====================================================================
    // Per-(tarifa, opcional, familia) isolation
    // =====================================================================

    public function test_tags_are_isolated_per_tarifa(): void
    {
        $db = new EtiquetaQuerySpyDb();
        $db->rows = [];
        $model = $this->buildModel($db);

        $model->replace_etiquetas_opcional('T1', 5, 'F1', ['ROJO']);

        foreach ($db->execStatements as $sql) {
            $this->assertStringContainsString("'T1'", $sql, 'every write must bind the first tarifa');
            $this->assertStringNotContainsString("'T2'", $sql, 'no write may leak into another tarifa');
        }

        $db->selectStatements = [];
        $read = $model->get_etiquetas_opcional('T2', 5, 'F1');

        $this->assertSame([], $read, 'the second tarifa has no tags');
        $this->assertCount(1, $db->selectStatements);
        $this->assertStringContainsString("codtarifa = 'T2'", $db->selectStatements[0]);
        $this->assertStringContainsString('id_opcional = 5', $db->selectStatements[0]);
        $this->assertStringContainsString("codfamilia = 'F1'", $db->selectStatements[0]);
    }

    public function test_get_etiquetas_opcional_returns_the_stored_set(): void
    {
        $db = new EtiquetaQuerySpyDb();
        $db->rows = [['etiqueta' => 'AZUL'], ['etiqueta' => 'ROJO']];
        $model = $this->buildModel($db);

        $this->assertSame(['AZUL', 'ROJO'], $model->get_etiquetas_opcional('T1', 5, 'F1'));
    }
}
