<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
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

namespace FSFramework\model;

/**
 * Feature definition (CAR-01, CAR-02).
 *
 * `codigo` is the stable key used by plugin registration and import mapping.
 * `origen` stores the registering plugin: a non-empty origen makes the
 * definition non-deletable (CAR-11).
 */
class catalogo_caracteristica extends \fs_model
{
    public const TIPO_BOOL = 'bool';
    public const TIPO_STRING = 'string';

    /** @var list<string> Closed type whitelist (CAR-02). */
    public const TIPOS = [self::TIPO_BOOL, self::TIPO_STRING];

    /** @var int|null */
    public $id;

    /** @var string|null */
    public $codigo;

    /** @var string|null */
    public $nombre;

    /** @var string|null */
    public $tipo;

    /** @var bool */
    public $activo;

    /** @var bool */
    public $importable;

    /** @var bool */
    public $exportable;

    /** @var bool */
    public $listable;

    /** @var int */
    public $orden;

    /** @var string */
    public $origen;

    /** @var string|null */
    public $valor_defecto;

    public function __construct($data = false)
    {
        parent::__construct('catalogo_caracteristicas');

        if ($data) {
            $this->id = isset($data['id']) ? (int) $data['id'] : null;
            $this->codigo = $data['codigo'] ?? null;
            $this->nombre = $data['nombre'] ?? null;
            $this->tipo = $data['tipo'] ?? null;
            $this->activo = isset($data['activo']) ? $this->str2bool($data['activo']) : true;
            $this->importable = isset($data['importable']) ? $this->str2bool($data['importable']) : false;
            $this->exportable = isset($data['exportable']) ? $this->str2bool($data['exportable']) : false;
            $this->listable = isset($data['listable']) ? $this->str2bool($data['listable']) : false;
            $this->orden = isset($data['orden']) ? (int) $data['orden'] : 0;
            $this->origen = (string) ($data['origen'] ?? '');
            $this->valor_defecto = $data['valor_defecto'] ?? null;
        } else {
            $this->id = null;
            $this->codigo = null;
            $this->nombre = null;
            $this->tipo = null;
            $this->activo = true;
            $this->importable = false;
            $this->exportable = false;
            $this->listable = false;
            $this->orden = 0;
            $this->origen = '';
            $this->valor_defecto = null;
        }
    }

    /**
     * Definition by stable codigo, or false when absent.
     *
     * @param string $codigo
     * @return static|false
     */
    public function get($codigo)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE codigo = ' . $this->var2str((string) $codigo) . ';');

        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    /**
     * @param int $id
     * @return static|false
     */
    public function get_by_id($id)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE id = ' . $this->intval($id) . ';');

        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    /**
     * All definitions ordered by orden ASC, codigo ASC (CAR-01).
     *
     * @param bool $onlyActive
     * @return array<int, static>
     */
    public function all($onlyActive = false)
    {
        return $this->fetch_list((bool) $onlyActive, null);
    }

    /**
     * Active + listable definitions, ordered — drives CAR-16 columns.
     *
     * @return array<int, static>
     */
    public function listable()
    {
        return $this->fetch_list(true, 'listable');
    }

    /**
     * Active + importable definitions, ordered — drives CAR-17.
     *
     * @return array<int, static>
     */
    public function importable()
    {
        return $this->fetch_list(true, 'importable');
    }

    /**
     * Active + exportable definitions, ordered — drives CAR-17.
     *
     * @return array<int, static>
     */
    public function exportable()
    {
        return $this->fetch_list(true, 'exportable');
    }

    /**
     * @param bool $onlyActive
     * @param string|null $flag listable|importable|exportable
     * @return array<int, static>
     */
    private function fetch_list(bool $onlyActive, ?string $flag): array
    {
        $where = '';
        if ($onlyActive) {
            $where .= ' WHERE activo = TRUE';
        }
        if ($flag !== null) {
            $where .= ($where === '' ? ' WHERE' : ' AND') . ' ' . $flag . ' = TRUE';
        }

        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . $where . ' ORDER BY orden ASC, codigo ASC;');

        $list = [];
        if ($data) {
            foreach ($data as $row) {
                $list[] = new static($row);
            }
        }

        return $list;
    }

    /**
     * A plugin-registered definition (non-empty origen) is not deletable.
     */
    public function is_deletable(): bool
    {
        return trim((string) $this->origen) === '';
    }

    public function exists()
    {
        if ($this->id === null) {
            return false;
        }

        return (bool) $this->db->select('SELECT id FROM ' . $this->table_name
            . ' WHERE id = ' . $this->intval($this->id) . ';');
    }

    public function test()
    {
        $this->codigo = $this->no_html(trim((string) $this->codigo));
        $this->nombre = $this->no_html(trim((string) $this->nombre));
        $this->origen = $this->no_html(trim((string) $this->origen));
        $this->orden = (int) $this->orden;

        $default = trim((string) $this->valor_defecto);
        $this->valor_defecto = $this->no_html($default);
        if ($this->valor_defecto === '') {
            $this->valor_defecto = null;
        }

        if ($this->codigo === '' || mb_strlen($this->codigo) > 50) {
            $this->new_error_msg('El código de la característica es obligatorio (máximo 50 caracteres).');
            return false;
        }

        if ($this->nombre === '' || mb_strlen($this->nombre) > 100) {
            $this->new_error_msg('El nombre de la característica es obligatorio (máximo 100 caracteres).');
            return false;
        }

        if (!in_array((string) $this->tipo, self::TIPOS, true)) {
            $this->new_error_msg('Tipo de característica no soportado: ' . (string) $this->tipo);
            return false;
        }

        if ($this->tipo === self::TIPO_BOOL
            && $this->valor_defecto !== null
            && !in_array($this->valor_defecto, ['1', '0'], true)) {
            $this->new_error_msg('El valor por defecto de una característica booleana debe ser 1 o 0.');
            return false;
        }

        $existing = $this->existing_by_codigo((string) $this->codigo);
        if ($existing && (int) ($existing['id'] ?? 0) !== (int) ($this->id ?? 0)) {
            $this->new_error_msg('Ya existe una característica con el código ' . $this->codigo . '.');
            return false;
        }

        return true;
    }

    public function save()
    {
        if (!$this->test()) {
            return false;
        }

        if ($this->exists()) {
            $sql = 'UPDATE ' . $this->table_name . ' SET '
                . 'codigo = ' . $this->var2str($this->codigo)
                . ', nombre = ' . $this->var2str($this->nombre)
                . ', tipo = ' . $this->var2str($this->tipo)
                . ', activo = ' . $this->var2str($this->activo)
                . ', importable = ' . $this->var2str($this->importable)
                . ', exportable = ' . $this->var2str($this->exportable)
                . ', listable = ' . $this->var2str($this->listable)
                . ', orden = ' . $this->intval($this->orden)
                . ', origen = ' . $this->var2str($this->origen)
                . ', valor_defecto = ' . $this->var2str($this->valor_defecto)
                . ' WHERE id = ' . $this->intval($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name
                . ' (codigo, nombre, tipo, activo, importable, exportable, listable, orden, origen, valor_defecto)'
                . ' VALUES ('
                . $this->var2str($this->codigo) . ','
                . $this->var2str($this->nombre) . ','
                . $this->var2str($this->tipo) . ','
                . $this->var2str($this->activo) . ','
                . $this->var2str($this->importable) . ','
                . $this->var2str($this->exportable) . ','
                . $this->var2str($this->listable) . ','
                . $this->intval($this->orden) . ','
                . $this->var2str($this->origen) . ','
                . $this->var2str($this->valor_defecto) . ');';
        }

        if (!$this->db->exec($sql)) {
            return false;
        }

        if ($this->id === null) {
            $this->id = (int) $this->db->lastval();
        }

        return true;
    }

    public function delete()
    {
        if (!$this->is_deletable()) {
            return $this->refuse_plugin_owned();
        }

        if ($this->id === null) {
            return false;
        }

        // CAR-11: the persisted `origen` is authoritative. Clearing the
        // in-memory property without saving must not unlock a plugin-owned row.
        $persisted = $this->persisted_origen();
        if ($persisted !== false && $persisted !== '') {
            return $this->refuse_plugin_owned();
        }

        return (bool) $this->db->exec('DELETE FROM ' . $this->table_name
            . ' WHERE id = ' . $this->intval($this->id)
            . " AND (origen IS NULL OR TRIM(origen) = '');");
    }

    private function refuse_plugin_owned(): bool
    {
        $this->new_error_msg('No se puede eliminar una característica registrada por un plugin.');

        return false;
    }

    /**
     * Persisted `origen` for this row, trimmed; `false` when the row (or the
     * query) is unavailable. CAR-11 needs the stored value, never the mutable
     * in-memory property, so `delete()` reads it before deleting.
     *
     * @return string|false
     */
    protected function persisted_origen()
    {
        $data = $this->db->select('SELECT origen FROM ' . $this->table_name
            . ' WHERE id = ' . $this->intval($this->id) . ';');

        if (!$data) {
            return false;
        }

        return trim((string) ($data[0]['origen'] ?? ''));
    }

    /**
     * DB seam for the duplicate-codigo check so `test()` is unit-testable
     * without a live database.
     *
     * @param string $codigo
     * @return array<string, mixed>|false
     */
    protected function existing_by_codigo(string $codigo)
    {
        $data = $this->db->select('SELECT id FROM ' . $this->table_name
            . ' WHERE codigo = ' . $this->var2str($codigo) . ';');

        if ($data) {
            return $data[0];
        }

        return false;
    }

    protected function install(): string
    {
        return '';
    }
}
