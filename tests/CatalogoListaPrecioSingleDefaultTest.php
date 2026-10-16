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
 * Single-default invariant on catalogo_lista_precio (multitarifa task 1.2,
 * R-MT-001 / design D1). Mock fs_db2 injected via reflection, mirroring the
 * CatalogoArticuloPrecioTest pattern. Cascade-free: query behavior only.
 */
final class CatalogoListaPrecioSingleDefaultTest extends TestCase
{
    private \FSFramework\model\catalogo_lista_precio $model;

    private MockListaDb $db;

    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';

        $this->db = new MockListaDb();
        $this->model = new \FSFramework\model\catalogo_lista_precio();
        $this->injectDb($this->db);
    }

    private function injectDb(MockListaDb $db): void
    {
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($this->model, $db);
    }

    private function listModel(array $data): \FSFramework\model\catalogo_lista_precio
    {
        $lista = new \FSFramework\model\catalogo_lista_precio($data);
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($lista, $this->db);

        return $lista;
    }

    public function test_saving_second_default_demotes_the_first(): void
    {
        $lista = $this->listModel([
            'codlista' => 'L2',
            'nombre' => 'Mayorista',
            'activa' => 't',
            'por_defecto' => 't',
            'coddivisa' => 'EUR',
        ]);

        $this->assertTrue($lista->save());

        $demote = null;
        foreach ($this->db->execSqls as $sql) {
            if (str_contains($sql, 'SET por_defecto = FALSE')) {
                $demote = $sql;
            }
        }
        $this->assertNotNull($demote, 'demotion UPDATE must be issued');
        $this->assertStringContainsString("codlista != 'L2'", $demote);

        // transaction boundaries: begin before, commit after, no rollback
        $this->assertTrue($this->db->begun, 'save must open a transaction');
        $this->assertTrue($this->db->committed, 'save must commit');
        $this->assertFalse($this->db->rolledBack);
    }

    public function test_failed_save_rolls_back_and_reports_failure(): void
    {
        $this->db->execFails = true;

        $lista = $this->listModel([
            'codlista' => 'L2',
            'nombre' => 'Mayorista',
            'activa' => 't',
            'por_defecto' => 't',
            'coddivisa' => 'EUR',
        ]);

        $this->assertFalse($lista->save());
        $this->assertTrue($this->db->rolledBack, 'failed save must roll back');
        $this->assertFalse($this->db->committed, 'failed save must not commit');
    }

    public function test_get_default_falls_back_to_def_when_none_marked(): void
    {
        // no row with por_defecto = TRUE, but the seeded DEF row exists
        $this->db->selectQueues = [
            [], // first query: no por_defecto = TRUE row
            [   // fallback query: the DEF row
                ['codlista' => 'DEF', 'nombre' => 'Lista por defecto', 'activa' => 't', 'por_defecto' => 't', 'coddivisa' => 'EUR'],
            ],
        ];

        $default = $this->model->get_default();

        $this->assertInstanceOf(\FSFramework\model\catalogo_lista_precio::class, $default);
        $this->assertSame('DEF', $default->codlista);
        $this->assertStringContainsString("codlista = 'DEF'", $this->db->selectSqls[1]);
    }

    public function test_get_default_returns_marked_row_first(): void
    {
        $this->db->selectQueues = [
            [
                ['codlista' => 'L1', 'nombre' => 'Retail', 'activa' => 't', 'por_defecto' => 't', 'coddivisa' => 'EUR'],
            ],
        ];

        $default = $this->model->get_default();

        $this->assertInstanceOf(\FSFramework\model\catalogo_lista_precio::class, $default);
        $this->assertSame('L1', $default->codlista);
    }

    public function test_saving_non_default_list_skips_demotion(): void
    {
        $lista = $this->listModel([
            'codlista' => 'L3',
            'nombre' => 'Campania',
            'activa' => 't',
            'por_defecto' => 'f',
            'coddivisa' => 'EUR',
        ]);

        $this->assertTrue($lista->save());

        foreach ($this->db->execSqls as $sql) {
            $this->assertStringNotContainsString('SET por_defecto = FALSE', $sql);
        }
    }

    public function test_naming_uses_codlista_only(): void
    {
        $modelFile = FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
        $this->assertFileExists($modelFile);
        $this->assertStringNotContainsStringIgnoringCase(
            'codtarifa',
            (string) file_get_contents($modelFile)
        );
    }
}

/**
 * Minimal fs_db2 stand-in for lista_precio save/get_default flows.
 */
class MockListaDb
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<list<array<string, mixed>>> queued SELECT results */
    public array $selectQueues = [];

    /** @var list<string> SQL passed to select() */
    public array $selectSqls = [];

    /** @var list<string> SQL passed to exec() */
    public array $execSqls = [];

    public bool $execFails = false;

    public bool $begun = false;

    public bool $committed = false;

    public bool $rolledBack = false;

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
        if (is_numeric($val)) {
            return "'" . (string) $val . "'";
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function select($sql)
    {
        $this->selectSqls[] = $sql;
        if (empty($this->selectQueues)) {
            return $this->rows;
        }

        return array_shift($this->selectQueues);
    }

    public function exec($sql)
    {
        $this->execSqls[] = $sql;

        return !$this->execFails;
    }

    public function table_exists($name)
    {
        return true;
    }

    public function begin_transaction(): bool
    {
        $this->begun = true;

        return true;
    }

    public function commit(): bool
    {
        $this->committed = true;

        return true;
    }

    public function rollback(): bool
    {
        $this->rolledBack = true;

        return true;
    }
}
