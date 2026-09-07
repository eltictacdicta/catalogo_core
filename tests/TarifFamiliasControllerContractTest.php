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
 * Source + behavior contract for the moved tarif_familias controller.
 *
 * The controller must be decoupled from tarifario: it extends
 * fbase_controller, keeps zero tarif_controller / plugins/tarifario
 * references, and replaces the tarif_tarifa_articulo model dependency with a
 * raw parameterized COUNT against the stable table tarif_tarifa_articulo
 * (design D1-D3). The public macro contract (count_articulos, called by
 * TarifarioComponents.html.twig:443) and the add/edit API surface stay
 * intact (spec: "Article count contract", "Add/edit familia in tarifa").
 */
final class TarifFamiliasControllerContractTest extends TestCase
{
    private const CONTROLLER_PATH = '/plugins/catalogo_core/controller/tarif_familias.php';

    private function controllerSource(): string
    {
        $path = FS_FOLDER . self::CONTROLLER_PATH;
        if (!is_file($path)) {
            self::fail('missing catalogo_core path: plugins/catalogo_core/controller/tarif_familias.php (moved controller not present)');
        }

        return (string) file_get_contents($path);
    }

    private function loadControllerClass(): void
    {
        if (class_exists('tarif_familias', false)) {
            return;
        }

        if (!is_file(FS_FOLDER . self::CONTROLLER_PATH)) {
            self::fail('missing catalogo_core path: plugins/catalogo_core/controller/tarif_familias.php (moved controller not present)');
        }

        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . self::CONTROLLER_PATH;
    }

    /** Extracts a method body by brace matching from $start at the signature. */
    private function methodSource(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            self::fail('missing method in moved controller: ' . $signature);
        }

        $open = (int) strpos($src, '{', (int) $start);
        $depth = 0;
        $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, (int) $start, $i - $start + 1);
                }
            }
        }

        self::fail('method body not terminated: ' . $signature);
    }

    public function test_controller_class_extends_fbase_controller(): void
    {
        $src = $this->controllerSource();
        $this->assertMatchesRegularExpression(
            '/class tarif_familias extends \\\\FSFramework\\\\Controller\\\\HtmxCrudController\b/',
            $src,
            'The pilot controller must extend HtmxCrudController (design D1)'
        );
        $this->assertDoesNotMatchRegularExpression('/extends tarif_controller\b/', $src);
    }

    public function test_controller_has_zero_tarif_controller_references(): void
    {
        $this->assertSame(
            0,
            substr_count($this->controllerSource(), 'tarif_controller'),
            'Zero tarif_controller references allowed after the reclass'
        );
    }

    public function test_controller_has_zero_tarifario_path_references(): void
    {
        $this->assertSame(
            0,
            substr_count($this->controllerSource(), 'plugins/tarifario'),
            'The moved controller must not reference any plugins/tarifario path'
        );
    }

    public function test_tarifa_articulo_references_reduced_to_the_table_constant(): void
    {
        $src = $this->controllerSource();
        $withoutConst = (string) preg_replace(
            "/public const TARIFA_ARTICULO_TABLE\s*=\s*'[^']*'\s*;/",
            '',
            $src
        );
        $this->assertSame(
            0,
            substr_count($withoutConst, 'tarif_tarifa_articulo'),
            'No require/use/property/instantiation of the tarif_tarifa_articulo model may remain (design D2)'
        );
    }

    public function test_tarifa_articulo_table_constant_value(): void
    {
        $src = $this->controllerSource();
        $this->assertSame(
            1,
            preg_match("/public const TARIFA_ARTICULO_TABLE\s*=\s*'([^']+)'\s*;/", $src, $m),
            'The single cross-plugin table reference must be a declared constant (design D3)'
        );
        $this->assertSame('tarif_tarifa_articulo', $m[1]);
    }

    public function test_delete_guard_routes_through_count_helper(): void
    {
        $src = $this->controllerSource();
        $this->assertSame(
            2,
            substr_count($src, '$this->count_tarifa_articulos($this->codtarifa'),
            'Both the delete guard and the public count_articulos must route through the helper'
        );
    }

    public function test_count_helper_uses_parameterized_sql(): void
    {
        $helper = $this->methodSource(
            $this->controllerSource(),
            'private function count_tarifa_articulos($codtarifa, $codfamilia): int'
        );
        $this->assertStringContainsString('$this->db->select(', $helper);
        $this->assertStringContainsString('WHERE codtarifa = ? AND codfamilia = ?', $helper);
        $this->assertStringContainsString('[$codtarifa, $codfamilia]', $helper);
    }

    public function test_public_api_surface_methods_exist(): void
    {
        $this->loadControllerClass();

        foreach (['count_articulos', 'get_posibles_madres', 'get_etiquetas_familia_text', 'get_etiquetas_familia_full'] as $method) {
            $this->assertTrue(
                method_exists('tarif_familias', $method),
                'Public API surface must keep ' . $method . ' (macro/view contract)'
            );
        }
    }

    public function test_count_articulos_returns_raw_sql_total(): void
    {
        $this->loadControllerClass();

        $db = new class {
            /** @var array<int, array{0: string, 1: array}> */
            public array $calls = [];
            /** @var array<int, array<string, mixed>>|null */
            public ?array $result = [];

            public function select(string $sql, array $params = [])
            {
                $this->calls[] = [$sql, $params];

                return $this->result;
            }
        };
        $db->result = [['total' => '7']];

        $controller = new class extends \tarif_familias {
            public function __construct()
            {
            }

            public function setCountDb(object $db): void
            {
                $this->db = $db;
            }
        };
        $controller->codtarifa = 'T1';
        $controller->setCountDb($db);

        $this->assertSame(7, $controller->count_articulos('FAM001'), 'count_articulos must return the raw SQL total as int');

        [$sql, $params] = $db->calls[0];
        $this->assertStringContainsString('tarif_tarifa_articulo', $sql, 'COUNT must hit the stable cross-plugin table');
        $this->assertStringContainsString('WHERE codtarifa = ? AND codfamilia = ?', $sql, 'SQL must be parameterized');
        $this->assertSame(['T1', 'FAM001'], $params, 'Parameters must bind codtarifa then codfamilia');
    }

    public function test_count_articulos_returns_zero_when_no_rows(): void
    {
        $this->loadControllerClass();

        $db = new class {
            public ?array $result = [];

            public function select(string $sql, array $params = [])
            {
                return $this->result;
            }
        };
        $db->result = [];

        $controller = new class extends \tarif_familias {
            public function __construct()
            {
            }

            public function setCountDb(object $db): void
            {
                $this->db = $db;
            }
        };
        $controller->codtarifa = 'T1';
        $controller->setCountDb($db);

        $this->assertSame(0, $controller->count_articulos('EMPTY'), 'Empty result set must map to 0');
    }

    // --- Amendment 1: menu rebrand + plain list retirement ---

    public function test_constructor_registers_familias_under_catalogo_folder(): void
    {
        $src = $this->controllerSource();
        $this->assertStringContainsString(
            "parent::__construct(__CLASS__, 'Familias', 'catalogo');",
            $src,
            'Amendment 1: the page must register as Familias under the catalogo folder'
        );
        $this->assertSame(
            0,
            substr_count($src, "'Familias Tarifario'"),
            'The old menu title must be gone'
        );
    }

    public function test_moved_view_has_zero_retired_list_references(): void
    {
        $viewPath = FS_FOLDER . '/plugins/catalogo_core/View/tarif_familias.html.twig';
        if (!is_file($viewPath)) {
            self::fail('missing catalogo_core path: plugins/catalogo_core/View/tarif_familias.html.twig');
        }

        $src = (string) file_get_contents($viewPath);
        $this->assertSame(
            0,
            substr_count($src, 'page=ventas_familias'),
            'The retired plain list must not be linked from the moved view'
        );
    }

    public function test_empty_state_link_targets_create_flow(): void
    {
        $viewPath = FS_FOLDER . '/plugins/catalogo_core/View/tarif_familias.html.twig';
        if (!is_file($viewPath)) {
            self::fail('missing catalogo_core path: plugins/catalogo_core/View/tarif_familias.html.twig');
        }

        $src = (string) file_get_contents($viewPath);
        $this->assertStringContainsString(
            '<a href="index.php?page=ventas_familia">Crear familias primero</a>',
            $src,
            'Empty state must link the singular create form (no cod) per Amendment 1'
        );
    }
}
