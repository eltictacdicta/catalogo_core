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

require_once 'plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_familia.php';
require_once 'plugins/catalogo_core/model/tarif_familia.php';
require_once 'plugins/catalogo_core/model/tarif_opcional.php';
require_once 'plugins/catalogo_core/model/core/catalogo_articulo_opcional.php';
require_once 'plugins/catalogo_core/model/core/catalogo_opcional_familia.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_opcional_familia.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_opcional_resolver.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_etiqueta_familia.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_opcional_etiqueta.php';
require_once 'plugins/catalogo_core/model/tarif_opcional_precio.php';

use FSFramework\model\tarif_tarifa_familia;
use FSFramework\model\tarif_tarifa;
use FSFramework\model\articulo;
use FSFramework\model\tarif_tarifa_opcional_etiqueta;
use FSFramework\model\tarif_tarifa_etiqueta_familia;
use FSFramework\model\tarif_tarifa_opcional_familia;
use FSFramework\model\tarif_tarifa_opcional_resolver;
use FSFramework\model\tarif_familia;
use FSFramework\model\tarif_opcional;
use FSFramework\model\catalogo_opcional_familia;
use FSFramework\model\catalogo_articulo_opcional;

/**
 * Vista "Configurador de opcionales": muestra la jerarquía completa de
 * categorías, subcategorías, etiquetas, productos y sus opcionales
 * asociados dentro de una tarifa seleccionada.
 *
 * Los opcionales de cada nivel se muestran con estado calculado:
 * - "visible": no tiene asociaciones directas a hijos -> se muestra con acción de despliegue
 * - "seleccionable": al menos un hijo tiene asociación directa -> oculto en la vista
 */
class tarif_configurador_opcionales extends fbase_controller
{
    use TarifarioOpcionalStateTrait;

    /**
     * Stable tarifa-article relation tables (tarifario-owned data, no
     * tarifario PHP class dependency — design D4).
     */
    private const TARIFA_ARTICULO_TABLE = 'tarif_tarifa_articulo';
    private const TARIFA_ARTICULO_ETIQUETA_TABLE = 'tarif_tarifa_articulo_etiqueta';
    private const TARIFA_ARTICULO_PRECIO_TABLE = 'tarif_articulo_precios';

    /**
     * Actions that mutate persisted state. They must arrive as a POST carrying
     * a valid CSRF token before the matching ajax_* handler runs.
     */
    private const MUTATING_ACTIONS = [
        'push_to_products',
        'push_to_tags',
        'push_to_children',
        'push_all_down',
        'create_opcional',
        'edit_opcional',
        'delete_opcional',
        'add_opcional_familia',
        'add_opcional_etiqueta',
        'add_opcional_producto',
        'remove_opcional_familia',
        'remove_opcional_etiqueta',
        'remove_opcional_producto',
    ];

    /** @var tarif_tarifa_familia */
    public $tarifa_familia;

    /** @var tarif_tarifa */
    public $tarifa;

    /** @var array Familias raíz (primer nivel) de la tarifa */
    public $familias_raiz = [];

    /** @var string Familia raíz seleccionada */
    public $codfamilia_raiz = '';

    /** @var array Árbol completo para la familia seleccionada */
    public $tree = [];

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Configurador Opcionales', 'tarifario');
    }

    protected function private_core()
    {
        parent::private_core();
        $this->init_tarifario_opcional_state();

        $this->tarifa_familia = new tarif_tarifa_familia();
        $this->tarifa = new tarif_tarifa();

        $this->init_tarifa();

        $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

        // Mutating dispatcher actions require POST + a valid framework CSRF
        // token (pre_private_core() validates it; requireCsrf() enforces it
        // even in soft mode). Non-mutating actions (htmx_tree,
        // search_opcionales) keep working over GET.
        if (in_array($action, self::MUTATING_ACTIONS, true) && !$this->guard_mutating_action()) {
            return;
        }

        switch ($action) {
            case 'htmx_tree':
                $this->htmx_tree();
                return;
            case 'push_to_products':
                $this->ajax_push_to_products();
                return;
            case 'push_to_tags':
                $this->ajax_push_to_tags();
                return;
            case 'push_to_children':
                $this->ajax_push_to_children();
                return;
            case 'push_all_down':
                $this->ajax_push_all_down();
                return;
            case 'search_opcionales':
                $this->ajax_search_opcionales();
                return;
            case 'create_opcional':
                $this->ajax_create_opcional();
                return;
            case 'edit_opcional':
                $this->ajax_edit_opcional();
                return;
            case 'delete_opcional':
                $this->ajax_delete_opcional();
                return;
            case 'add_opcional_familia':
                $this->ajax_add_opcional_familia();
                return;
            case 'add_opcional_etiqueta':
                $this->ajax_add_opcional_etiqueta();
                return;
            case 'add_opcional_producto':
                $this->ajax_add_opcional_producto();
                return;
            case 'remove_opcional_familia':
                $this->ajax_remove_opcional_familia();
                return;
            case 'remove_opcional_etiqueta':
                $this->ajax_remove_opcional_etiqueta();
                return;
            case 'remove_opcional_producto':
                $this->ajax_remove_opcional_producto();
                return;
        }

        $this->load_familias_raiz();
    }

    /**
     * Allows a mutating action only when it is a POST with a valid CSRF token.
     * Emits a JSON 403 response otherwise and returns false.
     */
    private function guard_mutating_action(): bool
    {
        $request = $this->getRequest();
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->reject_mutating_action();
        }

        if (!$this->requireCsrf()) {
            return $this->reject_mutating_action();
        }

        return true;
    }

    private function reject_mutating_action(): bool
    {
        $this->template = false;
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Token de seguridad inválido o método no permitido.',
        ]);

        return false;
    }

    private function init_tarifa()
    {
        $this->codtarifa = isset($_REQUEST['codtarifa']) ? trim($_REQUEST['codtarifa']) : '';

        if (empty($this->codtarifa)) {
            $default = $this->tarifa->get_default();
            if ($default) {
                $this->codtarifa = $default->codtarifa;
            } elseif (!empty($this->tarifas)) {
                $this->codtarifa = $this->tarifas[0]->codtarifa;
            }
        }
    }

    /**
     * Carga las familias raíz (sin madre) de la tarifa actual.
     */
    private function load_familias_raiz()
    {
        if (empty($this->codtarifa)) {
            return;
        }

        $all = $this->tarifa_familia->all_activas_from_tarifa($this->codtarifa);
        foreach ($all as $fam) {
            if (empty($fam->madre)) {
                $this->familias_raiz[] = $fam;
            }
        }
    }

    // ==================== RAW-SQL FORK (design D4) ====================
    // The configurator moved to catalogo_core and must not load tarifario PHP
    // classes. These helpers read the stable tarifario-owned relation tables
    // with parameterized SQL and no-op safely when the tables are absent, so
    // standalone catalogo_core never fatals.

    /**
     * Artículos (referencias) de una familia dentro de una tarifa.
     *
     * @return array<int, object>
     */
    private function articulos_from_familia($codtarifa, $codfamilia): array
    {
        if (!$this->db->table_exists(self::TARIFA_ARTICULO_TABLE)) {
            return [];
        }

        $data = $this->db->select(
            'SELECT referencia FROM ' . self::TARIFA_ARTICULO_TABLE
            . ' WHERE codtarifa = ? AND codfamilia = ?'
            . ' ORDER BY orden ASC, referencia ASC;',
            [$codtarifa, $codfamilia]
        );

        $list = [];
        if ($data) {
            foreach ($data as $row) {
                $item = new \stdClass();
                $item->referencia = $row['referencia'];
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * Etiquetas asignadas a un artículo dentro de una tarifa.
     *
     * @return array<int, string>
     */
    private function etiquetas_articulo($codtarifa, $referencia): array
    {
        if (!$this->db->table_exists(self::TARIFA_ARTICULO_ETIQUETA_TABLE)) {
            return [];
        }

        $data = $this->db->select(
            'SELECT etiqueta FROM ' . self::TARIFA_ARTICULO_ETIQUETA_TABLE
            . ' WHERE codtarifa = ? AND referencia = ? ORDER BY etiqueta ASC;',
            [$codtarifa, $referencia]
        );

        $list = [];
        if ($data) {
            foreach ($data as $row) {
                $list[] = $row['etiqueta'];
            }
        }

        return $list;
    }

    /**
     * Precio del artículo en una tarifa; cae al PVP base cuando no hay precio
     * específico o la tabla no existe.
     */
    private function precio_articulo_tarifa($referencia, $codtarifa): float
    {
        if ($this->db->table_exists(self::TARIFA_ARTICULO_PRECIO_TABLE)) {
            $data = $this->db->select(
                'SELECT precio FROM ' . self::TARIFA_ARTICULO_PRECIO_TABLE
                . ' WHERE referencia = ? AND codtarifa = ? LIMIT 1;',
                [$referencia, $codtarifa]
            );
            if ($data) {
                return (float) $data[0]['precio'];
            }
        }

        $articulo = new articulo();
        $art = $articulo->get($referencia);

        return $art ? (float) $art->pvp : 0.0;
    }

    /**
     * HTMX endpoint: construye y renderiza el árbol completo de una familia raíz.
     */
    private function htmx_tree()
    {
        $codfamilia = isset($_REQUEST['codfamilia']) ? trim($_REQUEST['codfamilia']) : '';

        if (empty($codfamilia) || empty($this->codtarifa)) {
            $this->template = false;
            echo '<div class="alert alert-warning">Seleccione una tarifa y una categoría.</div>';
            return;
        }

        $this->codfamilia_raiz = $codfamilia;
        $all_familias = $this->tarifa_familia->all_activas_from_tarifa($this->codtarifa);
        $this->tree = $this->build_tree_node($codfamilia, $all_familias);

        $this->template = 'tarif_configurador_tree';
    }

    /**
     * Construye recursivamente el nodo de árbol para una familia.
     *
     * @param string $codfamilia
     * @param array  $all_familias Todas las familias de la tarifa (para buscar hijas)
     * @return object Nodo con: codfamilia, descripcion, capitulo, opcionales[],
     *                          grupos_etiqueta[], productos_sin_etiqueta[], hijas[]
     */
    private function build_tree_node($codfamilia, $all_familias)
    {
        $fam_config = $this->tarifa_familia->get($this->codtarifa, $codfamilia);

        $node = new \stdClass();
        $node->codfamilia = $codfamilia;
        $node->descripcion = $fam_config ? $fam_config->descripcion : $codfamilia;
        $node->capitulo = $fam_config ? $fam_config->capitulo : '';

        // 1. Opcionales activos de esta familia en la tarifa
        $rel_familia = new tarif_tarifa_opcional_familia();
        $opc_familia = $rel_familia->get_opcionales_activos($this->codtarifa, $codfamilia);

        // 2. Etiquetas y productos agrupados
        $familia_etiqueta = new tarif_tarifa_etiqueta_familia();
        $tiene_etiquetas = $familia_etiqueta->familia_tiene_etiquetas($this->codtarifa, $codfamilia);
        $node->tiene_etiquetas = $tiene_etiquetas;

        $articulos_config = $this->articulos_from_familia($this->codtarifa, $codfamilia);

        // 3. Sub-familias recursivas
        $node->hijas = [];
        $child_codfamilias = [];
        foreach ($all_familias as $fam) {
            if ($fam->madre === $codfamilia) {
                $child_codfamilias[] = $fam->codfamilia;
                $node->hijas[] = $this->build_tree_node($fam->codfamilia, $all_familias);
            }
        }

        // 4. Calcular estado visible/seleccionable de cada opcional de familia
        $refs_productos = array_map(fn($a) => $a->referencia, $articulos_config);
        $node->opcionales = $this->build_opcionales_list_with_state(
            $opc_familia, $codfamilia, $refs_productos, $child_codfamilias
        );

        // 5. Construir grupos de artículos (con estados de opcionales a nivel etiqueta)
        $this->build_grupos_articulos($node, $articulos_config, $codfamilia, $tiene_etiquetas, $opc_familia);

        return $node;
    }

    /**
     * Construye los grupos de artículos por etiqueta y sin etiqueta para un nodo.
     */
    private function build_grupos_articulos($node, $articulos_config, $codfamilia, $tiene_etiquetas, $opc_familia)
    {
        $articulo_model = new articulo();
        $opcional_etiqueta = new tarif_tarifa_opcional_etiqueta();
        $art_opc = new catalogo_articulo_opcional();

        $articulos_items = [];
        foreach ($articulos_config as $art_config) {
            $art = $articulo_model->get($art_config->referencia);
            if (!$art) {
                continue;
            }

            $item = new \stdClass();
            $item->referencia = $art->referencia;
            $item->descripcion = $art->get_descripcion_idioma($this->codidioma);
            $item->pvp = $this->precio_articulo_tarifa($art->referencia, $this->codtarifa);

            // Opcionales directos del artículo (solo los de catalogo_articulo_opcional)
            $opc_directos = $art_opc->get_opcionales_directos_from_articulo($art->referencia);
            $item->opcionales = $this->build_opcionales_list($opc_directos);

            if ($tiene_etiquetas) {
                $tags = $this->etiquetas_articulo($this->codtarifa, $art->referencia);
                sort($tags);
            } else {
                $tags = [];
            }
            $item->tags = $tags;
            $item->tag_key = implode('|', $tags);

            $articulos_items[] = $item;
        }

        // Agrupar artículos por tag_key
        $groups_map = [];
        foreach ($articulos_items as $art) {
            $key = $art->tag_key;
            if (!isset($groups_map[$key])) {
                $groups_map[$key] = [
                    'tag_key' => $key,
                    'tags' => $art->tags,
                    'label' => empty($art->tags) ? '' : implode(', ', $art->tags),
                    'articulos' => [],
                    'opcionales' => []
                ];
            }
            $groups_map[$key]['articulos'][] = $art;
        }

        // Asignar opcionales a cada grupo de etiqueta con estado visible/seleccionable
        if ($tiene_etiquetas) {
            $opcionales_items = [];
            foreach ($opc_familia as $opc) {
                $opc_item = new \stdClass();
                $opc_item->id = $opc->id;
                $opc_item->nombre = $opc->nombre;
                $opc_item->codigo = isset($opc->codigo) ? $opc->codigo : '';
                $opc_item->precio = $opc->precio_en_tarifa($this->codtarifa);
                $tags = $opcional_etiqueta->get_etiquetas_opcional($this->codtarifa, intval($opc->id), $codfamilia);
                sort($tags);
                $opc_item->tags = $tags;
                $opcionales_items[] = $opc_item;
            }

            $opcionales_sin_tags = [];
            $opcionales_con_tags = [];
            foreach ($opcionales_items as $opc) {
                if (empty($opc->tags)) {
                    $opcionales_sin_tags[] = $opc;
                } else {
                    $opcionales_con_tags[] = $opc;
                }
            }

            foreach ($groups_map as $key => &$group) {
                $opc_map = [];
                foreach ($opcionales_sin_tags as $opc_base) {
                    $opc_map[intval($opc_base->id)] = $opc_base;
                }
                if (!empty($group['tags'])) {
                    $group_tags_idx = array_flip($group['tags']);
                    foreach ($opcionales_con_tags as $opc) {
                        foreach ($opc->tags as $tag) {
                            if (isset($group_tags_idx[$tag])) {
                                $opc_map[intval($opc->id)] = $opc;
                                break;
                            }
                        }
                    }
                }

                // Calcular estado de cada opcional del grupo:
                // "visible" si ningún producto del grupo tiene asociación directa
                $group_refs = array_map(fn($a) => $a->referencia, $group['articulos']);
                $opc_list = [];
                foreach (array_values($opc_map) as $opc_item) {
                    $obj = new \stdClass();
                    $obj->id = $opc_item->id;
                    $obj->nombre = $opc_item->nombre;
                    $obj->codigo = isset($opc_item->codigo) ? $opc_item->codigo : '';
                    $obj->precio = isset($opc_item->precio) ? $opc_item->precio : 0;
                    $obj->estado = $this->compute_estado_producto($opc_item->id, $group_refs, $art_opc);
                    $opc_list[] = $obj;
                }
                $group['opcionales'] = $opc_list;
            }
            unset($group);
        }

        // Separar grupos con etiqueta de los sin etiqueta
        uksort($groups_map, function ($a, $b) {
            if ($a === '' && $b !== '') return 1;
            if ($a !== '' && $b === '') return -1;
            return strcmp($a, $b);
        });

        $node->productos_sin_etiqueta = [];
        $node->grupos_etiqueta = [];

        foreach ($groups_map as $key => $group) {
            if ($key === '') {
                $node->productos_sin_etiqueta = $group['articulos'];
            } else {
                $node->grupos_etiqueta[] = (object) $group;
            }
        }
    }

    /**
     * Convierte un array de objetos tarif_opcional en una lista simplificada (sin estado).
     */
    private function build_opcionales_list($opcionales)
    {
        $list = [];
        foreach ($opcionales as $opc) {
            $item = new \stdClass();
            $item->id = $opc->id;
            $item->nombre = $opc->nombre;
            $item->codigo = isset($opc->codigo) ? $opc->codigo : '';
            $item->precio = $opc->precio_en_tarifa($this->codtarifa);
            $list[] = $item;
        }
        return $list;
    }

    /**
     * Construye la lista de opcionales de familia con estado visible/seleccionable.
     *
     * Jerarquía: familia padre -> familia hija -> etiqueta -> producto.
     * Un opcional es "visible" si NO tiene asociación directa con ningún hijo
     * (subcategoría, etiqueta o producto). Si tiene al menos una, es "seleccionable".
     */
    private function build_opcionales_list_with_state($opcionales, $codfamilia, $refs_productos, $child_codfamilias)
    {
        $opc_etiq = new tarif_tarifa_opcional_etiqueta();
        $art_opc = new catalogo_articulo_opcional();
        $rel_familia = new tarif_tarifa_opcional_familia();

        $list = [];
        foreach ($opcionales as $opc) {
            $item = new \stdClass();
            $item->id = $opc->id;
            $item->nombre = $opc->nombre;
            $item->codigo = isset($opc->codigo) ? $opc->codigo : '';
            $item->precio = $opc->precio_en_tarifa($this->codtarifa);

            // 1. Verificar si alguna subcategoría hija tiene este opcional activo
            $has_child_family = false;
            foreach ($child_codfamilias as $child_cod) {
                if ($rel_familia->is_activo_en_tarifa($this->codtarifa, intval($opc->id), $child_cod)) {
                    $has_child_family = true;
                    break;
                }
            }

            // 2. Verificar si tiene asociaciones a etiquetas
            $has_tag_assoc = false;
            if (!$has_child_family) {
                $tags = $opc_etiq->get_etiquetas_opcional($this->codtarifa, intval($opc->id), $codfamilia);
                $has_tag_assoc = !empty($tags);
            }

            // 3. Verificar si tiene asociaciones directas a productos
            $has_product_assoc = false;
            if (!$has_child_family && !$has_tag_assoc) {
                $has_product_assoc = $this->compute_estado_producto($opc->id, $refs_productos, $art_opc) === 'seleccionable';
            }

            $item->estado = ($has_child_family || $has_tag_assoc || $has_product_assoc) ? 'seleccionable' : 'visible';
            $list[] = $item;
        }
        return $list;
    }

    /**
     * Calcula si un opcional tiene asociación directa con alguno de los productos dados.
     * @return string 'visible' o 'seleccionable'
     */
    private function compute_estado_producto($id_opcional, $refs, $art_opc)
    {
        foreach ($refs as $ref) {
            if ($art_opc->exists_relation($ref, intval($id_opcional))) {
                return 'seleccionable';
            }
        }
        return 'visible';
    }

    // ==================== AJAX ENDPOINTS ====================

    /**
     * Despliega un opcional de familia/etiqueta a todos los productos del nivel correspondiente.
     */
    private function ajax_push_to_products()
    {
        $this->template = false;
        header('Content-Type: application/json');

        $id_opcional = isset($_REQUEST['id_opcional']) ? intval($_REQUEST['id_opcional']) : 0;
        $codfamilia = isset($_REQUEST['codfamilia']) ? trim($_REQUEST['codfamilia']) : '';
        $etiqueta = isset($_REQUEST['etiqueta']) ? trim($_REQUEST['etiqueta']) : '';

        if (empty($id_opcional) || empty($codfamilia) || empty($this->codtarifa)) {
            echo json_encode(['success' => false, 'error' => 'Parámetros insuficientes']);
            return;
        }

        $art_opc = new catalogo_articulo_opcional();
        $articulos = $this->articulos_from_familia($this->codtarifa, $codfamilia);
        $count = 0;

        if (!empty($etiqueta)) {
            // Solo productos que coincidan con el grupo de etiquetas (tag_key pipe-separated)
            $required_tags = explode('|', $etiqueta);
            sort($required_tags);
            foreach ($articulos as $art) {
                $tags = $this->etiquetas_articulo($this->codtarifa, $art->referencia);
                sort($tags);
                if ($tags === $required_tags) {
                    if ($art_opc->add($art->referencia, $id_opcional)) {
                        $count++;
                    }
                }
            }
        } else {
            // Todos los productos de la familia
            foreach ($articulos as $art) {
                if ($art_opc->add($art->referencia, $id_opcional)) {
                    $count++;
                }
            }
        }

        echo json_encode(['success' => true, 'count' => $count]);
    }

    /**
     * Despliega un opcional de familia a todas las etiquetas de esa familia.
     */
    private function ajax_push_to_tags()
    {
        $this->template = false;
        header('Content-Type: application/json');

        $id_opcional = isset($_REQUEST['id_opcional']) ? intval($_REQUEST['id_opcional']) : 0;
        $codfamilia = isset($_REQUEST['codfamilia']) ? trim($_REQUEST['codfamilia']) : '';

        if (empty($id_opcional) || empty($codfamilia) || empty($this->codtarifa)) {
            echo json_encode(['success' => false, 'error' => 'Parámetros insuficientes']);
            return;
        }

        $etiq_familia = new tarif_tarifa_etiqueta_familia();
        $etiquetas = $etiq_familia->get_etiquetas_familia($this->codtarifa, $codfamilia);

        $opc_etiq = new tarif_tarifa_opcional_etiqueta();
        $count = 0;
        foreach ($etiquetas as $etiqueta) {
            if ($opc_etiq->add($this->codtarifa, $id_opcional, $codfamilia, $etiqueta)) {
                $count++;
            }
        }

        echo json_encode(['success' => true, 'count' => $count]);
    }

    /**
     * Despliega un opcional de familia padre a todas las subfamilias hijas.
     */
    private function ajax_push_to_children()
    {
        $this->template = false;
        header('Content-Type: application/json');

        $id_opcional = isset($_REQUEST['id_opcional']) ? intval($_REQUEST['id_opcional']) : 0;
        $codfamilia = isset($_REQUEST['codfamilia']) ? trim($_REQUEST['codfamilia']) : '';

        if (empty($id_opcional) || empty($codfamilia) || empty($this->codtarifa)) {
            echo json_encode(['success' => false, 'error' => 'Parámetros insuficientes']);
            return;
        }

        $all_familias = $this->tarifa_familia->all_activas_from_tarifa($this->codtarifa);
        $rel_familia = new tarif_tarifa_opcional_familia();
        $base_model = new catalogo_opcional_familia();
        $count = 0;

        foreach ($all_familias as $fam) {
            if ($fam->madre === $codfamilia) {
                // Asegurar que existe la relación base
                if (!$base_model->exists_relation($id_opcional, $fam->codfamilia)) {
                    $base_model->add($id_opcional, $fam->codfamilia);
                }
                $rel_familia->set_activo($this->codtarifa, $id_opcional, $fam->codfamilia, true);
                $count++;
            }
        }

        echo json_encode(['success' => true, 'count' => $count]);
    }

    /**
     * Despliega TODOS los opcionales visibles de una categoría o etiqueta
     * al nivel inmediatamente inferior de la jerarquía.
     */
    private function ajax_push_all_down()
    {
        $this->template = false;
        header('Content-Type: application/json');

        $codfamilia = isset($_REQUEST['codfamilia']) ? trim($_REQUEST['codfamilia']) : '';
        $target = isset($_REQUEST['target']) ? trim($_REQUEST['target']) : '';
        $etiqueta = isset($_REQUEST['etiqueta']) ? trim($_REQUEST['etiqueta']) : '';

        if (empty($codfamilia) || empty($this->codtarifa) || empty($target)) {
            echo json_encode(['success' => false, 'error' => 'Parámetros insuficientes']);
            return;
        }

        $total = 0;

        switch ($target) {
            case 'children':
                $total = $this->batch_push_to_children($codfamilia);
                break;
            case 'tags':
                $total = $this->batch_push_to_tags($codfamilia);
                break;
            case 'products':
                $total = $this->batch_push_to_products($codfamilia, $etiqueta);
                break;
            default:
                echo json_encode(['success' => false, 'error' => 'Target no válido']);
                return;
        }

        echo json_encode(['success' => true, 'count' => $total]);
    }

    /**
     * Obtiene los IDs de opcionales visibles (sin distribuir) de una familia.
     */
    private function get_visible_opcional_ids(string $codfamilia): array
    {
        $rel_familia = new tarif_tarifa_opcional_familia();
        $opc_familia = $rel_familia->get_opcionales_activos($this->codtarifa, $codfamilia);

        $all_familias = $this->tarifa_familia->all_activas_from_tarifa($this->codtarifa);
        $child_codfamilias = [];
        foreach ($all_familias as $fam) {
            if ($fam->madre === $codfamilia) {
                $child_codfamilias[] = $fam->codfamilia;
            }
        }

        $articulos_config = $this->articulos_from_familia($this->codtarifa, $codfamilia);
        $refs_productos = array_map(fn($a) => $a->referencia, $articulos_config);

        $opcionales_with_state = $this->build_opcionales_list_with_state(
            $opc_familia, $codfamilia, $refs_productos, $child_codfamilias
        );

        $ids = [];
        foreach ($opcionales_with_state as $opc) {
            if ($opc->estado === 'visible') {
                $ids[] = intval($opc->id);
            }
        }
        return $ids;
    }

    /**
     * Obtiene los IDs de opcionales visibles a nivel de etiqueta (no asociados a productos del grupo).
     */
    private function get_visible_opcional_ids_for_tag(string $codfamilia, string $etiqueta): array
    {
        $rel_familia = new tarif_tarifa_opcional_familia();
        $opc_familia = $rel_familia->get_opcionales_activos($this->codtarifa, $codfamilia);
        $opcional_etiqueta = new tarif_tarifa_opcional_etiqueta();
        $art_opc = new catalogo_articulo_opcional();

        $required_tags = explode('|', $etiqueta);
        sort($required_tags);

        $articulos_config = $this->articulos_from_familia($this->codtarifa, $codfamilia);
        $group_refs = [];
        foreach ($articulos_config as $art) {
            $tags = $this->etiquetas_articulo($this->codtarifa, $art->referencia);
            sort($tags);
            if ($tags === $required_tags) {
                $group_refs[] = $art->referencia;
            }
        }

        $ids = [];
        foreach ($opc_familia as $opc) {
            $opc_tags = $opcional_etiqueta->get_etiquetas_opcional($this->codtarifa, intval($opc->id), $codfamilia);
            sort($opc_tags);

            $applies = false;
            if (empty($opc_tags)) {
                $applies = true;
            } else {
                foreach ($opc_tags as $tag) {
                    if (in_array($tag, $required_tags)) {
                        $applies = true;
                        break;
                    }
                }
            }

            if ($applies && $this->compute_estado_producto($opc->id, $group_refs, $art_opc) === 'visible') {
                $ids[] = intval($opc->id);
            }
        }
        return $ids;
    }

    private function batch_push_to_children(string $codfamilia): int
    {
        $visible_ids = $this->get_visible_opcional_ids($codfamilia);
        if (empty($visible_ids)) {
            return 0;
        }

        $all_familias = $this->tarifa_familia->all_activas_from_tarifa($this->codtarifa);
        $rel_familia = new tarif_tarifa_opcional_familia();
        $base_model = new catalogo_opcional_familia();
        $count = 0;

        foreach ($visible_ids as $id_opcional) {
            foreach ($all_familias as $fam) {
                if ($fam->madre === $codfamilia) {
                    if (!$base_model->exists_relation($id_opcional, $fam->codfamilia)) {
                        $base_model->add($id_opcional, $fam->codfamilia);
                    }
                    $rel_familia->set_activo($this->codtarifa, $id_opcional, $fam->codfamilia, true);
                    $count++;
                }
            }
        }
        return $count;
    }

    private function batch_push_to_tags(string $codfamilia): int
    {
        $visible_ids = $this->get_visible_opcional_ids($codfamilia);
        if (empty($visible_ids)) {
            return 0;
        }

        $etiq_familia = new tarif_tarifa_etiqueta_familia();
        $etiquetas = $etiq_familia->get_etiquetas_familia($this->codtarifa, $codfamilia);
        $opc_etiq = new tarif_tarifa_opcional_etiqueta();
        $count = 0;

        foreach ($visible_ids as $id_opcional) {
            foreach ($etiquetas as $etiq) {
                if ($opc_etiq->add($this->codtarifa, $id_opcional, $codfamilia, $etiq)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function batch_push_to_products(string $codfamilia, string $etiqueta): int
    {
        if (!empty($etiqueta)) {
            $visible_ids = $this->get_visible_opcional_ids_for_tag($codfamilia, $etiqueta);
        } else {
            $visible_ids = $this->get_visible_opcional_ids($codfamilia);
        }

        if (empty($visible_ids)) {
            return 0;
        }

        $art_opc = new catalogo_articulo_opcional();
        $articulos = $this->articulos_from_familia($this->codtarifa, $codfamilia);
        $count = 0;

        if (!empty($etiqueta)) {
            $required_tags = explode('|', $etiqueta);
            sort($required_tags);

            // Article tags do not depend on the opcional: resolve the matching
            // references once instead of re-querying them per opcional.
            $matching_refs = [];
            foreach ($articulos as $art) {
                $tags = $this->etiquetas_articulo($this->codtarifa, $art->referencia);
                sort($tags);
                if ($tags === $required_tags) {
                    $matching_refs[] = $art->referencia;
                }
            }

            foreach ($visible_ids as $id_opcional) {
                foreach ($matching_refs as $referencia) {
                    if ($art_opc->add($referencia, $id_opcional)) {
                        $count++;
                    }
                }
            }
        } else {
            foreach ($visible_ids as $id_opcional) {
                foreach ($articulos as $art) {
                    if ($art_opc->add($art->referencia, $id_opcional)) {
                        $count++;
                    }
                }
            }
        }
        return $count;
    }

    /**
     * Formatea un precio para mostrar.
     */
    public function format_precio($precio)
    {
        return number_format($precio ?? 0, 2, ',', '.') . ' €';
    }

    // ==================== CRUD OPCIONALES ====================

    /**
     * Busca opcionales existentes para autocompletado.
     */
    private function ajax_search_opcionales()
    {
        $this->template = false;
        header('Content-Type: application/json');

        $id_opcional = isset($_REQUEST['id_opcional']) ? intval($_REQUEST['id_opcional']) : 0;
        $query = isset($_REQUEST['q']) ? trim($_REQUEST['q']) : '';
        $exclude_familia = isset($_REQUEST['exclude_familia']) ? trim($_REQUEST['exclude_familia']) : '';

        $opc_model = new tarif_opcional();

        if ($id_opcional > 0) {
            $opc = $opc_model->get($id_opcional);
            $results = $opc ? [$opc] : [];
        } else {
            $results = $opc_model->search($query, 0, '', $this->codtarifa);
        }

        if (!empty($exclude_familia) && $id_opcional <= 0) {
            $rel_familia = new tarif_tarifa_opcional_familia();
            $ids_activos = $rel_familia->get_ids_opcionales_activos($this->codtarifa, $exclude_familia);
            $results = array_filter($results, fn($o) => !in_array(intval($o->id), $ids_activos));
        }

        $json = [];
        foreach ($results as $opc) {
            $json[] = [
                'id' => intval($opc->id),
                'codigo' => $opc->codigo,
                'nombre' => $opc->nombre,
                'descripcion' => $opc->descripcion ?? '',
                'precio' => floatval($opc->precio),
                'precio_tarifa' => floatval($opc->precio_en_tarifa($this->codtarifa)),
                'codigo2' => $opc->codigo2 ?? '',
            ];
        }

        echo json_encode(['success' => true, 'results' => $json]);
    }

    /**
     * Crea un nuevo opcional y opcionalmente lo asocia a una entidad.
     */
    private function ajax_create_opcional()
    {
        $this->template = false;
        header('Content-Type: application/json');

        $nombre = isset($_REQUEST['nombre']) ? trim($_REQUEST['nombre']) : '';
        $codigo = isset($_REQUEST['codigo']) ? trim($_REQUEST['codigo']) : '';
        $precio = isset($_REQUEST['precio']) ? floatval($_REQUEST['precio']) : 0;
        $descripcion = isset($_REQUEST['descripcion']) ? trim($_REQUEST['descripcion']) : '';
        $codigo2 = isset($_REQUEST['codigo2']) ? trim($_REQUEST['codigo2']) : '';

        if (empty($nombre)) {
            echo json_encode(['success' => false, 'error' => 'El nombre es obligatorio']);
            return;
        }

        $opc = new tarif_opcional();
        if (empty($codigo)) {
            $codigo = $opc->get_new_codigo();
        }

        $opc->codigo = $codigo;
        $opc->nombre = $nombre;
        $opc->descripcion = $descripcion;
        $opc->precio = $precio;
        $opc->codigo2 = $codigo2;
        $opc->activo = true;
        $opc->en_catalogo = false;
        $opc->en_tarifa = false;

        if (!$opc->save()) {
            $errors = $opc->get_errors();
            echo json_encode(['success' => false, 'error' => implode(', ', $errors) ?: 'Error al guardar']);
            return;
        }

        if ($precio > 0 && !empty($this->codtarifa)) {
            $opc->set_precio_tarifa($this->codtarifa, $precio);
        }

        $assoc = $this->process_asociacion($opc->id);

        echo json_encode([
            'success' => true,
            'id' => intval($opc->id),
            'codigo' => $opc->codigo,
            'nombre' => $opc->nombre,
            'asociacion' => $assoc,
        ]);
    }

    /**
     * Edita un opcional existente.
     */
    private function ajax_edit_opcional()
    {
        $this->template = false;
        header('Content-Type: application/json');

        $id = isset($_REQUEST['id_opcional']) ? intval($_REQUEST['id_opcional']) : 0;
        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'ID de opcional no proporcionado']);
            return;
        }

        $opc = (new tarif_opcional())->get($id);
        if (!$opc) {
            echo json_encode(['success' => false, 'error' => 'Opcional no encontrado']);
            return;
        }

        if (isset($_REQUEST['nombre']) && trim($_REQUEST['nombre']) !== '') {
            $opc->nombre = trim($_REQUEST['nombre']);
        }
        if (isset($_REQUEST['codigo'])) {
            $opc->codigo = trim($_REQUEST['codigo']);
        }
        if (isset($_REQUEST['descripcion'])) {
            $opc->descripcion = trim($_REQUEST['descripcion']);
        }
        if (isset($_REQUEST['codigo2'])) {
            $opc->codigo2 = trim($_REQUEST['codigo2']);
        }
        if (isset($_REQUEST['precio'])) {
            $opc->precio = floatval($_REQUEST['precio']);
        }

        if (!$opc->save()) {
            $errors = $opc->get_errors();
            echo json_encode(['success' => false, 'error' => implode(', ', $errors) ?: 'Error al guardar']);
            return;
        }

        if (isset($_REQUEST['precio_tarifa']) && !empty($this->codtarifa)) {
            $opc->set_precio_tarifa($this->codtarifa, floatval($_REQUEST['precio_tarifa']));
        }

        echo json_encode(['success' => true, 'id' => intval($opc->id), 'nombre' => $opc->nombre]);
    }

    /**
     * Elimina un opcional (y todas sus asociaciones por CASCADE).
     */
    private function ajax_delete_opcional()
    {
        $this->template = false;
        header('Content-Type: application/json');

        $id = isset($_REQUEST['id_opcional']) ? intval($_REQUEST['id_opcional']) : 0;
        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'ID de opcional no proporcionado']);
            return;
        }

        $opc = (new tarif_opcional())->get($id);
        if (!$opc) {
            echo json_encode(['success' => false, 'error' => 'Opcional no encontrado']);
            return;
        }

        if ($opc->delete()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Error al eliminar']);
        }
    }

    /**
     * Asocia un opcional existente a una familia para la tarifa actual.
     */
    private function ajax_add_opcional_familia()
    {
        $this->template = false;
        header('Content-Type: application/json');

        $id_opcional = isset($_REQUEST['id_opcional']) ? intval($_REQUEST['id_opcional']) : 0;
        $codfamilia = isset($_REQUEST['codfamilia']) ? trim($_REQUEST['codfamilia']) : '';

        if (empty($id_opcional) || empty($codfamilia) || empty($this->codtarifa)) {
            echo json_encode(['success' => false, 'error' => 'Parámetros insuficientes']);
            return;
        }

        $base_model = new catalogo_opcional_familia();
        if (!$base_model->exists_relation($id_opcional, $codfamilia)) {
            $base_model->add($id_opcional, $codfamilia);
        }

        $rel_familia = new tarif_tarifa_opcional_familia();
        $rel_familia->set_activo($this->codtarifa, $id_opcional, $codfamilia, true);

        echo json_encode(['success' => true]);
    }

    /**
     * Asocia un opcional a una etiqueta dentro de una familia para la tarifa actual.
     */
    private function ajax_add_opcional_etiqueta()
    {
        $this->template = false;
        header('Content-Type: application/json');

        $id_opcional = isset($_REQUEST['id_opcional']) ? intval($_REQUEST['id_opcional']) : 0;
        $codfamilia = isset($_REQUEST['codfamilia']) ? trim($_REQUEST['codfamilia']) : '';
        $etiqueta = isset($_REQUEST['etiqueta']) ? trim($_REQUEST['etiqueta']) : '';

        if (empty($id_opcional) || empty($codfamilia) || empty($etiqueta) || empty($this->codtarifa)) {
            echo json_encode(['success' => false, 'error' => 'Parámetros insuficientes']);
            return;
        }

        $base_model = new catalogo_opcional_familia();
        if (!$base_model->exists_relation($id_opcional, $codfamilia)) {
            $base_model->add($id_opcional, $codfamilia);
        }
        $rel_familia = new tarif_tarifa_opcional_familia();
        if (!$rel_familia->is_activo_en_tarifa($this->codtarifa, $id_opcional, $codfamilia)) {
            $rel_familia->set_activo($this->codtarifa, $id_opcional, $codfamilia, true);
        }

        $tags = explode('|', $etiqueta);
        $opc_etiq = new tarif_tarifa_opcional_etiqueta();
        $count = 0;
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if (!empty($tag) && $opc_etiq->add($this->codtarifa, $id_opcional, $codfamilia, $tag)) {
                $count++;
            }
        }

        echo json_encode(['success' => true, 'count' => $count]);
    }

    /**
     * Asocia un opcional directamente a un producto.
     */
    private function ajax_add_opcional_producto()
    {
        $this->template = false;
        header('Content-Type: application/json');

        $id_opcional = isset($_REQUEST['id_opcional']) ? intval($_REQUEST['id_opcional']) : 0;
        $referencia = isset($_REQUEST['referencia']) ? trim($_REQUEST['referencia']) : '';

        if (empty($id_opcional) || empty($referencia)) {
            echo json_encode(['success' => false, 'error' => 'Parámetros insuficientes']);
            return;
        }

        $art_opc = new catalogo_articulo_opcional();
        if ($art_opc->add($referencia, $id_opcional)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Error al asociar']);
        }
    }

    /**
     * Elimina la asociación de un opcional con una familia en la tarifa actual.
     */
    private function ajax_remove_opcional_familia()
    {
        $this->template = false;
        header('Content-Type: application/json');

        $id_opcional = isset($_REQUEST['id_opcional']) ? intval($_REQUEST['id_opcional']) : 0;
        $codfamilia = isset($_REQUEST['codfamilia']) ? trim($_REQUEST['codfamilia']) : '';

        if (empty($id_opcional) || empty($codfamilia) || empty($this->codtarifa)) {
            echo json_encode(['success' => false, 'error' => 'Parámetros insuficientes']);
            return;
        }

        $rel_familia = new tarif_tarifa_opcional_familia();
        $rel_familia->set_activo($this->codtarifa, $id_opcional, $codfamilia, false);

        $opc_etiq = new tarif_tarifa_opcional_etiqueta();
        $opc_etiq->delete_all_from_opcional_familia_tarifa($this->codtarifa, $id_opcional, $codfamilia);

        echo json_encode(['success' => true]);
    }

    /**
     * Elimina la asociación de un opcional con una etiqueta en la tarifa actual.
     */
    private function ajax_remove_opcional_etiqueta()
    {
        $this->template = false;
        header('Content-Type: application/json');

        $id_opcional = isset($_REQUEST['id_opcional']) ? intval($_REQUEST['id_opcional']) : 0;
        $codfamilia = isset($_REQUEST['codfamilia']) ? trim($_REQUEST['codfamilia']) : '';
        $etiqueta = isset($_REQUEST['etiqueta']) ? trim($_REQUEST['etiqueta']) : '';

        if (empty($id_opcional) || empty($codfamilia) || empty($this->codtarifa)) {
            echo json_encode(['success' => false, 'error' => 'Parámetros insuficientes']);
            return;
        }

        $opc_etiq = new tarif_tarifa_opcional_etiqueta();
        if (!empty($etiqueta)) {
            $tags = explode('|', $etiqueta);
            foreach ($tags as $tag) {
                $tag = trim($tag);
                if (!empty($tag)) {
                    $opc_etiq->remove($this->codtarifa, $id_opcional, $codfamilia, $tag);
                }
            }
        } else {
            $opc_etiq->delete_all_from_opcional_familia_tarifa($this->codtarifa, $id_opcional, $codfamilia);
        }

        echo json_encode(['success' => true]);
    }

    /**
     * Elimina la asociación de un opcional con un producto.
     */
    private function ajax_remove_opcional_producto()
    {
        $this->template = false;
        header('Content-Type: application/json');

        $id_opcional = isset($_REQUEST['id_opcional']) ? intval($_REQUEST['id_opcional']) : 0;
        $referencia = isset($_REQUEST['referencia']) ? trim($_REQUEST['referencia']) : '';

        if (empty($id_opcional) || empty($referencia)) {
            echo json_encode(['success' => false, 'error' => 'Parámetros insuficientes']);
            return;
        }

        $art_opc = new catalogo_articulo_opcional();
        if ($art_opc->remove($referencia, $id_opcional)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Error al eliminar asociación']);
        }
    }

    /**
     * Procesa la asociación opcional al crear uno nuevo (familia, etiqueta o producto).
     */
    private function process_asociacion(int $id_opcional): array
    {
        $result = ['tipo' => '', 'entidad' => ''];

        $assoc_type = isset($_REQUEST['assoc_type']) ? trim($_REQUEST['assoc_type']) : '';
        if (empty($assoc_type)) {
            return $result;
        }

        $result['tipo'] = $assoc_type;

        switch ($assoc_type) {
            case 'familia':
                $codfamilia = isset($_REQUEST['assoc_codfamilia']) ? trim($_REQUEST['assoc_codfamilia']) : '';
                if (!empty($codfamilia) && !empty($this->codtarifa)) {
                    $base_model = new catalogo_opcional_familia();
                    if (!$base_model->exists_relation($id_opcional, $codfamilia)) {
                        $base_model->add($id_opcional, $codfamilia);
                    }
                    $rel_familia = new tarif_tarifa_opcional_familia();
                    $rel_familia->set_activo($this->codtarifa, $id_opcional, $codfamilia, true);
                    $result['entidad'] = $codfamilia;
                }
                break;

            case 'etiqueta':
                $codfamilia = isset($_REQUEST['assoc_codfamilia']) ? trim($_REQUEST['assoc_codfamilia']) : '';
                $etiqueta = isset($_REQUEST['assoc_etiqueta']) ? trim($_REQUEST['assoc_etiqueta']) : '';
                if (!empty($codfamilia) && !empty($etiqueta) && !empty($this->codtarifa)) {
                    $base_model = new catalogo_opcional_familia();
                    if (!$base_model->exists_relation($id_opcional, $codfamilia)) {
                        $base_model->add($id_opcional, $codfamilia);
                    }
                    $rel_familia = new tarif_tarifa_opcional_familia();
                    if (!$rel_familia->is_activo_en_tarifa($this->codtarifa, $id_opcional, $codfamilia)) {
                        $rel_familia->set_activo($this->codtarifa, $id_opcional, $codfamilia, true);
                    }
                    $tags = explode('|', $etiqueta);
                    $opc_etiq = new tarif_tarifa_opcional_etiqueta();
                    foreach ($tags as $tag) {
                        $tag = trim($tag);
                        if (!empty($tag)) {
                            $opc_etiq->add($this->codtarifa, $id_opcional, $codfamilia, $tag);
                        }
                    }
                    $result['entidad'] = $codfamilia . '|' . $etiqueta;
                }
                break;

            case 'producto':
                $referencia = isset($_REQUEST['assoc_referencia']) ? trim($_REQUEST['assoc_referencia']) : '';
                if (!empty($referencia)) {
                    $art_opc = new catalogo_articulo_opcional();
                    $art_opc->add($referencia, $id_opcional);
                    $result['entidad'] = $referencia;
                }
                break;
        }

        return $result;
    }
}
