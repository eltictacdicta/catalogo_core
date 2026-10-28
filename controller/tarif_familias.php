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

require_once 'plugins/catalogo_core/model/tarif_tarifa_familia.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_etiqueta_familia.php';
require_once 'plugins/catalogo_core/Services/CaracteristicaResolver.php';
require_once 'plugins/catalogo_core/Services/CaracteristicaValorStore.php';

use FSFramework\model\tarif_tarifa_familia;
use FSFramework\model\tarif_tarifa_etiqueta_familia;
use FSFramework\model\tarif_tarifa;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaValorStore;
use FSFramework\Plugins\catalogo_core\Services\CatalogoOptions;
use FSFramework\Plugins\catalogo_core\Services\FamiliaEstructuraStore;
use FSFramework\Plugins\catalogo_core\Services\FamiliaTreeGuard;
use FSFramework\Plugins\catalogo_core\Services\TarifaFamiliaReorder;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Controlador para gestionar familias/categorías del tarifario por tarifa.
 * HTMX-first pilot: fragment endpoints, row swaps, tbody structural changes.
 */
class tarif_familias extends \FSFramework\Controller\HtmxCrudController
{
    /** Tabla estable de artículos por tarifa — permanece en tarifario (design D2/D3). */
    public const TARIFA_ARTICULO_TABLE = 'tarif_tarifa_articulo';

    /** @var bool Guard: las tablas de familias se aseguran una vez por proceso (design D4). */
    private static bool $tables_ensured = false;

    /** @var tarif_tarifa_familia */
    public $tarifa_familia;

    /** @var tarif_tarifa_etiqueta_familia */
    public $tarifa_etiqueta_familia;

    /** @var tarif_tarifa */
    public $tarifa;

    /** @var string Código de tarifa seleccionada */
    public $codtarifa;

    /** @var array Lista de tarifas disponibles */
    public $tarifas;

    /** @var array Árbol de familias */
    public $familias_tree;

    /** @var tarif_tarifa_familia|null Familia en edición */
    public $editing_familia;

    /** @var array Lista de familias base disponibles para añadir */
    public $familias_disponibles;

    /** @var boolean Filtrar solo activas */
    public $b_solo_activas;

    /**
     * Capacidad "estructura de familias por lista" (familias-jerarquia D5).
     * Flag off ⇒ la página opera sobre `tarif_tarifa_familia` (rollback exacto).
     */
    public $family_structure = false;

    /** @var array<int, object> Listas de precio activas para el selector. */
    public $listas_estructura = [];

    /** @var string Lista en contexto (`?codlista=`), '' cuando el flag está off. */
    public $codlista_estructura = '';

    /** @var array<string, object> codfamilia => fila de estructura de la lista en contexto. */
    public $estructura_por_familia = [];

    /** @var FamiliaEstructuraStore|null */
    private $estructura_store = null;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Familias', 'catalogo');
    }

    protected function private_core()
    {
        parent::private_core();

        // Permission — fbase_controller set this; we read it from the user directly.
        $this->allow_delete = $this->user->allow_delete_on($this->class_name);

        // CRUD configuration
        $this->crud('familias')
            ->rowPartial('partials/familias/familia_row')
            ->addOrderBy(['capitulo' => 'asc'])
            ->rowSwap(true);

        $this->familias_tree = [];
        $this->editing_familia = null;
        $this->familias_disponibles = [];
        $this->tarifas = [];

        // Dual-source switch (familias-jerarquia D5). Flag off keeps the legacy
        // `tarif_tarifa_familia` behaviour byte-for-byte; flag on operates on
        // `catalogo_familia_estructura` scoped by the selected price list.
        $this->init_family_structure();

        if ($this->family_structure) {
            // Canonical path: the legacy models are not instantiated, so the
            // flag-on request performs zero reads and writes on tarif_*.
            $this->tarifa_familia = null;
            $this->tarifa_etiqueta_familia = null;
            $this->tarifa = null;
            $this->codtarifa = isset($_REQUEST['codtarifa']) ? trim($_REQUEST['codtarifa']) : '';
        } else {
            // Ensure familia tables once per process
            if (!self::$tables_ensured) {
                self::$tables_ensured = true;
                if (class_exists(\FSFramework\Plugins\catalogo_core\Init::class)) {
                    \FSFramework\Plugins\catalogo_core\Init::ensureFamiliasTarifaTables();
                }
            }

            $this->tarifa_familia = new tarif_tarifa_familia();
            $this->tarifa_etiqueta_familia = new tarif_tarifa_etiqueta_familia();
            $this->tarifa = new tarif_tarifa();

            // Load tariffs
            $this->tarifas = $this->tarifa->all();

            // Get selected tariff
            $this->codtarifa = isset($_REQUEST['codtarifa']) ? trim($_REQUEST['codtarifa']) : '';
            if (empty($this->codtarifa)) {
                $default = $this->tarifa->get_default();
                $this->codtarifa = $default ? $default->codtarifa : '';
            }
        }

        // Active-only filter
        $this->b_solo_activas = false;
        if (isset($_REQUEST['b_solo_activas'])) {
            $this->b_solo_activas = ($_REQUEST['b_solo_activas'] === 'TRUE');
        }

        // Action dispatch
        if (isset($_REQUEST['action'])) {
            $this->process_action($_REQUEST['action']);
            return;
        }

        // Form submissions
        if (isset($_POST['save_familia'])) {
            $this->save_familia();
        } elseif (isset($_POST['add_familia'])) {
            $this->add_familia_to_tarifa();
        } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
            $this->delete_familia();
        } elseif (isset($_GET['edit'])) {
            $this->editing_familia = $this->find_familia_row((string) $_GET['edit']);
        }

        // Load tree
        $this->load_familias_tree();
        $this->load_familias_disponibles();
    }

    // ------------------------------------------------------------------
    // Dual-source seams (familias-jerarquia D5/D6)
    // ------------------------------------------------------------------

    /**
     * Resolves the capability flag and the list in context. With the flag off
     * nothing else happens: no list is read and no structure row is touched.
     */
    private function init_family_structure(): void
    {
        $this->family_structure = false;
        $this->listas_estructura = [];
        $this->codlista_estructura = '';
        $this->estructura_por_familia = [];

        if (!$this->catalogo_options()->familyStructureEnabled()) {
            return;
        }

        $this->family_structure = true;

        foreach ($this->lista_precio_model()->all() as $lista) {
            if ($lista->activa) {
                $this->listas_estructura[] = $lista;
            }
        }

        $this->codlista_estructura = $this->resolve_codlista_estructura();
    }

    /**
     * The supplied `codlista` is kept only when it is an active list; otherwise
     * the default list wins, then the first active list, then ''.
     */
    private function resolve_codlista_estructura(): string
    {
        $supplied = isset($_REQUEST['codlista']) ? trim((string) $_REQUEST['codlista']) : '';
        foreach ($this->listas_estructura as $lista) {
            if ($supplied !== '' && (string) $lista->codlista === $supplied) {
                return $supplied;
            }
        }

        foreach ($this->listas_estructura as $lista) {
            if (!empty($lista->por_defecto)) {
                return (string) $lista->codlista;
            }
        }

        return $this->listas_estructura === [] ? '' : (string) $this->listas_estructura[0]->codlista;
    }

    protected function catalogo_options(): CatalogoOptions
    {
        return new CatalogoOptions();
    }

    protected function lista_precio_model()
    {
        require_once 'plugins/catalogo_core/model/core/catalogo_lista_precio.php';

        return new \FSFramework\model\catalogo_lista_precio();
    }

    protected function familia_estructura_store(): FamiliaEstructuraStore
    {
        if (null === $this->estructura_store) {
            $this->estructura_store = new FamiliaEstructuraStore(
                $this->db,
                (string) ($this->user->nick ?? '')
            );
        }

        return $this->estructura_store;
    }

    /**
     * Authoritative cycle check (walks `familias.madre`).
     *
     * @throws \RuntimeException on cycle
     */
    protected function familia_tree_guard(string $codfamilia, ?string $madre): void
    {
        FamiliaTreeGuard::assertAcyclic($this->db, $codfamilia, $madre);
    }

    /**
     * Structure rows of the list in context (canonical path only).
     *
     * @return array<int, object>
     */
    private function estructura_rows(bool $onlyActive = false): array
    {
        if (!$this->family_structure || $this->codlista_estructura === '') {
            return [];
        }

        $rows = $this->familia_estructura_store()->rows($this->codlista_estructura, $onlyActive);
        if (!$onlyActive) {
            $this->estructura_por_familia = [];
            foreach ($rows as $row) {
                $this->estructura_por_familia[(string) $row->codfamilia] = $row;
            }
        }

        return $rows;
    }

    /**
     * All rows of the tree for the active source (legacy or canonical).
     *
     * @return array<int, object>
     */
    private function all_familia_rows(): array
    {
        if ($this->family_structure) {
            return $this->estructura_rows();
        }

        return $this->tarifa_familia->all_by_capitulo($this->codtarifa);
    }

    /**
     * One row of the active source, or false when it is not in the list.
     *
     * @return object|false
     */
    private function find_familia_row(string $codfamilia)
    {
        if ($this->family_structure) {
            if ($this->estructura_por_familia === [] && $this->codlista_estructura !== '') {
                $this->estructura_rows();
            }

            return $this->estructura_por_familia[$codfamilia] ?? false;
        }

        return $this->tarifa_familia->get($this->codtarifa, $codfamilia);
    }

    /**
     * Direct children of a family in the active source.
     *
     * @return array<int, object>
     */
    private function hijas_de(string $codfamilia): array
    {
        if ($this->family_structure) {
            return $this->familia_estructura_store()->children($this->codlista_estructura, $codfamilia);
        }

        return $this->tarifa_familia->hijas($this->codtarifa, $codfamilia);
    }

    /**
     * Runs both cycle checks before a `madre` change. A failure reports the
     * reason and returns false, so the caller writes nothing.
     */
    private function guard_estructura_ciclo(string $codfamilia, ?string $madre): bool
    {
        try {
            $this->familia_tree_guard($codfamilia, $madre);
            $this->familia_estructura_store()->assertAcyclicInList(
                $this->codlista_estructura,
                $codfamilia,
                $madre
            );
        } catch (\RuntimeException $e) {
            $this->new_error_msg($e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Persists one structure row of the list in context (canonical path).
     *
     * @param array<string, mixed> $values
     */
    private function save_estructura_row(string $codfamilia, array $values): bool
    {
        if (
            array_key_exists('madre', $values)
            && !$this->guard_estructura_ciclo($codfamilia, $values['madre'])
        ) {
            return false;
        }

        if (!$this->familia_estructura_store()->save($this->codlista_estructura, $codfamilia, $values)) {
            $this->new_error_msg(
                $this->familia_estructura_store()->lastError() ?: 'Error al guardar la familia.'
            );

            return false;
        }

        $this->estructura_por_familia = [];

        return true;
    }

    /**
     * Applies a chapter map on the active source.
     *
     * @param array<string, string> $chapters
     */
    private function recalculate_chapters(array $chapters): bool
    {
        if ($this->family_structure) {
            $ok = $this->familia_estructura_store()->saveChapterMap($this->codlista_estructura, $chapters);
            if (!$ok) {
                $this->new_error_msg(
                    $this->familia_estructura_store()->lastError() ?: 'Error al guardar el orden.'
                );
            }

            return $ok;
        }

        return (bool) $this->tarifa_familia->apply_chapter_map($this->codtarifa, $chapters);
    }

    /**
     * Persists a `madre` change on the active source.
     *
     * Flag off: the legacy model writes and its result is ignored, exactly as
     * before. Flag on: both cycle guards run first and a rejected/denied write
     * reports the reason and returns false.
     */
    private function persist_madre(object $fam): bool
    {
        if (!$this->family_structure) {
            $fam->save();

            return true;
        }

        return $this->save_estructura_row((string) $fam->codfamilia, ['madre' => $fam->madre]);
    }

    /**
     * PRG/fragment response for a failed canonical save.
     */
    private function respond_save_failure(): void
    {
        if ($this->requireHtmx()) {
            $this->noContentWithFlash();
        } else {
            header('Location: ' . $this->listUrl());
            exit;
        }
    }

    /**
     * Writes `familias.descripcion` (the base table stays writable; only its
     * schema is frozen).
     */
    private function save_familia_descripcion(string $codfamilia, string $descripcion): bool
    {
        require_once 'plugins/catalogo_core/model/core/familia.php';
        $base = new \FSFramework\model\familia();
        $row = $base->get($codfamilia);
        if (!$row) {
            return true;
        }

        $row->descripcion = trim($descripcion);

        return (bool) $row->save();
    }

    /**
     * Count of articles assigned to a family structure of one list.
     */
    private function count_estructura_articulos(string $codlista, string $codfamilia): int
    {
        $data = $this->db->select(
            'SELECT COUNT(*) AS total FROM catalogo_articulo_familia_estructura'
            . ' WHERE codlista = ? AND codfamilia = ?',
            [$codlista, $codfamilia]
        );

        return ($data && isset($data[0]['total'])) ? (int) $data[0]['total'] : 0;
    }

    /**
     * Action dispatch — Excel actions early-return, fragment actions use buildFragment.
     */
    private function process_action(string $action): void
    {
        // Excel actions — not fragment-based, early return
        if ($action === 'export_excel') {
            $this->export_excel();
            return;
        }
        if ($action === 'export_excel_template') {
            $this->export_excel_template();
            return;
        }
        if ($action === 'import_excel_chunk') {
            $this->import_excel_chunk();
            return;
        }

        // Duplicated petition guard
        $petitionId = $_POST['petition_id'] ?? $_GET['petition_id'] ?? '';
        if ($petitionId !== '' && $this->isDuplicatedPetition($petitionId)) {
            $this->noContentWithFlash();
            return;
        }

        switch ($action) {
            case 'reorder':
                $this->action_reorder();
                break;
            case 'get_next_capitulo':
                $this->action_get_next_capitulo();
                break;
            case 'edit_form':
                $this->action_edit_form();
                break;
            case 'promote':
                $this->action_promote();
                break;
            case 'demote':
                $this->action_demote();
                break;
            case 'toggle_catalogo':
                $this->action_toggle('ajax_toggle_catalogo');
                break;
            case 'toggle_en_tarifa':
                $this->action_toggle('ajax_toggle_en_tarifa');
                break;
            case 'toggle_activa':
                $this->action_toggle('ajax_toggle_activa');
                break;
            case 'delete':
                $this->delete_familia();
                break;
            default:
                $this->noContentWithFlash();
                break;
        }
    }

    // ------------------------------------------------------------------
    // Fragment actions
    // ------------------------------------------------------------------

    /**
     * PRG fallback target — list URL with the current tarifa selected.
     */
    protected function listUrl(): string
    {
        $url = $this->url();
        if ($this->family_structure && $this->codlista_estructura !== '') {
            $url .= '&codlista=' . urlencode($this->codlista_estructura);
        }

        return $this->codtarifa
            ? $url . '&codtarifa=' . urlencode($this->codtarifa)
            : $url;
    }

    /**
     * Query fragment that identifies the source in context: the canonical
     * price list when the structure capability is on, the legacy tariff
     * otherwise (familias-jerarquia task 3.2).
     *
     * Every form action and HTMX URL of the view carries this fragment, so a
     * page loaded with `?codlista=` keeps writing to that list instead of
     * silently falling back to the default one. The fragment starts with the
     * parameter name (no leading `&`), so templates keep the separator literal
     * and the flag-off markup stays exactly the legacy one.
     */
    public function source_query(): string
    {
        if ($this->family_structure) {
            return 'codlista=' . rawurlencode((string) $this->codlista_estructura);
        }

        return 'codtarifa=' . rawurlencode((string) $this->codtarifa);
    }

    /**
     * Price list in context (canonical path), or null when the flag is off or
     * no active list is selectable.
     *
     * @return object|null
     */
    public function get_lista_actual()
    {
        if (!$this->family_structure) {
            return null;
        }

        foreach ($this->listas_estructura as $lista) {
            if ((string) $lista->codlista === $this->codlista_estructura) {
                return $lista;
            }
        }

        return null;
    }

    /**
     * Server-authoritative reorder — accepts flat JSON array of codes,
     * validates permutation, recomputes chapters, saves only changed rows.
     */
    private function action_reorder(): void
    {
        $orderRaw = $_POST['order'] ?? '[]';
        $flatCodes = json_decode($orderRaw, true);
        if (!is_array($flatCodes)) {
            $this->new_error_msg('Payload de orden inválido.');
            $this->noContentWithFlash();
            return;
        }

        // Build current madre map from DB
        $allFamilias = $this->all_familia_rows();
        $madreByCode = [];
        foreach ($allFamilias as $fam) {
            $madreByCode[$fam->codfamilia] = $fam->madre;
        }

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        if (!$result['ok']) {
            $this->new_error_msg('Error en el orden: ' . $result['error']);
            $this->noContentWithFlash();
            return;
        }

        if ($this->recalculate_chapters($result['chapters'])) {
            $this->new_message('Orden guardado correctamente.');
            // Reload tree and render full tbody with fresh data
            $this->load_familias_tree();
            $this->renderTbodyFragment($this->get_familias_flat());
        } else {
            if (!$this->family_structure) {
                $this->new_error_msg('Error al guardar el orden.');
            }
            $this->noContentWithFlash();
        }
    }

    /**
     * GET next capítulo preview — returns a <span> fragment.
     */
    private function action_get_next_capitulo(): void
    {
        $madre = $_REQUEST['madre'] ?? null;
        if (empty($madre)) {
            $madre = null;
        }
        $capitulo = $this->family_structure
            ? $this->familia_estructura_store()->suggestCapitulo($this->codlista_estructura, $madre)
            : $this->tarifa_familia->suggest_capitulo($this->codtarifa, $madre);

        $this->renderFragment('partials/familias/capitulo_preview', [
            'capitulo' => $capitulo,
        ]);
    }

    /**
     * GET fragment: edit modal for one familia — HTMX-driven edit without a
     * full page reload. Renders the same partial the full page uses for the
     * no-JS fallback (?edit=CODE), so both paths share one template.
     */
    private function action_edit_form(): void
    {
        $codfamilia = isset($_GET['codfamilia']) ? trim($_GET['codfamilia']) : '';
        if ($codfamilia === '') {
            $this->new_error_msg('Código de familia no proporcionado.');
            $this->noContentWithFlash();
            return;
        }

        $this->editing_familia = $this->find_familia_row($codfamilia);
        if (!$this->editing_familia) {
            $this->new_error_msg('Familia no encontrada en esta tarifa.');
            $this->noContentWithFlash();
            return;
        }

        $this->renderFragment('partials/familias/edit_modal');
    }

    /**
     * Promote — moves a familia up one level (to its grandmother).
     * Re-derives flatOrder and madreMap server-side, reuses plan().
     */
    private function action_promote(): void
    {
        $codfamilia = $_POST['codfamilia'] ?? '';

        if (empty($codfamilia)) {
            $this->new_error_msg('Código de familia no proporcionado.');
            $this->noContentWithFlash();
            return;
        }

        $fam = $this->find_familia_row((string) $codfamilia);
        if (!$fam) {
            $this->new_error_msg('Familia no encontrada en esta tarifa.');
            $this->noContentWithFlash();
            return;
        }

        if (is_null($fam->madre)) {
            $this->new_error_msg('Esta familia ya es una familia raíz.');
            $this->noContentWithFlash();
            return;
        }

        $madre_actual = $this->find_familia_row((string) $fam->madre);
        $nueva_madre = null;
        if ($madre_actual && !is_null($madre_actual->madre)) {
            $nueva_madre = $madre_actual->madre;
        }

        $fam->madre = $nueva_madre;
        if (!$this->persist_madre($fam)) {
            if ($this->family_structure) {
                $this->noContentWithFlash();
                return;
            }
        }

        // Re-derive the full order and structure server-side
        $allFamilias = $this->all_familia_rows();
        $flatOrder = array_map(fn($f) => $f->codfamilia, $allFamilias);
        $madreMapAfter = [];
        foreach ($allFamilias as $f) {
            $madreMapAfter[$f->codfamilia] = $f->madre;
        }

        $result = TarifaFamiliaReorder::plan($flatOrder, $madreMapAfter);
        if ($result['ok']) {
            $this->recalculate_chapters($result['chapters']);
        }

        $this->new_message('Familia promovida correctamente.');
        $this->load_familias_tree();
        $this->renderTbodyFragment($this->get_familias_flat());
    }

    /**
     * Demote — moves a familia down one level (child of previous sibling).
     * Re-derives flatOrder and madreMap server-side, reuses plan().
     */
    private function action_demote(): void
    {
        $codfamilia = $_POST['codfamilia'] ?? '';

        if (empty($codfamilia)) {
            $this->new_error_msg('Código de familia no proporcionado.');
            $this->noContentWithFlash();
            return;
        }

        $fam = $this->find_familia_row((string) $codfamilia);
        if (!$fam) {
            $this->new_error_msg('Familia no encontrada en esta tarifa.');
            $this->noContentWithFlash();
            return;
        }

        $hermanos = $this->get_siblings($fam);
        $hermano_anterior = null;
        foreach ($hermanos as $h) {
            if ($h->codfamilia == $fam->codfamilia) {
                break;
            }
            $hermano_anterior = $h;
        }

        if (!$hermano_anterior) {
            $this->new_error_msg('No hay una familia hermana anterior para convertirse en madre.');
            $this->noContentWithFlash();
            return;
        }

        $fam->madre = $hermano_anterior->codfamilia;
        if (!$this->persist_madre($fam)) {
            if ($this->family_structure) {
                $this->noContentWithFlash();
                return;
            }
        }

        // Re-derive the full order and structure server-side
        $allFamilias = $this->all_familia_rows();
        $flatOrder = array_map(fn($f) => $f->codfamilia, $allFamilias);
        $madreMapAfter = [];
        foreach ($allFamilias as $f) {
            $madreMapAfter[$f->codfamilia] = $f->madre;
        }

        $result = TarifaFamiliaReorder::plan($flatOrder, $madreMapAfter);
        if ($result['ok']) {
            $this->recalculate_chapters($result['chapters']);
        }

        $this->new_message('Familia degradada correctamente.');
        $this->load_familias_tree();
        $this->renderTbodyFragment($this->get_familias_flat());
    }

    /**
     * Toggle action dispatcher — delegates to kept-intact private toggle methods,
     * then wraps the result with renderRowFragment.
     */
    private function action_toggle(string $method): void
    {
        // Delegate to the kept-intact private toggle logic
        $result = $this->$method();

        if ($result['success']) {
            $this->new_message('Familia actualizada.');
            if (!$this->requireHtmx()) {
                // No-htmx fallback: full POST + PRG redirect (CRD-02).
                header('Location: ' . $this->listUrl());
                exit;
            }
            // Re-fetch the updated model for the row fragment
            $codfamilia = $_POST['codfamilia'] ?? '';
            $fam = $this->find_familia_row((string) $codfamilia);
            if ($fam) {
                $this->renderRowFragment($fam);
            } else {
                $this->noContentWithFlash();
            }
        } else {
            $this->new_error_msg($result['error'] ?? 'Error al guardar.');
            if (!$this->requireHtmx()) {
                header('Location: ' . $this->listUrl());
                exit;
            }
            $this->noContentWithFlash();
        }
    }

    /**
     * Duplicated petition guard — session-based deduplication.
     */
    private function isDuplicatedPetition(string $petitionId): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $key = 'petition_' . md5($petitionId);
        if (isset($_SESSION[$key])) {
            return true;
        }

        $_SESSION[$key] = true;
        // Clean up old petition keys periodically
        if (count($_SESSION) > 100) {
            foreach ($_SESSION as $k => $v) {
                if (strpos($k, 'petition_') === 0 && $v === true) {
                    unset($_SESSION[$k]);
                }
            }
        }

        return false;
    }

    /**
     * Seam: families are always edited for the tarifa selected on this page.
     */
    protected function caracteristica_visibility_scope(): string
    {
        return CaracteristicaResolver::SCOPE_FAMILIA;
    }

    /**
     * Feature-value resolver seam (read side of the familia visibility
     * toggles). Overridable so the toggle contract is unit-testable DB-free.
     *
     * @return CaracteristicaResolver
     */
    protected function caracteristica_resolver()
    {
        return new CaracteristicaResolver();
    }

    /**
     * Feature-value store seam (write side of the familia visibility toggles).
     * Overridable so the toggle contract is unit-testable DB-free.
     *
     * @return CaracteristicaValorStore
     */
    protected function caracteristica_store()
    {
        return new CaracteristicaValorStore();
    }

    // ------------------------------------------------------------------
    // Kept-intact private toggle methods (return structured arrays)
    // ------------------------------------------------------------------

    /**
     * Visibility toggles persist as **familia-scope feature values** for the
     * current tarifa (`caracteristicas-producto` CAR-09 / D3), never on the
     * `tarif_tarifa_familia` legacy visibility columns. The JSON contract is
     * unchanged: `{success, en_catalogo|en_tarifa}`.
     *
     * @param string $codigo one of `en_catalogo` / `en_tarifa`
     */
    private function ajax_toggle_familia_visibility(string $codigo)
    {
        $codfamilia = isset($_POST['codfamilia']) ? (string) $_POST['codfamilia'] : '';
        if (empty($codfamilia)) {
            return ['success' => false, 'error' => 'Código de familia no proporcionado'];
        }
        $fam = $this->find_familia_row($codfamilia);
        if (!$fam) {
            return ['success' => false, 'error' => 'Familia no encontrada'];
        }

        if ($this->family_structure) {
            $nuevo = !(bool) $fam->{$codigo};
            if (!$this->save_estructura_row($codfamilia, [$codigo => $nuevo])) {
                return [
                    'success' => false,
                    'error' => $this->familia_estructura_store()->lastError() ?: 'Error al guardar',
                ];
            }

            return ['success' => true, $codigo => $nuevo];
        }

        // Read the effective familia value for this tarifa, then flip it.
        $actual = (bool) $this->caracteristica_resolver()
            ->resolve_bool($codigo, (string) $this->codtarifa, null, (string) $codfamilia);

        $ok = $this->caracteristica_store()->assign_bool(
            $this->caracteristica_visibility_scope(),
            (string) $this->codtarifa,
            ['codfamilia' => (string) $codfamilia],
            $codigo,
            !$actual
        );

        if ($ok) {
            return ['success' => true, $codigo => !$actual];
        }

        return ['success' => false, 'error' => 'Error al guardar'];
    }

    private function ajax_toggle_catalogo()
    {
        return $this->ajax_toggle_familia_visibility('en_catalogo');
    }

    private function ajax_toggle_en_tarifa()
    {
        return $this->ajax_toggle_familia_visibility('en_tarifa');
    }

    private function ajax_toggle_activa()
    {
        $codfamilia = isset($_POST['codfamilia']) ? $_POST['codfamilia'] : '';
        if (empty($codfamilia)) {
            return ['success' => false, 'error' => 'Código de familia no proporcionado'];
        }
        $fam = $this->find_familia_row((string) $codfamilia);
        if (!$fam) {
            return ['success' => false, 'error' => 'Familia no encontrada'];
        }

        if ($this->family_structure) {
            $nuevo = !(bool) $fam->activa;
            if (!$this->save_estructura_row((string) $codfamilia, ['activa' => $nuevo])) {
                return [
                    'success' => false,
                    'error' => $this->familia_estructura_store()->lastError() ?: 'Error al guardar',
                ];
            }

            return ['success' => true, 'activa' => $nuevo];
        }

        $fam->activa = !$fam->activa;
        if ($fam->save()) {
            return ['success' => true, 'activa' => $fam->activa];
        }
        return ['success' => false, 'error' => 'Error al guardar'];
    }

    // ------------------------------------------------------------------
    // Tree / hierarchy helpers (kept intact)
    // ------------------------------------------------------------------

    private function get_siblings($familia)
    {
        $all = $this->all_familia_rows();
        $siblings = [];
        foreach ($all as $f) {
            if ($f->madre === $familia->madre) {
                $siblings[] = $f;
            }
        }
        return $siblings;
    }

    private function load_familias_tree()
    {
        if ($this->family_structure) {
            $this->familias_tree = $this->codlista_estructura === ''
                ? []
                : $this->build_tree($this->estructura_rows($this->b_solo_activas));

            return;
        }

        if (empty($this->codtarifa)) {
            return;
        }
        if ($this->b_solo_activas) {
            $all_familias = $this->tarifa_familia->all_activas_from_tarifa($this->codtarifa);
        } else {
            $all_familias = $this->tarifa_familia->all_by_capitulo($this->codtarifa);
        }
        $this->familias_tree = $this->build_tree($all_familias);
    }

    private function build_tree($familias, $madre = null, $nivel = 0)
    {
        $tree = [];
        foreach ($familias as $fam) {
            if ($fam->madre === $madre) {
                $fam->nivel_tree = $nivel;
                $fam->hijas_tree = $this->build_tree($familias, $fam->codfamilia, $nivel + 1);
                $tree[] = $fam;
            }
        }
        return $tree;
    }

    public function get_familias_flat()
    {
        return $this->flatten_tree($this->familias_tree);
    }

    private function flatten_tree($tree, $nivel = 0)
    {
        $flat = [];
        foreach ($tree as $fam) {
            $fam->nivel_visual = $nivel;
            $flat[] = $fam;
            if (!empty($fam->hijas_tree)) {
                $flat = array_merge($flat, $this->flatten_tree($fam->hijas_tree, $nivel + 1));
            }
        }
        return $flat;
    }

    // ------------------------------------------------------------------
    // Save / Add / Delete — fragment responses
    // ------------------------------------------------------------------

    /**
     * Save familia — on success: tbody fragment + fs:modal-close.
     * On failure: 204 + flash error. Non-htmx fallback: PRG redirect.
     */
    private function save_familia(): void
    {
        if ($this->family_structure) {
            $this->save_familia_estructura();

            return;
        }

        $codfamilia = isset($_POST['codfamilia']) ? trim($_POST['codfamilia']) : '';

        $fam = $this->tarifa_familia->get($this->codtarifa, $codfamilia);
        if (!$fam) {
            $this->new_error_msg('Familia no encontrada en esta tarifa.');
            if ($this->requireHtmx()) {
                $this->noContentWithFlash();
            } else {
                header('Location: ' . $this->listUrl());
                exit;
            }
            return;
        }

        $madre = isset($_POST['madre']) ? trim($_POST['madre']) : '';
        $fam->madre = empty($madre) ? null : $madre;

        $capitulo = isset($_POST['capitulo']) ? trim($_POST['capitulo']) : '';
        $fam->capitulo = empty($capitulo) ? '' : $capitulo;

        $fam->en_catalogo = isset($_POST['en_catalogo']);
        $fam->en_tarifa = isset($_POST['en_tarifa']);
        $fam->activa = isset($_POST['activa']);

        if (isset($_POST['descripcion'])) {
            require_once 'plugins/catalogo_core/model/core/familia.php';
            $familia_base_model = new \FSFramework\model\familia();
            $familia_base = $familia_base_model->get($codfamilia);

            if ($familia_base) {
                $familia_base->descripcion = trim($_POST['descripcion']);
                if (!$familia_base->save()) {
                    $this->new_error_msg('Error al guardar la descripción de la familia base.');
                    if ($this->requireHtmx()) {
                        $this->noContentWithFlash();
                    } else {
                        header('Location: ' . $this->listUrl());
                        exit;
                    }
                    return;
                }
            }
        }

        if ($fam->save()) {
            $etiquetas_raw = isset($_POST['etiquetas']) ? trim($_POST['etiquetas']) : null;
            if (null !== $etiquetas_raw) {
                $etiquetas = preg_split('/[,\n]+/', $etiquetas_raw);
                $visibilidad = [];
                if (isset($_POST['etiqueta_visible']) && is_array($_POST['etiqueta_visible'])) {
                    foreach ($_POST['etiqueta_visible'] as $tag_visible) {
                        $visibilidad[trim($tag_visible)] = true;
                    }
                }
                if (!$this->tarifa_etiqueta_familia->replace_etiquetas_familia($this->codtarifa, $fam->codfamilia, $etiquetas, $visibilidad)) {
                    $this->new_error_msg('Familia guardada, pero no se pudieron guardar las etiquetas.');
                }
            }

            $this->new_message('Familia guardada correctamente.');

            if ($this->requireHtmx()) {
                $this->load_familias_tree();
                $this->renderTbodyFragment($this->get_familias_flat(), ['events' => ['fs:modal-close' => []]]);
            } else {
                header('Location: ' . $this->listUrl());
                exit;
            }
        } else {
            $this->new_error_msg('Error al guardar la familia.');
            if ($this->requireHtmx()) {
                $this->noContentWithFlash();
            } else {
                header('Location: ' . $this->listUrl());
                exit;
            }
        }
    }

    /**
     * Canonical save of the family node for the list in context (flag on).
     *
     * Same response contract as the legacy save; the structure row is written
     * only when the cycle guards and the permission filter allow it. Tags have
     * no canonical counterpart in this change, so they are not written here.
     */
    private function save_familia_estructura(): void
    {
        $codfamilia = isset($_POST['codfamilia']) ? trim((string) $_POST['codfamilia']) : '';

        $fam = $codfamilia === '' ? false : $this->find_familia_row($codfamilia);
        if (!$fam) {
            $this->new_error_msg('Familia no encontrada en esta lista.');
            $this->respond_save_failure();

            return;
        }

        $madre = isset($_POST['madre']) ? trim((string) $_POST['madre']) : '';
        $madre = $madre === '' ? null : $madre;

        if (!$this->guard_estructura_ciclo($codfamilia, $madre)) {
            $this->respond_save_failure();

            return;
        }

        $values = [
            'madre' => $madre,
            'capitulo' => isset($_POST['capitulo']) ? trim((string) $_POST['capitulo']) : '',
            'en_catalogo' => isset($_POST['en_catalogo']),
            'en_tarifa' => isset($_POST['en_tarifa']),
            'activa' => isset($_POST['activa']),
        ];

        // The permission filter runs inside the store save, BEFORE persisting:
        // a denial leaves the structure AND the base description untouched.
        if (!$this->familia_estructura_store()->save($this->codlista_estructura, $codfamilia, $values)) {
            $this->new_error_msg(
                $this->familia_estructura_store()->lastError() ?: 'Error al guardar la familia.'
            );
            $this->respond_save_failure();

            return;
        }

        $this->estructura_por_familia = [];

        if (isset($_POST['descripcion']) && !$this->save_familia_descripcion($codfamilia, (string) $_POST['descripcion'])) {
            $this->new_error_msg('Error al guardar la descripción de la familia base.');
            $this->respond_save_failure();

            return;
        }

        if (isset($_POST['etiquetas'])) {
            $this->new_advice('Las etiquetas no se guardan con la estructura por lista activa.');
        }

        $this->new_message('Familia guardada correctamente.');
        if ($this->requireHtmx()) {
            $this->load_familias_tree();
            $this->renderTbodyFragment($this->get_familias_flat(), ['events' => ['fs:modal-close' => []]]);
        } else {
            header('Location: ' . $this->listUrl());
            exit;
        }
    }

    /**
     * Add familia to tariff by name: links an existing catalog familia on an
     * exact (case-insensitive) name match, otherwise creates the base catalog
     * familia first and then links it. Same response pattern as save.
     */
    private function add_familia_to_tarifa(): void
    {
        if ($this->family_structure) {
            $this->add_familia_to_lista();

            return;
        }

        $familia_nombre = isset($_POST['familia_nombre']) ? trim($_POST['familia_nombre']) : '';
        $madre = isset($_POST['madre']) ? trim($_POST['madre']) : '';

        if ($familia_nombre === '') {
            $this->new_error_msg('Debe indicar el nombre de la familia.');
            if ($this->requireHtmx()) {
                $this->noContentWithFlash();
            } else {
                header('Location: ' . $this->listUrl());
                exit;
            }
            return;
        }

        require_once 'plugins/catalogo_core/model/core/familia.php';
        $familia_base_model = new \FSFramework\model\familia();

        $existente = $this->find_familia_by_descripcion($familia_base_model->all(), $familia_nombre);
        if (null !== $existente) {
            $codfamilia = $existente->codfamilia;
            if ($this->tarifa_familia->get($this->codtarifa, $codfamilia)) {
                $this->new_error_msg('Esta familia ya existe en la tarifa.');
                if ($this->requireHtmx()) {
                    $this->noContentWithFlash();
                } else {
                    header('Location: ' . $this->listUrl());
                    exit;
                }
                return;
            }
        } else {
            $nueva = new \FSFramework\model\familia();
            $nueva->codfamilia = $this->suggest_familia_code(
                $familia_nombre,
                function ($candidate) use ($familia_base_model) {
                    return false !== $familia_base_model->get($candidate);
                }
            );
            if (null === $nueva->codfamilia) {
                $this->new_error_msg('No se pudo generar un código único para la familia.');
                if ($this->requireHtmx()) {
                    $this->noContentWithFlash();
                } else {
                    header('Location: ' . $this->listUrl());
                    exit;
                }
                return;
            }
            $nueva->descripcion = $familia_nombre;
            if (!$nueva->save()) {
                $this->new_error_msg('Error al crear la familia en el catálogo.');
                if ($this->requireHtmx()) {
                    $this->noContentWithFlash();
                } else {
                    header('Location: ' . $this->listUrl());
                    exit;
                }
                return;
            }
            $codfamilia = $nueva->codfamilia;
        }

        $tf = $this->tarifa_familia->add_familia_to_tarifa(
            $this->codtarifa,
            $codfamilia,
            empty($madre) ? null : $madre,
            isset($_POST['en_catalogo']),
            isset($_POST['en_tarifa']),
            isset($_POST['activa'])
        );

        if ($tf) {
            $this->new_message('Familia añadida a la tarifa correctamente.');
            if ($this->requireHtmx()) {
                $this->load_familias_tree();
                $this->renderTbodyFragment($this->get_familias_flat(), ['events' => ['fs:modal-close' => []]]);
            } else {
                header('Location: ' . $this->listUrl());
                exit;
            }
        } else {
            $this->new_error_msg('Error al añadir la familia.');
            if ($this->requireHtmx()) {
                $this->noContentWithFlash();
            } else {
                header('Location: ' . $this->listUrl());
                exit;
            }
        }
    }

    /**
     * Canonical "add familia" for the list in context (flag on): resolves or
     * creates the base catalog familia, then upserts its structure row.
     */
    private function add_familia_to_lista(): void
    {
        $familia_nombre = isset($_POST['familia_nombre']) ? trim((string) $_POST['familia_nombre']) : '';
        $madre = isset($_POST['madre']) ? trim((string) $_POST['madre']) : '';

        if ($familia_nombre === '') {
            $this->new_error_msg('Debe indicar el nombre de la familia.');
            $this->respond_save_failure();

            return;
        }

        require_once 'plugins/catalogo_core/model/core/familia.php';
        $familia_base_model = new \FSFramework\model\familia();

        $existente = $this->find_familia_by_descripcion($familia_base_model->all(), $familia_nombre);
        if (null !== $existente) {
            $codfamilia = (string) $existente->codfamilia;
            if ($this->find_familia_row($codfamilia)) {
                $this->new_error_msg('Esta familia ya existe en la lista.');
                $this->respond_save_failure();

                return;
            }
        } else {
            $nueva = new \FSFramework\model\familia();
            $nueva->codfamilia = $this->suggest_familia_code(
                $familia_nombre,
                function ($candidate) use ($familia_base_model) {
                    return false !== $familia_base_model->get($candidate);
                }
            );
            if (null === $nueva->codfamilia) {
                $this->new_error_msg('No se pudo generar un código único para la familia.');
                $this->respond_save_failure();

                return;
            }
            $nueva->descripcion = $familia_nombre;
            if (!$nueva->save()) {
                $this->new_error_msg('Error al crear la familia en el catálogo.');
                $this->respond_save_failure();

                return;
            }
            $codfamilia = (string) $nueva->codfamilia;
        }

        $madre = $madre === '' ? null : $madre;
        if (
            !$this->familia_estructura_store()->add(
                $this->codlista_estructura,
                $codfamilia,
                $madre,
                [
                    'en_catalogo' => isset($_POST['en_catalogo']),
                    'en_tarifa' => isset($_POST['en_tarifa']),
                    'activa' => isset($_POST['activa']),
                ]
            )
        ) {
            $this->new_error_msg(
                $this->familia_estructura_store()->lastError() ?: 'Error al añadir la familia.'
            );
            $this->respond_save_failure();

            return;
        }

        $this->estructura_por_familia = [];
        $this->new_message('Familia añadida a la lista correctamente.');
        if ($this->requireHtmx()) {
            $this->load_familias_tree();
            $this->renderTbodyFragment($this->get_familias_flat(), ['events' => ['fs:modal-close' => []]]);
        } else {
            header('Location: ' . $this->listUrl());
            exit;
        }
    }

    /**
     * Exact (case-insensitive, trimmed) descripcion match over the base
     * catalog familia list.
     *
     * @param object[] $familias
     * @return object|null the matched familia, or null when absent
     */
    private function find_familia_by_descripcion(array $familias, string $nombre): ?object
    {
        $needle = mb_strtolower(trim($nombre), 'UTF-8');
        foreach ($familias as $fam) {
            if (mb_strtolower(trim((string) $fam->descripcion), 'UTF-8') === $needle) {
                return $fam;
            }
        }

        return null;
    }

    /**
     * Derive a unique codfamilia (1-8 chars, A-Z0-9) from the descripcion.
     * $codeExists receives a candidate and must return true when already taken.
     *
     * @return string|null null when no unique candidate could be generated
     */
    private function suggest_familia_code(string $descripcion, callable $codeExists): ?string
    {
        $source = $descripcion;
        if (function_exists('iconv')) {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $descripcion);
            if (false !== $transliterated) {
                $source = $transliterated;
            }
        }

        $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper($source));
        if (null === $normalized || '' === $normalized) {
            $normalized = 'FAM';
        }

        $candidate = substr($normalized, 0, 8);
        if (!$codeExists($candidate)) {
            return $candidate;
        }

        for ($i = 1; $i <= 99; $i++) {
            $candidate = substr($normalized, 0, 6) . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            if (!$codeExists($candidate)) {
                return $candidate;
            }
        }

        for ($i = 0; $i < 9; $i++) {
            $candidate = substr($normalized, 0, 4) . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if (!$codeExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Delete familia — POST-only (GET delete retired per CRD-02).
     * Responds with tbody fragment (remaining rows).
     */
    private function delete_familia(): void
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar en esta página.');
            $this->noContentWithFlash();
            return;
        }

        if ($this->family_structure) {
            $this->delete_familia_estructura();

            return;
        }

        $codfamilia = $_POST['codfamilia'] ?? '';
        $fam = $this->tarifa_familia->get($this->codtarifa, $codfamilia);

        if ($fam) {
            $hijas = $this->tarifa_familia->hijas($this->codtarifa, $codfamilia);
            if (!empty($hijas)) {
                $this->new_error_msg('No se puede eliminar una familia con subfamilias. Elimina primero las subfamilias.');
                $this->noContentWithFlash();
                return;
            }

            $num_articulos = $this->count_tarifa_articulos($this->codtarifa, $codfamilia);
            if ($num_articulos > 0) {
                $this->new_error_msg('No se puede eliminar una familia con artículos asociados en esta tarifa.');
                $this->noContentWithFlash();
                return;
            }

            if ($fam->delete()) {
                $this->new_message('Familia eliminada de la tarifa correctamente.');
                $this->load_familias_tree();
                $this->renderTbodyFragment($this->get_familias_flat());
            } else {
                $this->new_error_msg('Error al eliminar la familia.');
                $this->noContentWithFlash();
            }
        } else {
            $this->new_error_msg('Familia no encontrada en esta tarifa.');
            $this->noContentWithFlash();
        }
    }

    /**
     * Canonical delete (flag on): removes the structure row of the list in
     * context only. The base `familias` row is never deleted.
     */
    private function delete_familia_estructura(): void
    {
        $codfamilia = (string) ($_POST['codfamilia'] ?? '');
        $fam = $codfamilia === '' ? false : $this->find_familia_row($codfamilia);

        if (!$fam) {
            $this->new_error_msg('Familia no encontrada en esta lista.');
            $this->noContentWithFlash();

            return;
        }

        if (!empty($this->hijas_de($codfamilia))) {
            $this->new_error_msg('No se puede eliminar una familia con subfamilias. Elimina primero las subfamilias.');
            $this->noContentWithFlash();

            return;
        }

        if ($this->count_estructura_articulos($this->codlista_estructura, $codfamilia) > 0) {
            $this->new_error_msg('No se puede eliminar una familia con artículos asociados en esta lista.');
            $this->noContentWithFlash();

            return;
        }

        if (!$this->familia_estructura_store()->delete($this->codlista_estructura, $codfamilia)) {
            $this->new_error_msg(
                $this->familia_estructura_store()->lastError() ?: 'Error al eliminar la familia.'
            );
            $this->noContentWithFlash();

            return;
        }

        $this->estructura_por_familia = [];
        $this->new_message('Familia eliminada de la lista correctamente.');
        $this->load_familias_tree();
        $this->renderTbodyFragment($this->get_familias_flat());
    }

    // ------------------------------------------------------------------
    // Public API surface (kept intact for macro/view contract)
    // ------------------------------------------------------------------

    public function count_articulos($codfamilia)
    {
        if ($this->family_structure) {
            return $this->count_estructura_articulos($this->codlista_estructura, (string) $codfamilia);
        }

        return $this->count_tarifa_articulos($this->codtarifa, $codfamilia);
    }

    private function count_tarifa_articulos($codtarifa, $codfamilia): int
    {
        $data = $this->db->select(
            'SELECT COUNT(*) AS total FROM ' . self::TARIFA_ARTICULO_TABLE
            . ' WHERE codtarifa = ? AND codfamilia = ?',
            [$codtarifa, $codfamilia]
        );
        return ($data && isset($data[0]['total'])) ? (int) $data[0]['total'] : 0;
    }

    public function get_posibles_madres($exclude_codfamilia = null)
    {
        $all = $this->family_structure
            ? $this->estructura_rows()
            : $this->tarifa_familia->all_from_tarifa($this->codtarifa);
        if (is_null($exclude_codfamilia)) {
            return $all;
        }
        $exclude = [$exclude_codfamilia];
        $this->get_descendants($exclude_codfamilia, $exclude);
        $result = [];
        foreach ($all as $fam) {
            if (!in_array($fam->codfamilia, $exclude)) {
                $result[] = $fam;
            }
        }
        return $result;
    }

    private function get_descendants($codfamilia, &$exclude)
    {
        $hijas = $this->hijas_de((string) $codfamilia);
        foreach ($hijas as $hija) {
            $exclude[] = $hija->codfamilia;
            $this->get_descendants($hija->codfamilia, $exclude);
        }
    }

    private function load_familias_disponibles()
    {
        if ($this->family_structure) {
            if ($this->codlista_estructura === '') {
                return;
            }
        } elseif (empty($this->codtarifa)) {
            return;
        }

        require_once 'plugins/catalogo_core/model/core/familia.php';
        $familia_base = new \familia();
        $todas = $familia_base->all();
        $en_tarifa = $this->family_structure
            ? $this->estructura_rows()
            : $this->tarifa_familia->all_from_tarifa($this->codtarifa);
        $codigos_en_tarifa = [];
        foreach ($en_tarifa as $tf) {
            $codigos_en_tarifa[] = $tf->codfamilia;
        }
        $this->familias_disponibles = [];
        foreach ($todas as $f) {
            if (!in_array($f->codfamilia, $codigos_en_tarifa)) {
                $this->familias_disponibles[] = $f;
            }
        }
    }

    public function get_tarifa_actual()
    {
        if ($this->family_structure || empty($this->codtarifa) || null === $this->tarifa) {
            return null;
        }
        return $this->tarifa->get($this->codtarifa);
    }

    public function get_etiquetas_familia_text($codfamilia)
    {
        if ($this->family_structure) {
            return '';
        }

        $tags = $this->tarifa_etiqueta_familia->get_etiquetas_familia($this->codtarifa, $codfamilia);
        return implode(', ', $tags);
    }

    public function get_etiquetas_familia_full($codfamilia)
    {
        if ($this->family_structure) {
            return [];
        }

        return $this->tarifa_etiqueta_familia->get_etiquetas_familia_full($this->codtarifa, $codfamilia);
    }

    // ------------------------------------------------------------------
    // Excel flows (untouched — early-return before fragment branch)
    // ------------------------------------------------------------------

    private function export_excel()
    {
        if ($this->family_structure) {
            $this->template = false;
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'La exportación Excel no está disponible con la estructura por lista activa.',
            ]);
            return;
        }

        $this->template = false;
        @ini_set('display_errors', 0);
        error_reporting(0);
        @ini_set('memory_limit', '256M');
        @set_time_limit(120);

        try {
            if (empty($this->codtarifa)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'No hay tarifa seleccionada']);
                return;
            }

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Familias');

            $headers = ['Codigo', 'Descripcion', 'Madre', 'Capitulo', 'En Catalogo', 'En Tarifa', 'Activa', 'Etiquetas'];
            foreach ($headers as $col => $header) {
                $cell = chr(65 + $col) . '1';
                $sheet->setCellValue($cell, $header);
            }

            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '5B9BD5']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '2E75B6']]]
            ];
            $sheet->getStyle('A1:H1')->applyFromArray($headerStyle);
            $sheet->getRowDimension(1)->setRowHeight(25);

            $sheet->getColumnDimension('A')->setWidth(18);
            $sheet->getColumnDimension('B')->setWidth(40);
            $sheet->getColumnDimension('C')->setWidth(18);
            $sheet->getColumnDimension('D')->setWidth(12);
            $sheet->getColumnDimension('E')->setWidth(14);
            $sheet->getColumnDimension('F')->setWidth(12);
            $sheet->getColumnDimension('G')->setWidth(10);
            $sheet->getColumnDimension('H')->setWidth(35);

            $familias = $this->tarifa_familia->all_jerarquico($this->codtarifa);
            $row = 2;

            foreach ($familias as $fam) {
                $etiquetas = $this->tarifa_etiqueta_familia->get_etiquetas_familia($this->codtarifa, $fam->codfamilia);
                $etiquetas_str = implode(', ', $etiquetas);

                $sheet->setCellValue('A' . $row, $fam->codfamilia);
                $sheet->setCellValue('B' . $row, $fam->descripcion);
                $sheet->setCellValue('C' . $row, $fam->madre ?? '');
                $sheet->setCellValue('D' . $row, $fam->capitulo);
                $sheet->setCellValue('E' . $row, $fam->en_catalogo ? 'TRUE' : 'FALSE');
                $sheet->setCellValue('F' . $row, $fam->en_tarifa ? 'TRUE' : 'FALSE');
                $sheet->setCellValue('G' . $row, $fam->activa ? 'TRUE' : 'FALSE');
                $sheet->setCellValue('H' . $row, $etiquetas_str);
                $row++;
            }

            if ($row > 2) {
                $dataRange = 'A2:H' . ($row - 1);
                $sheet->getStyle($dataRange)->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9E2F3']]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
                ]);
                for ($r = 2; $r < $row; $r++) {
                    if ($r % 2 == 0) {
                        $sheet->getStyle('A' . $r . ':H' . $r)->applyFromArray([
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F7FC']]
                        ]);
                    }
                }
            }

            $sheet->freezePane('A2');

            $tarifa = $this->tarifa->get($this->codtarifa);
            $tarifa_nombre = $tarifa ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $tarifa->nombre) : $this->codtarifa;
            $filename = 'familias_' . $tarifa_nombre . '_' . date('Y-m-d_His') . '.xlsx';

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: max-age=0');

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');

        } catch (\Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
        }
    }

    private function export_excel_template()
    {
        $this->template = false;
        @ini_set('display_errors', 0);
        error_reporting(0);

        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Plantilla Familias');

            $headers = ['Codigo', 'Descripcion', 'Madre', 'En Catalogo', 'En Tarifa', 'Activa', 'Etiquetas'];
            foreach ($headers as $col => $header) {
                $cell = chr(65 + $col) . '1';
                $sheet->setCellValue($cell, $header);
            }

            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '5B9BD5']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '2E75B6']]]
            ];
            $sheet->getStyle('A1:G1')->applyFromArray($headerStyle);
            $sheet->getRowDimension(1)->setRowHeight(25);

            $sheet->getColumnDimension('A')->setWidth(18);
            $sheet->getColumnDimension('B')->setWidth(40);
            $sheet->getColumnDimension('C')->setWidth(18);
            $sheet->getColumnDimension('D')->setWidth(14);
            $sheet->getColumnDimension('E')->setWidth(12);
            $sheet->getColumnDimension('F')->setWidth(10);
            $sheet->getColumnDimension('G')->setWidth(35);

            $sheet->setCellValue('A2', 'FAM001');
            $sheet->setCellValue('B2', 'Familia Principal');
            $sheet->setCellValue('C2', '');
            $sheet->setCellValue('D2', 'TRUE');
            $sheet->setCellValue('E2', 'TRUE');
            $sheet->setCellValue('F2', 'TRUE');
            $sheet->setCellValue('G2', 'etiqueta1, etiqueta2');

            $sheet->setCellValue('A3', 'FAM002');
            $sheet->setCellValue('B3', 'Subfamilia de FAM001');
            $sheet->setCellValue('C3', 'FAM001');
            $sheet->setCellValue('D3', 'TRUE');
            $sheet->setCellValue('E3', 'FALSE');
            $sheet->setCellValue('F3', 'TRUE');
            $sheet->setCellValue('G3', '');

            $sheet->getStyle('A2:G3')->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => '999999']]
            ]);

            $sheet->setCellValue('A5', 'INSTRUCCIONES:');
            $sheet->setCellValue('A6', '- La columna "Codigo" es obligatoria y debe contener el código de la familia.');
            $sheet->setCellValue('A7', '- La columna "Madre" debe contener el código de la familia padre (vacío para familias raíz).');
            $sheet->setCellValue('A8', '- Los capítulos se reasignan automáticamente según el orden de las filas y la jerarquía.');
            $sheet->setCellValue('A9', '- Las etiquetas se separan por comas.');
            $sheet->setCellValue('A10', '- Valores válidos para En Catalogo/En Tarifa/Activa: TRUE, FALSE, SI, NO, 1, 0');
            $sheet->setCellValue('A11', '- Elimina las filas de ejemplo antes de importar.');
            $sheet->getStyle('A5')->getFont()->setBold(true);
            $sheet->getStyle('A6:A11')->getFont()->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF666666'));

            $sheet->freezePane('A2');

            $filename = 'plantilla_importacion_familias.xlsx';

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: max-age=0');

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');

        } catch (\Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
        }
    }

    private function import_excel_chunk()
    {
        if ($this->family_structure) {
            $this->template = false;
            @ini_set('display_errors', 0);
            error_reporting(0);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'La importación Excel no está disponible con la estructura por lista activa.',
            ]);
            return;
        }

        $this->template = false;
        @ini_set('display_errors', 0);
        error_reporting(0);
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        header('Content-Type: application/json');

        try {
            require_once 'base/fs_chunked_upload.php';
            $temp_dir = sys_get_temp_dir() . '/tarif_familias_import/';
            $uploader = new \fs_chunked_upload($temp_dir, ['xlsx', 'xls'], 50);
            $result = $uploader->handle_chunk();

            if (!empty($result['complete']) && !empty($result['file_path'])) {
                $codtarifa = isset($_REQUEST['codtarifa']) ? trim($_REQUEST['codtarifa']) : $this->codtarifa;
                if (empty($codtarifa)) {
                    @unlink($result['file_path']);
                    echo json_encode(['success' => false, 'error' => 'No hay tarifa seleccionada']);
                    return;
                }
                $process_result = $this->process_excel_file($result['file_path'], $codtarifa);
                @unlink($result['file_path']);
                echo json_encode($process_result);
                return;
            }

            echo json_encode($result);

        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
        }
    }

    private function process_excel_file($file_path, $codtarifa)
    {
        try {
            $spreadsheet = IOFactory::load($file_path);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();
            $highestCol = $sheet->getHighestColumn();
            $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);

            if ($highestRow < 2) {
                return ['success' => false, 'error' => 'El archivo está vacío (no tiene datos después del encabezado)'];
            }

            $header_map = [];
            $known_headers = [
                'codigo' => 'codigo', 'codfamilia' => 'codigo',
                'descripcion' => 'descripcion', 'nombre' => 'descripcion',
                'madre' => 'madre', 'padre' => 'madre', 'parent' => 'madre',
                'en catalogo' => 'en_catalogo', 'encatalogo' => 'en_catalogo', 'catalogo' => 'en_catalogo',
                'en tarifa' => 'en_tarifa', 'entarifa' => 'en_tarifa',
                'activa' => 'activa', 'activo' => 'activa', 'active' => 'activa',
                'etiquetas' => 'etiquetas', 'tags' => 'etiquetas', 'labels' => 'etiquetas'
            ];

            for ($col = 1; $col <= $highestColIndex; $col++) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $header_value = trim($sheet->getCell($colLetter . '1')->getValue() ?? '');
                $header_lower = mb_strtolower($header_value);
                if (isset($known_headers[$header_lower])) {
                    $header_map[$colLetter] = $known_headers[$header_lower];
                }
            }

            if (!in_array('codigo', $header_map)) {
                return ['success' => false, 'error' => 'No se ha encontrado la columna "Codigo" en el encabezado'];
            }

            require_once 'plugins/catalogo_core/model/core/familia.php';

            $familias_data = [];
            $orden_importacion = [];

            for ($row = 2; $row <= $highestRow; $row++) {
                $row_data = [];
                foreach ($header_map as $colLetter => $field_name) {
                    $value = $sheet->getCell($colLetter . $row)->getValue();
                    if ($value !== null) {
                        $row_data[$field_name] = trim((string) $value);
                    }
                }
                if (empty($row_data['codigo'])) {
                    continue;
                }
                $codfamilia = $row_data['codigo'];
                $familias_data[$codfamilia] = $row_data;
                $orden_importacion[] = $codfamilia;
            }

            if (empty($familias_data)) {
                return ['success' => false, 'error' => 'No se encontraron familias válidas en el archivo'];
            }

            $stats = [
                'total' => count($familias_data),
                'actualizados' => 0, 'creados' => 0, 'errores' => 0, 'sin_cambios' => 0,
                'detalles_errores' => []
            ];

            foreach ($familias_data as $codfamilia => $data) {
                $this->process_familia_row($codtarifa, $codfamilia, $data, $stats);
            }

            $this->recalculate_all_capitulos($codtarifa, $orden_importacion, $familias_data);

            return ['success' => true, 'stats' => $stats];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Error al procesar Excel: ' . $e->getMessage()];
        }
    }

    private function process_familia_row($codtarifa, $codfamilia, $data, &$stats)
    {
        $updated = false;
        $created = false;

        $familia_base_model = new \FSFramework\model\familia();
        $familia_base = $familia_base_model->get($codfamilia);

        if (!$familia_base) {
            $familia_base = new \FSFramework\model\familia();
            $familia_base->codfamilia = $codfamilia;
            $familia_base->descripcion = $data['descripcion'] ?? $codfamilia;
            if (!$familia_base->save()) {
                $stats['errores']++;
                if (count($stats['detalles_errores']) < 50) {
                    $stats['detalles_errores'][] = 'Error al crear familia base: ' . $codfamilia;
                }
                return false;
            }
            $created = true;
        } elseif (isset($data['descripcion']) && $data['descripcion'] !== '') {
            if ($familia_base->descripcion !== $data['descripcion']) {
                $familia_base->descripcion = $data['descripcion'];
                $familia_base->save();
                $updated = true;
            }
        }

        $fam_tarifa = $this->tarifa_familia->get($codtarifa, $codfamilia);

        if (!$fam_tarifa) {
            $fam_tarifa = new tarif_tarifa_familia();
            $fam_tarifa->codtarifa = $codtarifa;
            $fam_tarifa->codfamilia = $codfamilia;
            $fam_tarifa->en_catalogo = true;
            $fam_tarifa->en_tarifa = false;
            $fam_tarifa->activa = true;
            $fam_tarifa->capitulo = '';
            $created = true;
        }

        if (isset($data['madre'])) {
            $nueva_madre = empty($data['madre']) ? null : $data['madre'];
            if ($fam_tarifa->madre !== $nueva_madre) {
                $fam_tarifa->madre = $nueva_madre;
                $updated = true;
            }
        }

        if (isset($data['en_catalogo'])) {
            $val = $this->parse_bool_value($data['en_catalogo']);
            if ($val !== null && $fam_tarifa->en_catalogo !== $val) {
                $fam_tarifa->en_catalogo = $val;
                $updated = true;
            }
        }

        if (isset($data['en_tarifa'])) {
            $val = $this->parse_bool_value($data['en_tarifa']);
            if ($val !== null && $fam_tarifa->en_tarifa !== $val) {
                $fam_tarifa->en_tarifa = $val;
                $updated = true;
            }
        }

        if (isset($data['activa'])) {
            $val = $this->parse_bool_value($data['activa']);
            if ($val !== null && $fam_tarifa->activa !== $val) {
                $fam_tarifa->activa = $val;
                $updated = true;
            }
        }

        if (!$fam_tarifa->save()) {
            $stats['errores']++;
            if (count($stats['detalles_errores']) < 50) {
                $stats['detalles_errores'][] = 'Error al guardar familia en tarifa: ' . $codfamilia;
            }
            return false;
        }

        if (isset($data['etiquetas'])) {
            $etiquetas = array_filter(array_map('trim', preg_split('/[,;]+/', $data['etiquetas'])));
            $this->tarifa_etiqueta_familia->replace_etiquetas_familia($codtarifa, $codfamilia, $etiquetas);
            if (!empty($etiquetas)) {
                $updated = true;
            }
        }

        if ($created) {
            $stats['creados']++;
        } elseif ($updated) {
            $stats['actualizados']++;
        } else {
            $stats['sin_cambios']++;
        }

        return true;
    }

    private function parse_bool_value($value)
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['true', 'si', 'sí', '1', 'yes', 'verdadero', 'v'])) {
            return true;
        }
        if (in_array($value, ['false', 'no', '0', 'falso', 'f'])) {
            return false;
        }
        return null;
    }

    private function recalculate_all_capitulos($codtarifa, $orden_importacion, $familias_data)
    {
        $hijas_por_madre = [];
        foreach ($orden_importacion as $codfamilia) {
            $madre = isset($familias_data[$codfamilia]['madre']) ? $familias_data[$codfamilia]['madre'] : '';
            if (empty($madre)) {
                $madre = '__ROOT__';
            }
            if (!isset($hijas_por_madre[$madre])) {
                $hijas_por_madre[$madre] = [];
            }
            $hijas_por_madre[$madre][] = $codfamilia;
        }

        if (isset($hijas_por_madre['__ROOT__'])) {
            $chapter_num = 1;
            foreach ($hijas_por_madre['__ROOT__'] as $codfamilia) {
                $this->assign_capitulo_recursive($codtarifa, $codfamilia, (string) $chapter_num, $hijas_por_madre);
                $chapter_num++;
            }
        }

        $familias_procesadas = $this->get_all_processed_familias($hijas_por_madre);
        $chapter_num = isset($hijas_por_madre['__ROOT__']) ? count($hijas_por_madre['__ROOT__']) + 1 : 1;

        foreach ($orden_importacion as $codfamilia) {
            if (!in_array($codfamilia, $familias_procesadas)) {
                $this->assign_capitulo_recursive($codtarifa, $codfamilia, (string) $chapter_num, $hijas_por_madre);
                $chapter_num++;
            }
        }
    }

    private function get_all_processed_familias($hijas_por_madre)
    {
        $procesadas = [];
        if (isset($hijas_por_madre['__ROOT__'])) {
            foreach ($hijas_por_madre['__ROOT__'] as $codfamilia) {
                $this->collect_descendants($codfamilia, $hijas_por_madre, $procesadas);
            }
        }
        return $procesadas;
    }

    private function collect_descendants($codfamilia, $hijas_por_madre, &$procesadas)
    {
        $procesadas[] = $codfamilia;
        if (isset($hijas_por_madre[$codfamilia])) {
            foreach ($hijas_por_madre[$codfamilia] as $hija) {
                $this->collect_descendants($hija, $hijas_por_madre, $procesadas);
            }
        }
    }

    private function assign_capitulo_recursive($codtarifa, $codfamilia, $capitulo, $hijas_por_madre)
    {
        $fam = $this->tarifa_familia->get($codtarifa, $codfamilia);
        if ($fam) {
            $fam->capitulo = $capitulo;
            $fam->save();
        }
        if (isset($hijas_por_madre[$codfamilia])) {
            $child_num = 1;
            foreach ($hijas_por_madre[$codfamilia] as $hija_codfamilia) {
                $child_capitulo = $capitulo . '.' . $child_num;
                $this->assign_capitulo_recursive($codtarifa, $hija_codfamilia, $child_capitulo, $hijas_por_madre);
                $child_num++;
            }
        }
    }
}
