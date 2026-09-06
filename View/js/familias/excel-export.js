/**
 * Módulo de exportación Excel para familias
 * @module familias/excel-export
 */

import { getConfig } from './utils.js';

/**
 * Clase para gestionar la exportación de familias a Excel
 */
export class FamiliasExcelExporter {
    constructor() {
        this.config = getConfig();
        this.bindGlobalFunctions();
    }
    
    /**
     * Expone funciones globales para compatibilidad con onclick en HTML
     */
    bindGlobalFunctions() {
        window.familiasExcelExport = {
            exportarExcel: () => this.exportarExcel()
        };
    }
    
    /**
     * Ejecuta la exportación a Excel
     */
    exportarExcel() {
        const url = `${this.config.baseUrl}&codtarifa=${encodeURIComponent(this.config.codtarifa)}&action=export_excel`;
        
        $('#modal_exportar_excel_familias').modal('hide');
        window.location.href = url;
    }
}
