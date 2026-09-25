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

namespace Tests\CatalogoCore\Integration;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_functions.php';
require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo_rel.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional_precio.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional_ext.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional.php';

/**
 * Compatibility guard for the tarifario consumer (OPG-11, AD-14, C1/C2/C2b).
 *
 * (a) Behavioural: tarifario reads direct opcionales through
 *     `catalogo_articulo_opcional::get_opcionales_directos_from_articulo()`;
 *     a grouped opcional must be excluded identically when it belongs to one
 *     or two groups (the bridge anti-join is set-aware, not single-valued).
 *
 * (b) Source contract: BOTH tarifario bulk-delete paths clean the new bridge —
 *     `limpiar_opcionales()` and `limpiar_todo()` — and `limpiar_todo()` reports
 *     a `relaciones_grupo` count.
 *
 * Out of scope (pre-existing debt, deliberately not fixed by
 * `opcional-en-varios-grupos`): `limpiar_todo()` never cleaned the older
 * `catalogo_articulo_opcional_grupo` article↔group bridge, so the rows it
 * leaves behind dangle on the deleted-article side. That is an
 * article↔group concern owned elsewhere; this change only cleans the new
 * `catalogo_opcional_grupo_rel` bridge.
 */
final class OpcionalGrupoTarifarioCompatTest extends TestCase
{
    private const HANDLER_RELATIVE = 'plugins/tarifario/Services/ArticuloListActionHandler.php';
    private const ARTICULO_OPCIONAL_RELATIVE = 'plugins/catalogo_core/model/core/catalogo_articulo_opcional.php';

    /**
     * DB-free fake emulating the direct-opcionales anti-join read.
     */
    private function fakeWith(array $bridge, array $articuloOpcional, array $opcionales): object
    {
        return new class($bridge, $articuloOpcional, $opcionales) extends \fs_db2 {
            /** @var list<array{0:int,1:int}> */
            private array $bridge;

            /** @var array<string, list<int>> */
            private array $articuloOpcional;

            /** @var array<int, array<string, mixed>> */
            private array $opcionales;

            public string $lastSql = '';

            public function __construct(array $bridge, array $articuloOpcional, array $opcionales)
            {
                $this->bridge = $bridge;
                $this->articuloOpcional = $articuloOpcional;
                $this->opcionales = $opcionales;
            }

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
                $sql = trim((string) $sql);
                if (
                    str_contains($sql, 'NOT EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel')
                    && str_contains($sql, 'catalogo_articulo_opcional')
                ) {
                    $this->lastSql = $sql;
                    $referencia = (string) ($params[0] ?? '');
                    $grouped = [];
                    foreach ($this->bridge as $pair) {
                        $grouped[(int) $pair[0]] = true;
                    }

                    $rows = [];
                    foreach ($this->articuloOpcional[$referencia] ?? [] as $idOpcional) {
                        if (isset($grouped[$idOpcional])) {
                            continue;
                        }
                        $row = $this->opcionales[$idOpcional] ?? null;
                        if ($row === null) {
                            continue;
                        }
                        // Pre-seed the extension key so tarif_opcional skips its
                        // live `load_extension()` probe (keeps this test DB-free).
                        $row['ref_sap'] = null;
                        $rows[] = $row;
                    }

                    usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['nombre'], (string) $b['nombre']));

                    return $rows;
                }

                return [];
            }

            public function select_limit($sql, $limit = 50, $offset = 0, $params = [])
            {
                return [];
            }

            public function exec($sql, $transaction = null, $params = [], $batch = false)
            {
                return true;
            }

            public function table_exists($name, $list = false)
            {
                return false;
            }
        };
    }

    private function directRelModel(object $fake): object
    {
        return new class($fake) extends \FSFramework\model\catalogo_articulo_opcional {
            public function __construct(private object $fake)
            {
                $this->db = $fake;
                $this->table_name = 'catalogo_articulo_opcional';
            }
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function opcionalRows(): array
    {
        return [
            7 => [
                'id' => 7,
                'codigo' => 'OPC0007',
                'nombre' => 'Blanco',
                'descripcion' => '',
                'precio' => 0,
                'tipo_precio' => 'fijo',
                'porcentaje' => null,
                'activo' => true,
            ],
            8 => [
                'id' => 8,
                'codigo' => 'OPC0008',
                'nombre' => 'Cromo',
                'descripcion' => '',
                'precio' => 0,
                'tipo_precio' => 'fijo',
                'porcentaje' => null,
                'activo' => true,
            ],
        ];
    }

    /** @param array<int, object> $models @return list<int> */
    private function ids(array $models): array
    {
        return array_map(static fn ($model): int => (int) $model->id, $models);
    }

    public function test_direct_read_is_control_non_empty_without_memberships(): void
    {
        $fake = $this->fakeWith([], ['ART' => [7, 8]], $this->opcionalRows());
        $model = $this->directRelModel($fake);

        $this->assertSame([7, 8], $this->ids($model->get_opcionales_directos_from_articulo('ART')));
        $this->assertStringContainsString('NOT EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel', $fake->lastSql);
    }

    public function test_grouped_opcional_is_excluded_identically_with_one_or_two_groups(): void
    {
        $opcionales = $this->opcionalRows();

        $oneGroup = $this->fakeWith([[7, 3]], ['ART' => [7, 8]], $opcionales);
        $twoGroups = $this->fakeWith([[7, 3], [7, 4]], ['ART' => [7, 8]], $opcionales);

        $oneResult = $this->ids($this->directRelModel($oneGroup)->get_opcionales_directos_from_articulo('ART'));
        $twoResult = $this->ids($this->directRelModel($twoGroups)->get_opcionales_directos_from_articulo('ART'));

        $this->assertSame([8], $oneResult, 'a grouped opcional must not appear as a direct relation');
        $this->assertSame($oneResult, $twoResult, 'the direct set must be identical for one or two groups');
    }

    public function test_both_tarifario_bulk_delete_paths_clean_the_bridge(): void
    {
        $source = $this->handlerSource();

        $limpiarOpcionales = $this->methodSource($source, 'function limpiar_opcionales(');
        $this->assertStringContainsString(
            'DELETE FROM catalogo_opcional_grupo_rel',
            $limpiarOpcionales,
            'limpiar_opcionales() must delete the bridge rows before removing the opcionales'
        );

        $limpiarTodo = $this->methodSource($source, 'function limpiar_todo(');
        $this->assertStringContainsString(
            'catalogo_opcional_grupo_rel',
            $limpiarTodo,
            'limpiar_todo() must clean the bridge rows among its relation cleanups'
        );
        $this->assertStringContainsString(
            'relaciones_grupo',
            $limpiarTodo,
            'limpiar_todo() must account for the bridge cleanup in its stats'
        );
    }

    public function test_direct_read_predicate_is_a_bridge_anti_join(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/' . self::ARTICULO_OPCIONAL_RELATIVE);
        $method = $this->methodSource($source, 'function get_opcionales_directos_from_articulo(');

        $this->assertStringContainsString(
            'NOT EXISTS (SELECT 1 FROM ',
            $method,
            'the direct-read predicate must be the bridge anti-join'
        );
        $this->assertStringContainsString(
            'catalogo_opcional_grupo_rel::TABLE',
            $method,
            'the direct-read predicate must source membership from the bridge table'
        );
        $this->assertStringNotContainsString(
            'id_grupo',
            $method,
            'the direct-read predicate must not read the frozen single-valued column'
        );
    }

    private function handlerSource(): string
    {
        $path = FS_FOLDER . '/' . self::HANDLER_RELATIVE;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** Extracts a method body by brace matching from its signature. */
    private function methodSource(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            self::fail('missing method: ' . $signature);
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

        self::fail('method body is not terminated: ' . $signature);
    }
}
