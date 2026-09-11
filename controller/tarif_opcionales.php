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
require_once 'plugins/catalogo_core/model/tarif_familia.php';
require_once 'plugins/catalogo_core/model/tarif_opcional_precio.php';

use FSFramework\model\tarif_familia;
use FSFramework\model\tarif_opcional;
use FSFramework\model\tarif_tarifa;
use FSFramework\model\tarif_opcional_precio;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * Controlador para listar opcionales del tarifario.
 */
class tarif_opcionales extends fbase_controller
{
    use TarifarioOpcionalStateTrait;

    public $b_codfamilia;
    public $b_codtarifa;
    public $b_solo_activos;
    public $b_url;
    public $familia;
    public $offset;
    public $resultados;
    public $total_resultados;
    public $tarifa_seleccionada;
    
    /**
     * Cache de precios de opcionales para la tarifa seleccionada.
     * @var array
     */
    private $precios_cache = [];

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Opcionales', 'tarifario');
    }

    protected function private_core()
    {
        parent::private_core();
        $this->init_tarifario_opcional_state();

        $this->familia = new tarif_familia();
        $opcional = new tarif_opcional();

        // Procesar acciones
        $action = isset($_GET['action']) ? $_GET['action'] : '';
        
        if ($action == 'export_excel_opcionales') {
            $this->ini_filters();
            $this->export_excel_opcionales();
            return;
        } else if (isset($_POST['codigo']) && isset($_POST['nombre'])) {
            $this->new_opcional($opcional);
        } else if (isset($_GET['delete'])) {
            $this->delete_opcional($opcional);
        }

        $this->ini_filters();
        $this->search_opcionales();
        $this->load_precios_cache();
    }

    private function ini_filters()
    {
        $this->offset = 0;
        if (isset($_REQUEST['offset'])) {
            $this->offset = intval($_REQUEST['offset']);
        }

        $this->b_codfamilia = '';
        if (isset($_REQUEST['b_codfamilia'])) {
            $this->b_codfamilia = $_REQUEST['b_codfamilia'];
        }

        // Filtro de tarifa
        $this->b_codtarifa = '';
        $tarifa_model = new tarif_tarifa();
        
        if (isset($_REQUEST['b_codtarifa']) && $_REQUEST['b_codtarifa'] != '') {
            $this->b_codtarifa = $_REQUEST['b_codtarifa'];
            $this->tarifa_seleccionada = $tarifa_model->get($this->b_codtarifa);
        }
        
        // Si no hay tarifa seleccionada, usar la por defecto
        if (!$this->tarifa_seleccionada) {
            $this->tarifa_seleccionada = $tarifa_model->get_default();
            if ($this->tarifa_seleccionada) {
                $this->b_codtarifa = $this->tarifa_seleccionada->codtarifa;
            }
        }
        
        // Si aún no hay tarifa, usar la primera disponible
        if (!$this->tarifa_seleccionada && count($this->tarifas) > 0) {
            $this->tarifa_seleccionada = $this->tarifas[0];
            $this->b_codtarifa = $this->tarifa_seleccionada->codtarifa;
        }

        // Filtro de solo activos (en la tarifa seleccionada)
        $this->b_solo_activos = FALSE;
        if (isset($_REQUEST['b_solo_activos'])) {
            $this->b_solo_activos = ($_REQUEST['b_solo_activos'] == 'TRUE');
        }

        $this->b_url = $this->url() . "&query=" . $this->query
            . "&b_codfamilia=" . $this->b_codfamilia
            . "&b_codtarifa=" . $this->b_codtarifa
            . "&b_solo_activos=" . ($this->b_solo_activos ? 'TRUE' : 'FALSE');
    }

    private function search_opcionales()
    {
        $opcional = new tarif_opcional();

        $this->resultados = $opcional->search($this->query, $this->offset, $this->b_codfamilia, $this->b_codtarifa, $this->b_solo_activos);

        // Dedicated filtered count: paginate by the real total, not by the
        // number of rows returned in the current page.
        $this->total_resultados = $opcional->count_filtered(
            $this->query,
            $this->b_codfamilia,
            $this->b_codtarifa,
            $this->b_solo_activos
        );
    }

    /**
     * Carga los precios de los opcionales en cache para la tarifa seleccionada.
     */
    private function load_precios_cache()
    {
        $this->precios_cache = [];
        
        if (!$this->tarifa_seleccionada) {
            return;
        }

        $precio_model = new tarif_opcional_precio();
        
        foreach ($this->resultados as $opcional) {
            $precio = $precio_model->get($opcional->id, $this->tarifa_seleccionada->codtarifa);
            if ($precio) {
                $this->precios_cache[$opcional->id] = [
                    'precio' => $precio->precio,
                    'en_catalogo' => $precio->en_catalogo,
                    'activo' => true
                ];
            } else {
                $this->precios_cache[$opcional->id] = [
                    'precio' => null,
                    'en_catalogo' => false,
                    'activo' => false
                ];
            }
        }
    }

    /**
     * Obtiene el precio de un opcional en la tarifa seleccionada.
     * @param int $id_opcional
     * @return float|null
     */
    public function get_precio_opcional_tarifa($id_opcional)
    {
        if (isset($this->precios_cache[$id_opcional])) {
            return $this->precios_cache[$id_opcional]['precio'];
        }
        return null;
    }

    /**
     * Verifica si un opcional está activo en la tarifa seleccionada.
     * Un opcional está activo si tiene registro de precio en la tarifa.
     * @param int $id_opcional
     * @return bool
     */
    public function opcional_activo_en_tarifa($id_opcional)
    {
        if (isset($this->precios_cache[$id_opcional])) {
            return $this->precios_cache[$id_opcional]['activo'];
        }
        return false;
    }

    /**
     * Verifica si un opcional está en el catálogo de la tarifa seleccionada.
     * @param int $id_opcional
     * @return bool
     */
    public function opcional_en_catalogo_tarifa($id_opcional)
    {
        if (isset($this->precios_cache[$id_opcional])) {
            return $this->precios_cache[$id_opcional]['en_catalogo'];
        }
        return false;
    }

    private function new_opcional(&$opcional)
    {
        $codigo = $_POST['codigo'];
        if ($codigo == '') {
            $codigo = $opcional->get_new_codigo();
        }

        $opc0 = $opcional->get_by_codigo($codigo);
        if ($opc0) {
            $this->new_error_msg('Ya existe un opcional con el código <a href="' . $opc0->url() . '">' . $opc0->codigo . '</a>');
            return;
        }

        $opcional->codigo = $codigo;
        $opcional->nombre = $_POST['nombre'];
        $opcional->descripcion = isset($_POST['descripcion']) ? $_POST['descripcion'] : '';
        $opcional->ref_sap = isset($_POST['ref_sap']) ? trim($_POST['ref_sap']) : null;
        if ($opcional->ref_sap === '') {
            $opcional->ref_sap = null;
        }
        $opcional->precio = 0;

        if ($opcional->save()) {
            // Guardar familias seleccionadas
            if (isset($_POST['familias']) && is_array($_POST['familias'])) {
                foreach ($_POST['familias'] as $codfamilia) {
                    $opcional->add_familia($codfamilia);
                }
            }

            // Guardar precios por tarifa
            foreach ($this->tarifas as $tarifa) {
                $key = 'precio_tarifa_' . $tarifa->codtarifa;
                if (isset($_POST[$key]) && $_POST[$key] !== '') {
                    $precio = floatval(str_replace(',', '.', $_POST[$key]));
                    $opcional->set_precio_tarifa($tarifa->codtarifa, $precio);
                }
            }

            $this->new_message('Opcional <a href="' . $opcional->url() . '">' . $opcional->codigo . '</a> creado correctamente.');
        } else {
            $this->new_error_msg("¡Error al crear el opcional!");
        }
    }

    private function delete_opcional(&$opcional)
    {
        $opc = $opcional->get($_GET['delete']);
        if ($opc) {
            if (!$this->allow_delete) {
                $this->new_error_msg('No tienes permiso para eliminar en esta página.');
            } else if ($opc->delete()) {
                $this->new_message("Opcional " . $opc->codigo . " eliminado correctamente.", TRUE);
            } else {
                $this->new_error_msg("¡Error al eliminar el opcional!");
            }
        } else {
            $this->new_error_msg("Opcional no encontrado.");
        }
    }

    public function paginas()
    {
        return $this->tarif_paginas($this->b_url, $this->total_resultados, $this->offset);
    }

    /**
     * Exporta los opcionales a Excel.
     * Columnas: Codigo (No editar), Ref SAP:, Descripción, Precio, Familia, Subfamilia
     */
    private function export_excel_opcionales()
    {
        $this->template = FALSE;

        @ini_set('display_errors', 0);
        error_reporting(0);
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Opcionales');

            // Headers
            $headers = ['Codigo (No editar)', 'Ref SAP:', 'Descripción', 'Precio', 'Familia', 'Subfamilia'];
            foreach ($headers as $col => $header) {
                $cell = chr(65 + $col) . '1';
                $sheet->setCellValue($cell, $header);
            }

            // Estilo de headers
            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4']
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN]
                ]
            ];
            $sheet->getStyle('A1:F1')->applyFromArray($headerStyle);

            // Anchos de columna
            $sheet->getColumnDimension('A')->setWidth(20);  // Codigo
            $sheet->getColumnDimension('B')->setWidth(20);  // Ref SAP
            $sheet->getColumnDimension('C')->setWidth(50);  // Descripción
            $sheet->getColumnDimension('D')->setWidth(12);  // Precio
            $sheet->getColumnDimension('E')->setWidth(25);  // Familia
            $sheet->getColumnDimension('F')->setWidth(25);  // Subfamilia

            // Obtener todas las familias para construir el mapa de jerarquía
            $familia_model = new tarif_familia();
            $todas_familias = $familia_model->all();
            $familias_map = [];
            foreach ($todas_familias as $fam) {
                $familias_map[$fam->codfamilia] = $fam;
            }

            // Función para obtener familia y subfamilia separadas
            $getFamilyInfo = function($codfamilia) use ($familias_map) {
                if (empty($codfamilia) || !isset($familias_map[$codfamilia])) {
                    return ['familia' => '', 'subfamilia' => ''];
                }
                
                $current = $familias_map[$codfamilia];
                $subfamilia = $current->descripcion;
                $familia = '';
                
                // Si tiene madre, buscar la familia raíz
                if (!empty($current->madre) && isset($familias_map[$current->madre])) {
                    $parent = $familias_map[$current->madre];
                    while (!empty($parent->madre) && isset($familias_map[$parent->madre])) {
                        $parent = $familias_map[$parent->madre];
                    }
                    $familia = $parent->descripcion;
                } else {
                    // Es una familia raíz, no tiene subfamilia
                    $familia = $subfamilia;
                    $subfamilia = '';
                }
                
                return ['familia' => $familia, 'subfamilia' => $subfamilia];
            };

            // Obtener todos los opcionales
            $opcional_model = new tarif_opcional();
            $opcionales = $opcional_model->all(0, 99999);

            $row = 2;
            foreach ($opcionales as $opc) {
                // Obtener familias del opcional
                $familias_opcional = $opc->get_familias();
                
                // Obtener precio en la tarifa seleccionada
                $precio = 0;
                if ($this->tarifa_seleccionada) {
                    $precio_tarifa = $opc->get_precio_tarifa($this->tarifa_seleccionada->codtarifa);
                    if ($precio_tarifa) {
                        $precio = $precio_tarifa->precio;
                    }
                }

                if (count($familias_opcional) > 0) {
                    // Exportar una fila por cada familia asociada
                    foreach ($familias_opcional as $fam) {
                        $familyInfo = $getFamilyInfo($fam->codfamilia);
                        
                        $sheet->setCellValue('A' . $row, $opc->codigo);
                        $sheet->setCellValue('B' . $row, $opc->ref_sap ?? '');
                        $sheet->setCellValue('C' . $row, $opc->nombre);
                        $sheet->setCellValue('D' . $row, $precio);
                        $sheet->setCellValue('E' . $row, $familyInfo['familia']);
                        $sheet->setCellValue('F' . $row, $familyInfo['subfamilia']);

                        // Formato del precio
                        $sheet->getStyle('D' . $row)->getNumberFormat()
                            ->setFormatCode('#,##0.00');

                        $row++;
                    }
                } else {
                    // Opcional sin familias - exportar una fila sin familia
                    $sheet->setCellValue('A' . $row, $opc->codigo);
                    $sheet->setCellValue('B' . $row, $opc->ref_sap ?? '');
                    $sheet->setCellValue('C' . $row, $opc->nombre);
                    $sheet->setCellValue('D' . $row, $precio);
                    $sheet->setCellValue('E' . $row, '');
                    $sheet->setCellValue('F' . $row, '');

                    // Formato del precio
                    $sheet->getStyle('D' . $row)->getNumberFormat()
                        ->setFormatCode('#,##0.00');

                    $row++;
                }
            }

            // Estilo de datos
            if ($row > 2) {
                $dataRange = 'A2:F' . ($row - 1);
                $sheet->getStyle($dataRange)->applyFromArray([
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN]
                    ]
                ]);
            }

            // Congelar primera fila
            $sheet->freezePane('A2');

            // Nombre del archivo
            $tarifa_nombre = $this->tarifa_seleccionada ? $this->tarifa_seleccionada->nombre : 'sin_tarifa';
            $tarifa_nombre_safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $tarifa_nombre);
            $filename = 'opcionales_' . $tarifa_nombre_safe . '_' . date('Y-m-d') . '.xlsx';

            // Headers para descarga
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            exit();

        } catch (\Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
        }
    }
}
