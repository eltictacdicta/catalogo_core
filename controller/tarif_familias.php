<?php
/**
 * This file is part of catalogo_core
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

require_once 'plugins/catalogo_core/extras/fbase_controller.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_familia.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_etiqueta_familia.php';

use FSFramework\model\tarif_tarifa_familia;
use FSFramework\model\tarif_tarifa_etiqueta_familia;
use FSFramework\model\tarif_tarifa;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Controlador para gestionar familias/categorías del tarifario por tarifa.
 * Permite ver, crear, editar y reordenar familias con drag & drop.
 * Cada tarifa tiene su propia estructura de familias.
 */
class tarif_familias extends fbase_controller
{
    /** Tabla estable de artículos por tarifa — permanece en tarifario (design D2/D3). */
    public const TARIFA_ARTICULO_TABLE = 'tarif_tarifa_articulo';

    /** @var bool Guard: las tablas de familias se aseguran una vez por proceso (design D4). */
    private static bool $tables_ensured = false;

    /** @var tarif_tarifa_familia */
    public $tarifa_familia;

    /** @var tarif_tarifa_etiqueta_familia */
    public $tarifa_etiqueta_familia;
    
    /** @var tarif_tarifa */
    public $tarifa;
    
    /** @var string Código de tarifa seleccionada */
    public $codtarifa;
    
    /** @var array Lista de tarifas disponibles */
    public $tarifas;
    
    /** @var array Árbol de familias */
    public $familias_tree;
    
    /** @var tarif_tarifa_familia|null Familia en edición */
    public $editing_familia;
    
    /** @var array Lista de familias base disponibles para añadir */
    public $familias_disponibles;
    
    /** @var boolean Filtrar solo activas */
    public $b_solo_activas;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Familias Tarifario', 'tarifario');
    }

    protected function private_core()
    {
        parent::private_core();

        // Asegura las tablas del paquete familias-tarifa una vez por proceso
        // (reemplaza el chequeo de tablas del controlador base anterior — design D4).
        if (!self::$tables_ensured) {
            self::$tables_ensured = true;
            if (class_exists(\FSFramework\Plugins\catalogo_core\Init::class)) {
                \FSFramework\Plugins\catalogo_core\Init::ensureFamiliasTarifaTables();
            }
        }

        $this->tarifa_familia = new tarif_tarifa_familia();
        $this->tarifa_etiqueta_familia = new tarif_tarifa_etiqueta_familia();
        $this->tarifa = new tarif_tarifa();
        $this->familias_tree = [];
        $this->editing_familia = null;
        $this->familias_disponibles = [];

        // Cargar tarifas disponibles
        $this->tarifas = $this->tarifa->all();
        
        // Obtener tarifa seleccionada (por defecto, la tarifa por defecto)
        $this->codtarifa = isset($_REQUEST['codtarifa']) ? trim($_REQUEST['codtarifa']) : '';
        if (empty($this->codtarifa)) {
            $default = $this->tarifa->get_default();
            $this->codtarifa = $default ? $default->codtarifa : '';
        }
        
        // Filtro de solo activas (por defecto FALSE para ver todas)
        $this->b_solo_activas = FALSE;
        if (isset($_REQUEST['b_solo_activas'])) {
            $this->b_solo_activas = ($_REQUEST['b_solo_activas'] == 'TRUE');
        }

        // Procesar acciones AJAX
        if (isset($_REQUEST['action'])) {
            $this->process_action($_REQUEST['action']);
            return;
        }

        // Procesar formularios
        if (isset($_POST['save_familia'])) {
            $this->save_familia();
        } else if (isset($_POST['add_familia'])) {
            $this->add_familia_to_tarifa();
        } else if (isset($_GET['delete'])) {
            $this->delete_familia();
        } else if (isset($_GET['edit'])) {
            $this->editing_familia = $this->tarifa_familia->get($this->codtarifa, $_GET['edit']);
        }

        // Cargar árbol de familias
        $this->load_familias_tree();
        
        // Cargar familias disponibles para añadir
        $this->load_familias_disponibles();
    }

    /**
     * Procesa acciones AJAX
     */
    private function process_action($action)
    {
        // Acciones de Excel que no devuelven JSON
        if ($action === 'export_excel') {
            $this->export_excel();
            return;
        }
        if ($action === 'export_excel_template') {
            $this->export_excel_template();
            return;
        }
        if ($action === 'import_excel_chunk') {
            $this->import_excel_chunk();
            return;
        }

        $this->template = false;
        header('Content-Type: application/json');

        switch ($action) {
            case 'reorder':
                echo json_encode($this->ajax_reorder());
                break;
            case 'get_next_capitulo':
                $madre = isset($_REQUEST['madre']) ? $_REQUEST['madre'] : null;
                if (empty($madre)) {
                    $madre = null;
                }
                echo json_encode(['capitulo' => $this->tarifa_familia->suggest_capitulo($this->codtarifa, $madre)]);
                break;
            case 'promote':
                echo json_encode($this->ajax_promote());
                break;
            case 'demote':
                echo json_encode($this->ajax_demote());
                break;
            case 'toggle_catalogo':
                echo json_encode($this->ajax_toggle_catalogo());
                break;
            case 'toggle_en_tarifa':
                echo json_encode($this->ajax_toggle_en_tarifa());
                break;
            case 'toggle_activa':
                echo json_encode($this->ajax_toggle_activa());
                break;
            default:
                echo json_encode(['success' => false, 'error' => 'Acción desconocida']);
        }
    }

    /**
     * Sube de nivel una familia (la convierte en hija de su "abuela" o en raíz)
     */
    private function ajax_promote()
    {
        $codfamilia = isset($_POST['codfamilia']) ? $_POST['codfamilia'] : '';

        if (empty($codfamilia)) {
            return ['success' => false, 'error' => 'Código de familia no proporcionado'];
        }

        $fam = $this->tarifa_familia->get($this->codtarifa, $codfamilia);
        if (!$fam) {
            return ['success' => false, 'error' => 'Familia no encontrada en esta tarifa'];
        }

        if (is_null($fam->madre)) {
            return ['success' => false, 'error' => 'Esta familia ya es una familia raíz'];
        }

        // Obtener la madre actual
        $madre_actual = $this->tarifa_familia->get($this->codtarifa, $fam->madre);

        // La nueva madre será la abuela (madre de la madre) o null si la madre es raíz
        $nueva_madre = null;
        if ($madre_actual && !is_null($madre_actual->madre)) {
            $nueva_madre = $madre_actual->madre;
        }

        $fam->madre = $nueva_madre;
        $fam->capitulo = $this->tarifa_familia->suggest_capitulo($this->codtarifa, $nueva_madre);

        if ($fam->save()) {
            $this->recalculate_children_capitulos($fam->codfamilia, $fam->capitulo);
            return ['success' => true, 'message' => 'Familia promovida correctamente', 'new_madre' => $nueva_madre];
        }

        return ['success' => false, 'error' => 'Error al guardar los cambios'];
    }

    /**
     * Baja de nivel una familia (la convierte en hija de la familia anterior del mismo nivel)
     */
    private function ajax_demote()
    {
        $codfamilia = isset($_POST['codfamilia']) ? $_POST['codfamilia'] : '';

        if (empty($codfamilia)) {
            return ['success' => false, 'error' => 'Código de familia no proporcionado'];
        }

        $fam = $this->tarifa_familia->get($this->codtarifa, $codfamilia);
        if (!$fam) {
            return ['success' => false, 'error' => 'Familia no encontrada en esta tarifa'];
        }

        // Buscar el hermano anterior
        $hermanos = $this->get_siblings($fam);
        $hermano_anterior = null;

        foreach ($hermanos as $h) {
            if ($h->codfamilia == $fam->codfamilia) {
                break;
            }
            $hermano_anterior = $h;
        }

        if (!$hermano_anterior) {
            return ['success' => false, 'error' => 'No hay una familia hermana anterior para convertirse en madre'];
        }

        $fam->madre = $hermano_anterior->codfamilia;
        $fam->capitulo = $this->tarifa_familia->suggest_capitulo($this->codtarifa, $hermano_anterior->codfamilia);

        if ($fam->save()) {
            $this->recalculate_children_capitulos($fam->codfamilia, $fam->capitulo);
            return ['success' => true, 'message' => 'Familia degradada correctamente', 'new_madre' => $hermano_anterior->codfamilia];
        }

        return ['success' => false, 'error' => 'Error al guardar los cambios'];
    }

    /**
     * Toggle en_catalogo de una familia
     */
    private function ajax_toggle_catalogo()
    {
        $codfamilia = isset($_POST['codfamilia']) ? $_POST['codfamilia'] : '';

        if (empty($codfamilia)) {
            return ['success' => false, 'error' => 'Código de familia no proporcionado'];
        }

        $fam = $this->tarifa_familia->get($this->codtarifa, $codfamilia);
        if (!$fam) {
            return ['success' => false, 'error' => 'Familia no encontrada'];
        }

        $fam->en_catalogo = !$fam->en_catalogo;

        if ($fam->save()) {
            return ['success' => true, 'en_catalogo' => $fam->en_catalogo];
        }

        return ['success' => false, 'error' => 'Error al guardar'];
    }

    /**
     * Toggle en_tarifa de una familia (incluir en exportación de tarifa)
     */
    private function ajax_toggle_en_tarifa()
    {
        $codfamilia = isset($_POST['codfamilia']) ? $_POST['codfamilia'] : '';

        if (empty($codfamilia)) {
            return ['success' => false, 'error' => 'Código de familia no proporcionado'];
        }

        $fam = $this->tarifa_familia->get($this->codtarifa, $codfamilia);
        if (!$fam) {
            return ['success' => false, 'error' => 'Familia no encontrada'];
        }

        $fam->en_tarifa = !$fam->en_tarifa;

        if ($fam->save()) {
            return ['success' => true, 'en_tarifa' => $fam->en_tarifa];
        }

        return ['success' => false, 'error' => 'Error al guardar'];
    }

    /**
     * Toggle activa de una familia
     */
    private function ajax_toggle_activa()
    {
        $codfamilia = isset($_POST['codfamilia']) ? $_POST['codfamilia'] : '';

        if (empty($codfamilia)) {
            return ['success' => false, 'error' => 'Código de familia no proporcionado'];
        }

        $fam = $this->tarifa_familia->get($this->codtarifa, $codfamilia);
        if (!$fam) {
            return ['success' => false, 'error' => 'Familia no encontrada'];
        }

        $fam->activa = !$fam->activa;

        if ($fam->save()) {
            return ['success' => true, 'activa' => $fam->activa];
        }

        return ['success' => false, 'error' => 'Error al guardar'];
    }

    /**
     * Obtiene las familias hermanas (misma madre) ordenadas por capítulo
     */
    private function get_siblings($familia)
    {
        $all = $this->tarifa_familia->all_by_capitulo($this->codtarifa);
        $siblings = [];

        foreach ($all as $f) {
            if ($f->madre === $familia->madre) {
                $siblings[] = $f;
            }
        }

        return $siblings;
    }

    /**
     * Recalcula los capítulos de las hijas de una familia
     */
    private function recalculate_children_capitulos($codfamilia, $parent_capitulo)
    {
        $hijas = $this->tarifa_familia->hijas($this->codtarifa, $codfamilia);
        $index = 1;

        foreach ($hijas as $hija) {
            $hija->capitulo = $parent_capitulo . '.' . $index;
            $hija->save();
            $this->recalculate_children_capitulos($hija->codfamilia, $hija->capitulo);
            $index++;
        }
    }

    /**
     * Procesa el reordenamiento vía AJAX
     */
    private function ajax_reorder()
    {
        if (!isset($_POST['order'])) {
            return ['success' => false, 'error' => 'No se recibió el orden'];
        }

        $order = json_decode($_POST['order'], true);
        if (!is_array($order)) {
            return ['success' => false, 'error' => 'Formato de orden inválido'];
        }

        // Debug: log what we received
        error_log("ajax_reorder received: " . print_r($order, true));

        // El nuevo formato espera: codfamilia, madre, posicion
        // Si viene en formato antiguo (codfamilia, capitulo), usar el método antiguo
        if (isset($order[0]['capitulo']) && !isset($order[0]['posicion'])) {
            // Formato antiguo: solo guardar los capítulos recibidos
            $errors = [];
            foreach ($order as $item) {
                $fam = $this->tarifa_familia->get($this->codtarifa, $item['codfamilia']);
                if ($fam) {
                    $fam->capitulo = $item['capitulo'];
                    if (!$fam->save()) {
                        $errors[] = $item['codfamilia'];
                    }
                }
            }

            if (empty($errors)) {
                return ['success' => true, 'message' => 'Orden guardado correctamente'];
            }

            return ['success' => false, 'error' => 'Error al guardar: ' . implode(', ', $errors)];
        }

        // Nuevo formato con renumeración automática
        // Agrupar por madre para procesar cada grupo de hermanos
        $byMadre = [];
        foreach ($order as $item) {
            $madre = isset($item['madre']) ? $item['madre'] : '';
            if (empty($madre)) {
                $madre = '__ROOT__';
            }
            if (!isset($byMadre[$madre])) {
                $byMadre[$madre] = [];
            }
            $byMadre[$madre][] = $item;
        }

        error_log("byMadre groups: " . print_r($byMadre, true));

        // Procesar cada grupo de hermanos
        foreach ($byMadre as $madreKey => $siblings) {
            $madre = ($madreKey === '__ROOT__') ? null : $madreKey;
            
            // Ordenar por posición
            usort($siblings, function($a, $b) {
                return ($a['posicion'] ?? 0) - ($b['posicion'] ?? 0);
            });
            
            // Renumerar este grupo de hermanos
            $this->renumber_siblings_group($madre, $siblings);
        }

        return ['success' => true, 'message' => 'Orden guardado y renumerado correctamente'];
    }

    /**
     * Renumera un grupo de familias hermanas
     * @param string|null $madre Código de la madre (null para raíz)
     * @param array $siblings Array de familias con codfamilia y posicion
     */
    private function renumber_siblings_group($madre, $siblings)
    {
        // Calcular el prefijo del capítulo
        $prefix = '';
        if (!empty($madre)) {
            $mother = $this->tarifa_familia->get($this->codtarifa, $madre);
            if ($mother && !empty($mother->capitulo)) {
                $prefix = $mother->capitulo . '.';
            }
        }

        error_log("renumber_siblings_group: madre=" . ($madre ?? 'NULL') . ", prefix=$prefix, siblings=" . count($siblings));

        // Asignar nuevos capítulos secuenciales
        $chapter_num = 1;
        foreach ($siblings as $item) {
            $codfamilia = $item['codfamilia'];
            $family = $this->tarifa_familia->get($this->codtarifa, $codfamilia);
            
            if ($family) {
                $old_capitulo = $family->capitulo;
                $old_madre = $family->madre;
                $new_capitulo = $prefix . $chapter_num;
                
                // Actualizar madre si cambió
                $family->madre = $madre;
                $family->capitulo = $new_capitulo;
                
                error_log("Updating $codfamilia: capitulo $old_capitulo -> $new_capitulo, madre " . ($old_madre ?? 'NULL') . " -> " . ($madre ?? 'NULL'));
                
                if (!$family->save()) {
                    error_log("ERROR saving family $codfamilia");
                }
                
                // Renumera recursivamente las hijas
                $this->renumber_children_recursive($codfamilia, $new_capitulo);
            } else {
                error_log("Family not found: $codfamilia");
            }
            
            $chapter_num++;
        }
    }

    /**
     * Renumera recursivamente los hijos de una familia
     * @param string $codfamilia Código de la familia padre
     * @param string $parent_capitulo Capítulo del padre
     */
    private function renumber_children_recursive($codfamilia, $parent_capitulo)
    {
        $children = $this->tarifa_familia->hijas($this->codtarifa, $codfamilia);
        $chapter_num = 1;
        
        foreach ($children as $child) {
            $new_capitulo = $parent_capitulo . '.' . $chapter_num;
            
            if ($child->capitulo !== $new_capitulo) {
                $child->capitulo = $new_capitulo;
                $child->save();
                
                // Recursión para los nietos
                $this->renumber_children_recursive($child->codfamilia, $new_capitulo);
            }
            
            $chapter_num++;
        }
    }

    /**
     * Carga el árbol de familias ordenado por capítulo
     */
    private function load_familias_tree()
    {
        if (empty($this->codtarifa)) {
            return;
        }
        
        // Filtrar según parámetro
        if ($this->b_solo_activas) {
            $all_familias = $this->tarifa_familia->all_activas_from_tarifa($this->codtarifa);
        } else {
            $all_familias = $this->tarifa_familia->all_by_capitulo($this->codtarifa);
        }
        $this->familias_tree = $this->build_tree($all_familias);
    }

    /**
     * Construye el árbol jerárquico de familias
     */
    private function build_tree($familias, $madre = null, $nivel = 0)
    {
        $tree = [];

        foreach ($familias as $fam) {
            if ($fam->madre === $madre) {
                $fam->nivel_tree = $nivel;
                $fam->hijas_tree = $this->build_tree($familias, $fam->codfamilia, $nivel + 1);
                $tree[] = $fam;
            }
        }

        return $tree;
    }

    /**
     * Devuelve las familias en formato plano pero con nivel para la vista
     */
    public function get_familias_flat()
    {
        return $this->flatten_tree($this->familias_tree);
    }

    /**
     * Aplana el árbol manteniendo el orden jerárquico
     */
    private function flatten_tree($tree, $nivel = 0)
    {
        $flat = [];

        foreach ($tree as $fam) {
            $fam->nivel_visual = $nivel;
            $flat[] = $fam;

            if (!empty($fam->hijas_tree)) {
                $flat = array_merge($flat, $this->flatten_tree($fam->hijas_tree, $nivel + 1));
            }
        }

        return $flat;
    }

    /**
     * Guarda una familia (edición)
     */
    private function save_familia()
    {
        $codfamilia = isset($_POST['codfamilia']) ? trim($_POST['codfamilia']) : '';

        $fam = $this->tarifa_familia->get($this->codtarifa, $codfamilia);
        if (!$fam) {
            $this->new_error_msg('Familia no encontrada en esta tarifa.');
            return;
        }

        $madre = isset($_POST['madre']) ? trim($_POST['madre']) : '';
        $fam->madre = empty($madre) ? null : $madre;

        $capitulo = isset($_POST['capitulo']) ? trim($_POST['capitulo']) : '';
        $fam->capitulo = empty($capitulo) ? '' : $capitulo;

        $fam->en_catalogo = isset($_POST['en_catalogo']);
        $fam->en_tarifa = isset($_POST['en_tarifa']);
        $fam->activa = isset($_POST['activa']);

        if (isset($_POST['descripcion'])) {
            require_once 'plugins/catalogo_core/model/core/familia.php';
            $familia_base_model = new \FSFramework\model\familia();
            $familia_base = $familia_base_model->get($codfamilia);

            if ($familia_base) {
                $familia_base->descripcion = trim($_POST['descripcion']);
                if (!$familia_base->save()) {
                    $this->new_error_msg('Error al guardar la descripción de la familia base.');
                    return;
                }
            }
        }

        if ($fam->save()) {
            $etiquetas_raw = isset($_POST['etiquetas']) ? trim($_POST['etiquetas']) : null;
            if (null !== $etiquetas_raw) {
                $etiquetas = preg_split('/[,\n]+/', $etiquetas_raw);

                // Build visibility map from checkboxes
                $visibilidad = [];
                if (isset($_POST['etiqueta_visible']) && is_array($_POST['etiqueta_visible'])) {
                    foreach ($_POST['etiqueta_visible'] as $tag_visible) {
                        $visibilidad[trim($tag_visible)] = TRUE;
                    }
                }

                if (!$this->tarifa_etiqueta_familia->replace_etiquetas_familia($this->codtarifa, $fam->codfamilia, $etiquetas, $visibilidad)) {
                    $this->new_error_msg('Familia guardada, pero no se pudieron guardar las etiquetas.');
                    return;
                }
            }

            $this->new_message('Familia guardada correctamente.');
        } else {
            $this->new_error_msg('Error al guardar la familia.');
        }
    }

    /**
     * Añade una familia existente a la tarifa
     */
    private function add_familia_to_tarifa()
    {
        $codfamilia = isset($_POST['codfamilia']) ? trim($_POST['codfamilia']) : '';
        $madre = isset($_POST['madre']) ? trim($_POST['madre']) : '';

        if (empty($codfamilia)) {
            $this->new_error_msg('Debe seleccionar una familia.');
            return;
        }

        // Verificar que no existe ya en la tarifa
        if ($this->tarifa_familia->get($this->codtarifa, $codfamilia)) {
            $this->new_error_msg('Esta familia ya existe en la tarifa.');
            return;
        }

        $tf = $this->tarifa_familia->add_familia_to_tarifa(
            $this->codtarifa, 
            $codfamilia, 
            empty($madre) ? null : $madre
        );

        if ($tf) {
            $this->new_message('Familia añadida a la tarifa correctamente.');
        } else {
            $this->new_error_msg('Error al añadir la familia.');
        }
    }

    /**
     * Elimina una familia de la tarifa (no elimina la familia base)
     */
    private function delete_familia()
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar en esta página.');
            return;
        }

        $codfamilia = $_GET['delete'];
        $fam = $this->tarifa_familia->get($this->codtarifa, $codfamilia);
        
        if ($fam) {
            // Verificar si tiene subfamilias en esta tarifa
            $hijas = $this->tarifa_familia->hijas($this->codtarifa, $codfamilia);
            if (!empty($hijas)) {
                $this->new_error_msg('No se puede eliminar una familia con subfamilias. Elimina primero las subfamilias.');
                return;
            }

            // Verificar si tiene artículos en esta tarifa
            $num_articulos = $this->count_tarifa_articulos($this->codtarifa, $codfamilia);
            if ($num_articulos > 0) {
                $this->new_error_msg('No se puede eliminar una familia con artículos asociados en esta tarifa.');
                return;
            }

            if ($fam->delete()) {
                $this->new_message('Familia eliminada de la tarifa correctamente.');
            } else {
                $this->new_error_msg('Error al eliminar la familia.');
            }
        } else {
            $this->new_error_msg('Familia no encontrada en esta tarifa.');
        }
    }

    /**
     * Cuenta los artículos de una familia en la tarifa actual
     */
    public function count_articulos($codfamilia)
    {
        return $this->count_tarifa_articulos($this->codtarifa, $codfamilia);
    }

    /**
     * Cuenta artículos mediante SQL directo contra la tabla estable declarada
     * en TARIFA_ARTICULO_TABLE (permanece en tarifario) — sustituye al modelo
     * homónimo sin acoplarse a él (design D2).
     */
    private function count_tarifa_articulos($codtarifa, $codfamilia): int
    {
        $data = $this->db->select(
            'SELECT COUNT(*) AS total FROM ' . self::TARIFA_ARTICULO_TABLE
            . ' WHERE codtarifa = ? AND codfamilia = ?',
            [$codtarifa, $codfamilia]
        );

        return ($data && isset($data[0]['total'])) ? (int) $data[0]['total'] : 0;
    }

    /**
     * Devuelve las familias que pueden ser madres (excluyendo una familia específica y sus hijas)
     */
    public function get_posibles_madres($exclude_codfamilia = null)
    {
        $all = $this->tarifa_familia->all_from_tarifa($this->codtarifa);

        if (is_null($exclude_codfamilia)) {
            return $all;
        }

        // Excluir la familia y todas sus descendientes
        $exclude = [$exclude_codfamilia];
        $this->get_descendants($exclude_codfamilia, $exclude);

        $result = [];
        foreach ($all as $fam) {
            if (!in_array($fam->codfamilia, $exclude)) {
                $result[] = $fam;
            }
        }

        return $result;
    }

    /**
     * Obtiene todos los descendientes de una familia
     */
    private function get_descendants($codfamilia, &$exclude)
    {
        $hijas = $this->tarifa_familia->hijas($this->codtarifa, $codfamilia);
        foreach ($hijas as $hija) {
            $exclude[] = $hija->codfamilia;
            $this->get_descendants($hija->codfamilia, $exclude);
        }
    }

    /**
     * Carga las familias base que no están en la tarifa actual (para añadir)
     */
    private function load_familias_disponibles()
    {
        if (empty($this->codtarifa)) {
            return;
        }

        // Obtener todas las familias base
        require_once 'plugins/catalogo_core/model/core/familia.php';
        $familia_base = new \familia();
        $todas = $familia_base->all();

        // Obtener familias ya en la tarifa
        $en_tarifa = $this->tarifa_familia->all_from_tarifa($this->codtarifa);
        $codigos_en_tarifa = [];
        foreach ($en_tarifa as $tf) {
            $codigos_en_tarifa[] = $tf->codfamilia;
        }

        // Filtrar las que no están
        $this->familias_disponibles = [];
        foreach ($todas as $f) {
            if (!in_array($f->codfamilia, $codigos_en_tarifa)) {
                $this->familias_disponibles[] = $f;
            }
        }
    }

    /**
     * Devuelve la tarifa actual
     */
    public function get_tarifa_actual()
    {
        if (empty($this->codtarifa)) {
            return null;
        }
        return $this->tarifa->get($this->codtarifa);
    }

    /**
     * Devuelve las etiquetas de una familia como texto separado por comas.
     *
     * @param string $codfamilia
     * @return string
     */
    public function get_etiquetas_familia_text($codfamilia)
    {
        $tags = $this->tarifa_etiqueta_familia->get_etiquetas_familia($this->codtarifa, $codfamilia);
        return implode(', ', $tags);
    }

    /**
     * Devuelve las etiquetas de una familia con su visibilidad.
     *
     * @param string $codfamilia
     * @return array  Array de objetos con propiedades etiqueta y visible
     */
    public function get_etiquetas_familia_full($codfamilia)
    {
        return $this->tarifa_etiqueta_familia->get_etiquetas_familia_full($this->codtarifa, $codfamilia);
    }

    /**
     * Exporta las familias de la tarifa a un archivo Excel.
     * Incluye jerarquía (madre), flags de visibilidad y etiquetas.
     */
    private function export_excel()
    {
        $this->template = FALSE;

        @ini_set('display_errors', 0);
        error_reporting(0);
        @ini_set('memory_limit', '256M');
        @set_time_limit(120);

        try {
            if (empty($this->codtarifa)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'No hay tarifa seleccionada']);
                return;
            }

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Familias');

            // Headers
            $headers = ['Codigo', 'Descripcion', 'Madre', 'Capitulo', 'En Catalogo', 'En Tarifa', 'Activa', 'Etiquetas'];
            foreach ($headers as $col => $header) {
                $cell = chr(65 + $col) . '1';
                $sheet->setCellValue($cell, $header);
            }

            // Estilos del header
            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '5B9BD5']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '2E75B6']]]
            ];
            $sheet->getStyle('A1:H1')->applyFromArray($headerStyle);
            $sheet->getRowDimension(1)->setRowHeight(25);

            // Anchos de columna
            $sheet->getColumnDimension('A')->setWidth(18);
            $sheet->getColumnDimension('B')->setWidth(40);
            $sheet->getColumnDimension('C')->setWidth(18);
            $sheet->getColumnDimension('D')->setWidth(12);
            $sheet->getColumnDimension('E')->setWidth(14);
            $sheet->getColumnDimension('F')->setWidth(12);
            $sheet->getColumnDimension('G')->setWidth(10);
            $sheet->getColumnDimension('H')->setWidth(35);

            // Obtener familias ordenadas jerárquicamente
            $familias = $this->tarifa_familia->all_jerarquico($this->codtarifa);
            $row = 2;

            foreach ($familias as $fam) {
                // Obtener etiquetas
                $etiquetas = $this->tarifa_etiqueta_familia->get_etiquetas_familia($this->codtarifa, $fam->codfamilia);
                $etiquetas_str = implode(', ', $etiquetas);

                $sheet->setCellValue('A' . $row, $fam->codfamilia);
                $sheet->setCellValue('B' . $row, $fam->descripcion);
                $sheet->setCellValue('C' . $row, $fam->madre ?? '');
                $sheet->setCellValue('D' . $row, $fam->capitulo);
                $sheet->setCellValue('E' . $row, $fam->en_catalogo ? 'TRUE' : 'FALSE');
                $sheet->setCellValue('F' . $row, $fam->en_tarifa ? 'TRUE' : 'FALSE');
                $sheet->setCellValue('G' . $row, $fam->activa ? 'TRUE' : 'FALSE');
                $sheet->setCellValue('H' . $row, $etiquetas_str);

                $row++;
            }

            // Estilos de datos
            if ($row > 2) {
                $dataRange = 'A2:H' . ($row - 1);
                $sheet->getStyle($dataRange)->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9E2F3']]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
                ]);
                // Color alterno de filas
                for ($r = 2; $r < $row; $r++) {
                    if ($r % 2 == 0) {
                        $sheet->getStyle('A' . $r . ':H' . $r)->applyFromArray([
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F7FC']]
                        ]);
                    }
                }
            }

            // Congelar primera fila
            $sheet->freezePane('A2');

            // Nombre del archivo
            $tarifa = $this->tarifa->get($this->codtarifa);
            $tarifa_nombre = $tarifa ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $tarifa->nombre) : $this->codtarifa;
            $filename = 'familias_' . $tarifa_nombre . '_' . date('Y-m-d_His') . '.xlsx';

            // Headers de descarga
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: max-age=0');

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');

        } catch (\Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
        }
    }

    /**
     * Exporta una plantilla Excel vacía para importar familias.
     */
    private function export_excel_template()
    {
        $this->template = FALSE;

        @ini_set('display_errors', 0);
        error_reporting(0);

        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Plantilla Familias');

            // Headers
            $headers = ['Codigo', 'Descripcion', 'Madre', 'En Catalogo', 'En Tarifa', 'Activa', 'Etiquetas'];
            foreach ($headers as $col => $header) {
                $cell = chr(65 + $col) . '1';
                $sheet->setCellValue($cell, $header);
            }

            // Estilos del header
            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '5B9BD5']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '2E75B6']]]
            ];
            $sheet->getStyle('A1:G1')->applyFromArray($headerStyle);
            $sheet->getRowDimension(1)->setRowHeight(25);

            // Anchos de columna
            $sheet->getColumnDimension('A')->setWidth(18);
            $sheet->getColumnDimension('B')->setWidth(40);
            $sheet->getColumnDimension('C')->setWidth(18);
            $sheet->getColumnDimension('D')->setWidth(14);
            $sheet->getColumnDimension('E')->setWidth(12);
            $sheet->getColumnDimension('F')->setWidth(10);
            $sheet->getColumnDimension('G')->setWidth(35);

            // Filas de ejemplo
            $sheet->setCellValue('A2', 'FAM001');
            $sheet->setCellValue('B2', 'Familia Principal');
            $sheet->setCellValue('C2', '');
            $sheet->setCellValue('D2', 'TRUE');
            $sheet->setCellValue('E2', 'TRUE');
            $sheet->setCellValue('F2', 'TRUE');
            $sheet->setCellValue('G2', 'etiqueta1, etiqueta2');

            $sheet->setCellValue('A3', 'FAM002');
            $sheet->setCellValue('B3', 'Subfamilia de FAM001');
            $sheet->setCellValue('C3', 'FAM001');
            $sheet->setCellValue('D3', 'TRUE');
            $sheet->setCellValue('E3', 'FALSE');
            $sheet->setCellValue('F3', 'TRUE');
            $sheet->setCellValue('G3', '');

            $sheet->getStyle('A2:G3')->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => '999999']]
            ]);

            // Instrucciones
            $sheet->setCellValue('A5', 'INSTRUCCIONES:');
            $sheet->setCellValue('A6', '- La columna "Codigo" es obligatoria y debe contener el código de la familia.');
            $sheet->setCellValue('A7', '- La columna "Madre" debe contener el código de la familia padre (vacío para familias raíz).');
            $sheet->setCellValue('A8', '- Los capítulos se reasignan automáticamente según el orden de las filas y la jerarquía.');
            $sheet->setCellValue('A9', '- Las etiquetas se separan por comas.');
            $sheet->setCellValue('A10', '- Valores válidos para En Catalogo/En Tarifa/Activa: TRUE, FALSE, SI, NO, 1, 0');
            $sheet->setCellValue('A11', '- Elimina las filas de ejemplo antes de importar.');
            $sheet->getStyle('A5')->getFont()->setBold(true);
            $sheet->getStyle('A6:A11')->getFont()->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF666666'));

            // Congelar primera fila
            $sheet->freezePane('A2');

            // Nombre del archivo
            $filename = 'plantilla_importacion_familias.xlsx';

            // Headers de descarga
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: max-age=0');

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');

        } catch (\Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
        }
    }

    /**
     * Importa familias desde un archivo Excel usando chunks (Resumable.js).
     */
    private function import_excel_chunk()
    {
        $this->template = FALSE;

        @ini_set('display_errors', 0);
        error_reporting(0);
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        header('Content-Type: application/json');

        try {
            require_once 'base/fs_chunked_upload.php';

            // Directorio temporal para el Excel
            $temp_dir = sys_get_temp_dir() . '/tarif_familias_import/';

            // Crear uploader con extensiones xlsx/xls y máximo 50MB
            $uploader = new \fs_chunked_upload($temp_dir, ['xlsx', 'xls'], 50);
            $result = $uploader->handle_chunk();

            // Si el archivo está completo, procesarlo
            if (!empty($result['complete']) && !empty($result['file_path'])) {
                // Obtener tarifa del request
                $codtarifa = isset($_REQUEST['codtarifa']) ? trim($_REQUEST['codtarifa']) : $this->codtarifa;

                if (empty($codtarifa)) {
                    @unlink($result['file_path']);
                    echo json_encode(['success' => false, 'error' => 'No hay tarifa seleccionada']);
                    return;
                }

                // Procesar el Excel
                $process_result = $this->process_excel_file($result['file_path'], $codtarifa);

                // Eliminar archivo temporal
                @unlink($result['file_path']);

                echo json_encode($process_result);
                return;
            }

            // Chunk recibido pero archivo incompleto
            echo json_encode($result);

        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
        }
    }

    /**
     * Procesa un archivo Excel de familias y actualiza la tarifa.
     * 
     * @param string $file_path Ruta al archivo Excel
     * @param string $codtarifa Código de tarifa
     * @return array Resultado con stats
     */
    private function process_excel_file($file_path, $codtarifa)
    {
        try {
            // Leer Excel
            $spreadsheet = IOFactory::load($file_path);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();
            $highestCol = $sheet->getHighestColumn();
            $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);

            if ($highestRow < 2) {
                return ['success' => false, 'error' => 'El archivo está vacío (no tiene datos después del encabezado)'];
            }

            // Mapear headers a columnas
            $header_map = [];
            $known_headers = [
                'codigo' => 'codigo',
                'codfamilia' => 'codigo',
                'descripcion' => 'descripcion',
                'nombre' => 'descripcion',
                'madre' => 'madre',
                'padre' => 'madre',
                'parent' => 'madre',
                'en catalogo' => 'en_catalogo',
                'encatalogo' => 'en_catalogo',
                'catalogo' => 'en_catalogo',
                'en tarifa' => 'en_tarifa',
                'entarifa' => 'en_tarifa',
                'activa' => 'activa',
                'activo' => 'activa',
                'active' => 'activa',
                'etiquetas' => 'etiquetas',
                'tags' => 'etiquetas',
                'labels' => 'etiquetas'
            ];

            for ($col = 1; $col <= $highestColIndex; $col++) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $header_value = trim($sheet->getCell($colLetter . '1')->getValue() ?? '');
                $header_lower = mb_strtolower($header_value);

                if (isset($known_headers[$header_lower])) {
                    $header_map[$colLetter] = $known_headers[$header_lower];
                }
            }

            // Verificar que tiene columna 'codigo'
            if (!in_array('codigo', $header_map)) {
                return ['success' => false, 'error' => 'No se ha encontrado la columna "Codigo" en el encabezado'];
            }

            // Cargar modelo de familia base
            require_once 'plugins/catalogo_core/model/core/familia.php';

            // Procesar filas - primera pasada: recopilar datos
            $familias_data = [];
            $orden_importacion = [];

            for ($row = 2; $row <= $highestRow; $row++) {
                $row_data = [];
                
                foreach ($header_map as $colLetter => $field_name) {
                    $value = $sheet->getCell($colLetter . $row)->getValue();
                    if ($value !== null) {
                        $row_data[$field_name] = trim((string) $value);
                    }
                }

                // Saltar filas sin código
                if (empty($row_data['codigo'])) {
                    continue;
                }

                $codfamilia = $row_data['codigo'];
                $familias_data[$codfamilia] = $row_data;
                $orden_importacion[] = $codfamilia;
            }

            if (empty($familias_data)) {
                return ['success' => false, 'error' => 'No se encontraron familias válidas en el archivo'];
            }

            // Procesar familias
            $stats = [
                'total' => count($familias_data),
                'actualizados' => 0,
                'creados' => 0,
                'errores' => 0,
                'sin_cambios' => 0,
                'detalles_errores' => []
            ];

            foreach ($familias_data as $codfamilia => $data) {
                $result = $this->process_familia_row($codtarifa, $codfamilia, $data, $stats);
            }

            // Recalcular capítulos basándose en el orden de importación y jerarquía
            $this->recalculate_all_capitulos($codtarifa, $orden_importacion, $familias_data);

            return ['success' => true, 'stats' => $stats];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Error al procesar Excel: ' . $e->getMessage()];
        }
    }

    /**
     * Procesa una fila de familia del Excel.
     * 
     * @param string $codtarifa
     * @param string $codfamilia
     * @param array $data
     * @param array &$stats
     * @return bool
     */
    private function process_familia_row($codtarifa, $codfamilia, $data, &$stats)
    {
        $updated = false;
        $created = false;

        // Verificar si la familia base existe
        $familia_base_model = new \FSFramework\model\familia();
        $familia_base = $familia_base_model->get($codfamilia);

        if (!$familia_base) {
            // Crear familia base si no existe
            $familia_base = new \FSFramework\model\familia();
            $familia_base->codfamilia = $codfamilia;
            $familia_base->descripcion = $data['descripcion'] ?? $codfamilia;
            
            if (!$familia_base->save()) {
                $stats['errores']++;
                if (count($stats['detalles_errores']) < 50) {
                    $stats['detalles_errores'][] = 'Error al crear familia base: ' . $codfamilia;
                }
                return false;
            }
            $created = true;
        } else if (isset($data['descripcion']) && $data['descripcion'] !== '') {
            // Actualizar descripción si cambió
            if ($familia_base->descripcion !== $data['descripcion']) {
                $familia_base->descripcion = $data['descripcion'];
                $familia_base->save();
                $updated = true;
            }
        }

        // Obtener o crear configuración en tarifa
        $fam_tarifa = $this->tarifa_familia->get($codtarifa, $codfamilia);
        
        if (!$fam_tarifa) {
            // Crear nueva entrada en tarifa
            $fam_tarifa = new tarif_tarifa_familia();
            $fam_tarifa->codtarifa = $codtarifa;
            $fam_tarifa->codfamilia = $codfamilia;
            $fam_tarifa->en_catalogo = true;
            $fam_tarifa->en_tarifa = false;
            $fam_tarifa->activa = true;
            $fam_tarifa->capitulo = '';
            $created = true;
        }

        // Actualizar madre (se usará para recalcular capítulos)
        if (isset($data['madre'])) {
            $nueva_madre = empty($data['madre']) ? null : $data['madre'];
            if ($fam_tarifa->madre !== $nueva_madre) {
                $fam_tarifa->madre = $nueva_madre;
                $updated = true;
            }
        }

        // Actualizar flags
        if (isset($data['en_catalogo'])) {
            $val = $this->parse_bool_value($data['en_catalogo']);
            if ($val !== null && $fam_tarifa->en_catalogo !== $val) {
                $fam_tarifa->en_catalogo = $val;
                $updated = true;
            }
        }

        if (isset($data['en_tarifa'])) {
            $val = $this->parse_bool_value($data['en_tarifa']);
            if ($val !== null && $fam_tarifa->en_tarifa !== $val) {
                $fam_tarifa->en_tarifa = $val;
                $updated = true;
            }
        }

        if (isset($data['activa'])) {
            $val = $this->parse_bool_value($data['activa']);
            if ($val !== null && $fam_tarifa->activa !== $val) {
                $fam_tarifa->activa = $val;
                $updated = true;
            }
        }

        // Guardar configuración de tarifa
        if (!$fam_tarifa->save()) {
            $stats['errores']++;
            if (count($stats['detalles_errores']) < 50) {
                $stats['detalles_errores'][] = 'Error al guardar familia en tarifa: ' . $codfamilia;
            }
            return false;
        }

        // Procesar etiquetas
        if (isset($data['etiquetas'])) {
            $etiquetas = array_filter(array_map('trim', preg_split('/[,;]+/', $data['etiquetas'])));
            $this->tarifa_etiqueta_familia->replace_etiquetas_familia($codtarifa, $codfamilia, $etiquetas);
            if (!empty($etiquetas)) {
                $updated = true;
            }
        }

        // Actualizar estadísticas
        if ($created) {
            $stats['creados']++;
        } else if ($updated) {
            $stats['actualizados']++;
        } else {
            $stats['sin_cambios']++;
        }

        return true;
    }

    /**
     * Parsea un valor booleano desde el Excel.
     * 
     * @param string $value
     * @return bool|null
     */
    private function parse_bool_value($value)
    {
        $value = strtolower(trim($value));
        
        if (in_array($value, ['true', 'si', 'sí', '1', 'yes', 'verdadero', 'v'])) {
            return true;
        }
        if (in_array($value, ['false', 'no', '0', 'falso', 'f'])) {
            return false;
        }
        
        return null;
    }

    /**
     * Recalcula todos los capítulos de las familias de una tarifa.
     * Usa el orden de importación del Excel y la jerarquía definida por el campo madre.
     * 
     * @param string $codtarifa Código de tarifa
     * @param array $orden_importacion Array de codfamilia en orden de aparición
     * @param array $familias_data Datos de familias con sus madres
     */
    private function recalculate_all_capitulos($codtarifa, $orden_importacion, $familias_data)
    {
        // Construir mapa de madre -> hijas en orden de importación
        $hijas_por_madre = [];
        
        foreach ($orden_importacion as $codfamilia) {
            $madre = isset($familias_data[$codfamilia]['madre']) ? $familias_data[$codfamilia]['madre'] : '';
            if (empty($madre)) {
                $madre = '__ROOT__';
            }
            
            if (!isset($hijas_por_madre[$madre])) {
                $hijas_por_madre[$madre] = [];
            }
            $hijas_por_madre[$madre][] = $codfamilia;
        }

        // Procesar familias raíz primero
        if (isset($hijas_por_madre['__ROOT__'])) {
            $chapter_num = 1;
            foreach ($hijas_por_madre['__ROOT__'] as $codfamilia) {
                $this->assign_capitulo_recursive($codtarifa, $codfamilia, (string) $chapter_num, $hijas_por_madre);
                $chapter_num++;
            }
        }

        // Procesar familias huérfanas (cuya madre no está en la importación)
        // Las tratamos como raíz
        $familias_procesadas = $this->get_all_processed_familias($hijas_por_madre);
        $chapter_num = isset($hijas_por_madre['__ROOT__']) ? count($hijas_por_madre['__ROOT__']) + 1 : 1;
        
        foreach ($orden_importacion as $codfamilia) {
            if (!in_array($codfamilia, $familias_procesadas)) {
                // Esta familia tiene una madre que no existe en la importación
                // La tratamos como raíz
                $this->assign_capitulo_recursive($codtarifa, $codfamilia, (string) $chapter_num, $hijas_por_madre);
                $chapter_num++;
            }
        }
    }

    /**
     * Obtiene todas las familias que serán procesadas a partir del árbol.
     * 
     * @param array $hijas_por_madre
     * @return array
     */
    private function get_all_processed_familias($hijas_por_madre)
    {
        $procesadas = [];
        
        if (isset($hijas_por_madre['__ROOT__'])) {
            foreach ($hijas_por_madre['__ROOT__'] as $codfamilia) {
                $this->collect_descendants($codfamilia, $hijas_por_madre, $procesadas);
            }
        }
        
        return $procesadas;
    }

    /**
     * Recopila una familia y todos sus descendientes.
     * 
     * @param string $codfamilia
     * @param array $hijas_por_madre
     * @param array &$procesadas
     */
    private function collect_descendants($codfamilia, $hijas_por_madre, &$procesadas)
    {
        $procesadas[] = $codfamilia;
        
        if (isset($hijas_por_madre[$codfamilia])) {
            foreach ($hijas_por_madre[$codfamilia] as $hija) {
                $this->collect_descendants($hija, $hijas_por_madre, $procesadas);
            }
        }
    }

    /**
     * Asigna capítulo a una familia y sus hijas recursivamente.
     * 
     * @param string $codtarifa
     * @param string $codfamilia
     * @param string $capitulo
     * @param array $hijas_por_madre
     */
    private function assign_capitulo_recursive($codtarifa, $codfamilia, $capitulo, $hijas_por_madre)
    {
        // Actualizar capítulo de esta familia
        $fam = $this->tarifa_familia->get($codtarifa, $codfamilia);
        if ($fam) {
            $fam->capitulo = $capitulo;
            $fam->save();
        }

        // Procesar hijas
        if (isset($hijas_por_madre[$codfamilia])) {
            $child_num = 1;
            foreach ($hijas_por_madre[$codfamilia] as $hija_codfamilia) {
                $child_capitulo = $capitulo . '.' . $child_num;
                $this->assign_capitulo_recursive($codtarifa, $hija_codfamilia, $child_capitulo, $hijas_por_madre);
                $child_num++;
            }
        }
    }
}
