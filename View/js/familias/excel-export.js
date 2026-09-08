/**
 * Módulo de exportación Excel para familias — vanilla DOM (no jQuery).
 * Modal visibility is owned by the Alpine component (familiaExportModal),
 * which calls exportarExcel() and closes itself.
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
     * Ejecuta la exportación a Excel (el cierre del modal lo maneja Alpine)
     */
    exportarExcel() {
        const url = `${this.config.baseUrl}&codtarifa=${encodeURIComponent(this.config.codtarifa)}&action=export_excel`;
        window.location.href = url;
    }
}
