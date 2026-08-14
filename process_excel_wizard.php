<?php
/**
 * SSE entry point for catalogo_core article Excel import wizard.
 *
 * Standalone access is supported for backwards compatibility; the primary
 * route is index.php?page=ventas_articulos&action=excel_import_sse (embedded).
 *
 * @see plugins/catalogo_core/Services/ArticuloExcelImportWizardService.php
 */
declare(strict_types=1);

if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', dirname(__DIR__, 2));
}

require_once __DIR__ . '/process_excel_wizard_dispatch.php';

if (!defined('CATALOGO_EXCEL_WIZARD_EMBEDDED')) {
    catalogo_excel_wizard_bootstrap();
    catalogo_excel_wizard_run(false);
}
