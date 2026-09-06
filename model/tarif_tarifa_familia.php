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

/**
 * Configuración de familias por tarifa.
 * Permite que cada tarifa tenga su propia estructura de familias
 * (orden, jerarquía, inclusión) mientras las descripciones son compartidas.
 */
class tarif_tarifa_familia extends \fs_model
{
    /**
     * Código de la tarifa. Clave primaria (parte 1).
     * @var string
     */
    public $codtarifa;

    /**
     * Código de la familia. Clave primaria (parte 2).
     * @var string
     */
    public $codfamilia;

    /**
     * Código de la familia madre en ESTA tarifa.
     * Puede diferir de la jerarquía en otras tarifas.
     * @var string|null
     */
    public $madre;

    /**
     * Capítulo/orden en esta tarifa (ej: 1, 1.1, 2).
     * @var string
     */
    public $capitulo;

    /**
     * Nivel visual calculado (prefijo de indentación).
     * @var string
     */
    public $nivel;

    /**
     * Si la familia aparece en el catálogo público de esta tarifa.
     * @var boolean
     */
    public $en_catalogo;

    /**
     * Indica si la familia se incluye al exportar esta tarifa a clientes.
     * @var boolean
     */
    public $en_tarifa;

    /**
     * Si la familia está activa en esta tarifa.
     * @var boolean
     */
    public $activa;

    /**
     * Descripción de la familia (cargada de tabla familias).
     * @var string
     */
    public $descripcion;

    /**
     * Orden para visualización.
     * @var int
     */
    public $orden;

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_tarifa_familia');

        // Asegurar que la columna orden existe (una sola vez por sesión)
        static $orden_checked = false;
        if (!$orden_checked) {
            $this->add_orden_column_if_not_exists();
            $orden_checked = true;
        }

        if ($data) {
            $this->codtarifa = $data['codtarifa'];
            $this->codfamilia = $data['codfamilia'];
            $this->madre = isset($data['madre']) ? $data['madre'] : null;
            $this->capitulo = isset($data['capitulo']) ? $data['capitulo'] : '';
            $this->nivel = isset($data['nivel']) ? $data['nivel'] : '';
            $this->en_catalogo = $this->str2bool($data['en_catalogo']);
            $this->en_tarifa = isset($data['en_tarifa']) ? $this->str2bool($data['en_tarifa']) : false;
            $this->activa = $this->str2bool($data['activa']);
            $this->orden = isset($data['orden']) ? intval($data['orden']) : 0;
            // Descripción viene del JOIN con familias
            $this->descripcion = isset($data['descripcion']) ? $data['descripcion'] : '';
        } else {
            $this->codtarifa = null;
            $this->codfamilia = null;
            $this->madre = null;
            $this->capitulo = '';
            $this->nivel = '';
            $this->en_catalogo = true;
            $this->en_tarifa = false;
            $this->activa = true;
            $this->orden = 0;
            $this->descripcion = '';
        }
    }

    protected function install()
    {
        new tarif_tarifa();

        return $this->migrate_existing_familias();
    }

    /**
     * Añade la columna orden si no existe en la tabla.
     */
    private function add_orden_column_if_not_exists()
    {
        if (!$this->db->table_exists($this->table_name)) {
            return;
        }

        $sql = "SELECT column_name FROM information_schema.columns "
            . "WHERE table_name = '" . $this->table_name . "' AND column_name = 'orden';";
        $data = $this->db->select($sql);

        if (!$data || count($data) == 0) {
            $this->db->exec("ALTER TABLE " . $this->table_name . " ADD COLUMN orden integer NOT NULL DEFAULT 0;");
        }
    }

    /**
     * Migra las familias existentes de tarif_familia_ext a la tarifa por defecto.
     * @return string SQL de migración
     */
    private function migrate_existing_familias()
    {
        // Obtener la tarifa por defecto
        $tarifa = new tarif_tarifa();
        $default = $tarifa->get_default();

        if (!$default) {
            return '';
        }

        $codtarifa = $this->var2str($default->codtarifa);

        // Migrar desde tarif_familia_ext (si existe y tiene datos)
        $sql = "INSERT INTO " . $this->table_name . " (codtarifa, codfamilia, madre, capitulo, nivel, en_catalogo, en_tarifa, activa) "
            . "SELECT $codtarifa, f.codfamilia, f.madre, "
            . "COALESCE(e.capitulo, ''), COALESCE(e.nivel, ''), TRUE, FALSE, TRUE "
            . "FROM familias f "
            . "LEFT JOIN tarif_familia_ext e ON f.codfamilia = e.codfamilia "
            . "WHERE NOT EXISTS (SELECT 1 FROM " . $this->table_name . " WHERE codtarifa = $codtarifa AND codfamilia = f.codfamilia);";

        return $sql;
    }

    public function url()
    {
        return "index.php?page=tarif_familias&codtarifa=" . urlencode($this->codtarifa);
    }

    /**
     * Comprueba si existe el registro.
     * @return bool
     */
    public function exists()
    {
        if (is_null($this->codtarifa) || is_null($this->codfamilia)) {
            return false;
        }
        return $this->db->select("SELECT * FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
            . " AND codfamilia = " . $this->var2str($this->codfamilia) . ";");
    }

    /**
     * Obtiene una configuración de familia por tarifa.
     * @param string $codtarifa
     * @param string $codfamilia
     * @return tarif_tarifa_familia|false
     */
    public function get($codtarifa, $codfamilia)
    {
        $sql = "SELECT tf.*, f.descripcion FROM " . $this->table_name . " tf "
            . "LEFT JOIN familias f ON tf.codfamilia = f.codfamilia "
            . "WHERE tf.codtarifa = " . $this->var2str($codtarifa)
            . " AND tf.codfamilia = " . $this->var2str($codfamilia) . ";";
        $data = $this->db->select($sql);
        if ($data) {
            return new tarif_tarifa_familia($data[0]);
        }
        return false;
    }

    /**
     * Calcula el prefijo de nivel para mostrar jerarquía.
     * @return string
     */
    private function calcular_nivel()
    {
        $nivel = '';
        if ($this->madre) {
            $madre = $this->get($this->codtarifa, $this->madre);
            if ($madre) {
                $nivel = $madre->nivel . '— ';
            }
        }
        return $nivel;
    }

    public function test()
    {
        $this->codfamilia = $this->no_html(trim($this->codfamilia));
        $this->codtarifa = $this->no_html(trim($this->codtarifa));
        $this->capitulo = $this->no_html(trim($this->capitulo));

        if (empty($this->codfamilia) || empty($this->codtarifa)) {
            $this->new_error_msg("Código de tarifa y familia son obligatorios.");
            return false;
        }

        return true;
    }

    public function save()
    {
        if ($this->test()) {
            $this->nivel = $this->calcular_nivel();

            if ($this->exists()) {
                $sql = "UPDATE " . $this->table_name . " SET "
                    . "madre = " . $this->var2str($this->madre)
                    . ", capitulo = " . $this->var2str($this->capitulo)
                    . ", nivel = " . $this->var2str($this->nivel)
                    . ", en_catalogo = " . $this->var2str($this->en_catalogo)
                    . ", en_tarifa = " . $this->var2str($this->en_tarifa)
                    . ", activa = " . $this->var2str($this->activa)
                    . ", orden = " . $this->intval($this->orden)
                    . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
                    . " AND codfamilia = " . $this->var2str($this->codfamilia) . ";";
            } else {
                $sql = "INSERT INTO " . $this->table_name
                    . " (codtarifa, codfamilia, madre, capitulo, nivel, en_catalogo, en_tarifa, activa, orden) VALUES ("
                    . $this->var2str($this->codtarifa) . ","
                    . $this->var2str($this->codfamilia) . ","
                    . $this->var2str($this->madre) . ","
                    . $this->var2str($this->capitulo) . ","
                    . $this->var2str($this->nivel) . ","
                    . $this->var2str($this->en_catalogo) . ","
                    . $this->var2str($this->en_tarifa) . ","
                    . $this->var2str($this->activa) . ","
                    . $this->intval($this->orden) . ");";
            }

            if ($this->db->exec($sql)) {
                $this->actualizar_niveles_hijas();
                return true;
            }
        }
        return false;
    }

    /**
     * Actualiza los niveles de las familias hijas (recursivo).
     */
    private function actualizar_niveles_hijas()
    {
        foreach ($this->get_hijas() as $hija) {
            $hija->save();
        }
    }

    public function delete()
    {
        // Poner madre = NULL en las hijas antes de eliminar
        $sql = "UPDATE " . $this->table_name . " SET madre = NULL "
            . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
            . " AND madre = " . $this->var2str($this->codfamilia) . ";";
        $this->db->exec($sql);

        return $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
            . " AND codfamilia = " . $this->var2str($this->codfamilia) . ";");
    }

    /**
     * Devuelve la familia madre en esta tarifa.
     * @return tarif_tarifa_familia|false
     */
    public function get_madre()
    {
        if (is_null($this->madre)) {
            return false;
        }
        return $this->get($this->codtarifa, $this->madre);
    }

    /**
     * Devuelve las subfamilias (hijas directas) en esta tarifa.
     * @return array
     */
    public function get_hijas()
    {
        return $this->hijas($this->codtarifa, $this->codfamilia);
    }

    /**
     * Compara dos capítulos de forma natural (1, 1.1, 2, 2.1, 10, 10.1).
     * @param string $a
     * @param string $b
     * @return int
     */
    public static function compare_capitulos($a, $b)
    {
        return version_compare($a, $b);
    }

    /**
     * Ordena un array de familias por capítulo de forma natural.
     * @param array $familias Array de objetos tarif_tarifa_familia
     * @return array Array ordenado
     */
    private function sort_by_capitulo_natural($familias)
    {
        usort($familias, function ($a, $b) {
            // Primero comparar por capítulo de forma natural
            $cmp = version_compare($a->capitulo, $b->capitulo);
            if ($cmp !== 0) {
                return $cmp;
            }
            // Si tienen el mismo capítulo, ordenar por descripción
            return strcmp($a->descripcion, $b->descripcion);
        });
        return $familias;
    }

    /**
     * Devuelve las familias hijas de una familia en una tarifa.
     * @param string $codtarifa
     * @param string $codmadre
     * @return array
     */
    public function hijas($codtarifa, $codmadre)
    {
        $list = [];
        $sql = "SELECT tf.*, f.descripcion FROM " . $this->table_name . " tf "
            . "LEFT JOIN familias f ON tf.codfamilia = f.codfamilia "
            . "WHERE tf.codtarifa = " . $this->var2str($codtarifa)
            . " AND tf.madre = " . $this->var2str($codmadre)
            . " ORDER BY tf.capitulo ASC, f.descripcion ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_tarifa_familia($d);
            }
        }
        return $this->sort_by_capitulo_natural($list);
    }

    /**
     * Devuelve todas las familias de una tarifa.
     * @param string $codtarifa
     * @return array
     */
    public function all_from_tarifa($codtarifa)
    {
        $list = [];
        $sql = "SELECT tf.*, f.descripcion FROM " . $this->table_name . " tf "
            . "LEFT JOIN familias f ON tf.codfamilia = f.codfamilia "
            . "WHERE tf.codtarifa = " . $this->var2str($codtarifa)
            . " ORDER BY tf.capitulo ASC, f.descripcion ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_tarifa_familia($d);
            }
        }
        return $this->sort_by_capitulo_natural($list);
    }

    /**
     * Devuelve las familias activas de una tarifa.
     * @param string $codtarifa
     * @return array
     */
    public function all_activas_from_tarifa($codtarifa)
    {
        $list = [];
        $sql = "SELECT tf.*, f.descripcion FROM " . $this->table_name . " tf "
            . "LEFT JOIN familias f ON tf.codfamilia = f.codfamilia "
            . "WHERE tf.codtarifa = " . $this->var2str($codtarifa)
            . " AND tf.activa = TRUE"
            . " ORDER BY tf.orden ASC, tf.capitulo ASC, f.descripcion ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_tarifa_familia($d);
            }
        }
        return $this->sort_by_capitulo_natural($list);
    }

    /**
     * Devuelve las familias en catálogo de una tarifa.
     * @param string $codtarifa
     * @return array
     */
    public function all_en_catalogo_from_tarifa($codtarifa)
    {
        $list = [];
        $sql = "SELECT tf.*, f.descripcion FROM " . $this->table_name . " tf "
            . "LEFT JOIN familias f ON tf.codfamilia = f.codfamilia "
            . "WHERE tf.codtarifa = " . $this->var2str($codtarifa)
            . " AND tf.activa = TRUE AND tf.en_catalogo = TRUE"
            . " ORDER BY tf.orden ASC, tf.capitulo ASC, f.descripcion ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_tarifa_familia($d);
            }
        }
        return $this->sort_by_capitulo_natural($list);
    }

    /**
     * Devuelve todas las familias ordenadas jerárquicamente.
     * @param string $codtarifa
     * @return array
     */
    public function all_jerarquico($codtarifa)
    {
        $list = [];

        // Primero las raíces (sin madre)
        $sql = "SELECT tf.*, f.descripcion FROM " . $this->table_name . " tf "
            . "LEFT JOIN familias f ON tf.codfamilia = f.codfamilia "
            . "WHERE tf.codtarifa = " . $this->var2str($codtarifa)
            . " AND tf.madre IS NULL"
            . " ORDER BY tf.capitulo ASC, f.descripcion ASC;";
        $raices = $this->db->select($sql);

        if ($raices) {
            // Convertir a objetos y ordenar por capítulo
            $raices_objs = [];
            foreach ($raices as $r) {
                $raices_objs[] = new tarif_tarifa_familia($r);
            }
            $raices_objs = $this->sort_by_capitulo_natural($raices_objs);

            foreach ($raices_objs as $fam) {
                $list[] = $fam;
                $this->add_hijas_recursivo($list, $fam);
            }
        }

        return $list;
    }

    /**
     * Añade las hijas de una familia recursivamente a la lista.
     * @param array $list
     * @param tarif_tarifa_familia $familia
     */
    private function add_hijas_recursivo(&$list, $familia)
    {
        foreach ($familia->get_hijas() as $hija) {
            $list[] = $hija;
            $this->add_hijas_recursivo($list, $hija);
        }
    }

    /**
     * Devuelve todas las familias ordenadas por capítulo.
     * @param string $codtarifa
     * @return array
     */
    public function all_by_capitulo($codtarifa)
    {
        $list = [];
        $sql = "SELECT tf.*, f.descripcion FROM " . $this->table_name . " tf "
            . "LEFT JOIN familias f ON tf.codfamilia = f.codfamilia "
            . "WHERE tf.codtarifa = " . $this->var2str($codtarifa)
            . " ORDER BY tf.capitulo ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_tarifa_familia($d);
            }
        }
        return $this->sort_by_capitulo_natural($list);
    }

    /**
     * Sugiere el siguiente capítulo para una familia.
     * @param string $codtarifa
     * @param string|null $madre
     * @return string
     */
    public function suggest_capitulo($codtarifa, $madre = null)
    {
        $sql = "SELECT capitulo FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa);
        if (empty($madre)) {
            $sql .= " AND madre IS NULL";
        } else {
            $sql .= " AND madre = " . $this->var2str($madre);
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
            $madre_fam = $this->get($codtarifa, $madre);
            if ($madre_fam && !empty($madre_fam->capitulo)) {
                return $madre_fam->capitulo . ".1";
            }
        }
        return "1";
    }

    /**
     * Cuenta las familias de una tarifa.
     * @param string $codtarifa
     * @return int
     */
    public function count_from_tarifa($codtarifa)
    {
        $sql = "SELECT COUNT(*) as total FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa) . ";";
        $data = $this->db->select($sql);
        if ($data) {
            return intval($data[0]['total']);
        }
        return 0;
    }

    /**
     * Copia la estructura de familias de una tarifa a otra.
     * @param string $origen Código de tarifa origen
     * @param string $destino Código de tarifa destino
     * @return bool
     */
    public function copy_from_tarifa($origen, $destino)
    {
        // Primero eliminar las familias existentes en destino
        $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($destino) . ";");

        $sql = "INSERT INTO " . $this->table_name
            . " (codtarifa, codfamilia, madre, capitulo, nivel, en_catalogo, en_tarifa, activa, orden) "
            . "SELECT " . $this->var2str($destino) . ", codfamilia, madre, capitulo, nivel, en_catalogo, en_tarifa, activa, orden "
            . "FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($origen) . ";";

        return $this->db->exec($sql);
    }

    /**
     * Devuelve las familias marcadas como "en tarifa" de una tarifa.
     * Útil para exportar la tarifa a clientes.
     * @param string $codtarifa
     * @return array
     */
    public function all_en_tarifa_from_tarifa($codtarifa)
    {
        $list = [];
        $sql = "SELECT tf.*, f.descripcion FROM " . $this->table_name . " tf "
            . "LEFT JOIN familias f ON tf.codfamilia = f.codfamilia "
            . "WHERE tf.codtarifa = " . $this->var2str($codtarifa)
            . " AND tf.activa = TRUE AND tf.en_tarifa = TRUE"
            . " ORDER BY tf.capitulo ASC, f.descripcion ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_tarifa_familia($d);
            }
        }
        return $this->sort_by_capitulo_natural($list);
    }

    /**
     * Añade una familia existente a una tarifa.
     * @param string $codtarifa
     * @param string $codfamilia
     * @param string|null $madre
     * @return tarif_tarifa_familia|false
     */
    public function add_familia_to_tarifa($codtarifa, $codfamilia, $madre = null)
    {
        $tf = new tarif_tarifa_familia();
        $tf->codtarifa = $codtarifa;
        $tf->codfamilia = $codfamilia;
        $tf->madre = $madre;
        $tf->capitulo = $this->suggest_capitulo($codtarifa, $madre);
        $tf->en_catalogo = true;
        $tf->activa = true;

        if ($tf->save()) {
            return $tf;
        }
        return false;
    }

    /**
     * Elimina todas las familias de una tarifa.
     * @param string $codtarifa
     * @return bool
     */
    public function delete_all_from_tarifa($codtarifa)
    {
        return $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa) . ";");
    }

    /**
     * Renumera los capítulos de las familias hermanas (mismo padre) después de mover una familia.
     * Esta función ajusta automáticamente los números de capítulo para mantener la secuencia.
     * 
     * @param string $codtarifa Código de la tarifa
     * @param string|null $madre Código de la familia madre (null para familias raíz)
     * @param string $moved_codfamilia Código de la familia que se movió
     * @param int $new_position Nueva posición (1-based) de la familia movida
     * @return bool True si se renumeró correctamente
     */
    public function renumber_siblings($codtarifa, $madre, $moved_codfamilia, $new_position)
    {
        // Obtener todas las hermanas ordenadas por capítulo actual
        $siblings = $this->hijas($codtarifa, $madre);

        if (empty($siblings)) {
            return true;
        }

        // Separar la familia movida de las demás
        $moved_family = null;
        $other_siblings = [];

        foreach ($siblings as $sibling) {
            if ($sibling->codfamilia === $moved_codfamilia) {
                $moved_family = $sibling;
            } else {
                $other_siblings[] = $sibling;
            }
        }

        if (!$moved_family) {
            return false;
        }

        // Calcular el prefijo del capítulo (para subfamilias)
        $prefix = '';
        if (!empty($madre)) {
            $mother = $this->get($codtarifa, $madre);
            if ($mother && !empty($mother->capitulo)) {
                $prefix = $mother->capitulo . '.';
            }
        }

        // Reordenar: insertar la familia movida en la nueva posición
        $new_order = [];
        $pos = 1;
        $inserted = false;

        foreach ($other_siblings as $i => $sibling) {
            if ($pos === $new_position && !$inserted) {
                $new_order[] = $moved_family;
                $inserted = true;
            }
            $new_order[] = $sibling;
            $pos++;
        }

        // Si no se insertó, añadir al final
        if (!$inserted) {
            $new_order[] = $moved_family;
        }

        // Asignar nuevos capítulos secuenciales
        $chapter_num = 1;
        foreach ($new_order as $family) {
            $new_capitulo = $prefix . $chapter_num;

            // Solo actualizar si el capítulo cambió
            if ($family->capitulo !== $new_capitulo) {
                $family->capitulo = $new_capitulo;
                $family->save();

                // Renumera recursivamente las hijas de esta familia
                $this->renumber_children($codtarifa, $family->codfamilia);
            }

            $chapter_num++;
        }

        return true;
    }

    /**
     * Renumera recursivamente los capítulos de las hijas de una familia.
     * 
     * @param string $codtarifa Código de la tarifa
     * @param string $codfamilia Código de la familia padre
     */
    private function renumber_children($codtarifa, $codfamilia)
    {
        $family = $this->get($codtarifa, $codfamilia);
        if (!$family) {
            return;
        }

        $children = $this->hijas($codtarifa, $codfamilia);
        $chapter_num = 1;

        foreach ($children as $child) {
            $new_capitulo = $family->capitulo . '.' . $chapter_num;

            if ($child->capitulo !== $new_capitulo) {
                $child->capitulo = $new_capitulo;
                $child->save();

                // Recursión para las nietas
                $this->renumber_children($codtarifa, $child->codfamilia);
            }

            $chapter_num++;
        }
    }

    /**
     * Mueve una familia a una nueva posición y renumera automáticamente todos los afectados.
     * 
     * @param string $codtarifa Código de la tarifa
     * @param string $codfamilia Código de la familia a mover
     * @param string|null $new_madre Nueva madre (null para raíz)
     * @param int $new_position Nueva posición entre los hermanos (1-based)
     * @return array Resultado con 'success' y mensaje
     */
    public function move_family($codtarifa, $codfamilia, $new_madre, $new_position)
    {
        $family = $this->get($codtarifa, $codfamilia);
        if (!$family) {
            return ['success' => false, 'error' => 'Familia no encontrada'];
        }

        $old_madre = $family->madre;

        // Verificar que no se está intentando mover una familia como hija de sí misma o de sus descendientes
        if ($codfamilia === $new_madre) {
            return ['success' => false, 'error' => 'No se puede mover una familia como hija de sí misma'];
        }

        // Verificar que no es descendiente de la nueva madre
        if ($new_madre && $this->is_descendant($codtarifa, $codfamilia, $new_madre)) {
            return ['success' => false, 'error' => 'No se puede mover una familia como hija de uno de sus descendientes'];
        }

        // Actualizar la madre
        $family->madre = $new_madre;
        $family->save();

        // Renumera los hermanos de la posición antigua
        $this->renumber_siblings($codtarifa, $old_madre, null, 1);

        // Renumera los hermanos de la nueva posición (incluyendo la familia movida)
        $this->renumber_siblings($codtarifa, $new_madre, $codfamilia, $new_position);

        return ['success' => true, 'message' => 'Familia movida correctamente'];
    }

    /**
     * Verifica si una familia es descendiente de otra.
     * 
     * @param string $codtarifa Código de la tarifa
     * @param string $codfamilia Código de la posible familia descendiente
     * @param string $codancestor Código de la posible familia ancestro
     * @return bool True si codfamilia es descendiente de codancestor
     */
    private function is_descendant($codtarifa, $codfamilia, $codancestor)
    {
        $children = $this->hijas($codtarifa, $codfamilia);

        foreach ($children as $child) {
            if ($child->codfamilia === $codancestor) {
                return true;
            }
            if ($this->is_descendant($codtarifa, $child->codfamilia, $codancestor)) {
                return true;
            }
        }

        return false;
    }
}
