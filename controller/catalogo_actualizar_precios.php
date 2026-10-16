<?php
declare(strict_types=1);
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
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

require_once FS_FOLDER . '/base/fs_controller.php';
require_once FS_FOLDER . '/base/fs_settings.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoOptions.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoPriceUpdateService.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_grupo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';

/**
 * Batch price update page (multitarifa task 4.2, R-BU-001..005, design D6).
 *
 * Two-phase flow, each phase an admin-gated CSRF-checked POST:
 *   preview → computes affected articles and prospective prices via
 *   CatalogoPriceUpdateService (zero write); zero matches → warning via
 *   new_message(), nothing applied.
 *   apply → explicit confirm checkbox, persists exactly the previewed rows
 *   for the target list, then shows the resumen (updated count + affected
 *   references) via new_message(); failed rows via new_error_msg().
 *
 * Filter set (R-BU-002, AND-combined): codlista (required) + codfamilia
 * (+ incluir_subfamilias) + codgrupo. articulos.pvp is never rewritten.
 */
class catalogo_actualizar_precios extends fs_controller
{
    /** Multi-tariff master flag: hides the whole page when off (R-CO-003). */
    public bool $multi_tariff = false;

    /** @var list<\FSFramework\model\catalogo_lista_precio> active lists only */
    public array $listas = [];

    /** @var list<\FSFramework\model\familia> */
    public array $familias = [];

    /** @var list<\FSFramework\model\catalogo_grupo> */
    public array $grupos = [];

    /** @var list<array{referencia: string, precio_actual: float, precio_nuevo: float}> preview rows */
    public array $preview_rows = [];

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Actualizar precios', 'ventas', TRUE, TRUE);
    }

    protected function private_core()
    {
        $options = new \FSFramework\Plugins\catalogo_core\Services\CatalogoOptions();
        $this->multi_tariff = $options->multiTariffEnabled();
        $this->loadFormData();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!$this->isCsrfValid()) {
            $this->new_error_msg('Token de seguridad inválido.');
            return;
        }

        $action = (string) ($_POST['action'] ?? '');
        switch ($action) {
            case 'preview':
                $this->runPreview();
                break;

            case 'apply':
                $this->runApply();
                break;

            default:
                $this->new_error_msg('Acción no válida.');
        }
    }

    /**
     * Pure resumen builder (R-BU-005): success message with the updated
     * count, plus any failed references.
     *
     * @param list<string> $failed
     */
    public static function summarizeApply(int $updated, array $failed): string
    {
        $resumen = $updated . ' precios actualizados correctamente.';
        if (empty($failed)) {
            return $resumen;
        }

        return $resumen . ' No se pudo actualizar: ' . implode(', ', $failed) . '.';
    }

    /**
     * AND-combined filter set from the POST (R-BU-002).
     *
     * @return array<string, mixed>
     */
    private function readFilters(): array
    {
        return [
            'codlista' => trim((string) ($_POST['codlista'] ?? '')),
            'codfamilia' => trim((string) ($_POST['codfamilia'] ?? '')),
            'incluir_subfamilias' => $this->str2bool((string) ($_POST['incluir_subfamilias'] ?? 'FALSE')),
            'codgrupo' => ($_POST['codgrupo'] ?? '') !== '' ? (int) $_POST['codgrupo'] : null,
        ];
    }

    /** Preview phase: computes the affected rows without persisting. */
    private function runPreview(): void
    {
        $filters = $this->readFilters();
        if ($filters['codlista'] === '') {
            $this->new_error_msg('Debes seleccionar una lista de precio.');
            return;
        }

        $pct = (float) str_replace(',', '.', (string) ($_POST['pct'] ?? '0'));
        $rows = $this->service()->preview($filters, $pct);

        if (empty($rows)) {
            $this->preview_rows = [];
            $this->new_message('Ningún artículo coincide con los filtros seleccionados. No se aplicó ningún cambio.');
            return;
        }

        $this->preview_rows = $rows;
        $this->new_message('Vista previa: ' . count($rows) . ' artículos afectados. Revisa y confirma para aplicar.');
    }

    /** Apply phase: persists exactly the (recomputed) previewed rows. */
    private function runApply(): void
    {
        $filters = $this->readFilters();
        if ($filters['codlista'] === '') {
            $this->new_error_msg('Debes seleccionar una lista de precio.');
            return;
        }

        if (!$this->str2bool((string) ($_POST['confirm'] ?? 'FALSE'))) {
            $this->new_error_msg('Debes marcar la casilla de confirmación para aplicar los cambios.');
            return;
        }

        $pct = (float) str_replace(',', '.', (string) ($_POST['pct'] ?? '0'));
        $rows = $this->service()->preview($filters, $pct);

        if (empty($rows)) {
            $this->preview_rows = [];
            $this->new_message('Ningún artículo coincide con los filtros seleccionados. No se aplicó ningún cambio.');
            return;
        }

        $result = $this->service()->apply($rows, (string) $filters['codlista']);

        $affected = [];
        foreach ($rows as $row) {
            $affected[] = $row['referencia'];
        }

        if (empty($result['failed'])) {
            $this->new_message(self::summarizeApply($result['updated'], $result['failed'])
                . ' Artículos afectados: ' . implode(', ', $affected));
        } else {
            $this->new_error_msg(self::summarizeApply($result['updated'], $result['failed'])
                . ' Artículos afectados: ' . implode(', ', $affected));
        }
    }

    private function loadFormData(): void
    {
        $this->listas = [];
        foreach ((new \FSFramework\model\catalogo_lista_precio())->all() as $lista) {
            // Inactive lists are excluded from public flows (R-MT-001).
            if ($lista->activa) {
                $this->listas[] = $lista;
            }
        }

        $this->familias = (new \FSFramework\model\familia())->all();
        $this->grupos = (new \FSFramework\model\catalogo_grupo())->all();
    }

    private function service(): \FSFramework\Plugins\catalogo_core\Services\CatalogoPriceUpdateService
    {
        return new \FSFramework\Plugins\catalogo_core\Services\CatalogoPriceUpdateService();
    }
}
