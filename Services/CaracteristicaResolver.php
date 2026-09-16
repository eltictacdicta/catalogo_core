<?php
/**
 * This file is part of the catalogo_core plugin
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

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_valor.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_global.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_familia.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_articulo.php';

/**
 * Uniform effective-value read path (CAR-06, CAR-07).
 *
 * Read-only: it exposes no save/assign/materialize method. The scope walk runs
 * nearest-first (articulo → familia chain → global); within each scope the
 * requested tarifa row beats the `DEF` row; `valor_defecto` is consulted only
 * after every scope failed. `null` means "no value" and stays distinguishable
 * from a stored empty string.
 */
class CaracteristicaResolver
{
    public const SCOPE_ARTICULO = 'articulo';
    public const SCOPE_FAMILIA = 'familia';
    public const SCOPE_GLOBAL = 'global';
    public const DEF_TARIFA = 'DEF';

    /**
     * The two D12 visibility features derived from the parent product
     * (`caracteristicas-producto` CAR-12). Opcional/article visibility is
     * always expressed with exactly these codigos.
     */
    public const VISIBILITY_CODIGOS = ['en_catalogo', 'en_tarifa'];

    /**
     * Opcional parent tables needed by the D12 derivation (CAR-12). The names
     * mirror the `catalogo_core` models (`catalogo_opcional::TABLE`,
     * `catalogo_articulo_opcional::TABLE`, `catalogo_articulo_opcional_grupo::TABLE`,
     * `catalogo_opcional_familia::TABLE`) without forcing those classes to load.
     */
    private const OPCIONAL_TABLE = 'catalogo_opcionales';
    private const ARTICULO_OPCIONAL_TABLE = 'catalogo_articulo_opcional';
    private const ARTICULO_OPCIONAL_GRUPO_TABLE = 'catalogo_articulo_opcional_grupo';
    private const OPCIONAL_FAMILIA_TABLE = 'catalogo_opcional_familias';

    private const MAX_FAMILY_DEPTH = 32;

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $definitionCache = [];

    /** @var array<string, list<string>> */
    private array $familyChainCache = [];

    /**
     * Ordered definitions keyed by codigo (orden ASC, codigo ASC).
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitions(bool $onlyActive = true): array
    {
        $key = $onlyActive ? 'active' : 'all';
        if (isset($this->definitionCache[$key])) {
            return $this->definitionCache[$key];
        }

        $map = [];
        foreach ((array) $this->definition_model()->all($onlyActive) as $row) {
            $definition = $this->to_definition($row);
            if ($definition['codigo'] === '') {
                continue;
            }
            $map[$definition['codigo']] = $definition;
        }

        return $this->definitionCache[$key] = $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listable_definitions(): array
    {
        return $this->flagged_definitions('listable');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function importable_definitions(): array
    {
        return $this->flagged_definitions('importable');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportable_definitions(): array
    {
        return $this->flagged_definitions('exportable');
    }

    /**
     * Effective raw value, or null for "no value". Pure read.
     */
    public function resolve(
        string $codigo,
        string $codtarifa,
        ?string $referencia = null,
        ?string $codfamilia = null
    ): ?string {
        $definition = $this->definitions()[$codigo] ?? null;
        if ($definition === null || !(bool) ($definition['activo'] ?? true)) {
            return null;
        }

        $idCaracteristica = (int) $definition['id'];
        $probes = [$codtarifa, self::DEF_TARIFA];

        if ($referencia !== null && $referencia !== '') {
            foreach ($probes as $tarifa) {
                $row = $this->scope_model(self::SCOPE_ARTICULO)
                    ->get($tarifa, ['referencia' => $referencia], $idCaracteristica);
                if ($row) {
                    return $this->value_of($row);
                }
            }

            if ($codfamilia === null || $codfamilia === '') {
                $codfamilia = $this->articulo_family_lookup($referencia);
            }
        }

        foreach ($this->familia_chain($codfamilia) as $familia) {
            foreach ($probes as $tarifa) {
                $row = $this->scope_model(self::SCOPE_FAMILIA)
                    ->get($tarifa, ['codfamilia' => $familia], $idCaracteristica);
                if ($row) {
                    return $this->value_of($row);
                }
            }
        }

        foreach ($probes as $tarifa) {
            $row = $this->scope_model(self::SCOPE_GLOBAL)->get($tarifa, [], $idCaracteristica);
            if ($row) {
                return $this->value_of($row);
            }
        }

        return $this->normalize_default($definition['valor_defecto'] ?? null);
    }

    /**
     * Typed convenience. NULL = no value; bool via str2bool('1'|'0').
     */
    public function resolve_bool(
        string $codigo,
        string $codtarifa,
        ?string $referencia = null,
        ?string $codfamilia = null
    ): ?bool {
        return $this->to_bool($this->resolve($codigo, $codtarifa, $referencia, $codfamilia));
    }

    /**
     * D12 — effective visibility of an opcional for a tarifa.
     *
     * Rule: **existential union** over the opcional's parents. An opcional owns
     * no `en_catalogo`/`en_tarifa` flag; any parent visible makes it visible.
     * It is not visible when no parent resolves TRUE but at least one parent
     * yields a present value. It is "no value" (NULL) when no parent yields a
     * present value at all.
     *
     * Parent resolution:
     *  - article-attached (`catalogo_articulo_opcional`, plus
     *    `catalogo_articulo_opcional_grupo` when `id_grupo > 0`) → the parent
     *    article's effective value (article → family → global);
     *  - family-assigned (`catalogo_opcional_familias`) → the family's effective
     *    value (family walk with `madre`);
     *  - unassigned → the global scope value only.
     *
     * @return bool|null NULL = no parent carries a value.
     */
    public function resolve_opcional_visibility(int $id_opcional, string $codtarifa, string $codigo): ?bool
    {
        $visibility = $this->resolve_opcionales_visibility([$id_opcional], $codtarifa, $codigo);

        return $visibility[(int) $id_opcional] ?? null;
    }

    /**
     * Batched D12 derivation for a page of opcionales: `[id_opcional => ?bool]`.
     *
     * The parent discovery runs in a constant number of queries (see
     * {@see opcional_parents()}), independent of the number of opcionales.
     *
     * @param array<int, int|string> $id_opcionales
     * @return array<int, bool|null>
     */
    public function resolve_opcionales_visibility(array $id_opcionales, string $codtarifa, string $codigo): array
    {
        $ids = [];
        foreach ($id_opcionales as $id) {
            $value = (int) $id;
            if ($value > 0 && !in_array($value, $ids, true)) {
                $ids[] = $value;
            }
        }

        if ($ids === []) {
            return [];
        }

        $parents = $this->opcional_parents($ids);
        $result = [];

        foreach ($ids as $id) {
            $values = [];

            foreach ($parents[$id]['referencias'] ?? [] as $referencia) {
                $values[] = $this->resolve($codigo, $codtarifa, (string) $referencia, null);
            }

            foreach ($parents[$id]['familias'] ?? [] as $codfamilia) {
                $values[] = $this->resolve($codigo, $codtarifa, null, (string) $codfamilia);
            }

            if ($values === []) {
                // Unassigned opcional: the global scope only.
                $values[] = $this->resolve($codigo, $codtarifa, null, null);
            }

            $result[$id] = $this->union_bool($values);
        }

        return $result;
    }

    /**
     * Parent discovery for the D12 derivation.
     *
     * Three (four when a group relation is present) batched queries, all keyed
     * by the requested opcional ids: the `id_grupo` of each opcional, its
     * direct article relations, its group article relations and its family
     * assignments. Never a per-opcional query.
     *
     * @param list<int> $id_opcionales
     * @return array<int, array{referencias: list<string>, familias: list<string>}>
     */
    protected function opcional_parents(array $id_opcionales): array
    {
        $parents = [];
        foreach ($id_opcionales as $id) {
            $parents[$id] = ['referencias' => [], 'familias' => []];
        }

        $in = $this->in_list($id_opcionales);

        $groups = [];
        $rows = (array) $this->db()->select(
            'SELECT id, id_grupo FROM ' . self::OPCIONAL_TABLE . ' WHERE id IN (' . $in . ');'
        );
        foreach ($rows as $row) {
            $groups[(int) $row['id']] = (int) ($row['id_grupo'] ?? 0);
        }

        $rows = (array) $this->db()->select(
            'SELECT id_opcional, referencia FROM ' . self::ARTICULO_OPCIONAL_TABLE
            . ' WHERE id_opcional IN (' . $in . ');'
        );
        $byOpcional = [];
        foreach ($rows as $row) {
            $byOpcional[(int) $row['id_opcional']][] = (string) $row['referencia'];
        }

        $grupos = [];
        foreach ($groups as $idGrupo) {
            if ($idGrupo > 0 && !in_array($idGrupo, $grupos, true)) {
                $grupos[] = $idGrupo;
            }
        }

        $byGroup = [];
        if ($grupos !== []) {
            $rows = (array) $this->db()->select(
                'SELECT id_grupo, referencia FROM ' . self::ARTICULO_OPCIONAL_GRUPO_TABLE
                . ' WHERE id_grupo IN (' . $this->in_list($grupos) . ');'
            );
            foreach ($rows as $row) {
                $byGroup[(int) $row['id_grupo']][] = (string) $row['referencia'];
            }
        }

        foreach ($byOpcional as $id => $referencias) {
            $parents[$id]['referencias'] = $referencias;
        }

        foreach ($parents as $id => $parent) {
            $idGrupo = $groups[$id] ?? 0;
            if ($idGrupo > 0 && isset($byGroup[$idGrupo])) {
                $parents[$id]['referencias'] = array_values(array_unique(array_merge(
                    $parents[$id]['referencias'],
                    $byGroup[$idGrupo]
                )));
            }
        }

        $rows = (array) $this->db()->select(
            'SELECT id_opcional, codfamilia FROM ' . self::OPCIONAL_FAMILIA_TABLE
            . ' WHERE id_opcional IN (' . $in . ');'
        );
        foreach ($rows as $row) {
            $codfamilia = trim((string) ($row['codfamilia'] ?? ''));
            if ($codfamilia === '') {
                continue;
            }
            $parents[(int) $row['id_opcional']]['familias'][] = $codfamilia;
        }

        return $parents;
    }

    /**
     * Existential union of the parents' raw values: TRUE when any value is
     * boolean-true, FALSE when none is true but at least one is present, NULL
     * when every value is absent.
     *
     * @param list<string|null> $values
     */
    private function union_bool(array $values): ?bool
    {
        $present = false;
        foreach ($values as $value) {
            $bool = $this->to_bool($value);
            if ($bool === null) {
                continue;
            }
            $present = true;
            if ($bool) {
                return true;
            }
        }

        return $present ? false : null;
    }

    /**
     * @param string|null $raw
     */
    private function to_bool(?string $raw): ?bool
    {
        if ($raw === null) {
            return null;
        }

        return $raw === 't' || $raw === '1';
    }

    /**
     * @param list<mixed> $values
     */
    private function in_list(array $values): string
    {
        $parts = [];
        foreach ($values as $value) {
            $parts[] = $this->db()->var2str($value);
        }

        return $parts === [] ? 'NULL' : implode(', ', $parts);
    }

    /**
     * Nearest-first `madre` chain, cycle-guarded and memoized.
     *
     * @return list<string>
     */
    public function familia_chain(?string $codfamilia): array
    {
        if ($codfamilia === null || $codfamilia === '') {
            return [];
        }

        if (isset($this->familyChainCache[$codfamilia])) {
            return $this->familyChainCache[$codfamilia];
        }

        $chain = [];
        $visited = [];
        $current = $codfamilia;

        while ($current !== null
            && $current !== ''
            && !in_array($current, $visited, true)
            && count($chain) < self::MAX_FAMILY_DEPTH) {
            $chain[] = $current;
            $visited[] = $current;
            $current = $this->familia_madre($current);
        }

        return $this->familyChainCache[$codfamilia] = $chain;
    }

    // =====================================================================
    // Internals
    // =====================================================================

    /**
     * @return array<int, array<string, mixed>>
     */
    private function flagged_definitions(string $flag): array
    {
        $list = [];
        foreach ($this->definitions() as $definition) {
            if ((bool) ($definition[$flag] ?? false)) {
                $list[] = $definition;
            }
        }

        return $list;
    }

    /**
     * @param mixed $row
     * @return array<string, mixed>
     */
    private function to_definition($row): array
    {
        if (is_array($row)) {
            return $row + ['activo' => true, 'valor_defecto' => null];
        }

        return [
            'id' => (int) ($row->id ?? 0),
            'codigo' => (string) ($row->codigo ?? ''),
            'nombre' => (string) ($row->nombre ?? ''),
            'tipo' => (string) ($row->tipo ?? 'string'),
            'activo' => (bool) ($row->activo ?? true),
            'importable' => (bool) ($row->importable ?? false),
            'exportable' => (bool) ($row->exportable ?? false),
            'listable' => (bool) ($row->listable ?? false),
            'orden' => (int) ($row->orden ?? 0),
            'valor_defecto' => $row->valor_defecto ?? null,
        ];
    }

    /**
     * @param mixed $row section row (object or array)
     */
    private function value_of($row): ?string
    {
        if ($this->row_bool($row, 'custom')) {
            $value = $this->row_raw($row, 'valor');

            return $value === null ? null : (string) $value;
        }

        $idValor = (int) $this->row_raw($row, 'id_valor');
        if ($idValor <= 0) {
            return null;
        }

        return $this->catalog_value($idValor);
    }

    /**
     * @param mixed $row
     */
    private function row_bool($row, string $field): bool
    {
        $value = $this->row_raw($row, $field);
        if (is_bool($value)) {
            return $value;
        }

        return $value == 't' || $value == '1';
    }

    /**
     * @param mixed $row
     * @return mixed
     */
    private function row_raw($row, string $field)
    {
        if (is_array($row)) {
            return $row[$field] ?? null;
        }

        return $row->{$field} ?? null;
    }

    private function normalize_default($default): ?string
    {
        if ($default === null) {
            return null;
        }

        $value = (string) $default;

        return $value === '' ? null : $value;
    }

    // =====================================================================
    // Seams (unit tests override them to stay DB-free)
    // =====================================================================

    /** @return object */
    protected function definition_model()
    {
        return new \FSFramework\model\catalogo_caracteristica();
    }

    /**
     * @return object
     */
    protected function scope_model(string $scope)
    {
        return match ($scope) {
            self::SCOPE_ARTICULO => new \FSFramework\model\catalogo_caracteristica_articulo(),
            self::SCOPE_FAMILIA => new \FSFramework\model\catalogo_caracteristica_familia(),
            default => new \FSFramework\model\catalogo_caracteristica_global(),
        };
    }

    /** @return object */
    protected function valor_model()
    {
        return new \FSFramework\model\catalogo_caracteristica_valor();
    }

    /** @return object */
    protected function familia_model()
    {
        if (!class_exists(\FSFramework\model\familia::class, false)) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
        }

        return new \FSFramework\model\familia();
    }

    protected function catalog_value(int $idValor): ?string
    {
        $row = $this->valor_model()->get($idValor);
        if (!$row) {
            return null;
        }

        return (string) $row->valor;
    }

    protected function familia_madre(string $codfamilia): ?string
    {
        $familia = $this->familia_model()->get($codfamilia);
        if (!$familia || trim((string) ($familia->madre ?? '')) === '') {
            return null;
        }

        return (string) $familia->madre;
    }

    protected function articulo_family_lookup(string $referencia): ?string
    {
        if (!class_exists(\FSFramework\model\articulo::class, false)) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
        }

        if (!class_exists(\FSFramework\model\articulo::class, false)) {
            return null;
        }

        $articulo = new \FSFramework\model\articulo();
        $row = $articulo->get($referencia);
        if (!$row || trim((string) ($row->codfamilia ?? '')) === '') {
            return null;
        }

        return (string) $row->codfamilia;
    }

    /**
     * DB seam for the D12 parent discovery (unit tests inject a counting
     * double; the batch reader uses the same pattern).
     *
     * @return \fs_db2|object
     */
    protected function db()
    {
        return new \fs_db2();
    }
}
