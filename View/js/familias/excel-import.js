/**
 * Módulo de importación Excel para familias — vanilla DOM (no jQuery).
 * Modal visibility is owned by the Alpine component (familiaImportModal);
 * this module owns the step/progress/result DOM inside the modal and is
 * reset() from the Alpine close hook (the old hidden.bs.modal contract).
 * @module familias/excel-import
 */

import { formatBytes, getCsrfToken, getConfig } from './utils.js';

function el(id) {
    return document.getElementById(id);
}

function show(id) {
    const node = el(id);
    if (node) {
        node.style.removeProperty('display');
    }
}

function hide(id) {
    const node = el(id);
    if (node) {
        node.style.display = 'none';
    }
}

/**
 * Clase para gestionar la importación de familias desde Excel con Resumable.js
 */
export class FamiliasExcelImporter {
    constructor() {
        this.config = getConfig();
        this.resumable = null;
        this.csrfToken = '';
        this.bindGlobalFunctions();
    }

    /**
     * Expone funciones globales para compatibilidad con onclick en HTML
     */
    bindGlobalFunctions() {
        window.familiasExcelImport = {
            clearFile: () => this.clearFile(),
            startImport: () => this.startImport(),
            reset: () => this.reset(),
            finishAndReload: () => this.finishAndReload()
        };
    }

    /**
     * Inicializa el importador
     */
    init() {
        this.csrfToken = getCsrfToken();
        this.initResumable();
    }

    /**
     * Inicializa Resumable.js para subida por chunks
     */
    initResumable() {
        const dropZone = el('familias-excel-drop-zone');
        const browseButton = el('familias_excel_file_input');

        if (!dropZone) return;

        if (typeof Resumable === 'undefined') {
            console.error('Resumable.js no está cargado');
            return;
        }

        this.resumable = new Resumable({
            target: `${this.config.baseUrl}&action=import_excel_chunk`,
            chunkSize: 5 * 1024 * 1024,
            simultaneousUploads: 1,
            testChunks: false,
            throttleProgressCallbacks: 0.5,
            fileType: ['xlsx', 'xls'],
            maxFiles: 1,
            query: () => ({
                codtarifa: el('import_familias_excel_tarifa').value,
                _csrf_token: this.csrfToken
            })
        });

        if (!this.resumable.support) {
            alert('Tu navegador no soporta subidas de archivos grandes. Por favor, actualiza tu navegador.');
            return;
        }

        this.resumable.assignDrop(dropZone);
        this.resumable.assignBrowse(browseButton);

        dropZone.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.stopPropagation();
            e.currentTarget.style.borderColor = '#217346';
            e.currentTarget.style.background = '#e8f5e9';
        });

        const dragReset = (e) => {
            e.preventDefault();
            e.stopPropagation();
            e.currentTarget.style.borderColor = '#5bc0de';
            e.currentTarget.style.background = '#f8f9fa';
        };
        dropZone.addEventListener('dragleave', dragReset);
        dropZone.addEventListener('drop', dragReset);

        dropZone.addEventListener('click', (e) => {
            if (!e.target.closest('button')) {
                browseButton.click();
            }
        });

        this.resumable.on('fileAdded', (file) => {
            const ext = file.fileName.split('.').pop().toLowerCase();
            if (ext !== 'xlsx' && ext !== 'xls') {
                alert('Solo se permiten archivos .xlsx o .xls');
                this.resumable.removeFile(file);
                return;
            }

            el('familias_excel_file_name').textContent = file.fileName;
            el('familias_excel_file_size').textContent = '(' + formatBytes(file.size) + ')';
            show('familias_excel_file_info');
            el('btn_start_familias_excel_import').disabled = false;
        });

        this.resumable.on('fileProgress', (file) => {
            const progress = Math.floor(file.progress() * 100);
            const bar = el('familias_excel_progress_bar');
            bar.style.width = progress + '%';
            bar.textContent = 'Subiendo: ' + progress + '%';
        });

        this.resumable.on('fileSuccess', (file, message) => {
            console.log('Familias Excel fileSuccess - Raw message:', message);

            let response;
            try {
                response = JSON.parse(message);
            } catch (e) {
                console.error('Error parsing response:', e);
                this.showError('Error al procesar la respuesta del servidor');
                return;
            }

            hide('familias_excel_step_progress');
            show('familias_excel_step_result');
            show('btn_finish_familias_excel');

            if (response.success) {
                const stats = response.stats;
                let html = '<ul>';
                html += `<li><strong>${stats.total}</strong> familias procesadas</li>`;
                if (stats.creados > 0) {
                    html += `<li class="text-success"><strong>${stats.creados}</strong> creadas</li>`;
                }
                if (stats.actualizados > 0) {
                    html += `<li class="text-info"><strong>${stats.actualizados}</strong> actualizadas</li>`;
                }
                if (stats.sin_cambios > 0) {
                    html += `<li><strong>${stats.sin_cambios}</strong> sin cambios</li>`;
                }
                if (stats.errores > 0) {
                    html += `<li class="text-danger"><strong>${stats.errores}</strong> errores</li>`;
                }
                html += '</ul>';

                el('familias_excel_result_stats').innerHTML = html;
                show('familias_excel_result_success');

                if (stats.detalles_errores && stats.detalles_errores.length > 0) {
                    let detailsHtml = '<h5>Detalles:</h5><ul>';
                    for (let i = 0; i < stats.detalles_errores.length; i++) {
                        detailsHtml += `<li><small>${stats.detalles_errores[i]}</small></li>`;
                    }
                    if (stats.detalles_errores.length >= 50) {
                        detailsHtml += '<li><small>... y más</small></li>';
                    }
                    detailsHtml += '</ul>';
                    el('familias_excel_result_details').innerHTML = detailsHtml;
                    show('familias_excel_result_details');
                }
            } else {
                this.showError(response.error || response.message || 'Error desconocido');
            }
        });

        this.resumable.on('fileError', (file, message) => {
            console.error('Familias Excel fileError - Raw message:', message);

            let errorMsg = 'Error de conexión';
            try {
                const response = JSON.parse(message);
                errorMsg = response.error || response.message || errorMsg;
            } catch (e) {
                if (message) errorMsg = message;
            }

            this.showError(errorMsg);
        });
    }

    /**
     * Inicia la importación
     */
    startImport() {
        if (!this.resumable || this.resumable.files.length === 0) {
            alert('Selecciona un archivo Excel primero');
            return;
        }

        hide('familias_excel_step_upload');
        show('familias_excel_step_progress');
        const bar = el('familias_excel_progress_bar');
        bar.style.width = '0%';
        bar.textContent = 'Iniciando...';
        hide('btn_cancel_familias_excel');
        hide('btn_close_familias_excel');
        hide('btn_start_familias_excel_import');

        this.resumable.upload();
    }

    /**
     * Muestra un error
     */
    showError(message) {
        hide('familias_excel_step_progress');
        show('familias_excel_step_result');
        show('familias_excel_result_error');
        el('familias_excel_result_error_msg').textContent = message;
        show('btn_finish_familias_excel');
    }

    /**
     * Limpia el archivo seleccionado
     */
    clearFile() {
        if (this.resumable) {
            this.resumable.files = [];
        }
        hide('familias_excel_file_info');
        el('familias_excel_file_input').value = '';
        el('btn_start_familias_excel_import').disabled = true;
    }

    /**
     * Reinicia el estado del importador (invocado por el cierre del modal)
     */
    reset() {
        if (this.resumable) {
            this.resumable.files = [];
        }
        show('familias_excel_step_upload');
        hide('familias_excel_step_progress');
        hide('familias_excel_step_result');
        hide('familias_excel_result_success');
        hide('familias_excel_result_error');
        const details = el('familias_excel_result_details');
        details.style.display = 'none';
        details.innerHTML = '';
        hide('familias_excel_file_info');
        el('familias_excel_file_input').value = '';
        el('btn_start_familias_excel_import').disabled = true;
        show('btn_start_familias_excel_import');
        hide('btn_finish_familias_excel');
        show('btn_cancel_familias_excel');
        show('btn_close_familias_excel');
        const dropZone = el('familias-excel-drop-zone');
        if (dropZone) {
            dropZone.style.borderColor = '#5bc0de';
            dropZone.style.background = '#f8f9fa';
        }
    }

    /**
     * Cierra el modal y recarga la página (el estado del modal muere con el reload)
     */
    finishAndReload() {
        location.reload();
    }
}
