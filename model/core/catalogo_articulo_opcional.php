<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
namespace FSFramework\model;

/**
 * Relación muchos a muchos entre artículos y opcionales sueltos (sin grupo).
 */
class catalogo_articulo_opcional extends \fs_model
{
    public const TABLE = 'catalogo_articulo_opcional';

    public $id;
    public $referencia;
    public $id_opcional;
    public $obligatorio;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id = intval($data['id']);
            $this->referencia = $data['referencia'];
            $this->id_opcional = intval($data['id_opcional']);
            $this->obligatorio = $this->str2bool($data['obligatorio'] ?? false);
        } else {
            $this->id = null;
            $this->referencia = null;
            $this->id_opcional = null;
            $this->obligatorio = false;
        }
    }

    protected function install()
    {
        return '';
    }

    /**
     * Los opcionales con grupo solo se asignan al artículo vía el grupo.
     *
     * @return true|string
     */
    public function validate_opcional_for_articulo(int $idOpcional)
    {
        $opcional = new catalogo_opcional();
        $item = $opcional->get($idOpcional);
        if (!$item) {
            return 'Opcional no encontrado.';
        }

        if ($item->id_grupo) {
            return 'Este opcional pertenece a un grupo. Asigna el grupo al artículo, no cada variante.';
        }

        return true;
    }

    public function add($referencia, $id_opcional, bool $obligatorio = false)
    {
        $valid = $this->validate_opcional_for_articulo((int) $id_opcional);
        if ($valid !== true) {
            $this->new_error_msg((string) $valid);

            return false;
        }

        if ($this->exists_relation($referencia, $id_opcional)) {
            return $this->set_obligatorio($referencia, (int) $id_opcional, $obligatorio);
        }

        $sql = 'INSERT INTO ' . $this->table_name . ' (referencia, id_opcional, obligatorio) VALUES ('
            . $this->var2str($referencia) . ','
            . $this->intval($id_opcional) . ','
            . $this->var2str($obligatorio) . ');';

        return $this->db->exec($sql);
    }

    public function set_obligatorio(string $referencia, int $idOpcional, bool $obligatorio): bool
    {
        return (bool) $this->db->exec(
            'UPDATE ' . $this->table_name
            . ' SET obligatorio = ' . $this->var2str($obligatorio)
            . ' WHERE referencia = ' . $this->var2str($referencia)
            . ' AND id_opcional = ' . $this->intval($idOpcional) . ';'
        );
    }

    public function remove($referencia, $id_opcional)
    {
        return $this->db->exec('DELETE FROM ' . $this->table_name
            . ' WHERE referencia = ' . $this->var2str($referencia)
            . ' AND id_opcional = ' . $this->intval($id_opcional) . ';');
    }

    public function exists_relation($referencia, $id_opcional)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE referencia = ' . $this->var2str($referencia)
            . ' AND id_opcional = ' . $this->intval($id_opcional) . ';');

        return ($data && count($data) > 0);
    }

    /**
     * Opcionales sueltos asignados directamente al artículo (sin grupo).
     *
     * @return array<int, catalogo_opcional>
     */
    public function get_opcionales_sueltos_from_articulo($referencia)
    {
        $list = [];
        $data = $this->db->select('SELECT o.*, ao.obligatorio AS obligatorio_en_articulo FROM ' . catalogo_opcional::TABLE . ' o'
            . ' INNER JOIN ' . $this->table_name . ' ao ON o.id = ao.id_opcional'
            . ' WHERE ao.referencia = ' . $this->var2str($referencia)
            . ' AND (o.id_grupo IS NULL OR o.id_grupo = 0)'
            . ' ORDER BY o.nombre ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new catalogo_opcional($d);
            }
        }

        return $list;
    }

    /**
     * Opcionales resueltos para venta: sueltos directos + variantes de grupos asignados.
     *
     * @return array<int, catalogo_opcional>
     */
    public function get_opcionales_from_articulo($referencia)
    {
        $list = [];
        $seen = [];

        foreach ($this->get_opcionales_sueltos_from_articulo($referencia) as $opcional) {
            $id = (int) $opcional->id;
            if (!isset($seen[$id])) {
                $list[] = $opcional;
                $seen[$id] = true;
            }
        }

        $grupoRel = new catalogo_articulo_opcional_grupo();
        foreach ($grupoRel->get_grupos_from_articulo($referencia) as $grupo) {
            foreach ($grupo->get_opcionales_activos() as $opcional) {
                $id = (int) $opcional->id;
                if (!isset($seen[$id])) {
                    $list[] = $opcional;
                    $seen[$id] = true;
                }
            }
        }

        usort($list, static function (catalogo_opcional $a, catalogo_opcional $b): int {
            return strcmp((string) $a->nombre, (string) $b->nombre);
        });

        return $list;
    }

    public function get_articulos_from_opcional($id_opcional)
    {
        $list = [];
        $data = $this->db->select('SELECT a.* FROM articulos a'
            . ' INNER JOIN ' . $this->table_name . ' ao ON a.referencia = ao.referencia'
            . ' WHERE ao.id_opcional = ' . $this->intval($id_opcional)
            . ' ORDER BY a.referencia ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new articulo($d);
            }
        }

        return $list;
    }

    public function delete_all_from_articulo($referencia)
    {
        return $this->db->exec('DELETE FROM ' . $this->table_name
            . ' WHERE referencia = ' . $this->var2str($referencia) . ';');
    }

    public function delete_all_from_opcional($id_opcional)
    {
        return $this->db->exec('DELETE FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($id_opcional) . ';');
    }

    public function count_articulos($id_opcional)
    {
        $data = $this->db->select('SELECT COUNT(*) as total FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($id_opcional) . ';');
        if ($data) {
            return intval($data[0]['total']);
        }

        return 0;
    }

    public function exists()
    {
        if (is_null($this->id)) {
            return false;
        }

        return $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';');
    }

    public function save()
    {
        if ($this->exists()) {
            $sql = 'UPDATE ' . $this->table_name . ' SET '
                . 'referencia = ' . $this->var2str($this->referencia)
                . ', id_opcional = ' . $this->intval($this->id_opcional)
                . ', obligatorio = ' . $this->var2str($this->obligatorio)
                . ' WHERE id = ' . $this->intval($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (referencia, id_opcional, obligatorio) VALUES ('
                . $this->var2str($this->referencia) . ','
                . $this->intval($this->id_opcional) . ','
                . $this->var2str($this->obligatorio) . ');';
        }

        if ($this->db->exec($sql)) {
            if (is_null($this->id)) {
                $this->id = $this->db->lastval();
            }
            return true;
        }

        return false;
    }

    public function delete()
    {
        return $this->db->exec('DELETE FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';');
    }
}
