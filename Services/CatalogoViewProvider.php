<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
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
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Read model behind the `ventas_catalogo_view` page (familias-jerarquia D5,
 * R-FJ-004).
 *
 * Renders one price list at a time: the family structure of the selected list
 * filtered to `activa` AND `en_catalogo`, plus the article assignments of that
 * same list filtered to `en_catalogo`. The list identity is taken ONLY from
 * `catalogo_listas_precio.codlista` (R-MT-002): legacy `tarif_*` tables are
 * never consulted here.
 *
 * Two deliberate properties:
 * - **Inert when disabled**: with `CatalogoOptions::familyStructureEnabled()`
 *   off the provider returns a disabled result and issues ZERO queries (D3
 *   lifecycle gate).
 * - **Double filtering**: the SQL already scopes by list and visibility, and
 *   the rows are re-validated in PHP. The second pass is cheap and keeps the
 *   view correct even if a driver or a test double hands back extra rows.
 *
 * Hierarchy: depth is derived from the mother chain (`madre` of the structure
 * row, falling back to `familias.madre`), never from the display `nivel`
 * string. A visible family whose mother is hidden or missing is promoted to a
 * root node so it never disappears; cyclic leftovers are promoted the same
 * way and traversal keeps a visited set (D5, no infinite recursion).
 *
 * Article assignments whose family has no visible node are not rendered: a node
 * hidden for this list hides its articles with it.
 */
final class CatalogoViewProvider
{
    private const LIST_TABLE = 'catalogo_listas_precio';

    private const FAMILIA_TABLE = 'catalogo_familia_estructura';

    private const ARTICULO_TABLE = 'catalogo_articulo_familia_estructura';

    private \fs_db2 $db;

    private CatalogoOptions $options;

    public function __construct(\fs_db2 $db, ?CatalogoOptions $options = null)
    {
        $this->db = $db;
        $this->options = $options ?? new CatalogoOptions();
    }

    public function isEnabled(): bool
    {
        return $this->options->familyStructureEnabled();
    }

    /**
     * Active price lists, default list first — the only list source of the page.
     *
     * @return list<array{codlista: string, nombre: string}>
     */
    public function activeLists(): array
    {
        $sql = 'SELECT codlista, nombre, activa FROM ' . self::LIST_TABLE
            . ' WHERE activa = ' . $this->db->var2str(true)
            . ' ORDER BY por_defecto DESC, codlista ASC;';

        $lists = [];
        foreach ($this->rows($sql) as $row) {
            if (!$this->toBool($row['activa'] ?? false)) {
                continue;
            }
            $lists[] = [
                'codlista' => (string) $row['codlista'],
                'nombre' => (string) ($row['nombre'] ?? ''),
            ];
        }

        return $lists;
    }

    /**
     * Everything the page renders for one list.
     *
     * @return array{
     *     enabled: bool,
     *     lists: list<array{codlista: string, nombre: string}>,
     *     codlista: string|null,
     *     list_name: string,
     *     tree: list<array<string, mixed>>,
     *     empty: bool
     * }
     */
    public function build(?string $codlista): array
    {
        if (!$this->isEnabled()) {
            return $this->result(false, [], null, '', [], true);
        }

        $lists = $this->activeLists();
        $resolved = $this->resolveCodlista($codlista, $lists);
        if ($resolved === null) {
            return $this->result(true, $lists, null, '', [], true);
        }

        $nodes = $this->fetchNodes($resolved);
        $this->attachArticles($nodes, $this->fetchArticles($resolved));
        $tree = $this->buildTree($nodes);

        return $this->result(
            true,
            $lists,
            $resolved,
            $this->listName($lists, $resolved),
            $tree,
            $tree === []
        );
    }

    /**
     * @param list<array{codlista: string, nombre: string}> $lists
     */
    private function resolveCodlista(?string $requested, array $lists): ?string
    {
        if ($lists === []) {
            return null;
        }

        if ($requested !== null && $requested !== '') {
            foreach ($lists as $list) {
                if ($list['codlista'] === $requested) {
                    return $requested;
                }
            }
        }

        return $lists[0]['codlista'];
    }

    /**
     * @param list<array{codlista: string, nombre: string}> $lists
     */
    private function listName(array $lists, string $codlista): string
    {
        foreach ($lists as $list) {
            if ($list['codlista'] === $codlista) {
                return $list['nombre'];
            }
        }

        return '';
    }

    /**
     * Visible structure nodes of the list, without nesting yet.
     *
     * @return array<string, array<string, mixed>> keyed by codfamilia
     */
    private function fetchNodes(string $codlista): array
    {
        $sql = 'SELECT e.codlista, e.codfamilia, e.madre, e.capitulo, e.nivel, e.orden, e.activa,'
            . ' e.en_catalogo, f.descripcion, f.madre AS familia_madre'
            . ' FROM ' . self::FAMILIA_TABLE . ' e'
            . ' INNER JOIN familias f ON f.codfamilia = e.codfamilia'
            . ' WHERE e.codlista = ' . $this->db->var2str($codlista)
            . ' AND e.activa = ' . $this->db->var2str(true)
            . ' AND e.en_catalogo = ' . $this->db->var2str(true)
            . ' ORDER BY e.orden ASC, e.codfamilia ASC;';

        $nodes = [];
        foreach ($this->rows($sql) as $row) {
            if ((string) ($row['codlista'] ?? '') !== $codlista) {
                continue;
            }
            if (!$this->toBool($row['activa'] ?? false) || !$this->toBool($row['en_catalogo'] ?? false)) {
                continue;
            }

            $codfamilia = (string) $row['codfamilia'];
            $nodes[$codfamilia] = [
                'codfamilia' => $codfamilia,
                'descripcion' => (string) ($row['descripcion'] ?? ''),
                'madre' => isset($row['madre']) && $row['madre'] !== '' ? (string) $row['madre'] : null,
                'familia_madre' => isset($row['familia_madre']) && $row['familia_madre'] !== ''
                    ? (string) $row['familia_madre']
                    : null,
                'capitulo' => (string) ($row['capitulo'] ?? ''),
                'nivel' => (string) ($row['nivel'] ?? ''),
                'orden' => (int) ($row['orden'] ?? 0),
                'depth' => 1,
                'articles' => [],
                'children' => [],
            ];
        }

        return $nodes;
    }

    /**
     * Catalog-visible article assignments of the list.
     *
     * @return list<array{referencia: string, descripcion: string, codfamilia: string|null, orden: int}>
     */
    private function fetchArticles(string $codlista): array
    {
        $sql = 'SELECT af.codlista, af.referencia, af.codfamilia, af.orden, af.en_catalogo, a.descripcion'
            . ' FROM ' . self::ARTICULO_TABLE . ' af'
            . ' INNER JOIN articulos a ON a.referencia = af.referencia'
            . ' WHERE af.codlista = ' . $this->db->var2str($codlista)
            . ' AND af.en_catalogo = ' . $this->db->var2str(true)
            . ' ORDER BY af.orden ASC, af.referencia ASC;';

        $articles = [];
        foreach ($this->rows($sql) as $row) {
            if ((string) ($row['codlista'] ?? '') !== $codlista) {
                continue;
            }
            if (!$this->toBool($row['en_catalogo'] ?? false)) {
                continue;
            }

            $articles[] = [
                'referencia' => (string) $row['referencia'],
                'descripcion' => (string) ($row['descripcion'] ?? ''),
                'codfamilia' => isset($row['codfamilia']) && $row['codfamilia'] !== ''
                    ? (string) $row['codfamilia']
                    : null,
                'orden' => (int) ($row['orden'] ?? 0),
            ];
        }

        return $articles;
    }

    /**
     * Attach each article to its visible family node, keeping render order.
     *
     * @param array<string, array<string, mixed>> $nodes
     * @param list<array{referencia: string, descripcion: string, codfamilia: string|null, orden: int}> $articles
     */
    private function attachArticles(array &$nodes, array $articles): void
    {
        foreach ($articles as $article) {
            $codfamilia = $article['codfamilia'];
            if ($codfamilia === null || !isset($nodes[$codfamilia])) {
                continue;
            }

            $nodes[$codfamilia]['articles'][] = [
                'referencia' => $article['referencia'],
                'descripcion' => $article['descripcion'],
                'orden' => $article['orden'],
            ];
        }

        foreach ($nodes as $code => $node) {
            usort(
                $nodes[$code]['articles'],
                static fn (array $a, array $b): int => [$a['orden'], $a['referencia']] <=> [$b['orden'], $b['referencia']]
            );
        }
    }

    /**
     * Nest the visible nodes by mother chain, depth derived from the chain.
     *
     * @param array<string, array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private function buildTree(array $nodes): array
    {
        $childrenOf = [];
        $roots = [];

        foreach ($nodes as $code => $node) {
            $madre = $node['madre'] ?? $node['familia_madre'];
            if ($madre === null || $madre === '' || $madre === $code || !isset($nodes[$madre])) {
                $roots[] = $code;
                continue;
            }
            $childrenOf[$madre][] = $code;
        }

        $visited = [];
        $tree = [];
        foreach ($roots as $code) {
            $tree[] = $this->materialize($code, $nodes, $childrenOf, 1, $visited);
        }

        // Cyclic leftovers have no reachable root: promote them so no visible
        // family vanishes, and rely on the visited set to stop the walk.
        foreach (array_keys($nodes) as $code) {
            if (isset($visited[$code])) {
                continue;
            }
            $tree[] = $this->materialize($code, $nodes, $childrenOf, 1, $visited);
        }

        return $tree;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, list<string>> $childrenOf
     * @param array<string, bool> $visited
     * @return array<string, mixed>
     */
    private function materialize(string $code, array $nodes, array $childrenOf, int $depth, array &$visited): array
    {
        $visited[$code] = true;

        $node = $nodes[$code];
        $node['depth'] = $depth;
        $node['children'] = [];

        $children = $childrenOf[$code] ?? [];
        usort(
            $children,
            static fn (string $a, string $b): int => [$nodes[$a]['orden'], $a] <=> [$nodes[$b]['orden'], $b]
        );

        foreach ($children as $child) {
            if (isset($visited[$child])) {
                continue;
            }
            $node['children'][] = $this->materialize($child, $nodes, $childrenOf, $depth + 1, $visited);
        }

        return $node;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        $rows = $this->db->select($sql);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Boolean normalization matching the value shapes fs_db2 drivers return
     * for boolean columns (`'t'`/`'1'`/true, `'f'`/`'0'`/false).
     */
    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    /**
     * @param list<array{codlista: string, nombre: string}> $lists
     * @param list<array<string, mixed>> $tree
     * @return array{
     *     enabled: bool,
     *     lists: list<array{codlista: string, nombre: string}>,
     *     codlista: string|null,
     *     list_name: string,
     *     tree: list<array<string, mixed>>,
     *     empty: bool
     * }
     */
    private function result(
        bool $enabled,
        array $lists,
        ?string $codlista,
        string $listName,
        array $tree,
        bool $empty
    ): array {
        return [
            'enabled' => $enabled,
            'lists' => $lists,
            'codlista' => $codlista,
            'list_name' => $listName,
            'tree' => $tree,
            'empty' => $empty,
        ];
    }
}
