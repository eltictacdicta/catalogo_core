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
 * D3 adapter contract for the moved opcional price action (spec "Price
 * save/read uses codlista"): the adapter extends the canonical price model,
 * keys every read/write on `codlista`, aliases `codtarifa`, and the moved
 * controller persists prices through the catalogo_core adapter only.
 */
final class PrecioQuerySpyDb
{
    public string $lastSelect = '';

    /** @var list<string> */
    public array $executed = [];

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

    public function select($sql, $params = [])
    {
        $this->lastSelect = trim($sql);

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $this->executed[] = trim($sql);

        return true;
    }

    public function table_exists($name, $list = false)
    {
        return true;
    }
}

final class TarifOpcionalPreciosControllerTest extends TestCase
{
    private const ADAPTER_RELATIVE = 'plugins/catalogo_core/model/tarif_opcional_precio.php';
    private const CONTROLLER_RELATIVE = 'plugins/catalogo_core/controller/tarif_opcional_precios.php';

    private function loadAdapter(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_precio.php';
        require_once FS_FOLDER . '/' . self::ADAPTER_RELATIVE;
    }

    public function test_adapter_extends_the_canonical_price_model(): void
    {
        $this->loadAdapter();

        $this->assertTrue(
            is_subclass_of(
                \FSFramework\model\tarif_opcional_precio::class,
                \FSFramework\model\catalogo_opcional_precio::class
            ),
            'the moved price adapter must extend catalogo_opcional_precio (design D3)'
        );
    }

    public function test_adapter_get_reads_by_codlista(): void
    {
        $this->loadAdapter();

        $db = new PrecioQuerySpyDb();
        $model = new class() extends \FSFramework\model\tarif_opcional_precio {
            public $db;

            public function __construct()
            {
                // Skip parent::__construct(): no DB connection.
            }

            public function delete()
            {
                return true;
            }

            public function exists()
            {
                return false;
            }

            public function save()
            {
                return true;
            }
        };
        $model->db = $db;

        $model->get(7, 'DEF');

        $this->assertStringContainsString(
            'codlista',
            $db->lastSelect,
            'the adapter must read the canonical price by codlista'
        );
        $this->assertStringNotContainsString(
            'codtarifa',
            $db->lastSelect,
            'the adapter must not query the dead codtarifa column'
        );
    }

    public function test_adapter_does_not_create_a_physical_legacy_price_table(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/' . self::ADAPTER_RELATIVE);

        $this->assertStringNotContainsString(
            'parent::__construct(\'tarif_opcional_precios\')',
            $source,
            'the adapter must not bind to a physical tarif_opcional_precios table'
        );
        $this->assertSame(
            0,
            (int) preg_match('/CREATE TABLE\s+`?tarif_opcional_precios`?/i', $source),
            'the adapter must not create the legacy price table'
        );
    }

    public function test_moved_controller_persists_through_the_catalogo_core_adapter(): void
    {
        $path = FS_FOLDER . '/' . self::CONTROLLER_RELATIVE;
        if (!is_file($path)) {
            self::fail('missing catalogo_core path: ' . self::CONTROLLER_RELATIVE);
        }

        $source = (string) file_get_contents($path);

        $this->assertStringContainsString(
            self::ADAPTER_RELATIVE,
            $source,
            'the moved price controller must require the catalogo_core adapter'
        );
        $this->assertStringContainsString(
            'new tarif_opcional_precio()',
            $source,
            'the moved price controller must persist through the adapter'
        );
    }
}
