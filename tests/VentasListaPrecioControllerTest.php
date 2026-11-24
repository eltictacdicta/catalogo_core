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
 * Retired price-list page (R-MT-004 modified by `fusionar-paginas-tarifas-listas`).
 *
 * Only tariffs exist as a concept (R-MT-009): price lists are tariff mirrors;
 * `ventas_listas_precio` only keeps old links alive by redirecting there. It
 * renders nothing, writes nothing and is not on the menu. The list model's own
 * save/delete contract is still pinned here because it predates the page move.
 */
final class VentasListaPrecioControllerTest extends TestCase
{
    private const CONTROLLER_FILE = '/plugins/catalogo_core/controller/ventas_listas_precio.php';
    private const VIEW_FILE = '/plugins/catalogo_core/view/ventas_listas_precio.html.twig';

    protected function setUp(): void
    {
        parent::setUp();
        global $plugins;
        $plugins = [];
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
        require_once FS_FOLDER . self::CONTROLLER_FILE;
    }

    // ---- Redirect shim ----

    public function test_controller_stays_and_its_view_is_gone(): void
    {
        $this->assertFileExists(FS_FOLDER . self::CONTROLLER_FILE, 'old bookmarks must keep resolving');
        $this->assertFileDoesNotExist(FS_FOLDER . self::VIEW_FILE, 'the shim renders nothing');
    }

    public function test_shim_is_a_legacy_page_off_the_menu_without_template(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        $this->assertTrue((new \ReflectionClass('ventas_listas_precio'))->isSubclassOf(\fs_controller::class));
        $this->assertStringContainsString(
            "parent::__construct(__CLASS__, 'Listas de precio', 'catalogo', FALSE, FALSE)",
            $source,
            'the shim must leave the menu'
        );
        $this->assertStringContainsString('$this->template = FALSE;', $source);
    }

    public function test_shim_redirects_to_the_tariffs_page(): void
    {
        $this->assertSame(
            'index.php?page=tarif_tarifas',
            \ventas_listas_precio::TARGET
        );

        $shim = new class() extends \ventas_listas_precio {
            public ?string $location = null;

            public function __construct()
            {
                // Skip fs_controller: no DB, no request bootstrap.
            }

            public function run(): void
            {
                $this->private_core();
            }

            protected function send_location(string $url): void
            {
                $this->location = $url;
            }
        };

        $_POST = ['action' => 'delete', 'codlista' => 'DEF'];
        $shim->run();
        $_POST = [];

        $this->assertSame(\ventas_listas_precio::TARGET, $shim->location, 'POSTs are redirected too, never processed');
    }

    public function test_shim_never_persists(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);

        foreach (['->save(', '->delete(', '->exec(', 'catalogo_lista_precio', '$_POST'] as $needle) {
            $this->assertStringNotContainsString($needle, $source, 'the shim must not handle ' . $needle);
        }
    }

    // ---- List model contract (kept from the original page tests) ----

    public function test_list_model_save_pipeline_roundtrip(): void
    {
        $db = new VentasListaPrecioMockDb();
        $lista = new \FSFramework\model\catalogo_lista_precio([
            'codlista' => 'L2',
            'nombre' => 'Mayorista',
            'activa' => true,
            'por_defecto' => false,
            'coddivisa' => 'EUR',
        ]);
        (new \ReflectionProperty(\fs_model::class, 'db'))->setValue($lista, $db);

        $this->assertTrue($lista->save(), 'The list save pipeline must persist through the model');
        $this->assertStringContainsString('INSERT INTO catalogo_listas_precio', $db->lastSql);
    }

    public function test_default_list_cannot_be_deleted(): void
    {
        $db = new VentasListaPrecioMockDb();
        $lista = new \FSFramework\model\catalogo_lista_precio([]);
        $lista->codlista = \FSFramework\model\catalogo_lista_precio::DEFAULT_CODE;
        $lista->por_defecto = true;
        (new \ReflectionProperty(\fs_model::class, 'db'))->setValue($lista, $db);

        $this->assertFalse($lista->delete(), 'The default list must not be deletable');
        $this->assertNotEmpty($lista->get_errors());
    }
}

/**
 * Minimal fs_db2 stand-in for list-model flows.
 */
class VentasListaPrecioMockDb
{
    public string $lastSql = '';

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
        $this->lastSql = $sql;

        return [];
    }

    public function exec($sql)
    {
        $this->lastSql = $sql;

        return true;
    }

    public function table_exists($name)
    {
        return true;
    }

    public function begin_transaction(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function rollback(): bool
    {
        return true;
    }
}
