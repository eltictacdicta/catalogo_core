<?php
declare(strict_types=1);
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2014-2017 Carlos Garcia Gomez <neorazorx@gmail.com>
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

namespace FSFramework\Plugins\catalogo_core\Controller;

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/fabricante.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use FSFramework\Plugins\catalogo_core\Services\ArticuloExcelExportService;
use FSFramework\Plugins\catalogo_core\Services\ArticuloExcelImportWizardService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\Request;

class VentasArticulos extends PageController
{
    public array $resultados = [];
    public ?\articulo $articulo = null;
    public array $familias = [];
    public array $fabricantes = [];
    public bool $allow_delete = false;
    public bool $can_import_export = false;
    public int $price_decimals = 2;
    /** @var array<int, \FSFramework\model\impuesto> */
    public array $impuestos = [];
    public string $default_codimpuesto = 'IVA21';
    public int $offset = 0;
    public int $total_resultados = 0;
    public const ITEMS_PER_PAGE = 50;

    public function __construct()
    {
        parent::__construct('VentasArticulos');
        $this->setTemplate('ventas_articulos');
        $this->loadExtensions();
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on($this->getPageData()['name']);
        $this->price_decimals = defined('FS_NF0_ART') ? (int) FS_NF0_ART : 2;
        $this->initImportExportPermissions();
    }

    public function getPageData(): array
    {
        return [
            'name' => 'ventas_articulos',
            'title' => 'Artículos',
            'menu' => 'ventas',
            'showonmenu' => true,
            'ordernum' => 120,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $this->loadImpuestos();
        $this->articulo = new \articulo();

        if ($this->processExcelAction()) {
            return;
        }

        if ($this->request->request->has('nreferencia')) {
            $this->nuevoArticulo($this->request);
        }

        $this->offset = (int) $this->request->query->get('offset', 0);

        $search = $this->request->query->get('search', '');
        $codfamilia = $this->request->query->get('codfamilia', '');
        $codfabricante = $this->request->query->get('codfabricante', '');
        $con_stock = $this->request->query->get('con_stock', '') === 'TRUE';
        $bloqueados = $this->request->query->get('bloqueados', '') === 'TRUE';

        if ($search !== '' || $codfamilia !== '' || $codfabricante !== '' || $con_stock || $bloqueados) {
            $this->resultados = $this->articulo->search(
                (string) $search,
                $this->offset,
                (string) $codfamilia,
                $con_stock,
                (string) $codfabricante,
                $bloqueados
            );
        } else {
            $this->resultados = $this->articulo->all($this->offset);
        }

        $this->loadFilterOptions();
    }

    private function initImportExportPermissions(): void
    {
        $this->can_import_export = $this->user->admin
            || $this->user->have_access_to($this->getPageData()['name']);
    }

    private function loadImpuestos(): void
    {
        $impuesto = new \impuesto();
        $list = $impuesto->all();
        $this->impuestos = is_array($list) ? $list : [];

        $codes = [];
        foreach ($this->impuestos as $imp) {
            $codes[] = (string) ($imp->codimpuesto ?? '');
        }

        if (in_array('IVA21', $codes, true)) {
            $this->default_codimpuesto = 'IVA21';
        } elseif ($codes !== []) {
            $this->default_codimpuesto = $codes[0];
        } else {
            $this->default_codimpuesto = 'IVA21';
        }
    }

    private function processExcelAction(): bool
    {
        if (!$this->can_import_export) {
            return false;
        }

        $action = (string) $this->request->query->get('action', '');
        if ($action === '') {
            return false;
        }

        switch ($action) {
            case 'preview_excel':
                $this->template = false;
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode($this->previewExcel());
                return true;

            case 'get_preview':
                $this->template = false;
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode($this->getPreview());
                return true;

            case 'export_excel':
                $this->exportExcel(false);
                return true;

            case 'export_excel_filtered':
                $this->exportExcel(true);
                return true;

            case 'export_excel_template':
                $this->exportExcelTemplate();
                return true;

            case 'download_import_log':
                $this->downloadImportLog();
                return true;

            case 'excel_import_sse':
                return $this->handleExcelImportSse();
        }

        return false;
    }

    private function handleExcelImportSse(): bool
    {
        $this->template = false;
        @set_time_limit(300);

        $wizardAction = (string) $this->request->query->get('wizard_action', 'start');
        if ($wizardAction === 'start' && !$this->validateCsrfQueryToken()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'CSRF token missing or invalid.']);
            return true;
        }

        if (!defined('CATALOGO_EXCEL_WIZARD_EMBEDDED')) {
            define('CATALOGO_EXCEL_WIZARD_EMBEDDED', true);
        }

        require_once FS_FOLDER . '/plugins/catalogo_core/process_excel_wizard_dispatch.php';
        catalogo_excel_wizard_run(true);

        return true;
    }

    private function validateCsrfQueryToken(): bool
    {
        $token = (string) $this->request->query->get('_csrf_token', '');
        if ($token === '') {
            return false;
        }

        return \FSFramework\Security\CsrfManager::isValid($token);
    }

    /**
     * @return array<string,mixed>
     */
    public function previewExcel(): array
    {
        if (!$this->validateFormToken()) {
            return ['success' => false, 'error' => 'CSRF token missing or invalid.'];
        }

        $file = $this->request->files->get('file');
        if ($file === null || !$file->isValid()) {
            return ['success' => false, 'error' => 'No se recibió ningún archivo válido.'];
        }

        $origName = (string) $file->getClientOriginalName();
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            return ['success' => false, 'error' => 'Extensión no soportada. Usa .xlsx o .xls.'];
        }

        $tmpName = $file->getPathname();
        try {
            $spreadsheet = IOFactory::load($tmpName);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'No se pudo leer el archivo Excel: ' . $e->getMessage()];
        }

        $sheets = $spreadsheet->getSheetNames();
        if ($sheets === []) {
            return ['success' => false, 'error' => 'El archivo no contiene hojas.'];
        }

        $activeSheet = $spreadsheet->getActiveSheet();
        $highestColIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
            $activeSheet->getHighestColumn()
        );
        if ($highestColIdx > 30) {
            return ['success' => false, 'error' => 'Demasiadas columnas (máximo 30).'];
        }

        $token = bin2hex(random_bytes(16));
        $tmpDir = rtrim(FS_FOLDER, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0700, true);
        }
        $destPath = $tmpDir . 'catalogo_excel_wizard_' . $token . '.xlsx';
        if (!@copy($tmpName, $destPath)) {
            return ['success' => false, 'error' => 'No se pudo guardar el archivo temporal.'];
        }
        @chmod($destPath, 0600);

        return [
            'success' => true,
            'token' => $token,
            'sheets' => array_values($sheets),
            'active_sheet' => $activeSheet->getTitle(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getPreview(): array
    {
        $token = (string) $this->request->query->get('token', '');
        $sheet = (string) $this->request->query->get('sheet', '');
        $n = max(1, min(50, (int) $this->request->query->get('n', 10)));

        if (!preg_match('/^[A-Fa-f0-9]{8,64}$/', $token)) {
            return ['success' => false, 'error' => 'Token inválido.'];
        }
        if ($sheet === '') {
            return ['success' => false, 'error' => 'Hoja no especificada.'];
        }

        $filePath = $this->wizardTempPath($token);
        if (!is_file($filePath)) {
            return ['success' => false, 'error' => 'token_not_found'];
        }

        $service = new ArticuloExcelImportWizardService();
        $preview = $service->preview($filePath, $sheet, $n);

        return [
            'success' => true,
            'headers' => $preview['headers'],
            'rows' => $preview['rows'],
            'suggested_mapping' => $preview['suggested_mapping'],
            'field_options' => $preview['field_options'],
        ];
    }

    private function exportExcel(bool $filtered): void
    {
        $this->template = false;
        $articulos = $filtered ? $this->loadArticulosForExportFiltered() : $this->loadArticulosForExportAll();

        $service = new ArticuloExcelExportService();
        $spreadsheet = $service->buildSpreadsheet($articulos);
        $filename = $filtered ? 'articulos_filtrado_' . date('Y-m-d') . '.xlsx' : 'articulos_' . date('Y-m-d') . '.xlsx';
        $service->sendDownload($spreadsheet, $filename);
    }

    private function exportExcelTemplate(): void
    {
        $this->template = false;
        $service = new ArticuloExcelExportService();
        $spreadsheet = $service->buildSpreadsheet([], true);
        $service->sendDownload($spreadsheet, 'plantilla_articulos.xlsx');
    }

    private function downloadImportLog(): void
    {
        $this->template = false;
        $file = basename((string) $this->request->query->get('file', ''));
        if (!preg_match('/^catalogo_import_[0-9]{8}_[0-9]{6}_descartadas\.csv$/', $file)) {
            http_response_code(404);
            echo 'Archivo no encontrado.';
            return;
        }

        $path = rtrim(FS_FOLDER, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            http_response_code(404);
            echo 'Archivo no encontrado.';
            return;
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        readfile($path);
    }

    /**
     * @return \articulo[]
     */
    private function loadArticulosForExportAll(): array
    {
        $model = new \articulo();

        return $model->all(0, 999999);
    }

    /**
     * @return \articulo[]
     */
    private function loadArticulosForExportFiltered(): array
    {
        $model = new \articulo();
        $search = $this->request->query->get('search', '');
        $codfamilia = $this->request->query->get('codfamilia', '');
        $codfabricante = $this->request->query->get('codfabricante', '');
        $con_stock = $this->request->query->get('con_stock', '') === 'TRUE';
        $bloqueados = $this->request->query->get('bloqueados', '') === 'TRUE';

        if ($search !== '' || $codfamilia !== '' || $codfabricante !== '' || $con_stock || $bloqueados) {
            return $model->search(
                (string) $search,
                0,
                (string) $codfamilia,
                $con_stock,
                (string) $codfabricante,
                $bloqueados
            );
        }

        return $model->all(0, 999999);
    }

    private function wizardTempPath(string $token): string
    {
        return rtrim(FS_FOLDER, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'tmp' . DIRECTORY_SEPARATOR
            . 'catalogo_excel_wizard_' . $token . '.xlsx';
    }

    private function nuevoArticulo(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $referencia = (string) $request->request->get('nreferencia', '');
        $descripcion = (string) $request->request->get('ndescripcion', '');
        $codfamilia = $request->request->get('ncodfamilia');
        $codfabricante = $request->request->get('ncodfabricante');
        $codimpuesto = trim((string) $request->request->get('ncodimpuesto', ''));
        $pvp = (float) $request->request->get('npvp', 0);

        if ($referencia === '' || $descripcion === '') {
            $this->new_error_msg('Debes indicar referencia y descripción.');
            return;
        }

        if ($this->articulo->get($referencia)) {
            $this->new_error_msg('Ya existe un artículo con la referencia ' . $referencia);
            return;
        }

        $art = new \articulo();
        $art->referencia = $referencia;
        $art->descripcion = $descripcion;
        $art->codfamilia = ($codfamilia !== null && $codfamilia !== '') ? (string) $codfamilia : null;
        $art->codfabricante = ($codfabricante !== null && $codfabricante !== '') ? (string) $codfabricante : null;
        $art->pvp = $pvp;

        if ($codimpuesto === '' || !preg_match('/^[A-Za-z0-9._-]{1,10}$/', $codimpuesto)) {
            $codimpuesto = $this->default_codimpuesto;
        }
        $art->set_impuesto($codimpuesto);

        if ($art->save()) {
            $this->new_message('Artículo ' . $art->referencia . ' guardado correctamente.');
        } else {
            $this->new_error_msg('¡Imposible guardar el artículo!');
        }
    }

    private function loadExtensions(): void
    {
        $pageName = $this->getPageData()['name'];
        $fsext = new \fs_extension();
        foreach ($fsext->all() as $ext) {
            if (!in_array($ext->to, [null, $pageName], true)) {
                continue;
            }

            if ($ext->type !== 'config' && !$this->user->have_access_to($ext->from)) {
                continue;
            }

            $this->extensions[] = $ext;
        }
    }

    private function loadFilterOptions(): void
    {
        $familia = new \familia();
        $this->familias = $familia->all();

        $fabricante = new \fabricante();
        $this->fabricantes = $fabricante->all();
    }

    public function getPaginationUrl(int $offset): string
    {
        $params = $this->getListQueryParams();
        $params['offset'] = $offset;

        return $this->buildListUrl($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function buildListUrl(array $params = []): string
    {
        if ($params === []) {
            return $this->url();
        }

        $baseUrl = $this->url();
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . http_build_query($params);
    }

    /**
     * @return array<string, mixed>
     */
    private function getListQueryParams(): array
    {
        $params = $this->request->query->all();
        unset($params['page'], $params['action']);

        return $params;
    }

    public function hasMoreResults(): bool
    {
        return count($this->resultados) >= self::ITEMS_PER_PAGE;
    }

    /**
     * Query string for export filtered (preserves list filters).
     */
    public function getExportFilteredQuery(): string
    {
        $params = $this->getListQueryParams();
        unset($params['offset']);
        if ($params === []) {
            return '';
        }

        return '&' . http_build_query($params);
    }
}
