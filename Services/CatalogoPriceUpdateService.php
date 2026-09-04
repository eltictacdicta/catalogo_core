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

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Batch price update engine for the multi-tariff lists (multitarifa task 4.1,
 * R-BU-002 / R-BU-003):
 *
 *  - matchArticles(): AND-combined filter resolution over
 *    codlista (required) + codfamilia (+ incluir_subfamilias resolved
 *    recursively through the existing familia hierarchy) + codgrupo
 *    (catalogo_grupo_articulos membership).
 *  - preview(): computes current (effective) and prospective price per
 *    matched article, persisting NOTHING.
 *  - apply(): persists exactly the previewed rows for the target list via
 *    catalogo_articulo_precio::set_precio() — NEVER rewrites articulos.pvp.
 *
 * Formula: round(p * (1 + pct/100), 2) half-up, where p is the effective
 * price (per-list row when present, otherwise the default-list fallback).
 */
final class CatalogoPriceUpdateService
{
    /** @var CatalogoPriceResolver|null */
    private $resolver = null;

    /** @var \FSFramework\model\familia|null */
    private $familiaModel = null;

    /** @var \FSFramework\model\articulo|null */
    private $articuloModel = null;

    /** @var \FSFramework\model\catalogo_grupo_articulo|null */
    private $grupoArticuloModel = null;

    /** @var \FSFramework\model\catalogo_articulo_precio|null */
    private $precioModel = null;

    /**
     * Resolve the AND-combined filter set to a unique list of references.
     * A missing codlista fails closed (no matches).
     *
     * filters keys: codlista (required), codfamilia (optional),
     * incluir_subfamilias (optional bool), codgrupo (optional int id).
     *
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    public function matchArticles(array $filters): array
    {
        $codlista = trim((string) ($filters['codlista'] ?? ''));
        if ($codlista === '') {
            return [];
        }

        $sets = [];

        $codfamilia = trim((string) ($filters['codfamilia'] ?? ''));
        if ($codfamilia !== '') {
            $recursive = (bool) ($filters['incluir_subfamilias'] ?? false);
            $codes = $this->familyTreeCodes($codfamilia, $recursive);
            $sets[] = $this->articlesFromFamilies($codes);
        }

        $codgrupo = $filters['codgrupo'] ?? null;
        if ($codgrupo !== null && (string) $codgrupo !== '') {
            $groupRefs = [];
            foreach ($this->grupoArticuloModel()->all_from_grupo((int) $codgrupo) as $member) {
                $groupRefs[] = (string) $member->referencia;
            }
            $sets[] = $groupRefs;
        }

        if (empty($sets)) {
            return [];
        }

        // AND-combine all optional filters (intersection), preserving order.
        $intersection = $sets[0];
        foreach (array_slice($sets, 1) as $set) {
            $intersection = array_values(array_intersect($intersection, $set));
        }

        return array_values(array_unique($intersection));
    }

    /**
     * Compute the preview rows for the filter set and percentage. No write.
     * Returns rows: referencia, precio_actual, precio_nuevo.
     *
     * @param array<string, mixed> $filters
     * @return list<array{referencia: string, precio_actual: float, precio_nuevo: float}>
     */
    public function preview(array $filters, float $pct): array
    {
        $codlista = trim((string) ($filters['codlista'] ?? ''));
        $rows = [];

        foreach ($this->matchArticles($filters) as $referencia) {
            $current = $this->resolver()->effective($referencia, $codlista);
            if ($current === null) {
                // No effective price on the target list: nothing to update.
                continue;
            }

            $rows[] = [
                'referencia' => $referencia,
                'precio_actual' => $current,
                'precio_nuevo' => $this->computePrice($current, $pct),
            ];
        }

        return $rows;
    }

    /**
     * Persist exactly the previewed rows for the target list. Returns
     * ['updated' => int, 'failed' => list<string>].
     *
     * @param list<array{referencia: string, precio_nuevo: float}> $rows
     * @return array{updated: int, failed: list<string>}
     */
    public function apply(array $rows, string $codlista): array
    {
        $codlista = trim($codlista);
        $updated = 0;
        $failed = [];

        if ($codlista === '') {
            return ['updated' => 0, 'failed' => []];
        }

        foreach ($rows as $row) {
            $referencia = (string) $row['referencia'];
            $ok = $this->precioModel()->set_precio($referencia, $codlista, (float) $row['precio_nuevo']);
            if ($ok) {
                $updated++;
            } else {
                $failed[] = $referencia;
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /** round(p * (1 + pct/100), 2) — half-up (R-BU-003). */
    public function computePrice(float $p, float $pct): float
    {
        return round($p * (1 + $pct / 100), 2, PHP_ROUND_HALF_UP);
    }

    // ---- filter helpers ----

    /**
     * Family codes for the filter: the family itself plus (when recursive)
     * every descendant, resolved via the existing familia::hijas() hierarchy
     * (no model change, per design D6).
     *
     * @return list<string>
     */
    private function familyTreeCodes(string $codfamilia, bool $recursive): array
    {
        $codes = [$codfamilia];
        if ($recursive) {
            $this->collectChildren($codfamilia, $codes);
        }

        return $codes;
    }

    /**
     * @param list<string> $codes
     */
    private function collectChildren(string $codmadre, array &$codes): void
    {
        foreach ($this->familiaModel()->hijas($codmadre) as $hija) {
            $code = (string) $hija->codfamilia;
            if (!in_array($code, $codes, true)) {
                $codes[] = $code;
                $this->collectChildren($code, $codes);
            }
        }
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private function articlesFromFamilies(array $codes): array
    {
        $refs = [];
        foreach ($codes as $code) {
            foreach ($this->articuloModel()->all_from_familia($code) as $articulo) {
                $refs[] = (string) $articulo->referencia;
            }
        }

        return array_values(array_unique($refs));
    }

    // ---- lazy wiring ----

    private function resolver(): CatalogoPriceResolver
    {
        if ($this->resolver === null) {
            $this->resolver = new CatalogoPriceResolver();
        }

        return $this->resolver;
    }

    private function familiaModel(): \FSFramework\model\familia
    {
        if ($this->familiaModel === null) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
            $this->familiaModel = new \FSFramework\model\familia();
        }

        return $this->familiaModel;
    }

    private function articuloModel(): \FSFramework\model\articulo
    {
        if ($this->articuloModel === null) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
            $this->articuloModel = new \FSFramework\model\articulo();
        }

        return $this->articuloModel;
    }

    private function grupoArticuloModel(): \FSFramework\model\catalogo_grupo_articulo
    {
        if ($this->grupoArticuloModel === null) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_grupo_articulo.php';
            $this->grupoArticuloModel = new \FSFramework\model\catalogo_grupo_articulo();
        }

        return $this->grupoArticuloModel;
    }

    private function precioModel(): \FSFramework\model\catalogo_articulo_precio
    {
        if ($this->precioModel === null) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_precio.php';
            $this->precioModel = new \FSFramework\model\catalogo_articulo_precio();
        }

        return $this->precioModel;
    }

    // ---- @internal test setters (NOT production surface) ----

    /** @internal testing-only */
    public function setResolver(CatalogoPriceResolver $resolver): void
    {
        $this->resolver = $resolver;
    }

    /** @internal testing-only */
    public function setFamiliaModel(\FSFramework\model\familia $model): void
    {
        $this->familiaModel = $model;
    }

    /** @internal testing-only */
    public function setArticuloModel(\FSFramework\model\articulo $model): void
    {
        $this->articuloModel = $model;
    }

    /** @internal testing-only */
    public function setGrupoArticuloModel(\FSFramework\model\catalogo_grupo_articulo $model): void
    {
        $this->grupoArticuloModel = $model;
    }

    /** @internal testing-only */
    public function setPrecioModel(\FSFramework\model\catalogo_articulo_precio $model): void
    {
        $this->precioModel = $model;
    }
}
