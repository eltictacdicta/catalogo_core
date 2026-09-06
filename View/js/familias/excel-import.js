/**
 * Módulo de importación Excel para familias
 * @module familias/excel-import
 */

import { formatBytes, getCsrfToken, getConfig } from './utils.js';

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
        
        $('#modal_importar_excel_familias').on('hidden.bs.modal', () => {
            this.reset();
        });
    }
    
    /**
     * Inicializa Resumable.js para subida por chunks
     */
    initResumable() {
        const $dropZone = $('#familias-excel-drop-zone');
        const $browseButton = $('#familias_excel_file_input');
        
        if ($dropZone.length === 0) return;
        
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
                codtarifa: document.getElementById('import_familias_excel_tarifa').value,
                _csrf_token: this.csrfToken
            })
        });
        
        if (!this.resumable.support) {
            alert('Tu navegador no soporta subidas de archivos grandes. Por favor, actualiza tu navegador.');
            return;
        }
        
        this.resumable.assignDrop($dropZone[0]);
        this.resumable.assignBrowse($browseButton[0]);
        
        $dropZone.on('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).css({'border-color': '#217346', 'background': '#e8f5e9'});
        });
        
        $dropZone.on('dragleave drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).css({'border-color': '#5bc0de', 'background': '#f8f9fa'});
        });
        
        $dropZone.on('click', (e) => {
            if (!$(e.target).is('button')) {
                $browseButton.click();
            }
        });
        
        this.resumable.on('fileAdded', (file) => {
            const ext = file.fileName.split('.').pop().toLowerCase();
            if (ext !== 'xlsx' && ext !== 'xls') {
                alert('Solo se permiten archivos .xlsx o .xls');
                this.resumable.removeFile(file);
                return;
            }
            
            $('#familias_excel_file_name').text(file.fileName);
            $('#familias_excel_file_size').text('(' + formatBytes(file.size) + ')');
            $('#familias_excel_file_info').show();
            $('#btn_start_familias_excel_import').prop('disabled', false);
        });
        
        this.resumable.on('fileProgress', (file) => {
            const progress = Math.floor(file.progress() * 100);
            $('#familias_excel_progress_bar').css('width', progress + '%').text('Subiendo: ' + progress + '%');
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
            
            $('#familias_excel_step_progress').hide();
            $('#familias_excel_step_result').show();
            $('#btn_finish_familias_excel').show();
            
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
                
                $('#familias_excel_result_stats').html(html);
                $('#familias_excel_result_success').show();
                
                if (stats.detalles_errores && stats.detalles_errores.length > 0) {
                    let detailsHtml = '<h5>Detalles:</h5><ul>';
                    for (let i = 0; i < stats.detalles_errores.length; i++) {
                        detailsHtml += `<li><small>${stats.detalles_errores[i]}</small></li>`;
                    }
                    if (stats.detalles_errores.length >= 50) {
                        detailsHtml += '<li><small>... y más</small></li>';
                    }
                    detailsHtml += '</ul>';
                    $('#familias_excel_result_details').html(detailsHtml).show();
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
        
        $('#familias_excel_step_upload').hide();
        $('#familias_excel_step_progress').show();
        $('#familias_excel_progress_bar').css('width', '0%').text('Iniciando...');
        $('#btn_cancel_familias_excel').hide();
        $('#btn_close_familias_excel').hide();
        $('#btn_start_familias_excel_import').hide();
        
        this.resumable.upload();
    }
    
    /**
     * Muestra un error
     */
    showError(message) {
        $('#familias_excel_step_progress').hide();
        $('#familias_excel_step_result').show();
        $('#familias_excel_result_error').show();
        $('#familias_excel_result_error_msg').text(message);
        $('#btn_finish_familias_excel').show();
    }
    
    /**
     * Limpia el archivo seleccionado
     */
    clearFile() {
        if (this.resumable) {
            this.resumable.files = [];
        }
        $('#familias_excel_file_info').hide();
        $('#familias_excel_file_input').val('');
        $('#btn_start_familias_excel_import').prop('disabled', true);
    }
    
    /**
     * Reinicia el estado del importador
     */
    reset() {
        if (this.resumable) {
            this.resumable.files = [];
        }
        $('#familias_excel_step_upload').show();
        $('#familias_excel_step_progress').hide();
        $('#familias_excel_step_result').hide();
        $('#familias_excel_result_success').hide();
        $('#familias_excel_result_error').hide();
        $('#familias_excel_result_details').hide().empty();
        $('#familias_excel_file_info').hide();
        $('#familias_excel_file_input').val('');
        $('#btn_start_familias_excel_import').prop('disabled', true).show();
        $('#btn_finish_familias_excel').hide();
        $('#btn_cancel_familias_excel').show();
        $('#btn_close_familias_excel').show();
        $('#familias-excel-drop-zone').css({'border-color': '#5bc0de', 'background': '#f8f9fa'});
    }
    
    /**
     * Cierra el modal y recarga la página
     */
    finishAndReload() {
        $('#modal_importar_excel_familias').modal('hide');
        location.reload();
    }
}
