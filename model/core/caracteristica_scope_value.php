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

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_valor.php';

/**
 * Shared base of the three tarifa-keyed feature value scopes (CAR-04, CAR-05).
 *
 * Abstract on purpose: the value triad invariant, the keyed `exists()` /
 * `save()` / `delete()`, the transactional `copy_from_tarifa()` and the
 * inheritance depth are identical across the global, familia and articulo
 * scopes, so they live here once. Each concrete model declares its table and
 * scope key.
 */
abstract class caracteristica_scope_value extends \fs_model
{
    public const DEF_TARIFA = 'DEF';

    /**
     * The value-triad columns every scope table shares (CAR-04/CAR-05).
     *
     * @var list<string>
     */
    protected const VALUE_COLUMNS = ['id_caracteristica', 'id_valor', 'valor', 'custom'];

    /** @var string|null */
    public $codtarifa;

    /** @var int|null */
    public $id_caracteristica;

    /** @var int|null */
    public $id_valor;

    /** @var string|null */
    public $valor;

    /** @var bool */
    public $custom;

    public function __construct($data = false)
    {
        parent::__construct(static::TABLE);

        if ($data) {
            $this->codtarifa = $data['codtarifa'] ?? null;
            $this->id_caracteristica = isset($data['id_caracteristica'])
                ? (int) $data['id_caracteristica']
                : null;
            $this->id_valor = isset($data['id_valor']) ? (int) $data['id_valor'] : null;
            $this->valor = $data['valor'] ?? null;
            $this->custom = isset($data['custom']) ? $this->str2bool($data['custom']) : false;
        } else {
            $this->codtarifa = null;
            $this->id_caracteristica = null;
            $this->id_valor = null;
            $this->valor = null;
            $this->custom = false;
        }

        foreach ($this->key_columns() as $column) {
            if ($data && array_key_exists($column, $data)) {
                $this->{$column} = $data[$column];
            } else {
                $this->{$column} = null;
            }
        }
    }

    /** @return string articulo|familia|global */
    public function scope(): string
    {
        return static::SCOPE;
    }

    /**
     * Scope key column names (empty for the global scope).
     *
     * @return list<string>
     */
    public function key_columns(): array
    {
        return [];
    }

    /**
     * Parallel property values of {@see key_columns()}.
     *
     * @return list<mixed>
     */
    public function key_values(): array
    {
        $values = [];
        foreach ($this->key_columns() as $column) {
            $values[] = $this->{$column};
        }

        return $values;
    }

    /**
     * Raw scope row for a composite key, or false when absent (pure read).
     *
     * @param string $codtarifa
     * @param array<string, mixed> $key
     * @param int $idCaracteristica
     * @return static|false
     */
    public function get($codtarifa, array $key = [], $idCaracteristica = null)
    {
        $where = 'codtarifa = ' . $this->var2str((string) $codtarifa);
        foreach ($this->key_columns() as $column) {
            $where .= ' AND ' . $column . ' = ' . $this->var2str($key[$column] ?? null);
        }
        $where .= ' AND id_caracteristica = ' . $this->intval($idCaracteristica);

        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE ' . $where . ';');

        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function exists()
    {
        if ($this->codtarifa === null || $this->id_caracteristica === null) {
            return false;
        }

        return (bool) $this->db->select('SELECT 1 FROM ' . $this->table_name
            . ' WHERE ' . $this->primary_key_where() . ';');
    }

    public function test()
    {
        $hasIdValor = $this->id_valor !== null && (int) $this->id_valor > 0;
        $hasValor = $this->valor !== null;

        if ($hasIdValor === $hasValor) {
            $this->new_error_msg('Exactly one value representation is required.');
            return false;
        }

        // `custom` is derived, never accepted as caller input.
        $this->custom = $hasValor;

        $this->codtarifa = $this->no_html(trim((string) $this->codtarifa));
        if ($this->codtarifa === '') {
            $this->new_error_msg('La tarifa es obligatoria.');
            return false;
        }

        $this->id_caracteristica = $this->intval($this->id_caracteristica);
        $definition = $this->definition_for((int) $this->id_caracteristica);
        if (!$definition) {
            $this->new_error_msg('La característica indicada no existe.');
            return false;
        }

        if ((string) ($definition['tipo'] ?? '') === catalogo_caracteristica::TIPO_BOOL) {
            if (!$hasIdValor) {
                $this->new_error_msg('Una característica booleana solo admite valores predefinidos.');
                return false;
            }

            $catalogValue = $this->catalogo_value_for((int) $this->id_valor);
            if (!$catalogValue
                || (int) ($catalogValue['id_caracteristica'] ?? 0) !== (int) $this->id_caracteristica) {
                $this->new_error_msg('El valor predefinido no pertenece a la característica.');
                return false;
            }

            if (!in_array((string) ($catalogValue['valor'] ?? ''), ['1', '0'], true)) {
                $this->new_error_msg('Valor booleano no válido.');
                return false;
            }
        } elseif ($hasIdValor) {
            $catalogValue = $this->catalogo_value_for((int) $this->id_valor);
            if (!$catalogValue
                || (int) ($catalogValue['id_caracteristica'] ?? 0) !== (int) $this->id_caracteristica) {
                $this->new_error_msg('El valor predefinido no pertenece a la característica.');
                return false;
            }
        } else {
            // An empty custom string is a stored value, kept distinguishable from null.
            $this->valor = $this->no_html((string) $this->valor);
        }

        foreach ($this->key_columns() as $column) {
            if (trim((string) $this->{$column}) === '') {
                $this->new_error_msg('La clave del ámbito es obligatoria.');
                return false;
            }
        }

        return true;
    }

    public function save()
    {
        if (!$this->test()) {
            return false;
        }

        $columns = '';
        $values = '';
        foreach ($this->key_columns() as $column) {
            $columns .= $column . ', ';
            $values .= $this->var2str($this->{$column}) . ', ';
        }

        if ($this->exists()) {
            $sql = 'UPDATE ' . $this->table_name . ' SET '
                . 'id_valor = ' . $this->var2str($this->id_valor)
                . ', valor = ' . $this->var2str($this->valor)
                . ', custom = ' . $this->var2str($this->custom)
                . ' WHERE ' . $this->primary_key_where() . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name
                . ' (codtarifa, ' . $columns . 'id_caracteristica, id_valor, valor, custom) VALUES ('
                . $this->var2str($this->codtarifa) . ', '
                . $values
                . $this->intval($this->id_caracteristica) . ', '
                . $this->var2str($this->id_valor) . ', '
                . $this->var2str($this->valor) . ', '
                . $this->var2str($this->custom) . ');';
        }

        return (bool) $this->db->exec($sql);
    }

    public function delete()
    {
        if ($this->codtarifa === null || $this->id_caracteristica === null) {
            return false;
        }

        return (bool) $this->db->exec('DELETE FROM ' . $this->table_name
            . ' WHERE ' . $this->primary_key_where() . ';');
    }

    /**
     * Copies the scope rows of `$origen` into `$destino` (CAR-10, design §11).
     *
     * The destination rows are replaced, never merged: the destination is
     * cleared and repopulated from the origin inside a single transaction, so
     * a failed INSERT cannot leave the destination empty. Both statements run
     * with `$transaction = false` (nothing auto-commits before the explicit
     * commit), mirroring `tarif_tarifa_opcional::copy_from_tarifa()`.
     *
     * `coddivisa` is never part of the copy: the destination currency is
     * respected (`R-TAR-CUR-008`).
     *
     * @param string $origen
     * @param string $destino
     * @return bool
     */
    public function copy_from_tarifa($origen, $destino): bool
    {
        // Copying a tarifa onto itself is a no-op: the DELETE would remove the
        // very rows the INSERT ... SELECT is meant to read.
        if ((string) $origen === (string) $destino) {
            return true;
        }

        if (!$this->db->begin_transaction()) {
            return false;
        }

        $columns = array_merge(['codtarifa'], $this->key_columns(), self::VALUE_COLUMNS);
        $select = array_merge([$this->var2str($destino)], $this->key_columns(), self::VALUE_COLUMNS);

        $deleted = $this->db->exec('DELETE FROM ' . $this->table_name
            . ' WHERE codtarifa = ' . $this->var2str($destino) . ';', false);
        if (!$deleted) {
            $this->db->rollback();
            return false;
        }

        $sql = 'INSERT INTO ' . $this->table_name
            . ' (' . implode(', ', $columns) . ') '
            . 'SELECT ' . implode(', ', $select) . ' '
            . 'FROM ' . $this->table_name
            . ' WHERE codtarifa = ' . $this->var2str($origen) . ';';

        if (!$this->db->exec($sql, false)) {
            $this->db->rollback();
            return false;
        }

        if (!$this->db->commit()) {
            $this->db->rollback();
            return false;
        }

        return true;
    }

    /**
     * @return string SQL fragment for the table PK columns.
     */
    protected function primary_key_where(): string
    {
        $where = 'codtarifa = ' . $this->var2str($this->codtarifa);
        foreach ($this->key_columns() as $column) {
            $where .= ' AND ' . $column . ' = ' . $this->var2str($this->{$column});
        }

        return $where . ' AND id_caracteristica = ' . $this->intval($this->id_caracteristica);
    }

    /**
     * Definition seam (unit tests stub it to stay DB-free).
     *
     * @param int $idCaracteristica
     * @return array<string, mixed>|false
     */
    protected function definition_for(int $idCaracteristica)
    {
        $model = new catalogo_caracteristica();
        $row = $model->get_by_id($idCaracteristica);

        if (!$row) {
            return false;
        }

        return [
            'id' => $row->id,
            'tipo' => $row->tipo,
        ];
    }

    /**
     * Predefined-value seam (unit tests stub it to stay DB-free).
     *
     * @param int $idValor
     * @return array<string, mixed>|false
     */
    protected function catalogo_value_for(int $idValor)
    {
        $model = new catalogo_caracteristica_valor();
        $row = $model->get($idValor);

        if (!$row) {
            return false;
        }

        return [
            'id' => $row->id,
            'id_caracteristica' => $row->id_caracteristica,
            'valor' => $row->valor,
        ];
    }
}
