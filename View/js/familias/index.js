/**
 * Módulo principal para familias
 * Inicializa todos los submódulos de Excel
 * @module familias/index
 */

import { FamiliasExcelExporter } from './excel-export.js';
import { FamiliasExcelImporter } from './excel-import.js';

document.addEventListener('DOMContentLoaded', () => {
    const exporter = new FamiliasExcelExporter();

    const importer = new FamiliasExcelImporter();
    importer.init();

    console.log('Módulos de familias Excel inicializados');
});
