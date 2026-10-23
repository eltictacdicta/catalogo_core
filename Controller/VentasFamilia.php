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

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_familia_estructura.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoOptions.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/FamiliaTreeGuard.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use FSFramework\Event\FSEventDispatcher;
use FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent;
use FSFramework\Plugins\catalogo_core\Services\CatalogoOptions;
use FSFramework\Plugins\catalogo_core\Services\FamiliaTreeGuard;
use Symfony\Component\HttpFoundation\Request;

class VentasFamilia extends PageController
{
    public ?\FSFramework\model\familia $familia = null;
    public array $articulos = [];
    public array $madres = [];
    public bool $allow_delete = false;

    /** Family-structure capability flag: hides every per-list control when off. */
    public bool $family_structure = false;

    /** Selected price list for the per-list structure panel. */
    public string $codlista = '';

    /** @var list<\FSFramework\model\catalogo_lista_precio> active lists only */
    public array $listas_estructura = [];

    /** @var array<string,\FSFramework\model\catalogo_familia_estructura|object> codfamilia => row */
    public array $estructura_por_lista = [];

    /** @var list<object> nested tree nodes with derived depth */
    public array $estructura_tree = [];

    public function __construct()
    {
        parent::__construct('VentasFamilia');
        $this->setTemplate('ventas_familia');
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on($this->getPageData()['name']);
        $this->loadExtensions();
    }

    public function getPageData(): array
    {
        return [
            'name' => 'ventas_familia',
            'title' => 'Familia',
            'menu' => 'catalogo',
            'showonmenu' => false,
            'ordernum' => 120,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $this->familia = new \FSFramework\model\familia();
        $this->family_structure = (new CatalogoOptions())->familyStructureEnabled();

        $cod = $this->request->query->get('cod');
        if ($cod !== null && $cod !== '') {
            $fam = $this->familia->get((string) $cod);
            if ($fam) {
                $this->familia = $fam;
            } else {
                $this->new_error_msg('Familia no encontrada.');
                return;
            }
        }

        if ($this->request->request->has('scodfamilia')) {
            $this->editarFamilia($this->request);
        } elseif ($this->request->request->has('delete') && $this->allow_delete) {
            $this->eliminarFamilia($this->request);
        } elseif ($this->request->request->has('save_estructura')) {
            $this->guardarEstructuraFamilia();
        }

        if ($this->familia->codfamilia !== null) {
            $offset = $this->request->query->getInt('offset', 0);
            $this->articulos = $this->familia->get_articulos($offset);
        }

        $this->madres = $this->familia->madres();
        $this->loadFamiliaEstructura();
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

    private function editarFamilia(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $codfamilia = (string) $request->request->get('scodfamilia', '');
        $fam = $this->familia->get($codfamilia);

        if (!$fam) {
            $fam = new \FSFramework\model\familia();
            $fam->codfamilia = $codfamilia;
        }

        $fam->descripcion = (string) $request->request->get('sdescripcion', '');

        $madre = $request->request->get('smadre');
        $madre = ($madre !== null && $madre !== '') ? (string) $madre : null;

        if ($madre !== null && $codfamilia !== '') {
            try {
                $this->assertFamilyAcyclic($codfamilia, $madre);
            } catch (\RuntimeException $e) {
                $this->new_error_msg($e->getMessage());
                return;
            }
        }

        $fam->madre = $madre;

        if ($fam->save()) {
            $this->new_message('Familia ' . $fam->descripcion . ' guardada correctamente.');
            $this->familia = $fam;
            return;
        }

        $this->new_error_msg('¡Imposible guardar la familia!');
    }

    private function eliminarFamilia(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar en esta página.');
            return;
        }

        $cod = (string) $request->request->get('delete', '');
        $fam = $this->familia->get($cod);

        if (!$fam) {
            $this->new_error_msg('¡Familia no encontrada!');
            return;
        }

        if ($fam->delete()) {
            $this->new_message('Familia ' . $fam->descripcion . ' eliminada correctamente.');
            $this->redirect('tarif_familias');
            return;
        }

        $this->new_error_msg('¡Imposible eliminar la familia!');
    }

    // ------------------------------------------------------------------
    // Per-list family structure (tasks 3.1/3.2)
    // ------------------------------------------------------------------

    /**
     * Loads the active price lists, the selected list and the batch of
     * structure rows for that list. Flag-off keeps every collection empty and
     * performs zero queries (inert capability).
     */
    private function loadFamiliaEstructura(): void
    {
        $this->family_structure = (new CatalogoOptions())->familyStructureEnabled();
        $this->codlista = '';
        $this->listas_estructura = [];
        $this->estructura_por_lista = [];
        $this->estructura_tree = [];

        if (!$this->family_structure) {
            return;
        }

        $listaModel = $this->lista_precio_model();
        foreach ($listaModel->all() as $lista) {
            if (!empty($lista->activa)) {
                $this->listas_estructura[] = $lista;
            }
        }

        $activeCodes = array_map(
            static fn ($lista): string => (string) $lista->codlista,
            $this->listas_estructura
        );

        $requested = trim((string) $this->request->query->get('codlista', ''));
        if ($requested !== '' && in_array($requested, $activeCodes, true)) {
            $this->codlista = $requested;
        } elseif ($this->listas_estructura !== []) {
            $default = $listaModel->get_default();
            $defaultCode = $default ? (string) $default->codlista : '';
            $this->codlista = in_array($defaultCode, $activeCodes, true)
                ? $defaultCode
                : (string) $this->listas_estructura[0]->codlista;
        }

        if ($this->codlista === '') {
            return;
        }

        foreach ($this->estructura_model()->all_from_list($this->codlista) as $row) {
            $this->estructura_por_lista[(string) $row->codfamilia] = $row;
        }

        $familias = $this->familia->all();
        $this->estructura_tree = $this->buildEstructuraTree(is_array($familias) ? $familias : []);
    }

    /**
     * Builds a nested tree from the base family list. Depth is derived from
     * the `madre` chain (never stored); siblings are ordered by the per-list
     * `orden` when a structure row exists, then by description.
     *
     * @param list<object> $familias
     * @return list<object>
     */
    private function buildEstructuraTree(array $familias, ?string $madre = null, int $nivel = 0): array
    {
        $nodes = [];
        foreach ($familias as $fam) {
            $famMadre = $fam->madre ?? null;
            $famMadre = ($famMadre === null || $famMadre === '') ? null : (string) $famMadre;
            if ($famMadre !== $madre) {
                continue;
            }

            $codfamilia = (string) $fam->codfamilia;
            $row = $this->estructura_por_lista[$codfamilia] ?? null;

            $node = new \stdClass();
            $node->codfamilia = $codfamilia;
            $node->descripcion = (string) ($fam->descripcion ?? '');
            $node->madre = $famMadre;
            $node->nivel = (string) ($fam->nivel ?? '');
            $node->nivel_visual = $nivel;
            $node->estructura_persistida = $row !== null;
            $node->estructura = $row ?: (object) [
                'codlista' => $this->codlista,
                'codfamilia' => $codfamilia,
                'madre' => $famMadre,
                'capitulo' => '',
                'nivel' => (string) ($fam->nivel ?? ''),
                'en_catalogo' => true,
                'en_tarifa' => false,
                'activa' => true,
                'orden' => 0,
            ];
            $node->hijas_tree = [];
            $nodes[] = $node;
        }

        usort($nodes, static function (object $a, object $b): int {
            $aOrden = $a->estructura_persistida ? (int) $a->estructura->orden : PHP_INT_MAX;
            $bOrden = $b->estructura_persistida ? (int) $b->estructura->orden : PHP_INT_MAX;
            if ($aOrden !== $bOrden) {
                return $aOrden <=> $bOrden;
            }

            return strcasecmp($a->descripcion, $b->descripcion);
        });

        foreach ($nodes as $node) {
            $node->hijas_tree = $this->buildEstructuraTree($familias, $node->codfamilia, $nivel + 1);
        }

        return $nodes;
    }

    /**
     * Upserts one structure row for the selected list. Flag-off aborts before
     * any read/write; a denied permission filter also blocks persistence.
     */
    private function guardarEstructuraFamilia(): void
    {
        if (!$this->family_structure) {
            $this->new_error_msg('La estructura por lista no está habilitada.');
            return;
        }

        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $codfamilia = trim((string) $this->request->request->get('estructura_codfamilia', ''));
        $codlista = trim((string) $this->request->request->get('estructura_codlista', $this->codlista));
        $activeCodes = array_map(
            static fn ($lista): string => (string) $lista->codlista,
            $this->listas_estructura
        );

        if ($codfamilia === '' || !in_array($codlista, $activeCodes, true)) {
            $this->new_error_msg('Familia o lista de precios no válida.');
            return;
        }

        $filterEvent = self::dispatchFamilyStructureFilter($codfamilia, (string) $this->user->nick, $codlista);
        if ($filterEvent === null || !$filterEvent->isAllowed()) {
            $this->new_error_msg('No tienes permisos para editar la estructura de familias: '
                . ($filterEvent !== null ? $filterEvent->getDenialReason() : 'error del filtro'));
            return;
        }

        $row = $this->estructura_model()->get($codlista, $codfamilia);
        if (!$row) {
            $row = $this->nueva_estructura();
            $row->codlista = $codlista;
            $row->codfamilia = $codfamilia;
        }

        $row->capitulo = trim((string) $this->request->request->get('estructura_capitulo', ''));
        $row->nivel = trim((string) $this->request->request->get('estructura_nivel', ''));
        $row->orden = (int) $this->request->request->get('estructura_orden', 0);
        $row->en_catalogo = $this->request->request->has('estructura_en_catalogo');
        $row->en_tarifa = $this->request->request->has('estructura_en_tarifa');
        $row->activa = $this->request->request->has('estructura_activa');

        if ($row->save()) {
            $this->new_message('Estructura de la familia ' . $codfamilia . ' guardada correctamente.');
            return;
        }

        $this->new_error_msg('¡Imposible guardar la estructura de la familia!');
    }

    /**
     * Neutral permission filter for structure writes (design D6). Reuses the
     * frozen event contract: zero listeners resolve allow.
     */
    public static function dispatchFamilyStructureFilter(
        string $codfamilia,
        string $nick,
        string $codlista = ''
    ): ?ArticlePermissionFilterEvent {
        $filterEvent = new ArticlePermissionFilterEvent(
            $codfamilia,
            ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            $nick,
            $codlista
        );

        try {
            FSEventDispatcher::getInstance()->dispatch($filterEvent, ArticlePermissionFilterEvent::NAME);
        } catch (\Throwable $e) {
            error_log('ArticlePermissionFilterEvent listener error: ' . $e->getMessage());
            $filterEvent->deny('permission filter error');
        }

        return $filterEvent;
    }

    /**
     * Cycle rejection seam (task 3.3). Kept overridable so the controller save
     * path is testable without a database.
     *
     * @throws \RuntimeException when the re-parent would close a cycle
     */
    protected function assertFamilyAcyclic(string $codfamilia, string $newMadre): void
    {
        FamiliaTreeGuard::assertAcyclic($this->db, $codfamilia, $newMadre);
    }

    /** @return list<object> view-facing tree accessor */
    public function getEstructuraTree(): array
    {
        return $this->estructura_tree;
    }

    // ---- overridable model factories (test seam) ----

    protected function lista_precio_model(): \FSFramework\model\catalogo_lista_precio
    {
        return new \FSFramework\model\catalogo_lista_precio();
    }

    protected function estructura_model(): \FSFramework\model\catalogo_familia_estructura
    {
        return new \FSFramework\model\catalogo_familia_estructura();
    }

    protected function nueva_estructura(): \FSFramework\model\catalogo_familia_estructura
    {
        return new \FSFramework\model\catalogo_familia_estructura();
    }
}
