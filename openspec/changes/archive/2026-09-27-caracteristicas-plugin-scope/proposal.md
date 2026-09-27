# Proposal: caracteristicas-plugin-scope

> SDD root: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`).
> Core `openspec/` is reference only — no entry is created there (CAR-19).
> Change dir: `plugins/catalogo_core/openspec/changes/caracteristicas-plugin-scope/`.

## Intent

`catalogo_core` exposes feature definitions (`catalogo_caracteristicas`) through
**three independent read paths** that none of them scope by ownership:

1. the management panel (`Controller/VentasCaracteristicas.php::load_definiciones()`)
   reads the raw model `all()`;
2. the resolver (`Services/CaracteristicaResolver.php::definitions()`) reads the
   model;
3. the batch reader (`Services/CaracteristicaValorBatchReader.php::definition_rows()`)
   runs its own raw SQL.

None of them consults `origen`. As a verified live fact, `tarifario` is **absent**
from `tmp/{FS_TMP_NAME}enabled_plugins.list` yet its `en_catalogo`/`en_tarifa`
definitions still appear in the `ventas_caracteristicas` panel and still resolve
for product-list columns and import. A plugin-owned definition whose plugin is
disabled should not behave as if the plugin were active.

This change makes a definition owned by a **non-enabled** plugin **inert across
the whole feature system**, with a single plugin-agnostic ownership rule, while
preserving every DB row and assigned value so that re-enabling the owning plugin
restores the definition and its values untouched.

## Scope

### In Scope

- One ownership predicate: a definition is active when `origen === ''`, otherwise
  when `\FSFramework\Core\Plugins::isEnabled($origen)` returns `true`. No hardcoded
  plugin name (preserves CAR-19).
- Wiring the predicate into the **three read paths**:
  - `CaracteristicaResolver::definitions()` (cascades to `resolve()`,
    `resolve_bool()`, `resolve_opcional_visibility()`, the flagged helpers and
    `CaracteristicaValorStore::definitions()`);
  - `Controller/VentasCaracteristicas.php::load_definiciones()` (panel listing);
  - `CaracteristicaValorBatchReader::definition_rows()` (product-list columns,
    listable columns, hook context) — `origen` added to the SELECT and filtered
    in PHP because the enabled set is runtime-only and not expressible in SQL.
- **Inert means inert**: direct POST/URL access to an inert definition's actions
  (`save_definition`, `delete_definition`, `toggle_flag`, `save_catalogo_valor`)
  is refused/ignored; no mutation of inert rows.
- Non-destructive: rows and assigned values are preserved; re-enabling the owner
  restores definition and values with **no writes**.
- Operator-created rows (`origen = ''`) remain always active.
- Fail-closed direction: when the plugin registry is unavailable
  (`$GLOBALS['plugins']` empty, e.g. an unpopulated CLI/test context), plugin-owned
  definitions are hidden; `origen = ''` rows are the only unconditional exception.
- Spec delta in the existing capability `caracteristicas-producto`: add the new
  requirement (exploration proposes `CAR-20`) and modify targeted scenarios in
  CAR-06/11/12/16/17/18/19.
- Test coverage (new predicate unit test plus regression assertions) with
  `strict_tdd: true`.

### Out of Scope

- **Deletion of definitions.** Explicitly not part of this change.
- **The "no delete button" observation.** CAR-11 makes plugin-owned rows
  non-deletable, so the panel renders no delete button for any row today
  (`View/ventas_caracteristicas.html.twig:78`). Recorded as an observation, not
  fixed here.
- **Re-homing `en_catalogo`/`en_tarifa`.** They stay `tarifario`-owned;
  inert-is-correct. Their call sites must merely degrade safely when tarifario is
  disabled.
- **Any change under `plugins/tarifario/`** — no production file and no tarifario
  spec is modified (CAR-19).
- **Any core file** (`base/`, `src/`, root `controller/`, root `model/`) and any
  repository-root `openspec/` entry.
- **New Composer dependency.**
- A tarifario-side spec delta for its host-neutrality guarantee
  (`R-TAR-HOOK-007`) — optional, separate follow-up; referenced here only as
  context.

## Capabilities

> Contract with the spec phase. Only spec-level behavior changes are listed.

### New Capabilities

None.

### Modified Capabilities

- **`caracteristicas-producto`** — the existing capability spec at
  `plugins/catalogo_core/openspec/specs/caracteristicas-producto/spec.md` receives
  a **delta spec** in this change folder:
  - **ADD** a new requirement (proposed id **CAR-20 — "Enabled-plugin ownership
    scope"**): the normative rule that a non-empty `origen` whose plugin is not
    enabled makes the definition inert across resolver, panel, import/export and
    product-list columns; rows and values preserved; re-enable restores them;
    `origen = ''` always active; the check uses the framework plugin registry and
    MUST NOT introduce a `catalogo_core → tarifario` dependency.
    Scenarios: (a) owner disabled ⇒ absent from panel/import/columns and
    `resolve()` returns `null`; (b) owner re-enabled ⇒ definition and values return
    unchanged with no writes; (c) operator row (`origen = ''`) unaffected.
  - **MODIFY CAR-06** (effective-value precedence) — one scenario pinning that the
    owner-disabled `null` path is the same as the existing missing/inactive `null`
    path.
  - **MODIFY CAR-11** (default feature registration) — clause linking `origen`
    non-deletability to the new enabled-scope rule.
  - **MODIFY CAR-12** (D12 opcional visibility) — one scenario confirming the
    owner-disabled path yields "not visible" with no new return value and no writes.
  - **MODIFY CAR-16** (`listable` columns) — scenario: an owner-disabled `listable`
    definition emits no column (batch-reader filter).
  - **MODIFY CAR-17** (`importable`/`exportable` columns) — scenario: an
    owner-disabled definition contributes no import field and no export column.
  - **MODIFY CAR-18** (management panel) — scenario: owner-disabled definitions are
    not listed, and their direct actions are refused; record-in-spec that
    plugin-owned rows render no delete button (CAR-11) as observed and out of scope.
  - **MODIFY CAR-19** (plugin-local boundaries) — scenario asserting the enabled
    check reads `origen` + the framework registry and adds no tarifario coupling or
    hardcoded plugin name.

**No other capability** (for example `opcionales-*`, `articulos-excel-import-export`
or any tarifario spec) is modified.

## Approach

Adopt the exploration's recommended **Approach B**: one small, plugin-agnostic
ownership predicate consumed by all three read paths.

- Introduce a dedicated ownership helper (working name
  `Services/CaracteristicaOwnership.php`, final name in design) exposing a single
  testable predicate, e.g. `is_active(string $origen): bool`, that returns `true`
  for `''` and otherwise delegates to
  `\FSFramework\Core\Plugins::isEnabled($origen)` behind an overridable seam so
  DB-free tests can control the enabled set.
- Wire it into:
  1. `CaracteristicaResolver::definitions()` — the effective-value choke point;
     everything built on it (values, writes, flagged helpers,
     `CaracteristicaValorStore`) follows and owner-disabled definitions become
     no-op reads (`null`) and no-op writes.
  2. `VentasCaracteristicas::load_definiciones()` — the panel listing.
  3. `CaracteristicaValorBatchReader::definition_rows()` — add `origen` to the
     SELECT and filter in PHP, keeping the constant query count (CAR-16 "no N+1").
- Panel direct `get()` actions (`save_definition`, `delete_definition`,
  `toggle_flag`, `save_catalogo_valor`) resolve the target definition and then
  apply the same predicate, refusing/ignoring the action when it is inert
  (fail-closed), consistent with "inert means inert".
- `origen = ''` short-circuits to active before the registry is consulted, so
  operator rows never depend on plugin state.
- Accept the consequence: with `tarifario` disabled, the D12 opcional visibility
  derived from `en_catalogo`/`en_tarifa` resolves to "not visible" in a
  `catalogo_core`-only install. This is intended and must be asserted by tests,
  not assumed.
- Reuse the framework registry (`\FSFramework\Core\Plugins`) and read the **row's
  `origen`**; never reference the tarifario namespace or the literal `'tarifario'`
  in `catalogo_core`.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `plugins/catalogo_core/Services/CaracteristicaOwnership.php` (new) | New | Single ownership predicate: `''` ⇒ active, else `Plugins::isEnabled($origen)`, behind a test seam. |
| `plugins/catalogo_core/Services/CaracteristicaResolver.php` | Modified | `definitions()` drops inert definitions; cascades to `resolve()`, `resolve_bool()`, `resolve_opcional_visibility()`, `flag`-driven helpers and `CaracteristicaValorStore::definitions()`. |
| `plugins/catalogo_core/Controller/VentasCaracteristicas.php` | Modified | `load_definiciones()` filters inert rows for the listing; `save_definition`/`delete_definition`/`toggle_flag`/`save_catalogo_valor` refuse inert targets. |
| `plugins/catalogo_core/Services/CaracteristicaValorBatchReader.php` | Modified | `definition_rows()` adds `origen` to the SELECT and filters in PHP; query count stays constant (CAR-16). |
| `plugins/catalogo_core/Services/CaracteristicaValorStore.php` | Inherited | Becomes no-op for inert definitions via resolver-filtered `definitions()`; operator (`origen=''`) rows unaffected. |
| `plugins/catalogo_core/Services/ArticuloExcelImportWizardService.php` | Inherited | Inherits the rule via `importable_definitions()`; inert definitions add no import field. |
| `plugins/catalogo_core/extras/VentasArticulosListTrait.php` | Asserted | Product-list visibility resolves `false` when tarifario is disabled; no code change expected, behavior must be tested. |
| `plugins/catalogo_core/extras/VentasOpcionalesListTrait.php` | Asserted | D12 opcional visibility resolves "not visible" with tarifario disabled; behavior must be tested. |
| `plugins/catalogo_core/Controller/VentasArticulo.php` | Asserted | Persists nothing for the two flags when tarifario is disabled (best-effort write already documented); behavior must be tested. |
| `plugins/catalogo_core/extras/CaracteristicaHookContextTrait.php` | Asserted | Hook context excludes inert definitions; behavior must be tested. |
| `plugins/catalogo_core/View/ventas_caracteristicas.html.twig` | Unchanged | No code change required once the controller filters the listing; delete-button observation (CAR-11) stays as-is. |
| `plugins/catalogo_core/tests/CaracteristicaOwnershipTest.php` (new) | New | Predicate unit test with explicit `origen` seeds and controlled enabled set. |
| `plugins/catalogo_core/tests/Controller/VentasCaracteristicasControllerTest.php` | Modified | Covers inert rows absent from listing and direct actions refused. |
| `plugins/catalogo_core/tests/CaracteristicaResolverTest.php` | Modified | Covers inert definition ⇒ `null` (same contract as missing/inactive). |
| `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php` | Modified | Covers inert `listable` definition emits no column, constant query count. |
| `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php` | Modified | Asserts no tarifario namespace/literal in the ownership check and no new dependency direction. |
| `plugins/catalogo_core/tests/CaracteristicaDefaultsTest.php`, `OpcionalVisibilityDerivationTest.php`, `OpcionalVisibilityParityTest.php`, `Services/ArticuloExcelCaracteristicaTest.php`, `Services/ArticuloExcelFeaturePersistenciaTest.php` | Asserted | Regression: existing fixtures omit `origen` (default `''`) and must stay green; new inert cases seeded explicitly. |
| `plugins/catalogo_core/openspec/changes/caracteristicas-plugin-scope/specs/caracteristicas-producto/spec.md` | New | Delta spec (ADD CAR-20; MODIFIED CAR-06/11/12/16/17/18/19). |
| `plugins/tarifario/**` | Unchanged | No production file and no tarifario spec change (CAR-19). |
| Repository-root `openspec/` | Unchanged | No change entry (CAR-19). |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Panel direct `get()` actions allow a crafted POST to mutate an inert definition when only the listing is filtered. | Med | Apply the predicate to `save_definition`/`delete_definition`/`toggle_flag`/`save_catalogo_valor`; refuse inert targets (fail-closed) and test it. |
| Fail-mode when the registry is unavailable: `Plugins::isEnabled` reads `$GLOBALS['plugins'] ?? []`, so an unpopulated CLI/test context makes every plugin-owned definition inert. | Med | Document and test the chosen fail-closed direction; `origen = ''` is the unconditional operator exception; `base/config2.php` populates the registry at bootstrap in normal requests. |
| Behavior change in a `catalogo_core`-only install: D12 opcional visibility derived from `en_catalogo`/`en_tarifa` resolves to "not visible". | High (intended) | Explicitly accepted; assert `null` ⇒ `false`/not-visible with tests so the change is deliberate, not accidental. |
| Existing test fixtures that stub a plugin-owned definition without enabling its plugin will now go inert. | Med | Audit fixtures; most omit `origen` and default to `''` (stay active). New tests seed `origen` and `$GLOBALS['plugins']` explicitly. |
| Batch reader regression: adding `origen` to `definition_rows()` could introduce per-row queries. | Low | Filter in PHP over the existing single fetch; keep the constant query count and assert it (CAR-16 "no N+1"). |
| Boundary violation: an ownership check that hardcodes `'tarifario'` or imports the tarifario namespace. | Low | Predicate reads only the row's `origen` + `\FSFramework\Core\Plugins`; `CaracteristicaBoundariesTest` extended to prove it. |
| Scope creep into the "no delete button" observation (CAR-11) or into deletion of definitions. | Med | Both are explicitly out of scope and recorded as observations; the spec delta touches only the enabled-scope rule. |
| Three call sites drifting out of agreement. | Low | One shared predicate; add a test that the panel, resolver and batch reader agree for the same enabled state. |

## Rollback Plan

- **Code.** The change is additive and self-contained in
  `plugins/catalogo_core/` (one new helper plus three wiring sites and the
  controller action guards). Revert the commit to restore current behavior; no
  schema change and no data migration is involved, so no database rollback is
  needed.
- **Data.** Zero destructive operations: inert rows and their assigned values are
  never deleted or mutated. Re-enabling the owning plugin immediately restores the
  definition and values, so the change is reversible at runtime by toggling plugin
  state alone.
- **Spec.** Revert the delta spec; the canonical
  `openspec/specs/caracteristicas-producto/spec.md` is only updated at archive
  time, so no canonical spec is affected while the change is active.
- **Emergency.** If a production install needs the previous behavior before a
  revert is deployed, re-enabling the owning plugin restores its definitions
  without a code change.

## Dependencies

- Framework seam already present: `\FSFramework\Core\Plugins::isEnabled()` /
  `enabled()` (`src/Core/Plugins.php`), populated from
  `tmp/{FS_TMP_NAME}enabled_plugins.list`. No core change required.
- Existing model `catalogo_caracteristica` (`origen` column, `is_deletable()`).
- Existing test suites and the plugin runner:
  `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` and the
  root PHPUnit suite for regression.
- Cross-reference (context only, not a change): tarifario spec
  `R-TAR-HOOK-007` ("Host neutrality with tarifario inactive"). No tarifario file
  or spec is modified by this change.

## Success Criteria

- [ ] With the owning plugin disabled: its definitions do not appear in the
      `ventas_caracteristicas` panel, add no product-list column, offer no
      import/export field, and `resolve()` returns `null` for them.
- [ ] The panel, the resolver and the batch reader agree on the active definition
      set for the same enabled-plugin state (single-rule consistency asserted by
      tests).
- [ ] Direct POST/URL access to an inert definition's
      action (`save_definition`, `delete_definition`, `toggle_flag`,
      `save_catalogo_valor`) persists nothing and is reported/ignored.
- [ ] Re-enabling the owning plugin restores the definition **and** its assigned
      values byte-identically, with no writes occurring during the inert period.
- [ ] Operator-created definitions (`origen = ''`) are listed, resolvable and
      writable regardless of plugin state.
- [ ] A `catalogo_core`-only install (tarifario disabled) resolves product
      visibility as not visible and writes nothing for the two flags — asserted,
      not assumed.
- [ ] No file outside `plugins/catalogo_core/` changes; `plugins/tarifario/` and
      the repository-root `openspec/` are untouched (CAR-19).
- [ ] The ownership check contains no tarifario namespace reference and no
      hardcoded `'tarifario'` literal (`CaracteristicaBoundariesTest`).
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
      passes, and the root PHPUnit suite shows no regression.
