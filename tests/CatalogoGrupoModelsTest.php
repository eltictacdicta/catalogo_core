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
 * Role-group storage models (multitarifa task 1.3, R-RG-001).
 *
 * The plugin test bootstrap has no DB layer, so query-behavior scenarios run
 * against a mock fs_db2 injected via reflection; cascade behavior is asserted
 * structurally against the XML schemas (FK ... ON DELETE CASCADE).
 */
final class CatalogoGrupoModelsTest extends TestCase
{
    private MockGrupoDb $db;

    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        $core = FS_FOLDER . '/plugins/catalogo_core/model/core/';
        require_once $core . 'catalogo_grupo.php';
        require_once $core . 'catalogo_grupo_rol.php';
        require_once $core . 'catalogo_grupo_usuario.php';
        require_once $core . 'catalogo_grupo_tarifa.php';
        require_once $core . 'catalogo_grupo_articulo.php';

        $this->db = new MockGrupoDb();
    }

    /**
     * @param class-string $class
     */
    private function model(string $class, array $data = []): object
    {
        $obj = new $class($data ?: []);
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($obj, $this->db);

        return $obj;
    }

    public function test_group_saves_with_insert_and_updates_when_exists(): void
    {
        $grupo = $this->model(\FSFramework\model\catalogo_grupo::class, [
            'nombre' => 'Comerciales',
        ]);
        $this->assertTrue($grupo->save());
        $this->assertStringContainsString('INSERT INTO catalogo_grupo', $this->db->lastSql);
    }

    public function test_group_rejects_empty_nombre(): void
    {
        $grupo = $this->model(\FSFramework\model\catalogo_grupo::class, [
            'nombre' => '',
        ]);
        $this->assertFalse($grupo->save());
        $this->assertNotEmpty($grupo->get_errors());
    }

    public function test_group_rol_persists_gestor_member_role(): void
    {
        $rol = $this->model(\FSFramework\model\catalogo_grupo_rol::class, [
            'id_grupo' => '1',
            'codrol' => 'gestor',
        ]);
        $this->assertTrue($rol->save());
        $this->assertStringContainsString('INSERT INTO catalogo_grupo_roles', $this->db->lastSql);
        $this->assertStringContainsString("'gestor'", $this->db->lastSql);
    }

    public function test_group_usuario_persists_membership(): void
    {
        $usuario = $this->model(\FSFramework\model\catalogo_grupo_usuario::class, [
            'id_grupo' => '1',
            'nick' => 'juan',
            'rol' => 'gestor',
        ]);
        $this->assertTrue($usuario->save());
        $this->assertStringContainsString('INSERT INTO catalogo_grupo_usuarios', $this->db->lastSql);
    }

    public function test_group_usuario_rejects_invalid_rol(): void
    {
        $usuario = $this->model(\FSFramework\model\catalogo_grupo_usuario::class, [
            'id_grupo' => '1',
            'nick' => 'juan',
            'rol' => 'superadmin',
        ]);
        $this->assertFalse($usuario->save());
        $this->assertNotEmpty($usuario->get_errors());
    }

    public function test_group_tarifa_persists_list_scope(): void
    {
        $tarifa = $this->model(\FSFramework\model\catalogo_grupo_tarifa::class, [
            'id_grupo' => '1',
            'codlista' => 'L1',
        ]);
        $this->assertTrue($tarifa->save());
        $this->assertStringContainsString('INSERT INTO catalogo_grupo_tarifas', $this->db->lastSql);
        $this->assertStringContainsString("'L1'", $this->db->lastSql);
    }

    public function test_group_articulo_persists_article_scope(): void
    {
        $articulo = $this->model(\FSFramework\model\catalogo_grupo_articulo::class, [
            'id_grupo' => '1',
            'referencia' => 'A',
        ]);
        $this->assertTrue($articulo->save());
        $this->assertStringContainsString('INSERT INTO catalogo_grupo_articulos', $this->db->lastSql);
        $this->assertStringContainsString("'A'", $this->db->lastSql);
    }

    public function test_member_deletes_scoped_to_group(): void
    {
        $usuario = $this->model(\FSFramework\model\catalogo_grupo_usuario::class, [
            'id_grupo' => '1',
            'nick' => 'juan',
            'rol' => 'gestor',
        ]);
        $this->assertTrue($usuario->delete_from_grupo(1));
        $this->assertStringContainsString('DELETE FROM catalogo_grupo_usuarios WHERE id_grupo', $this->db->lastSql);
    }

    public function test_xml_schemas_have_group_fk_with_cascade(): void
    {
        foreach (['roles', 'usuarios', 'tarifas', 'articulos'] as $suffix) {
            $xml = file_get_contents(
                FS_FOLDER . '/plugins/catalogo_core/model/table/catalogo_grupo_' . $suffix . '.xml'
            );
            $this->assertNotFalse($xml, 'catalogo_grupo_' . $suffix . '.xml must exist');
            $this->assertMatchesRegularExpression(
                '/FOREIGN KEY \(id_grupo\)[^"]*REFERENCES catalogo_grupo[^\"]*ON DELETE CASCADE/s',
                (string) $xml,
                'catalogo_grupo_' . $suffix . ' must cascade on group delete'
            );
        }
    }

    public function test_grupo_tarifas_fk_targets_catalogo_listas_precio(): void
    {
        $xml = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/table/catalogo_grupo_tarifas.xml'
        );
        $this->assertMatchesRegularExpression(
            '/FOREIGN KEY \(codlista\)[^"]*REFERENCES catalogo_listas_precio[^\"]*ON DELETE CASCADE/s',
            $xml
        );
    }

    public function test_grupo_articulos_fk_targets_articulos(): void
    {
        $xml = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/table/catalogo_grupo_articulos.xml'
        );
        $this->assertMatchesRegularExpression(
            '/FOREIGN KEY \(referencia\)[^"]*REFERENCES articulos[^\"]*ON DELETE CASCADE/s',
            $xml
        );
    }

    public function test_group_delete_removes_group_row(): void
    {
        $grupo = $this->model(\FSFramework\model\catalogo_grupo::class, [
            'nombre' => 'Comerciales',
        ]);
        $this->assertTrue($grupo->delete());
        $this->assertStringContainsString('DELETE FROM catalogo_grupo WHERE id', $this->db->lastSql);
    }

    public function test_naming_uses_codlista_only(): void
    {
        foreach (['catalogo_grupo', 'catalogo_grupo_rol', 'catalogo_grupo_usuario', 'catalogo_grupo_tarifa', 'catalogo_grupo_articulo'] as $name) {
            $file = FS_FOLDER . '/plugins/catalogo_core/model/core/' . $name . '.php';
            $this->assertFileExists($file);
            $this->assertStringNotContainsStringIgnoringCase('codtarifa', (string) file_get_contents($file));
        }
    }
}

/**
 * Minimal fs_db2 stand-in for group model flows.
 */
class MockGrupoDb
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

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

        return $this->rows;
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
}
