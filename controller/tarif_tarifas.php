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
require_once 'plugins/catalogo_core/model/tarif_tarifa_articulo.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_articulo_etiqueta.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_etiqueta_familia.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_opcional_familia.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_articulo_opcional.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_opcional_etiqueta.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_opcional.php';

use FSFramework\model\tarif_tarifa;
use FSFramework\model\tarif_tarifa_familia;
use FSFramework\model\tarif_tarifa_articulo;
use FSFramework\model\tarif_articulo_precio;
use FSFramework\model\tarif_opcional_precio;
use FSFramework\model\tarif_tarifa_articulo_etiqueta;
use FSFramework\model\tarif_tarifa_etiqueta_familia;
use FSFramework\model\tarif_tarifa_opcional_familia;
use FSFramework\model\tarif_tarifa_articulo_opcional;
use FSFramework\model\tarif_tarifa_opcional_etiqueta;
use FSFramework\model\tarif_tarifa_opcional;

/**
 * Controlador para gestionar las tarifas del tarifario.
 *
 * Owned by catalogo_core after the tarifas page migration. The groups/RBAC
 * surface belongs to the optional tarifario plugin and is consumed through the
 * loadTarifarioModel() soft seam; every group path fails closed when tarifario
 * is inactive.
 */
class tarif_tarifas extends fbase_controller
{
    public $tarifa;
    public $resultados;
    public $tarifa_familia;
    public $tarifa_articulo;

    /**
     * TRUE when the optional tarifario group/RBAC models resolved.
     * @var bool
     */
    public $tarifario_activo = false;

    /**
     * Grupos de roles disponibles para asignar a tarifas.
     * @var array
     */
    public $grupos_disponibles = [];

    /**
     * Modelo de grupo-tarifa para gestionar asignaciones, o NULL si el
     * plugin tarifario no está activo.
     * @var object|null
     */
    public $grupo_tarifa_model;

    /**
     * Modelo de grupo-rol para consultas, o NULL si el plugin tarifario
     * no está activo.
     * @var object|null
     */
    public $grupo_rol_model;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Tarifas', 'tarifario');
    }

    protected function private_core()
    {
        parent::private_core();

        $this->tarifa = new tarif_tarifa();
        $this->tarifa_familia = new tarif_tarifa_familia();
        $this->tarifa_articulo = new tarif_tarifa_articulo();

        $this->grupo_tarifa_model = $this->loadTarifarioModel('FSFramework\\model\\tarif_grupo_tarifa');
        $this->grupo_rol_model = $this->loadTarifarioModel('FSFramework\\model\\tarif_grupo_rol');
        $this->tarifario_activo = ($this->grupo_tarifa_model !== null && $this->grupo_rol_model !== null);

        // Cargar grupos disponibles
        $this->grupos_disponibles = $this->grupo_rol_model ? $this->grupo_rol_model->all() : [];

        // AJAX: Obtener grupos asignados a una tarifa
        if (isset($_REQUEST['ajax_grupos_tarifa'])) {
            $this->ajax_grupos_tarifa($_REQUEST['ajax_grupos_tarifa']);
            return;
        }

        // Procesar acciones. Toda mutación se despacha exclusivamente por POST
        // y con CSRF válido, de modo que no pueda ejecutarse desde una query
        // string manipulada.
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'guardar_tarifa':
                    if ($this->requireCsrf()) {
                        $this->guardar_tarifa();
                    }
                    break;
                case 'asignar_grupo':
                    if ($this->requireCsrf()) {
                        $this->asignar_grupo();
                    }
                    break;
                case 'delete':
                    if ($this->requireCsrf()) {
                        $this->delete_tarifa();
                    }
                    break;
                case 'set_default':
                    if ($this->requireCsrf()) {
                        $this->set_default();
                    }
                    break;
                case 'copy_structure':
                    if ($this->requireCsrf()) {
                        $this->copy_structure();
                    }
                    break;
            }
        }

        // Cargar tarifas
        $this->resultados = $this->tarifa->all();
    }

    /**
     * Resolves a tarifario model through a soft seam.
     *
     * The groups/RBAC tables belong to the optional tarifario plugin, so
     * catalogo_core must not hard-require them. Returns NULL when the class is
     * absent, letting every group path fail closed.
     *
     * @param string $fqcn fully qualified class name
     * @return object|null
     */
    protected function loadTarifarioModel(string $fqcn): ?object
    {
        if (!class_exists($fqcn)) {
            return null;
        }

        $model = new $fqcn();

        return is_object($model) ? $model : null;
    }

    private function guardar_tarifa()
    {
        $codtarifa = isset($_POST['codtarifa']) ? trim($_POST['codtarifa']) : '';
        $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
        $is_new = false;
        $heredar_de = isset($_POST['heredar_de']) ? trim($_POST['heredar_de']) : '';
        $codgrupo = isset($_POST['codgrupo']) ? trim($_POST['codgrupo']) : '';

        if (empty($nombre)) {
            $this->new_error_msg('El nombre de la tarifa es obligatorio.');
            return;
        }

        // Si el código está vacío, generar uno nuevo
        if ($codtarifa == '') {
            $codtarifa = $this->tarifa->get_new_codigo();
            $is_new = true;
        }

        // Verificar si es edición o creación
        $tarifa = $this->tarifa->get($codtarifa);
        if ($tarifa) {
            // Edición
            $is_new = false;
        } else {
            // Creación
            $tarifa = new tarif_tarifa();
            $tarifa->codtarifa = $codtarifa;
            $is_new = true;
        }

        $tarifa->nombre = $nombre;
        $tarifa->activa = isset($_POST['activa']);
        $tarifa->por_defecto = isset($_POST['por_defecto']);

        // Validar coddivisa (per-tariff currency). El modelo también
        // valida en test(), pero hacerlo en el controller garantiza un
        // abort temprano antes de generar SQL y un mensaje de error
        // explícito al usuario vía new_error_msg. La normalización
        // strtoupper/trim evita que 'usd' o ' usd ' se rechacen
        // incorrectamente por case-sensitivity o espacios.
        $coddivisa = isset($_POST['coddivisa']) ? strtoupper(trim((string) $_POST['coddivisa'])) : '';
        if (!in_array($coddivisa, tarif_tarifa::ALLOWED_CURRENCIES, true)) {
            $this->new_error_msg('Moneda no válida. Use EUR, MXN o USD.');
            return;
        }
        $tarifa->coddivisa = $coddivisa;

        if ($tarifa->save()) {
            if ($is_new) {
                // Si es nueva y se quiere heredar de otra
                if (!empty($heredar_de)) {
                    $this->heredar_estructura($heredar_de, $codtarifa);
                    $this->new_message('Tarifa <b>' . $tarifa->nombre . '</b> creada con estructura heredada de <b>' . $heredar_de . '</b>.');
                } else {
                    $this->new_message('Tarifa <b>' . $tarifa->nombre . '</b> creada correctamente (vacía).');
                }

                // Si se seleccionó un grupo, asignar la tarifa al grupo
                if (!empty($codgrupo) && $this->tarifario_activo) {
                    $this->grupo_tarifa_model->asignar($codgrupo, $codtarifa, $this->user->nick);
                    $grupo = $this->grupo_rol_model->get($codgrupo);
                    $nombre_grupo = $grupo ? $grupo->nombre : $codgrupo;
                    $this->new_message('Tarifa asignada al grupo <b>' . $nombre_grupo . '</b>.');
                }
            } else {
                $this->new_message('Tarifa <b>' . $tarifa->nombre . '</b> guardada correctamente.');
            }
        } else {
            $this->new_error_msg('Error al guardar la tarifa.');
        }
    }

    /**
     * Hereda la estructura completa de una tarifa a otra.
     * Copia: familias, artículos, precios, grupos, etiquetas,
     * relaciones opcional-familia, artículo-opcional, estados "En SAP", etc.
     *
     * Decisión de producto (Q4=A en design §11 de tarifario-tarifa-coddivisa):
     * la moneda del destino se respeta; los precios copiados heredan el valor
     * numérico tal cual, sin conversión de divisa. La re-evaluación de precios
     * en la nueva moneda es responsabilidad del operador.
     *
     * @param string $origen
     * @param string $destino
     */
    private function heredar_estructura($origen, $destino)
    {
        // 1. Copiar familias
        $this->tarifa_familia->copy_from_tarifa($origen, $destino);

        // 2. Copiar artículos (incluye en_tarifa, en_catalogo, codfamilia, orden)
        $this->tarifa_articulo->copy_from_tarifa($origen, $destino);

        // 3. Copiar precios de artículos (incluye activo, en_tarifa, en_catalogo, en_sap)
        $this->copy_precios_articulos($origen, $destino);

        // 4. Copiar precios de opcionales
        $this->copy_precios_opcionales($origen, $destino);

        // 5. Copiar asignaciones de grupos (los mismos grupos con acceso a la tarifa origen)
        $this->copy_grupos($origen, $destino);

        // 6. Copiar etiquetas de artículos por tarifa
        $this->copy_etiquetas_articulos($origen, $destino);

        // 7. Copiar etiquetas de familias por tarifa
        $this->copy_etiquetas_familias($origen, $destino);

        // 8. Copiar relaciones opcional-familia por tarifa
        $this->copy_opcional_familias($origen, $destino);

        // 9. Copiar relaciones artículo-opcional por tarifa
        $this->copy_articulo_opcionales($origen, $destino);

        // 10. Copiar etiquetas de opcionales por tarifa
        $this->copy_opcional_etiquetas($origen, $destino);

        // 11. Copiar el estado master opcional por tarifa
        $this->copy_tarifa_opcionales($origen, $destino);
    }

    /**
     * Copia los precios de artículos de una tarifa a otra.
     * @param string $origen
     * @param string $destino
     */
    private function copy_precios_articulos($origen, $destino)
    {
        $precio = new tarif_articulo_precio();
        // Primero eliminar precios existentes en destino
        $this->db->exec("DELETE FROM tarif_articulo_precios WHERE codtarifa = " . $precio->var2str($destino) . ";");

        // Copiar de origen a destino (incluye todos los campos: activo, en_tarifa, en_catalogo, en_sap)
        $sql = "INSERT INTO tarif_articulo_precios (referencia, codtarifa, precio, activo, en_tarifa, en_catalogo, en_sap) "
            . "SELECT referencia, " . $precio->var2str($destino) . ", precio, activo, en_tarifa, en_catalogo, en_sap "
            . "FROM tarif_articulo_precios WHERE codtarifa = " . $precio->var2str($origen) . ";";
        $this->db->exec($sql);
    }

    /**
     * Copia los precios de opcionales de una tarifa a otra.
     * @param string $origen
     * @param string $destino
     */
    private function copy_precios_opcionales($origen, $destino)
    {
        $precio = new tarif_opcional_precio();
        // Primero eliminar precios existentes en destino
        $this->db->exec("DELETE FROM catalogo_opcional_precios WHERE codlista = " . $precio->var2str($destino) . ";");

        // Copiar de origen a destino (incluye porcentaje y en_catalogo por lista)
        $sql = "INSERT INTO catalogo_opcional_precios (id_opcional, codlista, precio, porcentaje, en_catalogo) "
            . "SELECT id_opcional, " . $precio->var2str($destino) . ", precio, porcentaje, en_catalogo "
            . "FROM catalogo_opcional_precios WHERE codlista = " . $precio->var2str($origen) . ";";
        $this->db->exec($sql);
    }

    /**
     * Copia las asignaciones de grupos de una tarifa a otra.
     * No-op cuando el plugin tarifario no está activo.
     * @param string $origen
     * @param string $destino
     */
    private function copy_grupos($origen, $destino)
    {
        if (!$this->grupo_tarifa_model) {
            return;
        }

        $asignados = $this->grupo_tarifa_model->all_from_tarifa($origen);
        foreach ($asignados as $gt) {
            if (!$this->grupo_tarifa_model->exists_asignacion($gt->codgrupo, $destino)) {
                $this->grupo_tarifa_model->asignar($gt->codgrupo, $destino, $this->user->nick);
            }
        }
    }

    /**
     * Copia las etiquetas de artículos de una tarifa a otra.
     * @param string $origen
     * @param string $destino
     */
    private function copy_etiquetas_articulos($origen, $destino)
    {
        $model = new tarif_tarifa_articulo_etiqueta();
        // Eliminar etiquetas existentes en destino
        $this->db->exec("DELETE FROM tarif_tarifa_articulo_etiqueta WHERE codtarifa = " . $model->var2str($destino) . ";");

        // Copiar de origen a destino
        $sql = "INSERT INTO tarif_tarifa_articulo_etiqueta (codtarifa, referencia, etiqueta) "
            . "SELECT " . $model->var2str($destino) . ", referencia, etiqueta "
            . "FROM tarif_tarifa_articulo_etiqueta WHERE codtarifa = " . $model->var2str($origen) . ";";
        $this->db->exec($sql);
    }

    /**
     * Copia las etiquetas de familias de una tarifa a otra.
     * @param string $origen
     * @param string $destino
     */
    private function copy_etiquetas_familias($origen, $destino)
    {
        $model = new tarif_tarifa_etiqueta_familia();
        // Eliminar etiquetas existentes en destino
        $this->db->exec("DELETE FROM tarif_tarifa_etiqueta_familia WHERE codtarifa = " . $model->var2str($destino) . ";");

        // Copiar de origen a destino (incluye visibilidad)
        $sql = "INSERT INTO tarif_tarifa_etiqueta_familia (codtarifa, codfamilia, etiqueta, visible) "
            . "SELECT " . $model->var2str($destino) . ", codfamilia, etiqueta, visible "
            . "FROM tarif_tarifa_etiqueta_familia WHERE codtarifa = " . $model->var2str($origen) . ";";
        $this->db->exec($sql);
    }

    /**
     * Copia las relaciones opcional-familia por tarifa de una tarifa a otra.
     * @param string $origen
     * @param string $destino
     */
    private function copy_opcional_familias($origen, $destino)
    {
        $model = new tarif_tarifa_opcional_familia();
        $model->copy_from_tarifa($origen, $destino);
    }

    /**
     * Copia las relaciones artículo-opcional por tarifa de una tarifa a otra.
     * @param string $origen
     * @param string $destino
     */
    private function copy_articulo_opcionales($origen, $destino)
    {
        $model = new tarif_tarifa_articulo_opcional();
        $model->copy_from_tarifa($origen, $destino);
    }

    /**
     * Copia las etiquetas de opcionales por tarifa de una tarifa a otra.
     * @param string $origen
     * @param string $destino
     */
    private function copy_opcional_etiquetas($origen, $destino)
    {
        $model = new tarif_tarifa_opcional_etiqueta();
        // Eliminar etiquetas existentes en destino
        $this->db->exec("DELETE FROM tarif_tarifa_opcional_etiqueta WHERE codtarifa = " . $model->var2str($destino) . ";");

        // Copiar de origen a destino
        $sql = "INSERT INTO tarif_tarifa_opcional_etiqueta (codtarifa, id_opcional, codfamilia, etiqueta) "
            . "SELECT " . $model->var2str($destino) . ", id_opcional, codfamilia, etiqueta "
            . "FROM tarif_tarifa_opcional_etiqueta WHERE codtarifa = " . $model->var2str($origen) . ";";
        $this->db->exec($sql);
    }

    /**
     * Master per-(tarifa, opcional) state. Overridable seam so the copy step
     * is unit-testable without a live database.
     *
     * @return tarif_tarifa_opcional
     */
    protected function opcional_master_state()
    {
        return new tarif_tarifa_opcional();
    }

    /**
     * Copies the per-tarifa opcional master state (activa, en_catalogo,
     * en_tarifa and orden) from one tarifa to another. The master is the
     * authoritative source consumed by the matrices and the export.
     *
     * @param string $origen
     * @param string $destino
     */
    private function copy_tarifa_opcionales($origen, $destino)
    {
        $this->opcional_master_state()->copy_from_tarifa($origen, $destino);
    }

    /**
     * Copia la estructura completa de una tarifa a otra existente.
     *
     * Decisión de producto (Q4=A en design §11 de tarifario-tarifa-coddivisa):
     * la moneda del destino se respeta; los precios copiados heredan el valor
     * numérico tal cual, sin conversión de divisa. La re-evaluación de precios
     * en la nueva moneda es responsabilidad del operador.
     */
    private function copy_structure()
    {
        $origen = isset($_POST['copy_from']) ? trim((string) $_POST['copy_from']) : '';
        $destino = isset($_POST['copy_to']) ? trim((string) $_POST['copy_to']) : '';

        if ($origen === $destino) {
            $this->new_error_msg('La tarifa de origen y la de destino deben ser distintas.');
            return;
        }

        $tarifa_origen = $this->tarifa->get($origen);
        $tarifa_destino = $this->tarifa->get($destino);

        if (!$tarifa_origen) {
            $this->new_error_msg('Tarifa origen no encontrada.');
            return;
        }

        if (!$tarifa_destino) {
            $this->new_error_msg('Tarifa destino no encontrada.');
            return;
        }

        $this->heredar_estructura($origen, $destino);
        $this->new_message('Estructura copiada de <b>' . $tarifa_origen->nombre . '</b> a <b>' . $tarifa_destino->nombre . '</b>.');
    }

    private function delete_tarifa()
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar.');
            return;
        }

        $tarifa = $this->tarifa->get(isset($_POST['delete']) ? $_POST['delete'] : '');
        if ($tarifa) {
            if ($tarifa->por_defecto) {
                $this->new_error_msg('No se puede eliminar la tarifa por defecto.');
            } else if ($tarifa->delete()) {
                $this->new_message('Tarifa <b>' . $tarifa->nombre . '</b> eliminada correctamente.');
            } else {
                $this->new_error_msg('Error al eliminar la tarifa.');
            }
        } else {
            $this->new_error_msg('Tarifa no encontrada.');
        }
    }

    private function set_default()
    {
        $tarifa = $this->tarifa->get(isset($_POST['set_default']) ? $_POST['set_default'] : '');
        if ($tarifa) {
            $tarifa->por_defecto = TRUE;
            if ($tarifa->save()) {
                $this->new_message('Tarifa <b>' . $tarifa->nombre . '</b> establecida como por defecto.');
            } else {
                $this->new_error_msg('Error al establecer la tarifa por defecto.');
            }
        } else {
            $this->new_error_msg('Tarifa no encontrada.');
        }
    }

    /**
     * Cuenta cuántos artículos tienen precio en una tarifa.
     * @param string $codtarifa
     * @return int
     */
    public function count_articulos_tarifa($codtarifa)
    {
        $precio = new tarif_articulo_precio();
        return count($precio->all_from_tarifa($codtarifa));
    }

    /**
     * Cuenta cuántos opcionales tienen precio en una tarifa.
     * @param string $codtarifa
     * @return int
     */
    public function count_opcionales_tarifa($codtarifa)
    {
        $precio = new tarif_opcional_precio();
        return count($precio->all_from_tarifa($codtarifa));
    }

    /**
     * Cuenta las familias de una tarifa.
     * @param string $codtarifa
     * @return int
     */
    public function count_familias_tarifa($codtarifa)
    {
        return $this->tarifa_familia->count_from_tarifa($codtarifa);
    }

    /**
     * Cuenta los artículos en una tarifa (de tarif_tarifa_articulo).
     * @param string $codtarifa
     * @return int
     */
    public function count_articulos_en_tarifa($codtarifa)
    {
        return $this->tarifa_articulo->count_from_tarifa($codtarifa);
    }

    /**
     * Cuenta los artículos en tarifa (exportables) de una tarifa.
     * @param string $codtarifa
     * @return int
     */
    public function count_articulos_exportables($codtarifa)
    {
        return $this->tarifa_articulo->count_en_tarifa($codtarifa);
    }

    /**
     * Cuenta los artículos en catálogo de una tarifa.
     * @param string $codtarifa
     * @return int
     */
    public function count_articulos_catalogo($codtarifa)
    {
        return $this->tarifa_articulo->count_en_catalogo($codtarifa);
    }

    /**
     * Cuenta los usuarios con rol en una tarifa.
     * Devuelve 0 cuando el plugin tarifario no está activo.
     * @param string $codtarifa
     * @return int
     */
    public function count_usuarios_tarifa($codtarifa)
    {
        $rol_model = $this->loadTarifarioModel('FSFramework\\model\\tarif_tarifa_rol');

        return $rol_model ? $rol_model->count_usuarios($codtarifa) : 0;
    }

    /**
     * Devuelve los grupos que tienen asignada una tarifa.
     * Devuelve una lista vacía cuando el plugin tarifario no está activo.
     * @param string $codtarifa
     * @return array
     */
    public function get_grupos_tarifa($codtarifa)
    {
        return $this->grupo_rol_model ? $this->grupo_rol_model->get_grupos_tarifa($codtarifa) : [];
    }

    /**
     * Responde con JSON los códigos de grupos asignados a una tarifa.
     * @param string $codtarifa
     */
    private function ajax_grupos_tarifa($codtarifa)
    {
        $this->template = FALSE;
        $codtarifa = trim($codtarifa);
        $grupos = [];

        if (!empty($codtarifa) && $this->grupo_tarifa_model) {
            $asignados = $this->grupo_tarifa_model->all_from_tarifa($codtarifa);
            foreach ($asignados as $gt) {
                $grupos[] = $gt->codgrupo;
            }
        }

        header('Content-Type: application/json');
        echo json_encode(['grupos' => $grupos]);
    }

    /**
     * Asigna/desasigna grupos a una tarifa.
     * Reemplaza todas las asignaciones de grupos para la tarifa dada.
     * Falla de forma controlada cuando el plugin tarifario no está activo.
     */
    private function asignar_grupo()
    {
        if (!$this->grupo_tarifa_model || !$this->grupo_rol_model) {
            $this->new_error_msg('La gestión de grupos no está disponible: el plugin tarifario no está activo.');
            return;
        }

        $codtarifa = isset($_POST['codtarifa']) ? trim($_POST['codtarifa']) : '';
        $grupos_seleccionados = isset($_POST['grupos']) ? $_POST['grupos'] : [];

        if (empty($codtarifa)) {
            $this->new_error_msg('Tarifa no especificada.');
            return;
        }

        $tarifa = $this->tarifa->get($codtarifa);
        if (!$tarifa) {
            $this->new_error_msg('Tarifa no encontrada.');
            return;
        }

        // Obtener grupos actualmente asignados
        $asignados_actual = $this->grupo_tarifa_model->all_from_tarifa($codtarifa);
        $codgrupos_actual = [];
        foreach ($asignados_actual as $gt) {
            $codgrupos_actual[] = $gt->codgrupo;
        }

        // Desasignar los que ya no están seleccionados
        foreach ($codgrupos_actual as $codgrupo) {
            if (!in_array($codgrupo, $grupos_seleccionados)) {
                $this->grupo_tarifa_model->desasignar($codgrupo, $codtarifa);
            }
        }

        // Asignar los nuevos
        foreach ($grupos_seleccionados as $codgrupo) {
            if (!in_array($codgrupo, $codgrupos_actual)) {
                $this->grupo_tarifa_model->asignar($codgrupo, $codtarifa, $this->user->nick);
            }
        }

        $count = count($grupos_seleccionados);
        if ($count > 0) {
            $this->new_message('Tarifa <b>' . $tarifa->nombre . '</b> asignada a ' . $count . ' grupo(s).');
        } else {
            $this->new_message('Se han eliminado todas las asignaciones de grupo para la tarifa <b>' . $tarifa->nombre . '</b>.');
        }
    }
}
