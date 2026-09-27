# Explore — caracteristicas-plugin-scope

> Phase: `explore` (read-only). No source code, spec, or test was modified.
> SDD root: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`).
> Core `openspec/` is reference only — no entries created there.
> Test runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`.

- **Change**: `caracteristicas-plugin-scope`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Artifact store**: `openspec` (plugin-local only)
- **Change dir**: `plugins/catalogo_core/openspec/changes/caracteristicas-plugin-scope/`

---

## 1. Goal restated (user-confirmed, not re-litigated)

Today in `catalogo_core` the `ventas_caracteristicas` panel lists **every** row of
`catalogo_caracteristicas` regardless of whether the plugin that registered it is
enabled. Observed fact confirmed against the live workspace:

- `FS_TMP_NAME` = `103e4b1b6b85e188307e/`.
- `tmp/103e4b1b6b85e188307e/enabled_plugins.list` =
  `business_data,clientes_core,clientes_facturacion,FSDK,catalogo_core,tpvmod,system_updater,factura_pdf1,OidcProvider`.
- `tarifario` is **absent**, yet its definitions `en_catalogo` / `en_tarifa`
  (`origen = 'tarifario'`) still appear in the panel and are still usable.

**Confirmed decision:** a definition whose owning plugin (`origen`) is not enabled
MUST be **inert across the whole feature system** — not listed in the panel, not
offered for import/export, not driving product-list columns, and ignored by the
resolver. DB rows and assigned values MUST be preserved; re-enabling the plugin
MUST restore the definition and its values. **Deletion of definitions is out of
scope.**

**Recorded, NOT solved here:** every existing definition has non-empty `origen`;
CAR-11 makes plugin-owned definitions non-deletable, so the panel renders no
delete button for any row today (`View/ventas_caracteristicas.html.twig:78`
`{% if fsc.allow_delete and def.is_deletable() %}`). This change is the
enabled-plugin visibility rule only.

---

## 2. Current State

### 2.1 Definition identity and `origen`

`plugins/catalogo_core/model/core/catalogo_caracteristica.php`
(`FSFramework\model\catalogo_caracteristica`, table `catalogo_caracteristicas`):

- `origen` is a public string, default `''` (`:66`, `:85`, `:97`).
- `is_deletable()` returns `trim($origen) === ''` (`:208-211`) — CAR-11.
- Read methods (`:142-203`): `all($onlyActive=false)` → `fetch_list($onlyActive, null)`;
  `listable()/importable()/exportable()` → `fetch_list(true, '<flag>')`.
  The **only** filter in `fetch_list()` is `activo` and the flag column. `origen`
  is **not** consulted anywhere in the read path.
- `get($codigo)` (`:108-118`) and `get_by_id($id)` (`:124-134`) are unfiltered.

### 2.2 The three independent read paths (no shared visibility rule today)

| Path | Entry point | How it reads definitions | Consults `origen`? |
|---|---|---|---|
| Panel | `Controller/VentasCaracteristicas.php:120-123` `load_definiciones()` | `caracteristica_model()->all()` (raw model, no resolver) | No |
| Resolver | `Services/CaracteristicaResolver.php:77-94` `definitions()` | `definition_model()->all($onlyActive)` | No |
| Batched list / hook context | `Services/CaracteristicaValorBatchReader.php:119-131` `definition_rows()` | **own raw SQL** `SELECT … FROM catalogo_caracteristicas WHERE activo = TRUE [AND listable = TRUE / AND codigo IN (…)]` | No |

The resolver is the shared choke point for **effective values** (`resolve()`,
`resolve_bool()`, `resolve_opcional_visibility()`, `listable/importable/exportable_definitions()`,
and `CaracteristicaValorStore::definitions()`), but it is **not** on the panel path
nor on the batch-reader path. The batch reader deliberately replays the resolver's
precedence in PHP with a constant query count (CAR-16 "no N+1") and never calls
`CaracteristicaResolver::definitions()`.

### 2.3 Registration (where `origen` is written)

- `Services/CaracteristicaRegistry.php`: `DEFAULTS` holds `medidas` with
  `origen = 'catalogo_core'` (`:45`); `registerDefault(..., $origen, ...)` is the
  targeted idempotent upsert (`:60-116`).
- `plugins/catalogo_core/Init.php:88` (`init()`) and `:126` (`upgrade()`) call
  `CaracteristicaRegistry::registerDefaults()` → registers `medidas`.
- `plugins/tarifario/Init.php:122-129` registers `en_catalogo` / `en_tarifa`
  with `origen = 'tarifario'` (`:122`, `:126`), guarded by `class_exists()` +
  a static guard, re-attempted from `upgrade()` (`:95-98`).
- Net: `origen ∈ {'catalogo_core', 'tarifario', ''}`. Operator-created rows have
  `origen = ''` and MUST stay always-active.

### 2.4 Framework plugin-state API (the seam the rule must use)

`src/Core/Plugins.php:77-80`:
```php
public static function isEnabled(string $pluginName): bool
{
    return in_array($pluginName, self::enabled(), true);
}
```
`enabled()` returns `$GLOBALS['plugins'] ?? []` (`:72-75`), populated at bootstrap
by `base/config2.php:116-128` from `tmp/{FS_TMP_NAME}enabled_plugins.list`. This is
the canonical, already-existing, plugin-agnostic registry — the rule can be
expressed generically as `isEnabled($row->origen)` for any non-empty `origen`,
with **no** hardcoded `'tarifario'` string in `catalogo_core` (preserves CAR-19).

### 2.5 Current consumers of the two tarifario-owned codigos (hardcoded in catalogo_core)

| File:line | Constant | Role |
|---|---|---|
| `Services/CaracteristicaResolver.php:50` | `VISIBILITY_CODIGOS = ['en_catalogo','en_tarifa']` | D12/reference list |
| `Services/CaracteristicaValorStore.php:37` | `LEGACY_BOOL_CODIGOS = ['en_catalogo','en_tarifa']` | dual-write target (`:182`) |
| `extras/VentasArticulosListTrait.php:48,311` | local `VISIBILITY_CODIGOS` | product-list visibility batch |
| `extras/VentasOpcionalesListTrait.php:415` | resolver constant | D12 opcional visibility cache |
| `Controller/VentasArticulo.php:639` | resolver constant | persists article visibility on save |
| `extras/CaracteristicaHookContextTrait.php:129` | `definitions()` keys | hook context map |
| `plugins/tarifario/controller/tarif_catalogo_view.php:2307,2348,2383,2429` | resolver constant | tarifario catalog read-through/mirror (plugin code) |

These are pre-existing hardcodings, not introduced by this change. `VentasArticulo.php:615-648`
already documents that the definitions are registered by tarifario and a
catalogo_core-only install has nothing to write (best-effort `assign_bool`).

### 2.6 Resolver `null` semantics today

`CaracteristicaResolver::resolve()` returns `null` ("no value") when the definition
is missing **or** `activo` is false (`:129-132`). Every call site already treats
`null` safely as "not visible / no value" or as a no-op write:

- `resolve_bool()` → `null` ⇒ `to_bool(null)=null` (`:174-181`, `:376-383`).
- `VentasArticulosListTrait::articulo_visibility_bool()` → `null` ⇒ `false` (`:352-361`).
- `VentasOpcionalesListTrait` D12 union → `union_bool()` returns `null` when every
  parent is absent; caller casts `(bool)` ⇒ `false` (`:415-419`).
- `CaracteristicaValorStore::assign()/assign_bool()/clear()` → `null` definition ⇒
  `return false` (write refused; `:77-80`, `:118-121`, `:146-149`).
- `CaracteristicaValorBatchReader` yields `null` per cell; `caracteristica_cell()`
  renders the neutral `-` (`:451-463`).

No identified call site fatals or writes on the `null` path.

### 2.7 Actual production consumers of the resolver's flagged-definition helpers

- `importable_definitions()` — consumed by `ArticuloExcelImportWizardService::load_importable_definitions()` (`:430-442`), called from `:111`.
- `listable_definitions()` and `exportable_definitions()` — **not consumed in production** (grep). The product list uses `CaracteristicaValorBatchReader::columns()`; `ArticuloExcelExportService::buildSpreadsheet()` has an `array $exportable = []` parameter that no `Controller/VentasArticulos.php:388,403` caller passes. So the flags-driven list/export are effectively wired through the batch reader (list) and currently-unused resolver helpers (export). This must be accounted for in the spec delta so the rule is stated on the **batch reader** and on the **import wizard**, not only on the resolver helpers.

---

## 3. Affected Areas

### 3.1 Production files (catalogo_core)

- `plugins/catalogo_core/Services/CaracteristicaResolver.php` — the effective-value
  choke point; `definitions()` must drop inert definitions so `resolve()`,
  `resolve_bool()`, the flagged helpers and `CaracteristicaValorStore` all agree.
- `plugins/catalogo_core/Controller/VentasCaracteristicas.php` — `load_definiciones()`
  (`:120-123`) must not expose inert rows; direct `get()` actions
  (`save_definition` `:147`, `delete_definition` `:188`, `toggle_flag` `:228`,
  `save_catalogo_valor` `:264`) need an explicit decision (see Risks).
- `plugins/catalogo_core/Services/CaracteristicaValorBatchReader.php` — `definition_rows()`
  (`:119-131`) must add `origen` to the SELECT and filter in PHP (the enabled set
  is runtime, not expressible in SQL). This is the path behind the product list
  visibility columns (`VentasArticulosListTrait:310-311`), the CAR-16 listable
  columns (`:426-430`) and the hook context (`CaracteristicaHookContextTrait:135-140`).
- `plugins/catalogo_core/Services/CaracteristicaValorStore.php` — inherits the rule
  via `definitions()` (`:329-333`), so import/article writes to inert definitions
  become no-ops; confirm no regression for operator (`origen=''`) rows.
- `plugins/catalogo_core/View/ventas_caracteristicas.html.twig` — no code change
  required if the controller filters `definiciones`; the delete-button observation
  (CAR-11, `:78`) stays as-is.
- `plugins/catalogo_core/extras/VentasArticulosListTrait.php`, `extras/VentasOpcionalesListTrait.php`,
  `Controller/VentasArticulo.php`, `extras/CaracteristicaHookContextTrait.php` —
  consumers; they need no change if the resolver/batch-reader seam is authoritative,
  but their behavior under tarifario-inactive must be asserted (visibility ⇒ false;
  opcional D12 ⇒ not visible).
- `plugins/catalogo_core/Services/ArticuloExcelImportWizardService.php` — inherits
  the rule via `importable_definitions()`.
- `plugins/catalogo_core/model/core/catalogo_caracteristica.php` — only if Approach C
  is chosen (filter in the model).

### 3.2 Production files (tarifario)

- `plugins/tarifario/controller/tarif_catalogo_view.php:2307,2348,2383,2429` and
  `plugins/tarifario/Services/ExcelRowUpdater.php:530`, `Services/ExcelHierarchyService.php:672-689`,
  `Services/ExcelImportWizardService.php:180-196` — all of these only run when
  tarifario is **enabled**; no tarifario code change is required by this rule.
  `catalogo_core` MUST NOT import the tarifario namespace (CAR-19 / `CaracteristicaBoundariesTest`).

### 3.3 Tests (regression surface; `strict_tdd: true`)

Existing suites that read definitions or the two codigos and must stay green, and
new coverage the rule requires:

- Definition/registration: `tests/CaracteristicaDefaultsTest.php`,
  `tests/CaracteristicaModelTest.php`.
- Panel: `tests/Controller/VentasCaracteristicasControllerTest.php` (its stub
  `all()` at `:415` and `is_deletable()` at `:420`).
- Resolver/values: `tests/CaracteristicaResolverTest.php`,
  `tests/CaracteristicaValorStoreTest.php`, `tests/CaracteristicaAssignmentScopeTest.php`,
  `tests/CaracteristicaReadThroughTest.php` (+ `ReadThroughFeatureReader :45`).
- Batch/columns/hook: `tests/Controller/VentasArticulosListCaracteristicasTest.php`
  (`ListCaracteristicasFakeBatchReader :29`, `ListCaracteristicasQueryCountingReader :333`),
  `tests/CaracteristicaHookContextTest.php`.
- D12 opcionales: `tests/OpcionalVisibilityDerivationTest.php`,
  `tests/OpcionalVisibilityParityTest.php`, `tests/TarifOpcionalesControllerMasterStateTest.php`,
  `tests/CatalogoOpcionalesUnifiedControllerTest.php`.
- Import/export: `tests/Services/ArticuloExcelCaracteristicaTest.php`,
  `tests/Services/ArticuloExcelFeaturePersistenciaTest.php`.
- Boundaries: `tests/CaracteristicaBoundariesTest.php` (must confirm the rule adds
  **no** catalogo_core → tarifario dependency and no hardcoded plugin name in the
  ownership check).
- Tarifario suite: `plugins/tarifario/tests/**` — `resolve_bool('en_catalogo', …)`
  and `VISIBILITY_CODIGOS` are asserted in several tests
  (`TarifCatalogoJsonImportVisibilityTest`, `TarifCatalogoVisibilityReadThroughTest`,
  `ExcelRowUpdaterFeatureVisibilityTest`, `CaracteristicaDefaultsRegistrationTest`,
  `ExcelImportWizardServiceTest`, `ExcelImportWizardFieldCatalogTest`,
  `ExcelHierarchyServiceCreateFamiliaRowTest`). Their stubs default `origen` to `''`,
  so they should stay active; verify explicitly.

### 3.4 Spec delta needed (`plugins/catalogo_core/openspec/specs/caracteristicas-producto/spec.md`)

All deltas are `ADDED`/`MODIFIED` inside the existing capability
`caracteristicas-producto`; **no** new capability and **no** other plugin spec is
modified (keeps the change plugin-local).

1. **New requirement (proposed id CAR-20 — "Enabled-plugin ownership scope")** —
   the normative rule: a definition with non-empty `origen` whose owning plugin is
   not enabled is inert across resolver, panel, import/export and product-list
   columns; rows and values are preserved; re-enabling restores them; `origen=''`
   rows are always active; the check uses the framework plugin registry and MUST
   NOT introduce a `catalogo_core → tarifario` dependency.
   Scenarios: (a) owner disabled ⇒ definition absent from panel/import/columns and
   `resolve()` returns `null`; (b) owner re-enabled ⇒ definition and its values
   return unchanged with no writes; (c) operator row (`origen=''`) unaffected.
2. **MODIFY CAR-16** (`listable` columns) — add a scenario: an owner-disabled
   `listable` definition emits no column (batch reader filter).
3. **MODIFY CAR-17** (`importable`/`exportable` columns) — add a scenario: an
   owner-disabled definition contributes no import field and no export column.
4. **MODIFY CAR-18** (management panel) — add a scenario: owner-disabled
   definitions are not listed; and record that plugin-owned rows render no delete
   button (CAR-11) as observed, out of scope.
5. **MODIFY CAR-11** — add the ownership/enabled clause next to `origen`'s
   non-deletability so registration and the new scope rule live together.
6. **MODIFY CAR-06/CAR-12** (resolver/D12) — one scenario each confirming the
   owner-disabled `null` path is the same as the existing missing/inactive
   `null` path (no new return value, no writes).
7. **MODIFY CAR-19** (plugin-local boundaries) — scenario asserting the enabled
   check reads `origen` + the framework registry and adds no tarifario coupling.

**Other plugin specs:** `plugins/tarifario/openspec/specs/tarifario/catalogo-integration/spec.md`
`R-TAR-HOOK-007` ("Host neutrality with tarifario inactive") is the closest existing
guarantee and this change brings `catalogo_core` in line with its spirit, but it
belongs to the tarifario spec tree. Because no tarifario **file** changes, the SDD
stays plugin-local; note the cross-reference in the proposal and leave the tarifario
spec untouched (a tarifario-side delta is a separate, optional follow-up).

---

## 4. Approaches (for the "owning plugin not enabled ⇒ inert" seam)

### Approach A — Filter only inside `CaracteristicaResolver`
Change `definitions()` (`:77-94`) to skip rows whose `origen` owner is not enabled;
everything built on it (`resolve`, `resolve_bool`, the flagged helpers,
`CaracteristicaValorStore`) follows.

- Pros: single file, smallest diff, no new type; automatically covers import wizard list, resolver-based export/flagged helpers and all writes.
- Cons: **does not satisfy the requirement alone** — the panel reads the model
  directly (`VentasCaracteristicas:122`) and the batch reader uses its own SQL
  (`CaracteristicaValorBatchReader:119-131`), so tarifario definitions would still
  appear in the panel and still drive the product-list visibility/listable columns
  and the hook context.
- Effort: Low, but incomplete.

### Approach B — Shared ownership predicate/service consumed by all three read paths
Add one small service (e.g. `Services/CaracteristicaOwnership.php` /
`CaracteristicaPluginScope`) exposing a single testable predicate
`is_active(string $origen): bool` that returns `true` for `''` and delegates to
`FSFramework\Core\Plugins::isEnabled($origen)` otherwise, behind an overridable
seam for DB-free tests. Wire it into:

1. `CaracteristicaResolver::definitions()` (cascades to values/writes/flags),
2. `VentasCaracteristicas::load_definiciones()` (panel list),
3. `CaracteristicaValorBatchReader::definition_rows()` (add `origen` to the SELECT,
   filter in PHP).

- Pros: one rule, all read paths agree; generic (`origen` + registry, never a
  hardcoded `'tarifario'`); no model→registry hard dependency; each site has a
  small, local seam.
- Cons: three call sites to keep consistent; the batch reader must carry `origen`
  through its SQL; the panel's direct `get()` actions need an explicit decision.
- Effort: Medium.

### Approach C — Filter inside the model read methods
Make `catalogo_caracteristica::fetch_list()` consult an overridable
`is_owner_active()` seam (default `Plugins::isEnabled`), so `all()/listable()/importable()/exportable()`
are scoped at the model.

- Pros: panel (`all()`) and resolver (`all()`) covered from one place; flagged
  model reads also covered; the model is the natural owner of "which definitions
  exist".
- Cons: puts the registry dependency in the data model (the exact tension the
  brief names), even if seam-isolated; SQL cannot express the runtime enabled set,
  so filtering is a PHP pass over the full fetch and `get()/get_by_id()` stay
  unfiltered; the batch reader still needs its own change (raw SQL), so it is not
  a true single choke point; changing the `all()` contract can surprise existing
  callers/tests.
- Effort: Medium.

**Comparison summary**

| Approach | Panel | Resolver | Batch reader | Cross-reviewability | Effort |
|---|---|---|---|---|---|
| A | ✗ | ✓ | ✗ | 1 file | Low |
| B | ✓ | ✓ | ✓ | 3 files + 1 helper | Medium |
| C | ✓ | ✓ | ✗ | model + batch reader | Medium |

---

## 5. Recommendation

Adopt **Approach B**: a single, plugin-agnostic ownership predicate consumed by the
resolver, the panel and the batch reader. It is the only option that makes the
panel, import/export and the resolver agree, and it preserves CAR-19 because the
predicate reads the **row's `origen`** and the **framework registry**, never a
hardcoded `'tarifario'`.

Answers to the brief's questions:

1. **Seam** — a shared `is_active($origen)` helper wired into
   `CaracteristicaResolver::definitions()`, `VentasCaracteristicas::load_definiciones()`
   and `CaracteristicaValorBatchReader::definition_rows()`. Approach A alone fails
   the panel and list requirements; Approach C couples the model to the registry
   and still misses the batch reader.
2. **Ownership tension** — **keep the two flags tarifario-owned and accept
   inert-is-correct.** They are tarifario domain concepts (`En Catálogo`/`En Tarifa`);
   re-homing them to `catalogo_core` would change CAR-11, force an always-active
   flag even with tarifario off (contradicting the confirmed decision) and churn
   tarifario tests. When tarifario is disabled: the article-list visibility columns
   resolve `false` (not notices), `VentasArticulo::persist_articulo_visibility`
   writes nothing (best-effort, already documented), the D12 opcional derivation
   resolves `null` ⇒ opaque `false`, and the import wizard offers no `en_*` fields.
   No orphan writes occur because the write path goes through the resolver-filtered
   `definitions()`.
3. **`resolve()` null path** — confirmed correct and reused as-is: the
   owner-disabled case is exactly the existing missing/inactive case, so no call
   site changes its behavior contract. The only new behavior to pin with tests is
   that the batch reader and the panel agree with the resolver.
4. **Blast radius** — §3.1–§3.3 above is the full inventory (production files,
   tarifario consumers that run only when enabled, and every test that reads the
   definitions or the two codigos).
5. **Spec delta** — §3.4: one new requirement (CAR-20) plus targeted MODIFIED
   scenarios in CAR-06/11/12/16/17/18/19, all in `caracteristicas-producto`; no
   other capabability and no other plugin spec changes.

---

## 6. Risks

- **Panel direct `get()` actions.** `save_definition`/`delete_definition`/`toggle_flag`/
  `save_catalogo_valor` (`VentasCaracteristicas:147-288`) use `get()`, not `all()`.
  Filtering only the listing leaves a crafted POST able to mutate an inert
  definition. Decide in the spec: refuse inert-owner rows on mutation too
  (fail-closed), consistent with "inert".
- **Fail-mode when the registry is unavailable.** `Plugins::isEnabled` reads
  `$GLOBALS['plugins'] ?? []`; a CLI/test context without the populated list makes
  every plugin-owned definition inert. `base/config2.php` always populates it at
  bootstrap, so this is an edge, but the proposal must state the chosen fail
  direction (fail-closed = hide) and the operator (`origen=''`) exception.
- **D12 opcional visibility in a catalogo_core-only install.** With tarifario
  disabled, `resolve_opcional_visibility` returns `null` for every opcional ⇒ the
  UI shows them as not visible. This is a real behavior change for that install and
  must be asserted, not assumed.
- **Test-fixture defaults.** Most existing stubs omit `origen`, which defaults to
  `''` (operator) and therefore stays active; that keeps current suites green.
  New tests must explicitly seed `origen` and `$GLOBALS['plugins']`. Any test that
  stubs a tarifario-owned definition without enabling tarifario will now go inert.
- **Batch reader contract.** Adding `origen` to `definition_rows()` keeps the
  constant query count (CAR-16 "no N+1"); the PHP filter must not spawn per-row
  queries.
- **Boundary test.** The ownership helper must not reference the tarifario
  namespace or the literal `'tarifario'`; `CaracteristicaBoundariesTest` must be
  extended to prove it.
- **Scope creep.** The "no delete button" observation (CAR-11, all rows plugin-owned)
  is recorded but must NOT be fixed in this change.

---

## 7. Ready for Proposal

**Yes.** The requirement is unambiguous, the seam is identified with a concrete
recommendation (Approach B), the ownership tension is resolved (inert-is-correct;
flags stay tarifario-owned), the `null` path is verified safe, and the spec delta
is scoped to `caracteristicas-producto`. The proposal should:

1. state the inert rule + preservation/re-enable semantics for non-empty `origen`,
2. adopt the shared ownership predicate and name the three wiring sites,
3. state the fail-mode decision and the operator (`origen=''`) exception,
4. explicitly record the panel `get()`-action decision,
5. keep deletion and the delete-button observation out of scope,
6. keep the SDD plugin-local (no tarifario file/spec change; reference
   `R-TAR-HOOK-007` only as context).

---

## Result Contract

- **status**: `explored`
- **executive_summary**: `catalogo_core` exposes feature definitions through three
  independent read paths — the panel (raw model `all()`), the resolver
  (`definitions()`), and the batch reader (own SQL) — none of which consult
  `origen`. Confirmed live: `tarifario` is not in the active
  `enabled_plugins.list`, yet its `en_catalogo`/`en_tarifa` rows still list and
  resolve. The fix is a single plugin-agnostic ownership predicate
  (`''` ⇒ active; else `FSFramework\Core\Plugins::isEnabled($origen)`) wired into
  the resolver, the panel list, and the batch reader, making owner-disabled
  definitions inert everywhere while preserving rows/values and restoring them on
  re-enable. The two flags stay tarifario-owned (inert-is-correct); `resolve()`
  already returns `null` for missing/inactive and that path is safe at every call
  site. Spec delta is one new requirement plus targeted scenarios in
  `caracteristicas-producto` (CAR-06/11/12/16/17/18/19), no other plugin spec.
- **artifacts**: `plugins/catalogo_core/openspec/changes/caracteristicas-plugin-scope/explore.md`
- **next_recommended**: `sdd-propose` for `caracteristicas-plugin-scope`
- **risks**: panel direct `get()` mutations; fail-mode when `$GLOBALS['plugins']` is
  unavailable; D12 opcional visibility change in a catalogo_core-only install;
  test fixtures lacking explicit `origen`; keeping the boundary test free of any
  tarifario coupling; scope creep into the (recorded) delete-button observation.
- **skill_resolution**: loaded `fsframework-plugin-sdd` (routing: plugin-local,
  artifacts under `plugins/catalogo_core/openspec/`, never core) and `sdd-explore`
  (read-only investigation, single `explore.md` artifact, structured analysis +
  Result Contract envelope). No other skill applied.
