<?php
/**
 * This file is part of tarifario
 * Copyright (C) 2025 FSFramework Team
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
namespace FSFramework\model;

require_once 'plugins/catalogo_core/model/core/familia.php';

/**
 * Familia/Categoría de artículos del tarifario.
 *
 * @deprecated Read-only wrapper over familias rows hydrated with historical
 * tarif_familia_ext columns. Write operations have been removed — use
 * \FSFramework\model\familia + tarif_tarifa_familia for all writes.
 *
 * Extiende la familia base usando tabla separada (tarif_familia_ext)
 * para añadir campos de capítulo y nivel sin modificar la tabla original.
 */
class tarif_familia extends \FSFramework\model\familia
{
    /**
     * Nombre de la tabla de extensión.
     * @var string
     */
    protected $ext_table = 'tarif_familia_ext';

    /**
     * Capítulo para ordenación jerárquica (ej. 1, 1.1, 1.2).
     * @var string
     */
    public $capitulo;

    public function __construct($data = FALSE)
    {
        parent::__construct($data);

        if ($data) {
            $this->capitulo = isset($data['capitulo']) ? $data['capitulo'] : '';
            // nivel puede venir de la tabla base o de la extensión
            $this->nivel = isset($data['nivel']) ? $data['nivel'] : '';
        } else {
            $this->capitulo = '';
        }
    }

    public function url()
    {
        if (is_null($this->codfamilia)) {
            return "index.php?page=tarif_familias";
        }
        return "index.php?page=tarif_familias&cod=" . urlencode($this->codfamilia);
    }

    /**
     * Devuelve una familia a partir de su código.
     * @param string $cod
     * @return tarif_familia|false
     */
    public function get($cod)
    {
        $sql = "SELECT f.*, e.capitulo, e.nivel FROM " . $this->table_name . " f "
            . "LEFT JOIN " . $this->ext_table . " e ON f.codfamilia = e.codfamilia "
            . "WHERE f.codfamilia = " . $this->var2str($cod) . ";";
        $data = $this->db->select($sql);
        if ($data) {
            return new tarif_familia($data[0]);
        }
        return FALSE;
    }

    /**
     * Devuelve la familia madre.
     * @return tarif_familia|false
     */
    public function get_madre()
    {
        if (is_null($this->madre)) {
            return FALSE;
        }
        return $this->get($this->madre);
    }

    /**
     * Devuelve las subfamilias (hijas directas).
     * @return array
     */
    public function get_hijas()
    {
        $list = [];
        $sql = "SELECT f.*, e.capitulo, e.nivel FROM " . $this->table_name . " f "
            . "LEFT JOIN " . $this->ext_table . " e ON f.codfamilia = e.codfamilia "
            . "WHERE f.madre = " . $this->var2str($this->codfamilia)
            . " ORDER BY f.descripcion ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_familia($d);
            }
        }
        return $list;
    }

    /**
     * Devuelve las familias hijas por código de madre.
     * @param string|null $codmadre
     * @return array
     */
    public function hijas($codmadre = FALSE)
    {
        if (!$codmadre) {
            $codmadre = $this->codfamilia;
        }

        $list = [];
        $sql = "SELECT f.*, e.capitulo, e.nivel FROM " . $this->table_name . " f "
            . "LEFT JOIN " . $this->ext_table . " e ON f.codfamilia = e.codfamilia "
            . "WHERE f.madre = " . $this->var2str($codmadre)
            . " ORDER BY f.descripcion ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_familia($d);
            }
        }
        return $list;
    }

    /**
     * Devuelve todas las familias ordenadas jerárquicamente.
     * @return array
     */
    public function all()
    {
        $list = [];

        // Primero obtenemos las familias raíz (sin madre)
        $sql = "SELECT f.*, e.capitulo, e.nivel FROM " . $this->table_name . " f "
            . "LEFT JOIN " . $this->ext_table . " e ON f.codfamilia = e.codfamilia "
            . "WHERE f.madre IS NULL ORDER BY f.descripcion ASC;";
        $raices = $this->db->select($sql);

        if ($raices) {
            foreach ($raices as $r) {
                $fam = new tarif_familia($r);
                $list[] = $fam;
                // Añadir hijas recursivamente
                $this->add_hijas_recursivo($list, $fam);
            }
        }

        return $list;
    }

    /**
     * Añade las hijas de una familia recursivamente a la lista.
     * @param array $list
     * @param tarif_familia $familia
     */
    private function add_hijas_recursivo(&$list, $familia)
    {
        foreach ($familia->get_hijas() as $hija) {
            $list[] = $hija;
            $this->add_hijas_recursivo($list, $hija);
        }
    }

    /**
     * Devuelve todas las familias sin ordenar jerárquicamente.
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function all_simple($offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $list = [];
        $sql = "SELECT f.*, e.capitulo, e.nivel FROM " . $this->table_name . " f "
            . "LEFT JOIN " . $this->ext_table . " e ON f.codfamilia = e.codfamilia "
            . "ORDER BY f.descripcion ASC";
        $data = $this->db->select_limit($sql, $limit, $offset);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_familia($d);
            }
        }
        return $list;
    }

    /**
     * Busca familias por descripción o código.
     * @param string $query
     * @param int $offset
     * @return array
     */
    public function search($query = '', $offset = 0)
    {
        $list = [];
        $query = $this->no_html(mb_strtolower($query, 'UTF8'));

        $sql = "SELECT f.*, e.capitulo, e.nivel FROM " . $this->table_name . " f "
            . "LEFT JOIN " . $this->ext_table . " e ON f.codfamilia = e.codfamilia";
        if ($query != '') {
            $sql .= " WHERE lower(f.codfamilia) LIKE '%" . $query . "%'"
                . " OR lower(f.descripcion) LIKE '%" . $query . "%'";
        }
        $sql .= " ORDER BY f.descripcion ASC";

        $data = $this->db->select_limit($sql, FS_ITEM_LIMIT, $offset);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_familia($d);
            }
        }

        return $list;
    }

    /**
     * Devuelve todas las familias ordenadas por capítulo.
     * @return array
     */
    public function all_by_capitulo()
    {
        $list = [];
        $sql = "SELECT f.*, e.capitulo, e.nivel FROM " . $this->table_name . " f "
            . "LEFT JOIN " . $this->ext_table . " e ON f.codfamilia = e.codfamilia "
            . "ORDER BY e.capitulo ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_familia($d);
            }
        }
        return $list;
    }

    /**
     * Sugiere el siguiente capítulo para una familia.
     * @param string|null $madre
     * @return string
     */
    public function suggest_capitulo($madre = null)
    {
        $sql = "SELECT e.capitulo FROM " . $this->table_name . " f "
            . "LEFT JOIN " . $this->ext_table . " e ON f.codfamilia = e.codfamilia";
        if (empty($madre)) {
            $sql .= " WHERE f.madre IS NULL";
        } else {
            $sql .= " WHERE f.madre = " . $this->var2str($madre);
        }

        $data = $this->db->select($sql);
        if ($data) {
            $chapters = [];
            foreach ($data as $d) {
                if (!empty($d['capitulo'])) {
                    $chapters[] = $d['capitulo'];
                }
            }

            if (!empty($chapters)) {
                usort($chapters, 'version_compare');
                $last = end($chapters);

                $parts = explode('.', $last);
                $last_num = array_pop($parts);
                $next_num = intval($last_num) + 1;

                if (empty($parts)) {
                    return (string) $next_num;
                } else {
                    return implode('.', $parts) . '.' . $next_num;
                }
            }
        }

        if (empty($madre)) {
            return "1";
        } else {
            $madre_fam = $this->get($madre);
            if ($madre_fam && !empty($madre_fam->capitulo)) {
                return $madre_fam->capitulo . ".1";
            }
        }
        return "1";
    }
}
