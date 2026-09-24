# Design: opcional-en-varios-grupos

- **Change**: `opcional-en-varios-grupos`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Secondary consumer touched**: `plugins/tarifario` (two bulk-delete cleanups + one predicate guard) and `plugins/tpvmod` (one cart-add dedupe + grouped detection)
- **Inputs**: `proposal.md`, `exploration.md`, `pending-decisions.md`, `compatibility-tarifario.md`, delta spec `specs/opcionales-management/spec.md` (OUM-02, OUM-05, OPG-02..OPG-11)
- **Confirmed tokens (not re-litigated)**: `tpv_dedupe_at_add`, `ui_checkbox_list`, `column_freeze_then_drop`, `research_skip`
- **Baseline suite**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (regression floor) + `ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml`
- **Artifact store**: `openspec`. No core `openspec/` entry is created.

---

## Technical Approach

Membership of an opcional in optional groups moves from the single-valued
`catalogo_opcionales.id_grupo` column to a new M:N bridge
`catalogo_opcional_grupo_rel(id, id_opcional, id_grupo)` that becomes the **single
source of truth**. The legacy column is **frozen** (no behavior read, no write) but
its physical column and data survive; only a follow-up soak-drop change removes it.

The change is strictly additive-then-subtractive and mirrors the plugin's existing
article↔group bridge (`catalogo_articulo_opcional_grupo`) in every dimension:
schema shape (PK + UNIQUE, **no DB FK**, application-side cascade), the create-table
template in `CatalogLegacyTableMigration::syncArticuloOpcionalGrupoTable()`
(`:122-157`), the idempotent-insert style (`ON CONFLICT`/`INSERT IGNORE`), and the
batched-reader style (`CaracteristicaResolver::opcional_parents()`).

"Loose" is redefined as **no bridge row**; `b_id_grupo=0` and `all_sin_grupo()`
become a `NOT EXISTS` anti-join, and a numeric `b_id_grupo` becomes an `EXISTS`
membership test, so a multi-group opcional matches every group it belongs to.

---

## Architecture Decisions

| # | Decision | Alternatives | Choice & Rationale |
|---|---|---|---|
| **AD-1** | Bridge schema and dialect DDL | (A) comma/JSON column; (B) reuse `catalogo_articulo_opcional_grupo`; (C) new `catalogo_opcional_grupo_rel` | **(C)** A set cannot live in a nullable int column; the article bridge is a different entity (referencia, not id_opcional). Canonical create-table statements (PG and MySQL) are pinned in §"Bridge DDL" and mirror `syncArticuloOpcionalGrupoTable()` byte-for-byte in shape: `PRIMARY KEY (id)` + `UNIQUE (id_opcional, id_grupo)`, **no FK, no `ON DELETE CASCADE`**. XML `model/table/catalogo_opcional_grupo_rel.xml` + model `model/core/catalogo_opcional_grupo_rel.php` (`FSFramework\model\catalogo_opcional_grupo_rel`). |
| **AD-2** | Migration + backfill | (A) drop-and-rebuild; (B) flag-gated one-shot; (C) create-if-missing + cheap pre-check + idempotent insert | **(C)** New `CatalogLegacyTableMigration::migrateOpcionalGroupRelations(\fs_db2 $db)` called from `migrateIfNeeded()`. Create-if-missing (does **not** early-return, so a half-applied run is healed), then a `LEFT JOIN` pre-check on `(id_opcional, id_grupo)` mirroring `hasPendingRows()` (`:387-396`), then the backfill with `ON CONFLICT (id_opcional, id_grupo) DO NOTHING` (PG) / `INSERT IGNORE` (MySQL). Re-runnable; the UNIQUE constraint is created in the same step so `ON CONFLICT` always has a target. |
| **AD-3** | `migrateGroupedOptionalAssignments()` source | (A) keep reading `catalogo_opcionales.id_grupo`; (B) read the bridge | **(B)** It converts direct article↔opcional relations of grouped opcionales into article↔group relations and deletes the invalid direct rows. Once the bridge is the truth it must join `catalogo_opcional_grupo_rel r ON r.id_opcional = ao.id_opcional` (M:N ⇒ an opcional in G1+G2 propagates the article to both groups). Guard list gains `catalogo_opcional_grupo_rel`. |
| **AD-4** | `catalogo_opcional` membership API | (A) keep single-valued API and add setters; (B) replace with set operations | **(B)** `get_grupo()` / `etiqueta_grupo()` / `assign_to_grupo(int)` are **removed**; `remove_from_grupo()` changes arity (0→1). New surface pinned in §"Model API". Exact reversal of the single-valued design is required because every reader/writer of `id_grupo` had to change anyway (exploration DP-3), so a compatibility shim would be an unmaintained second source of truth. |
| **AD-5** | `save()` and the "grouped ⇒ no direct relations" side effect | (A) keep the side effect inside `save()`; (B) move it to first membership add | **(B)** `save()` MUST stop writing `id_grupo`. The cleanup `catalogo_articulo_opcional::delete_all_from_opcional()` fires exactly on the **0 → ≥1 membership transition**, inside `add_to_grupo()` / `set_grupos()`. Removing the last membership does **not** restore previously deleted direct relations (OPG-05 scenario 4, confirmed default). |
| **AD-6** | Fate of the `id_grupo` PHP property | (A) keep as vestigial hydrated field; (B) remove the property and every read | **(B)** `public $id_grupo` and its constructor mapping are **removed** from `catalogo_opcional`; the physical column and its data survive untouched. This makes the "no code path reads the column" invariant literal and grep-testable, and forces every consumer (incl. tpvmod, AD-13) to the bridge. The follow-up soak-drop change owns `ALTER TABLE ... DROP COLUMN id_grupo`, the XML column removal and this backfill's deletion. |
| **AD-7** | Cascade points | (A) DB FK `ON DELETE CASCADE`; (B) app-side deletes | **(B)** `catalogo_opcional_grupo::delete()` deletes that group's bridge rows (`delete_all_from_grupo`); `catalogo_opcional::delete()` deletes its own (`delete_all_from_opcional`). The **two** tarifario bulk-delete paths — `ArticuloListActionHandler::limpiar_opcionales()` **and** `::limpiar_todo()` — each gain `DELETE FROM catalogo_opcional_grupo_rel;`; they are the **only** bulk deletes of `catalogo_opcionales` in the codebase (verified by grep across `plugins/`). Every other removal is per-row through `catalogo_opcional::delete()`. Pre-existing out-of-scope debt: `limpiar_todo()` already leaves `catalogo_articulo_opcional_grupo` rows dangling on its deleted-article side — see §"tarifario compatibility". |
| **AD-8** | Dependent readers move to the bridge | (A) leave some readers on `id_grupo`; (B) rewrite all | **(B)** `catalogo_opcional_grupo::{get_opcionales,count_opcionales,get_opcionales_activos}` JOIN the bridge; `catalogo_articulo_opcional::{validate_opcional_for_articulo,get_opcionales_sueltos_from_articulo,get_opcionales_directos_from_articulo}` replace the `id_grupo` predicate with a bridge anti-join; `catalogo_opcional_familia::{add_with_propagation,remove_with_propagation}` iterate **all** `grupo_ids()`; `VentasArticulo::loadOpcionalesDisponibles()` uses the anti-join; `VentasOpcionalGrupo`'s available list = `all_not_in_grupo($idGrupo)` (OPG-07) and its three mutation/read call sites move to `add_to_grupo` / `remove_from_grupo(int)` / `grupo_ids()` — §"VentasOpcionalGrupo" enumerates each one. |
| **AD-9** | `b_id_grupo` filter + loose definition | (A) keep `id_grupo IS NULL`; (B) `EXISTS`/`NOT EXISTS` on the bridge | **(B)** `where_id_grupo()` returns `''` / `NOT EXISTS(...)` / `EXISTS(...)`; `all_sin_grupo()` is the anti-join. Exact SQL pinned in §"Filters". Group `activo` is irrelevant to membership (only listing/presentation). |
| **AD-10** | Resolver `opcional_parents()` | (A) per-opcional loop over its groups; (B) one batched membership query + existing group-article query | **(B)** Query 1 reads `catalogo_opcional_grupo_rel` (`id_opcional, id_grupo`) instead of `catalogo_opcionales.id_grupo`, then the existing group→article query unions parents from **all** groups. Query count stays **4** (3 when no opcional in the batch has a membership), never per-opcional. |
| **AD-11** | UI shape (`ui_checkbox_list`) | (A) `<select multiple>`; (B) checkbox list | **(B)** `ventas_opcional.html.twig` and the `ventas_opcionales.html.twig` create modal render active groups as `name="grupos[]"` checkboxes; `set_grupos()` persists the checked set in one save. |
| **AD-12** | TPV cart-add dedupe (`tpv_dedupe_at_add`) | (A) show once (T2); (B) dedupe at add (T4) | **(B)** The opcional is presented under every group it belongs to; `plugins/tpvmod/view/js/tpvmod.js::tpvmod_pick_opcional()` dedupes a second selection of the same opcional id **before** the exclusive-group replacement branch. Exact edit in §"TPV". |
| **AD-13** | tpvmod grouped detection | (A) keep reading `$candidate->id_grupo`; (B) bridge-backed `is_grouped()` | **(B)** With AD-6 there is no property to read, and a post-migration grouped opcional has `id_grupo = NULL`, so the old reuse-skip silently regresses. `tpvmod_opcional_candidate_array()` exposes a `grouped` flag from `is_grouped()`; the payload's `grupo_id` is pinned `null` (quick-create opcionales are always loose). |
| **AD-14** | tarifario compatibility guard | (A) change a signature; (B) bridge predicate only | **(B)** `get_opcionales_directos_from_articulo()` keeps its signature and result set (grouped opcionales are excluded through the anti-join); the only behavior change is the two tarifario bulk-delete cleanups (AD-7). |
| **AD-15** | Bridge provisioning at boot | (A) migration only; (B) migration + `ensureCatalogTables()` + init ensure | **(B)** `migrateOpcionalGroupRelations()` runs in both `init()` and `upgrade()` via `migrateLegacyTables()`; additionally `catalogo_opcional_grupo_rel` is added to `Init::ensureCatalogTables()` (`:622-637`) and a new `ensureOpcionalGrupoRelTable()` runs in `init()` next to `ensureArticuloOpcionalGrupoTable()` (`:60`, `:613-620`), so a consumer that depends on `catalogo_core` never boots without it (OPG-03). |
| **AD-16** | Test strategy | — | §"Testing Strategy": 6 existing files updated, 5 new files, mapping each delta scenario. |

---

## Bridge DDL

`CatalogLegacyTableMigration::migrateOpcionalGroupRelations(\fs_db2 $db)` create step
(create-if-missing; mirrored by `model/table/catalogo_opcional_grupo_rel.xml`):

**PostgreSQL**
```sql
CREATE TABLE catalogo_opcional_grupo_rel (
id serial NOT NULL,
id_opcional integer NOT NULL,
id_grupo integer NOT NULL,
PRIMARY KEY (id),
CONSTRAINT catalogo_opcional_grupo_rel_unique UNIQUE (id_opcional, id_grupo)
);
```

**MySQL**
```sql
CREATE TABLE IF NOT EXISTS catalogo_opcional_grupo_rel (
id INT NOT NULL AUTO_INCREMENT,
id_opcional INT NOT NULL,
id_grupo INT NOT NULL,
PRIMARY KEY (id),
UNIQUE KEY catalogo_opcional_grupo_rel_unique (id_opcional, id_grupo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

No `FOREIGN KEY`, no `REFERENCES`, no `ON DELETE CASCADE` (mirrors
`syncArticuloOpcionalGrupoTable()` `:128-150`). After creating the table the method
mirrors the template's model touch (`new \FSFramework\model\catalogo_opcional_grupo_rel();`)
so `fs_model` reconciles columns.

XML (`model/table/catalogo_opcional_grupo_rel.xml`): columns `id` (`serial`),
`id_opcional` (`integer`), `id_grupo` (`integer`), constraints
`catalogo_opcional_grupo_rel_pkey PRIMARY KEY (id)` and
`catalogo_opcional_grupo_rel_unique UNIQUE (id_opcional, id_grupo)`.

---

## Migration and backfill (OPG-03)

### Ordering inside `migrateIfNeeded()` (`:49-63`)

```php
self::syncOptionalGroupColumns($db);            // ensures the frozen id_grupo source exists
self::syncArticuloOpcionalGrupoTable($db);
self::migrateOpcionalGroupRelations($db);       // NEW: create bridge + backfill   ← inserted here
self::migrateGroupedOptionalAssignments($db);   // AD-3: now reads the bridge
self::syncObligatorioColumns($db);
self::purgeOrphanDescriptions($db);
```

The backfill **must run before any future drop** of `id_grupo`; it is the only
moment the legacy values are read into the bridge. `syncOptionalGroupColumns()`
(`:209-228`) keeps the column alive but is explicitly **not** the source of truth
(add a doc comment saying so).

### Idempotency pre-check

```php
private static function hasPendingGroupRelations(\fs_db2 $db): bool
{
    $data = $db->select(
        'SELECT 1 FROM catalogo_opcionales o '
        . 'LEFT JOIN catalogo_opcional_grupo_rel r '
        . 'ON r.id_opcional = o.id AND r.id_grupo = o.id_grupo '
        . 'WHERE o.id_grupo IS NOT NULL AND o.id_grupo > 0 AND r.id IS NULL LIMIT 1;'
    );

    return (bool) $data;
}
```
Steady-state boot stays at one `LIMIT 1` read (same pattern as `hasPendingRows()`
`:387-396`). The pre-check keys on the **pair**, not `id`, because the bridge is
many-to-many.

### Backfill (runs only when the pre-check is true)

**PostgreSQL**
```sql
INSERT INTO catalogo_opcional_grupo_rel (id_opcional, id_grupo)
SELECT o.id, o.id_grupo FROM catalogo_opcionales o
WHERE o.id_grupo IS NOT NULL AND o.id_grupo > 0
ON CONFLICT (id_opcional, id_grupo) DO NOTHING;
```
**MySQL**
```sql
INSERT IGNORE INTO catalogo_opcional_grupo_rel (id_opcional, id_grupo)
SELECT o.id, o.id_grupo FROM catalogo_opcionales o
WHERE o.id_grupo IS NOT NULL AND o.id_grupo > 0;
```

`id_grupo IS NULL` or `= 0` stays loose. A second run inserts nothing (pre-check
false, and `ON CONFLICT`/`IGNORE` as belt-and-braces), satisfying the OPG-03
"backfill is idempotent" scenario. Live data: `Roble` (1) and `Blanco` (2) get
`(1, 1)` and `(2, 1)`; `Toallero` (5) stays loose.

### `migrateGroupedOptionalAssignments()` rewrite (AD-3)

**PostgreSQL**
```sql
INSERT INTO catalogo_articulo_opcional_grupo (referencia, id_grupo)
SELECT DISTINCT ao.referencia, r.id_grupo
FROM catalogo_articulo_opcional ao
INNER JOIN catalogo_opcional_grupo_rel r ON r.id_opcional = ao.id_opcional
ON CONFLICT (referencia, id_grupo) DO NOTHING;

DELETE FROM catalogo_articulo_opcional ao
USING catalogo_opcional_grupo_rel r
WHERE ao.id_opcional = r.id_opcional;
```
**MySQL**
```sql
INSERT IGNORE INTO catalogo_articulo_opcional_grupo (referencia, id_grupo)
SELECT DISTINCT ao.referencia, r.id_grupo
FROM catalogo_articulo_opcional ao
INNER JOIN catalogo_opcional_grupo_rel r ON r.id_opcional = ao.id_opcional;

DELETE ao FROM catalogo_articulo_opcional ao
INNER JOIN catalogo_opcional_grupo_rel r ON r.id_opcional = ao.id_opcional;
```

Guard adds `catalogo_opcional_grupo_rel` to the `tableExists` conjunction at
`:165-169`.

---

## Model API surface (AD-4, AD-5)

### New bridge model `FSFramework\model\catalogo_opcional_grupo_rel`

```php
public const TABLE = 'catalogo_opcional_grupo_rel';
public $id; public $id_opcional; public $id_grupo;

public function add(int $idOpcional, int $idGrupo): bool;          // idempotent (exists_relation short-circuit)
public function remove(int $idOpcional, int $idGrupo): bool;
public function exists_relation(int $idOpcional, int $idGrupo): bool;
public function group_ids_for_opcional(int $idOpcional): array;    // list<int>
public function map_for_opcionales(array $idOpcionales): array;    // [id_opcional => list<int>], ONE query
public function delete_all_from_opcional(int $idOpcional): bool;
public function delete_all_from_grupo(int $idGrupo): bool;
public function exists(); public function save(); public function delete();
protected function install();
```

`map_for_opcionales()` is the single batched reader feeding the list column
(OPG-02), the resolver's alternates and the availability filters:
```sql
SELECT id_opcional, id_grupo FROM catalogo_opcional_grupo_rel
WHERE id_opcional IN (<var2str ids>);
```

### `catalogo_opcional` — new / renamed / removed

| Member | Action | Signature / contract |
|---|---|---|
| `add_to_grupo` | **ADD** | `public function add_to_grupo(int $idGrupo): bool` — validate group, insert bridge row (idempotent), and if the opcional was loose before the insert, run `catalogo_articulo_opcional::delete_all_from_opcional($this->id)`. |
| `remove_from_grupo` | **CHANGE arity** | `public function remove_from_grupo(int $idGrupo): bool` — delete that bridge row only. Never restores direct relations. |
| `set_grupos` | **ADD** | `public function set_grupos(array $idGrupos): bool` — normalize (`intval`, `>0`, `array_unique`), diff against `grupo_ids()`, add missing / remove unchecked, fire the first-membership cleanup once when transitioning 0 → ≥1. |
| `get_grupos` | **ADD** | `public function get_grupos(): array` — `array<int, catalogo_opcional_grupo>` via bridge JOIN (ordered by grupo `orden, nombre`). |
| `grupo_ids` | **ADD** | `public function grupo_ids(): array` — `list<int>` (delegates to the bridge model). |
| `grupos_labels` | **ADD** | `public function grupos_labels(): array` — `list<string>` group names (replaces `etiqueta_grupo()`). |
| `is_grouped` | **ADD** | `public function is_grouped(): bool` — bridge `EXISTS` for this id (replaces every `if ($opcional->id_grupo)` check). |
| `all_sin_grupo` | **CHANGE body** | same signature; anti-join body (§Filters). |
| `all_not_in_grupo` | **ADD** | `public function all_not_in_grupo(int $idGrupo, int $offset = 0, int $limit = FS_ITEM_LIMIT): array` — OPG-07 available list. |
| `all_activos_sin_grupo` | **ADD** | `public function all_activos_sin_grupo(int $offset = 0, int $limit = FS_ITEM_LIMIT): array` — used by `VentasArticulo::loadOpcionalesDisponibles()`. |
| `where_id_grupo` | **CHANGE body** | `protected function where_id_grupo($id_grupo, string $alias = 'o'): string` — `EXISTS`/`NOT EXISTS` body (§Filters). |
| `get_grupo` | **REMOVE** | replaced by `get_grupos()`. |
| `etiqueta_grupo` | **REMOVE** | replaced by `grupos_labels()` / the list trait's batched map. |
| `assign_to_grupo` | **REMOVE** | replaced by `add_to_grupo()`. |
| `$id_grupo` property + constructor mapping | **REMOVE** | AD-6. Physical column preserved. |

### `save()` (AD-5, frozen write)

- UPDATE: drop `, id_grupo = ...` (was `:389`).
- INSERT: drop `id_grupo` from the column list and its value (was `:392-400`).
- Remove the post-save block `if ($this->id_grupo) { (new catalogo_articulo_opcional())->delete_all_from_opcional(...); }` (`:408-411`).
- `delete()` gains the bridge cascade:
```php
public function delete()
{
    (new catalogo_opcional_grupo_rel())->delete_all_from_opcional((int) $this->id);
    return $this->db->exec('DELETE FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';');
}
```
`tarif_opcional::delete()` inherits the cascade through `parent::delete()`.

---

## Frozen column policy (AD-6)

| Layer | Action |
|---|---|
| PHP property | `catalogo_opcional::$id_grupo` **deleted**; no member reads it. |
| Constructor | No `$data['id_grupo']` mapping. |
| Writers | `save()` emits no `id_grupo`; tpvmod stops assigning it (AD-13). |
| Readers | every behavior reader replaced (AD-8, AD-10, AD-11, AD-13). |
| Schema | `id_grupo integer NULL` remains in `model/table/catalogo_opcionales.xml` and in the live table. `syncOptionalGroupColumns()` still ensures the column so a rollback can read it. |
| Allowed residual references | the migration service (`syncOptionalGroupColumns`, `migrateOpcionalGroupRelations` pre-check/backfill, `migrateGroupedOptionalAssignments`), the XML column and any archived SDD docs. |
| Drop | owned by the **follow-up soak-drop change** (precedent `caracteristicas-post-soak-drop`): `ALTER TABLE catalogo_opcionales DROP COLUMN id_grupo` + remove the XML column + delete the backfill. |

A source-audit test asserts no membership predicate on `catalogo_opcionales.id_grupo`
survives in behavior code (see AD-16).

---

## Cascade points (AD-7)

| Trigger | Statement |
|---|---|
| `catalogo_opcional_grupo::delete()` | replace `UPDATE catalogo_opcionales SET id_grupo = NULL ...` (`:160-162`) with `(new catalogo_opcional_grupo_rel())->delete_all_from_grupo((int) $this->id)`, keeping the existing `catalogo_articulo_opcional_grupo::delete_all_from_grupo()` call. |
| `catalogo_opcional::delete()` | `(new catalogo_opcional_grupo_rel())->delete_all_from_opcional((int) $this->id)` before the master `DELETE`. |
| `tarifario` bulk delete — `limpiar_opcionales()` | in `plugins/tarifario/Services/ArticuloListActionHandler.php::limpiar_opcionales()` (`:2167-2238`) insert a **new step 4** — `$db->exec("DELETE FROM catalogo_opcional_grupo_rel;")` — immediately **before** the existing step that deletes `catalogo_opcionales` (`:2207-2214`, which becomes step 5), mirroring the steps-1..3 count/error style: count it into `$stats['relaciones_grupo']`, append to `$errores` on failure, and report it in the success message (`:2220-2228`). No FK makes the order irrelevant; the placement keeps the existing step numbering and message coherent. |
| `tarifario` bulk delete — `limpiar_todo()` | in the same class, `limpiar_todo()` (`:1742-2050`) insert `$deleteTable('catalogo_opcional_grupo_rel', 'relaciones_grupo')` **between step 10** (`catalogo_opcional_familias`, `:1987`) **and step 11** (`catalogo_opcionales`, `:1992`). Add `'relaciones_grupo' => 0` to the `$stats` seed (`:1747-1759`) and the count to the success message (`:2036+`). The `$deleteTable` helper is already existence-guarded and accumulates errors through its caller. |

---

## Dependent readers (AD-8)

### `catalogo_opcional_grupo` (`model/core/catalogo_opcional_grupo.php`)

```sql
-- get_opcionales(): :172-189
SELECT o.* FROM catalogo_opcionales o
INNER JOIN catalogo_opcional_grupo_rel r ON r.id_opcional = o.id
WHERE r.id_grupo = <id> ORDER BY o.nombre ASC;

-- count_opcionales(): :191-204
SELECT COUNT(*) as total FROM catalogo_opcional_grupo_rel WHERE id_grupo = <id>;

-- get_opcionales_activos(): :224-242  (adds o.activo = TRUE to get_opcionales)
```
`delete()` as in AD-7. `count_articulos()` is untouched (it reads the
article↔group bridge).

### `catalogo_articulo_opcional` (`model/core/catalogo_articulo_opcional.php`)

- `validate_opcional_for_articulo()` `:47-60` → `if ($item->is_grouped()) { return '...'; }`.
- `get_opcionales_sueltos_from_articulo()` `:114-129` and
  `get_opcionales_directos_from_articulo()` `:175-190`: replace
  `AND (o.id_grupo IS NULL OR o.id_grupo = 0)` with
  `AND NOT EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r.id_opcional = o.id)`.
- `get_opcionales_from_articulo()` `:136-165` is **behavior-preserved**: loose reads
  now exclude grouped rows via the anti-join, the group loop returns an opcional for
  each group it belongs to, and the existing `$seen` map dedupes by id (OPG-09
  scenario 1). Locked by a test, no code change.

### `catalogo_opcional_familia` (`model/core/catalogo_opcional_familia.php`)

`add_with_propagation()` (`:52-88`) and `remove_with_propagation()` (`:90-120`):
replace `if ($op && $op->id_grupo)` with `$grupoIds = $op ? $op->grupo_ids() : []; if ($grupoIds !== []) { ... }`,
and iterate **every** `$idGrupo` in `$grupoIds` for the article↔group propagation
(`catalogo_articulo_opcional_grupo::add/remove`).

### `VentasArticulo::loadOpcionalesDisponibles()` (`Controller/VentasArticulo.php:950-967`)

Replace `if ($opcional->id_grupo) continue;` with a call to
`$opcionalModel->all_activos_sin_grupo(0, 500)` (bridge anti-join) instead of
`all_activos(0, 500)` + per-row skip. Article↔group add/remove/toggle
(`:1012-1140`) is untouched.

### `VentasOpcionalGrupo` (`Controller/VentasOpcionalGrupo.php`)

AD-4 removes `assign_to_grupo()`, changes `remove_from_grupo()` arity, and AD-6
removes the `$id_grupo` property, so every mutation/read site here breaks. Pinned
replacements (no other site in this controller touches membership):

| Site | Current | Replacement | Why |
|---|---|---|---|
| available-list read, `loadGrupoRelations()` `:102` | `$opcionalModel->all_sin_grupo(0, 500)` | `$opcionalModel->all_not_in_grupo((int) $this->grupo->id, 0, 500)` (OPG-07) | `all_sin_grupo()` is loose-only and would never offer an opcional already in another group; an opcional in **this** group is excluded by the anti-join, and the existing `$asignados` guard (`:95-106`) stays as a harmless defensive dedupe. |
| add, `addOpcionalAlGrupo()` `:178` | `$item->assign_to_grupo((int) $this->grupo->id)` | `$item->add_to_grupo((int) $this->grupo->id)` | same `int` argument, same `bool` contract and same `get_errors()` fallback (`:183-187`). |
| membership check, `removeOpcionalDelGrupo()` `:205` | `(int) $item->id_grupo !== (int) $this->grupo->id` | `!in_array((int) $this->grupo->id, $item->grupo_ids(), true)` | the property no longer exists; membership is a set test (OPG-04). |
| remove, `removeOpcionalDelGrupo()` `:210` | `$item->remove_from_grupo()` | `$item->remove_from_grupo((int) $this->grupo->id)` | AD-4 arity change (0 → 1): delete only this group's bridge row. |

The view `View/ventas_opcional_grupo.html.twig` is unchanged apart from the
available-list data now including other groups' members (no markup change needed).

---

## Filters (AD-9)

### `where_id_grupo($id_grupo, string $alias = 'o')`

| Input | Returned condition |
|---|---|
| `''` | `''` (filter disabled) |
| `'0'` | `NOT EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r.id_opcional = <alias>.id)` |
| `'<n>'` (`intval`, injection-proof) | `EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r.id_opcional = <alias>.id AND r.id_grupo = <n>)` |

`catalogo_opcional::search()` (`:464-515`), `tarif_opcional::search()` (`:234-310`)
and `tarif_opcional::count_filtered()` (`:324-372`) keep passing the same
`$grupo_condition` into queries aliased `o`; no signature change. `count_filtered()`
already uses the same predicate as `search()` (OUM-02 scenario "total matches").

### `all_sin_grupo()`

```sql
SELECT * FROM catalogo_opcionales o
WHERE NOT EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r.id_opcional = o.id)
ORDER BY o.nombre ASC
```
(aliased `o` so the correlated subquery resolves; the `select_limit($sql, $limit, $offset)` wrapper is unchanged).

### `all_not_in_grupo(int $idGrupo, ...)` (OPG-07)

```sql
SELECT * FROM catalogo_opcionales o
WHERE NOT EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel r
                  WHERE r.id_opcional = o.id AND r.id_grupo = <idGrupo>)
ORDER BY o.nombre ASC
```
An opcional that belongs to **another** group is therefore offered; one already in
**this** group is excluded. The loose-only list (`all_sin_grupo()`) is no longer used
here.

---

## Resolver rewrite (AD-10, OPG-10)

`CaracteristicaResolver::opcional_parents()` (`Services/CaracteristicaResolver.php:267-338`).

New class constant: `private const OPCIONAL_GRUPO_REL_TABLE = 'catalogo_opcional_grupo_rel';`

| # | Query | Change |
|---|---|---|
| 1 | `SELECT id_opcional, id_grupo FROM catalogo_opcional_grupo_rel WHERE id_opcional IN (<in>)` | **replaces** the old `SELECT id, id_grupo FROM catalogo_opcionales`; builds `$memberships[$id] = list<int>` |
| 2 | `SELECT id_opcional, referencia FROM catalogo_articulo_opcional WHERE id_opcional IN (<in>)` | unchanged |
| 3 | `SELECT id_grupo, referencia FROM catalogo_articulo_opcional_grupo WHERE id_grupo IN (<distinct groups>)` | unchanged; distinct group ids are the union over `$memberships` |
| 4 | `SELECT id_opcional, codfamilia FROM catalogo_opcional_familias WHERE id_opcional IN (<in>)` | unchanged |

Union loop replaces the single-`$idGrupo` merge:
```php
foreach ($parents as $id => $parent) {
    foreach ($memberships[$id] ?? [] as $idGrupo) {
        if (isset($byGroup[$idGrupo])) {
            $parents[$id]['referencias'] = array_values(array_unique(array_merge(
                $parents[$id]['referencias'],
                $byGroup[$idGrupo]
            )));
        }
    }
}
```

**Resulting query count: exactly 4 batched queries when at least one opcional in
the batch has a membership, and exactly 3 when none does** (query 3 is the only
conditional one, exactly as today; the delta spec's "currently four"). It stays
constant and independent of the requested opcional count and of how many groups
each opcional belongs to — no per-opcional query. Existing batch-reader assertions
remain valid:
`OpcionalVisibilityDerivationTest::test_query_count_is_bounded_and_independent_of_the_page_size`
(`assertLessThanOrEqual(4, ...)`) and `assertSame(count(single), count(page))`; the
docblock (`:256-266`) is updated to say "four (three without memberships)" and to
drop the `id_grupo`-of-each-opcional phrasing.

The union now adds the article parents of **every** group an opcional belongs to
(deduplicated), satisfying OPG-10 scenario 1.

---

## UI (AD-11, OPG-02)

### Edit page `View/ventas_opcional.html.twig`

Replace the single `<select name="sid_grupo">` (`:192-204`) with the checkbox list:
```twig
{{ 'optional-group'|trans }}:
{% for grupo in fsc.grupos_opcional %}
<label class="checkbox-inline">
   <input type="checkbox" name="grupos[]" value="{{ grupo.id }}"
          {% if grupo.id in fsc.grupos_asignados_ids %} checked{% endif %}/>
   {{ grupo.nombre }}{% if grupo.exclusivo %} ({{ 'optional-group-exclusive-short'|trans }}){% endif %}
</label>
{% endfor %}
<p class="help-block"><a href="index.php?page=ventas_opcional_grupos">{{ 'optional-groups-manage'|trans }}</a></p>
```
- Tab badge/articles gating (`:121-123`, `:333-340`): `fsc.opcional.id_grupo` →
  `fsc.opcional.is_grouped()`; the "view group" link becomes one link per
  `fsc.grupos_asignados`.
- `Controller/VentasOpcional` gains `public array $grupos_asignados_ids = []` and
  `public array $grupos_asignados = []`; `loadGruposOpcional()` renders
  `all_activos()` **unioned with** the opcional's current groups (so an inactive-group
  membership is shown and `set_grupos()` is lossless). The `?id_grupo=<n>` preset
  (`:87-93`) seeds `grupos_asignados_ids = [$n]` instead of writing the model.
- `guardarOpcional()`: read `$request->request->all('grupos')`, drop the
  `sid_grupo`/`id_grupo` writes (`:216-227`), and call `$this->opcional->set_grupos($ids)`
  after `save()`. `addArticulo()` `:319-322` → `if ($this->opcional->is_grouped())`.

### Create modal `View/ventas_opcionales.html.twig`

Replace `<select name="sid_grupo">` (`:104-114`) with the same `name="grupos[]"`
checkbox list over `fsc.grupos_opcional`. `VentasOpcionalesListTrait::new_opcional()`
drops the `sid_grupo` read (`:772-775`) and the `$opcional->id_grupo = ...` write
(`:787`), and calls `$opcional->set_grupos((array) $this->request->request->all('grupos'))`
**inside** the existing `run_in_transaction()` so memberships persist with the
opcional (OUM-05 scenario 2).

### "Grupo" column — one batched map (OPG-02)

`VentasOpcionalesListTrait::load_grupos_cache()` (`:313-330`):
1. load all groups once (`all()`), build `$nombres[grupo_id] = nombre` from **all**
   groups so inactive memberships still render, and keep `$this->grupos_opcional`
   (active only) for the filter/create checkboxes;
2. build `$this->nombres_grupo_opcional[$id] = list<string>` from **one** call to
   `catalogo_opcional_grupo_rel::map_for_opcionales($ids)` (seam
   `opcional_grupo_rel_model()` for tests) — no per-row or per-membership lookup.

Accessor renamed `nombre_grupo_opcional(int): string` → `nombres_grupo_opcional(int): array`
(returns `[]` for loose rows). The view (`:426-429`) renders labels as badges:
```twig
{% set grupos = fsc.nombres_grupo_opcional(value.id) %}
{% if grupos|length > 0 %}
   {% for nombre in grupos %}<span class="label label-info">{{ nombre }}</span>{% endfor %}
{% else %}<span class="text-muted">-</span>{% endif %}
```
The group filter `<select name="b_id_grupo">` (`:333-351`) is unchanged markup; only
`where_id_grupo()`'s SQL changes.

---

## TPV (AD-12, AD-13, OPG-09)

### Presentation per group

`tpvmod_opcionales_for_articulo()` (`lib/tpvmod_opcionales.php:241-303`) needs **no
code change**: `$grupo->get_opcionales_activos()` becomes bridge-backed (AD-8), so an
opcional in G1+G2 is listed under both. `tpvmod_opcionales_flat_list()` returns it
once per group (used for lookup). OPG-09 scenario 2.

### Cart-add dedupe — exact call site

File: **`plugins/tpvmod/view/js/tpvmod.js`**, function **`tpvmod_pick_opcional(parentUid, opcionalId)`** (lines 675-707). Current branch (`:695-698`):
```js
if(opcional.grupo_id && opcional.grupo_exclusivo)
   tpvmod_remove_opcional_in_grupo(parentUid, String(opcional.grupo_id));
else if(tpvmod_get_added_opcional_ids(parentUid)[String(opcional.id)])
   return;
```
The `else if` dedupe is skipped for exclusive groups (the default), so selecting the
same opcional id under a second exclusive group adds a second line. Pinned rewrite:
```js
if(tpvmod_get_added_opcional_ids(parentUid)[String(opcional.id)])
   return;

if(opcional.grupo_id && opcional.grupo_exclusivo)
   tpvmod_remove_opcional_in_grupo(parentUid, String(opcional.grupo_id));
```
Dedupe-by-id now precedes the exclusive-group replacement, so the first branch is only
reached when picking a **different** opcional in the same exclusive group (its previous
selection is removed and the new one added). OPG-09 scenario 3.

### tpvmod grouped detection (AD-13)

`plugins/tpvmod/lib/tpvmod_opcionales_ajax.php`:
- `tpvmod_opcional_candidate_array()` (`:31-51`): replace `'id_grupo' => ...` with
  `'grouped' => $candidate instanceof \FSFramework\model\catalogo_opcional ? $candidate->is_grouped() : (bool)($candidate['grouped'] ?? false)`.
- `tpvmod_match_opcional_by_nombre()` (`:59-86`): `$idGrupo = (int)($item['id_grupo'] ?? 0); if ($idGrupo > 0) continue;` → `if (!empty($item['grouped'])) continue;`.
- `tpvmod_opcionales_ajax_opcional_payload()` (`:151-174`): `'grupo_id' => null` (quick-create/reuse never produces a grouped opcional).
- `tpvmod_opcionales_ajax_persist()` (`:254`, `:262`): drop both `$opcional->id_grupo = ...` assignments.
- `plugins/tpvmod/lib/tpvmod_opcionales.php:111` (`tpvmod_normalize_opcional_input()`): rename the `'id_grupo' => null` data key to `'grouped' => false`, so the freeze sweep leaves no `id_grupo` reference in behavior code (the `catalogo_opcional` constructor ignores unknown keys, so this is cosmetic-but-consistent with AD-6).

---

## tarifario compatibility (AD-14, OPG-11)

`plugins/tarifario/`:
- `Services/ArticuloListActionHandler.php` — **both** bulk-delete paths gain the
  identical `catalogo_opcional_grupo_rel` cleanup (AD-7, C2 + C2b of
  `compatibility-tarifario.md`): `limpiar_opcionales()` (`:2167-2238`) before its
  `catalogo_opcionales` delete, and `limpiar_todo()` (`:1742-2050`) between the
  `catalogo_opcional_familias` (`:1987`) and `catalogo_opcionales` (`:1992`)
  deletes. Both are reachable from the same action dispatch (`:162-163` / `:170-171`).
- Pre-existing out-of-scope debt (state it, do not silently expand scope):
  `limpiar_todo()` deletes the tarifario artículos (`:1907` / `:1928`) and all
  `catalogo_opcionales` (`:1992`) but never touches the older
  `catalogo_articulo_opcional_grupo` bridge, so the article↔group rows it leaves
  behind dangle on the deleted-article side — pre-existing debt, unrelated to this
  change. (`limpiar_opcionales()` keeps artículos and grupos alive, so its
  `catalogo_articulo_opcional_grupo` rows stay referentially valid.) This change
  **only** cleans the new `catalogo_opcional_grupo_rel` bridge; fixing the older
  bridge is **out of scope** (it is an articulo↔group concern, explicitly left
  alone) and is flagged as follow-up debt in the verify report. Including it would
  be a one-line `$deleteTable(...)` but is deliberately deferred.
- `model/tarif_articulo.php:130-134` `get_opcionales()` is unchanged; it delegates to
  `catalogo_articulo_opcional::get_opcionales_directos_from_articulo()`, whose only
  change is the bridge anti-join, so the direct set for 1 or 2 groups is identical
  (grouped rows excluded either way). Guarded by a compatibility test seeding a
  2-group opcional.
- `model/tarif_opcional.php` never sets `id_grupo`; with `save()` frozen it stays
  transparent.

---

## Testing Strategy (AD-16)

Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` and
`ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml`. Tests are DB-free
(fake `fs_db2`/anonymous subclasses), mirroring the existing patterns.

### Existing files updated

| File | Action | Why / scenario |
|---|---|---|
| `tests/fixtures/opcional_visibility_fakes.php` | Update | Replace the `FROM catalogo_opcionales` branch with `FROM catalogo_opcional_grupo_rel`; `$groups` becomes `array<int, list<int>>` (`id_opcional => [id_grupo...]`) emitting **one row per pair** (`['id_opcional' => 7, 'id_grupo' => 3]`), `inInts()` filtering on `id_opcional`; keep `groupArticles`, `articles`, `families`. **OPG-10**. |
| `tests/OpcionalVisibilityDerivationTest.php` | Update | `test_grouped_opcional_uses_the_group_article_parents` seeds `groups: [7 => [3]]`; add `test_multi_group_opcional_unions_all_group_article_parents` (`groups: [7 => [3, 4]]`, `groupArticles: [3 => ['REF-G1'], 4 => ['REF-G2']]`, assert both parents union) = **OPG-10** scenario 1; keep `test_query_count_is_bounded_and_independent_of_the_page_size` (`assertLessThanOrEqual(4, ...)`, equal counts) = **OPG-10** scenario 2. |
| `tests/CatalogoOpcionalGrupoTest.php` | Update + extend | Drop the two property assertions `testOpcionalStoresIdGrupo` / `testGroupedOptionalHasIdGrupo`; keep `testUrlNuevoEnGrupoIncludesQueryParam`, `testGrupoModelGeneratesCodigoPrefix`, `testTpvmodBuildOpcionalItemIncludesGroupMetadata` (its `grupo_id` comes from the explicit `$grupo` argument, not the removed property) and `testTpvmodOpcionalesForArticuloReturnsGroupedPayloadWhenEmpty`; add the bridge-backed contracts: `test_multi_group_opcional_appears_under_each_group` + `test_activos_read_is_bridge_backed` (**OPG-06**), `test_available_list_offers_member_of_another_group` + `test_available_list_excludes_current_member` (**OPG-07**), `test_resolved_for_sale_read_yields_one_occurrence` + `test_group_scoped_presentation_lists_each_group` (**OPG-09**), plus bridge-model rows-per-group assertions (**OPG-03**). Seed kept: opcional `X` in `G1` and `G2`, article `A` assigned to both. |
| `tests/CatalogoOpcionalesUnifiedControllerTest.php` | Update | `test_new_opcional_persists_percentage_and_group` (`:706-732`) posts `grupos[]` and asserts `set_grupos()`; `test_where_id_grupo_treats_sentinel_and_rejects_injection` (`:804-823`) asserts the new `EXISTS`/`NOT EXISTS` strings; the group-map test (`:789-801`) asserts the array accessor `nombres_grupo_opcional()`; the stub (`:1066`) drops `$id_grupo` and gains `set_grupos()`. **OUM-02, OUM-05, OPG-02, OPG-04**. |
| `tests/CatalogoOpcionalesHtmxContractTest.php` | Update | Assert `name="grupos[]"` (not `sid_grupo`), `fsc.nombres_grupo_opcional(`, no `etiqueta_grupo(`, no `id_grupo` in the list/edit views. **OUM-05, OPG-02**. |
| `tests/VentasOpcionalesControllerTest.php` | Update | `CreateOrderingOpcionalStub` (`:232-280`) drops `$id_grupo` and gains `set_grupos(array): bool`; create payload (`:243`) migrates to `grupos[]`. **OUM-05**. |
| `tests/Services/CatalogLegacyTableMigrationTest.php` | Update | Seed `catalogo_opcional_grupo_rel` and assert the create + backfill statements (`ON CONFLICT (id_opcional, id_grupo) DO NOTHING` / `INSERT IGNORE`) and that `migrateGroupedOptionalAssignments` joins `catalogo_opcional_grupo_rel`. **OPG-03**. |
| `tests/Integration/CatalogoCoreHookMarkersTest.php` | Update | Drop `'id_grupo' => ''` from the host fixture (`:483`). |
| `tests/InitOpcionalesTablesTest.php` | Update | Assert `ensureCatalogTables()` includes `catalogo_opcional_grupo_rel` and `init()` ensures it. **OPG-03**. |
| `tests/Controller/VentasOpcionalesExportParityTest.php` | Keep | `search`/`count_filtered` signatures unchanged (AD-9). |
| `plugins/tpvmod/tests/TpvmodOpcionalRapidoTest.php` | Update | Candidate/payload assertions (`:133`, `:151`, `:230-260`, `:425`, `:491-523`, `:688`) migrate from `id_grupo` to `grouped` / `grupo_id => null`. **OUM-05 / AD-13**. |

### New files

| File | Covers | Approach |
|---|---|---|
| `tests/CatalogoOpcionalGrupoRelModelTest.php` | OPG-03 | Bridge model `add` idempotency, `remove`, `exists_relation`, `group_ids_for_opcional`, `map_for_opcionales` (one query per call), `delete_all_from_*`; DDL source contract asserts both PG and MySQL create statements, `PRIMARY KEY (id)`, `UNIQUE (id_opcional, id_grupo)` and the **absence** of `FOREIGN KEY`/`REFERENCES`. |
| `tests/CatalogoOpcionalMembershipTest.php` | OPG-04, OPG-05, OPG-08 | Anonymous `catalogo_opcional` subclass with a fake `fs_db2`: `add_to_grupo` inserts + fires the first-membership `delete_all_from_opcional`; `remove_from_grupo` does not restore; `set_grupos` diff; `is_grouped`/`grupos_labels`; `all_sin_grupo`/`where_id_grupo`/`all_not_in_grupo` SQL. |
| `tests/Integration/CatalogoOpcionalGroupMigrationTest.php` | OPG-03 | Source contract: `migrateOpcionalGroupRelations` exists, is called from `migrateIfNeeded()` **before** `migrateGroupedOptionalAssignments`, uses the `LEFT JOIN (id_opcional, id_grupo)` pre-check, and emits the dialect backfills; `migrateGroupedOptionalAssignments` joins `catalogo_opcional_grupo_rel`. |
| `tests/Integration/OpcionalGrupoTarifarioCompatTest.php` | OPG-11 | Two-group seed: `get_opcionales_directos_from_articulo()` excludes a grouped opcional identically with 1 or 2 groups (fake db). Source contract asserts `ArticuloListActionHandler::limpiar_opcionales()` **and** `::limpiar_todo()` both contain `DELETE FROM catalogo_opcional_grupo_rel` (a per-method slice, not one file-wide grep), that `limpiar_todo()` adds `relaciones_grupo` to `$stats`, and that the direct-read predicate is a bridge anti-join (not `id_grupo`). Documents the `catalogo_articulo_opcional_grupo` pre-existing orphan as out of scope. |
| `plugins/tpvmod/tests/TpvmodOpcionalDedupeTest.php` | OPG-09 (scenario 3) | Source contract on `view/js/tpvmod.js`: `tpvmod_pick_opcional` checks `tpvmod_get_added_opcional_ids(...)` **before** the exclusive replacement; `tpvmod_opcionales_ajax.php` contains no `id_grupo` read; `tpvmod_match_opcional_by_nombre` uses `grouped`. OPG-09 scenarios 1–2 are covered behaviorally in `tests/CatalogoOpcionalGrupoTest.php`. |

Two-group seed used by the compatibility and resolver tests: opcional `X` in groups
`G1` and `G2`, article `A` assigned to both, `A` directly related to `X` before
grouping (assert the relation is converted/deleted once).

---

## Threat Matrix

| Threat | Vector | Mitigation | Verified by |
|---|---|---|---|
| Silent divergence (a reader keeps `id_grupo`) | any missed call site | grep audit throughout; property removed (AD-6); freeze-then-drop | `CatalogOpcionalMembershipTest`, `CatalogoOpcionalesUnifiedControllerTest`, migration test |
| Orphan memberships | group/opcional/bulk delete | app-side cascade (AD-7) + **both** tarifario bulk-delete paths (`limpiar_opcionales`, `limpiar_todo`) + `catalogo_opcional::delete()` | `CatalogoOpcionalGrupoRelModelTest`, `OpcionalGrupoTarifarioCompatTest` (per-method source contract) |
| Migration ordering/dialect | backfill after drop, or `ON CONFLICT` without UNIQUE | create-if-missing + UNIQUE in the same step, pre-check, backfill before drop | `CatalogoOpcionalGroupMigrationTest`, `CatalogLegacyTableMigrationTest` |
| Resolver query blow-up | per-opcional loop | one batched membership query; constant 4/3 | `OpcionalVisibilityDerivationTest` |
| SQL injection in filters | `b_id_grupo` value | `intval()` for numeric, fixed string templates for `EXISTS`/`NOT EXISTS` | `CatalogoOpcionalesUnifiedControllerTest` |
| Double charge in TPV | same id picked under two groups | dedupe-by-id first in `tpvmod_pick_opcional` | `TpvmodOpcionalDedupeTest` |
| Lost first-membership cleanup | side effect removed with `save()` | fires in `add_to_grupo`/`set_grupos` on 0→≥1 | `CatalogoOpcionalMembershipTest` |

---

## Migration / Rollout (work units)

| WU | Change | Rollback |
|---|---|---|
| **WU-1** | Bridge XML + model + `migrateOpcionalGroupRelations()` + `migrateGroupedOptionalAssignments()` rewrite + `Init` ensure | Revert files; the bridge is inert and `id_grupo` is still readable. |
| **WU-2** | `catalogo_opcional` membership API + `save()` freeze + cascade + `all_sin_grupo`/`where_id_grupo`/`all_not_in_grupo` | Revert the model; readers still work on the frozen column. |
| **WU-3** | Dependent readers (group model, `catalogo_articulo_opcional`, `catalogo_opcional_familia`, `VentasArticulo`, resolver) | Revert; the bridge can be populated but unused. |
| **WU-4** | Controllers + trait + views (checkbox list, batched label map) | Revert view/controller files; the bridge is inert. |
| **WU-5** | tpvmod dedupe + grouped detection + tarifario two bulk-delete cleanups/predicate | Revert the consumer edits; no schema change. |
| **WU-6** | Verify pass (plugin suites, grep audit, `verify-report.md`) | No production change. |

No destructive DDL, no Composer dependency, no `vendor/` delta. Dynamic property
removal (AD-6) is a PHP-level change; the DB column is untouched.

---

## Commit Units

1. `feat(catalogo_core): add opcional-group membership bridge and backfill`
2. `refactor(catalogo_core): move opcional group membership to the bridge`
3. `feat(catalogo_core): multi-group opcional UI and batched group labels`
4. `fix(tpvmod): dedupe an opcional selected across several groups`
5. `fix(tarifario): clean opcional bridge rows on both bulk-delete paths`

---

## Open Questions

1. **Group names in the list column**: pinned to **all** groups (so inactive
   memberships still render), which adds one `all()` query versus the current
   active-only map. Confirm this is preferred over active-only labels.
2. **Inactive-group membership in the edit checkbox list**: pinned to render the
   union (active ∪ current) so `set_grupos()` is lossless. Confirm we should not
   silently drop inactive memberships.
3. **Cross-repo touch**: the tpvmod JS/PHP edits (AD-12/AD-13) live in a separate
   plugin repo while the SDD is owned by `catalogo_core`. Confirm the companion
   tpvmod commit is expected in this change rather than a tpvmod follow-up SDD.
4. **`tarifario` stats label**: the bulk-delete success message gains a
   `relaciones-grupo` count; confirm the wording.
