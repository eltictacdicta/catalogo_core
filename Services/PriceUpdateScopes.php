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
 * A price update scope: WHICH rows a batch update touches (articles, ...).
 *
 * The scope owns row selection and its deterministic order. It never sees the
 * target: it receives no target code and no price book of the target, so the
 * same selection logic serves every destination.
 */
interface PriceUpdateScopeInterface
{
    public function code(): string;

    public function label(): string;

    /** Human label of the row key (e.g. the column header of the preview). */
    public function keyLabel(): string;

    /**
     * Rows selected by the filters, in the scope's canonical order. Empty
     * filters select nothing (fail closed).
     *
     * @param array<string, mixed> $filters
     * @return list<array{key: string, label: string}>
     */
    public function match(array $filters): array;

    /**
     * Price book that does NOT depend on the target, or null when the storage
     * is decided by the target (D1: the engine composes canonical-first).
     */
    public function canonicalBook(string $key): ?PriceBookInterface;
}

/**
 * Scope "articulos": articles selected by family (+ recursive subfamilies)
 * and/or article group, AND-combined. Storage depends on the target, so there
 * is no canonical book.
 */
final class ArticulosPriceScope implements PriceUpdateScopeInterface
{
    private const PAGE_FALLBACK = 50;

    /** @var \FSFramework\model\familia|null */
    private $familiaModel = null;

    /** @var \FSFramework\model\articulo|null */
    private $articuloModel = null;

    /** @var \FSFramework\model\catalogo_grupo_articulo|null */
    private $grupoArticuloModel = null;

    public function code(): string
    {
        return 'articulos';
    }

    public function label(): string
    {
        return 'Artículos';
    }

    public function keyLabel(): string
    {
        return 'Referencia';
    }

    public function canonicalBook(string $key): ?PriceBookInterface
    {
        return null;
    }

    /**
     * Filter keys: codfamilia (optional), incluir_subfamilias (optional bool),
     * codgrupo (optional int id). Any other key is ignored.
     *
     * @param array<string, mixed> $filters
     * @return list<array{key: string, label: string}>
     */
    public function match(array $filters): array
    {
        $labels = [];
        $sets = [];

        $codfamilia = trim((string) ($filters['codfamilia'] ?? ''));
        if ($codfamilia !== '') {
            $recursive = (bool) ($filters['incluir_subfamilias'] ?? false);
            $sets[] = $this->articlesFromFamilies($this->familyTreeCodes($codfamilia, $recursive), $labels);
        }

        $codgrupo = $filters['codgrupo'] ?? null;
        if ($codgrupo !== null && (string) $codgrupo !== '') {
            $groupRefs = [];
            foreach ($this->grupoArticuloModel()->all_from_grupo((int) $codgrupo) as $member) {
                $groupRefs[] = (string) $member->referencia;
            }
            $sets[] = $groupRefs;
        }

        if ($sets === []) {
            return [];
        }

        // AND-combine every filter (intersection).
        $selected = $sets[0];
        foreach (array_slice($sets, 1) as $set) {
            $selected = array_values(array_intersect($selected, $set));
        }
        $selected = array_values(array_unique($selected));

        // Deterministic order, independent of how families/groups were walked.
        usort($selected, static fn (string $a, string $b): int => strcmp($a, $b));

        $rows = [];
        foreach ($selected as $referencia) {
            $rows[] = ['key' => $referencia, 'label' => $labels[$referencia] ?? $referencia];
        }

        return $rows;
    }

    /**
     * The family itself plus (when recursive) every descendant, through the
     * existing familia::hijas() hierarchy.
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

    /** @param list<string> $codes */
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
     * Every article of the families, paginating until each one is exhausted:
     * articulo::all_from_familia() is capped by FS_ITEM_LIMIT per call.
     *
     * @param list<string> $codes
     * @param array<string, string> $labels reference => description (filled when known)
     * @return list<string>
     */
    private function articlesFromFamilies(array $codes, array &$labels): array
    {
        $limit = defined('FS_ITEM_LIMIT') && (int) FS_ITEM_LIMIT > 0 ? (int) FS_ITEM_LIMIT : self::PAGE_FALLBACK;
        $refs = [];

        foreach ($codes as $code) {
            $offset = 0;
            do {
                $batch = $this->articuloModel()->all_from_familia($code, $offset, $limit);
                $before = count($refs);
                foreach ($batch as $articulo) {
                    $referencia = (string) $articulo->referencia;
                    $refs[$referencia] = true;
                    $description = trim((string) ($articulo->descripcion ?? ''));
                    if ($description !== '') {
                        $labels[$referencia] = $description;
                    }
                }
                $offset += $limit;
                // A batch below the limit is the last one; a batch that adds
                // nothing new guards against a source that ignores the offset.
                $more = count($batch) >= $limit && count($refs) > $before;
            } while ($more);
        }

        return array_map('strval', array_keys($refs));
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
}

/**
 * Closed factory of scopes. The set of scopes is owned by catalogo_core (it is
 * not extensible by consumers); an unknown code resolves to null and the
 * caller fails closed.
 */
final class PriceUpdateScopes
{
    private const DEFAULT_CODE = 'articulos';

    public static function forCode(string $code): ?PriceUpdateScopeInterface
    {
        return match ($code) {
            'articulos' => new ArticulosPriceScope(),
            default => null,
        };
    }

    public static function defaultCode(): string
    {
        return self::DEFAULT_CODE;
    }
}
