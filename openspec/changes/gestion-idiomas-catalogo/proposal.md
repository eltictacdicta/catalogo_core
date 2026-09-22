# Proposal: gestion-idiomas-catalogo (multi-language catalog descriptions)

**Change**: `gestion-idiomas-catalogo`
**SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`) — core `openspec/` is NOT a tracker for this change.
**Secondary plugin**: `plugins/tarifario` (its consumer deltas are authored under THIS change — same routing precedent as `caracteristicas-producto`).
**Artifact store**: `openspec`. **Reference only**: core `openspec/` — no entries created there.
**Delivery**: `delivery_strategy: auto-chain`, `review_budget_lines: 800`.
**Inputs**: `explore.md` (current-state map, 7 defects a–g, ~25 consumers, locked contracts, 5 starter slices), `preproposal.md` (CONFIRMED decisions D1–D12), `research.md` (revision 2, outcome `partial`, 31 sources, verdicts D1–D12, recommendations R1–R9, gaps G1–G9).

---

## Intent

Close the multi-language **description** feature that `catalogo_core` already
half-built: the tables exist, the read API exists, and nothing can manage or edit
it. The change delivers three things:

1. **Language management** — create / rename / activate / deactivate / set-default
   / delete a language (`catalogo_idiomas` CRUD). Today nothing calls
   `catalogo_idioma::save()` or `delete()`; `catalogo_idioma::url()` already points
   at `index.php?page=ventas_articulos#idiomas`, and that section does not exist.
2. **A language selector in the article editor** — one description /
   short-description pair edited for the **currently selected language**, replacing
   the duplicated default-language field and the per-language loop that renders the
   default twice and discards the posted value (defect **g**).
3. **Migration of every direct consumer of `articulos.descripcion`** to the
   language API, with `articulos.descripcion` reduced to a **deprecated, frozen
   compatibility shim**. No backfill, no mirror (D1).

Target semantics: `articulo_descripciones` is the single source of truth for
**all** languages including the default; `catalogo_idiomas.por_defecto` is the only
default pointer; the read chain is **requested language → configured default →
`articulos.descripcion` → `''`**.

The change updates locked canonical specs (`articulo-detalle-canonico`
ART-01/ART-05, `articulos-excel-import-export`), touches ~25 production points
across two plugins, and must be chained. Honest size is stated in §"Size Forecast".

## Confirmed decisions (settled input)

D1–D12 are **maintainer decisions, not research conclusions**. `research.md` §6
analysed their consistency with external evidence (§10 of that file is explicit
that analysis is not approval). They are received here as settled scope input and
are not re-opened.

| ID | Decision | Research verdict |
|---|---|---|
| **D1** | No re-sync of `articulos.descripcion`: never backfilled from or mirrored to `articulo_descripciones`. | `partial` — forbids the source-language write-through PrestaShop/Odoo keep (S5, S1) |
| **D2** | Single description / short-description pair driven by a language selector in `ventas_articulo`. | `supports` (S4, S20, S25) |
| **D3** | Migrate every direct consumer of `articulos.descripcion` to `articulo::get_descripcion_idioma()` (or explicit-language equivalent). | `partial` — does not decide the consumers with no language context (→ D10) |
| **D4** | `articulo_descripciones` is the single source of truth for ALL languages, including the default. | `supports` (S1, S4, S20) |
| **D5** | `catalogo_idiomas.por_defecto` is the only default pointer; changing it is a flag flip touching no description data. | `supports` (S5, S18, S24); WPML (S8, S9) is the counter-example that validates it |
| **D6** | Read chain: requested → configured default → `articulos.descripcion` → `''`. | `supports with caveats` — the legacy third leg has no external equivalent and is marked deprecated |
| **D7** | Run `sdd-research` before `propose`. | `not addressed` (process decision) |
| **D8** | Excel: one column per active language (locale-suffixed headers). | `partial` — per-locale export is evidenced (S22); the suffix header shape is a local convention |
| **D9** | Language management UI lives in a `#idiomas` section on `ventas_articulos`; no new page, no new menu row. | `not addressed` (product-local; confirms the existing `url()` seam) |
| **D10** | No-context consumers resolve to the configured default language. | `partial` — "default as last resort" is evidenced (S5); "silence means default" is a local simplification |
| **D11** | Article search is language-agnostic (`LEFT JOIN articulo_descripciones` + `OR`). | **contradicts** every retrieved system (S1, S6, S20, S28/S29) — deliberate deviation |
| **D12** | Language mutations are administrator-only (`$user->admin`), POST-only, CSRF-validated. | `not addressed` (framework-local) |

## Approach

**Make the translation table authoritative, then retire the base column from the
read path — without ever moving its data.**

### Target architecture

- **`articulo_descripciones` is the single source of truth for every language**,
  the default one included (D4). A translation is present or absent; a fallback is
  resolved at read time and is **never materialised** (R2 — do not copy Sylius's
  write-on-read, S18).
- **`catalogo_idiomas.por_defecto` is the only default pointer** (D5). Changing
  the default changes resolution only; it moves no description row. This avoids the
  WPML failure mode where the default is welded to the content's origin language
  and untranslated content disappears when the default changes (S8, S9).
- **`articulos.descripcion` is a deprecated frozen compatibility shim.** It is not
  backfilled into `articulo_descripciones`, it is not mirrored on write, and it is
  never presented as an equivalent of the default language (D1). Its text can only
  go stale; that is accepted, and the spec must say so.
- **Read chain (D6)**: requested language → configured default → the legacy column
  → `''`. The third leg exists only to keep pre-existing articles legible; it is a
  shim, not a language.
- **Default resolution is total (R1)**: exactly one active `por_defecto` always
  resolves; an unresolvable default degrades deterministically instead of returning
  `false` (defect **d**).
- **Language mutations are administrator-only, POST-only and CSRF-validated**
  (D12). The `ventas_articulos` page stays accessible: the page itself keeps **no**
  `#[AdminOnly]` attribute.

### Model foundation (catalogo_core)

| Surface | Change |
|---|---|
| `model/core/catalogo_idioma.php` | Total default + `get_effective_default_code()`; invariant "exactly one **active** default" enforced on `save()`/`delete()`/deactivate; `set_default()`; last-language guard; `delete()` orphan cleanup (mechanism decided in `design`, R7); validation hardening |
| `model/core/articulo_descripcion.php` | Clearing semantics: `test()` accepts an empty `descripcion`; save path deletes the row when both `descripcion` and `descripcion_corta` are empty; **keep the row when only `descripcion_corta` is set** (local decision, R3); `articulos_search_*` invalidation on every write (defect **f**) |
| `model/core/articulo.php` | `get_descripcion_idioma()`/`descripcion_idioma()` resolve a **dynamic** default instead of the hard-coded `'es'`; remove the mirror branch from `set_descripcion_idioma()` (D1); optional explicit `descripcion_corta` accessor (decided in `design`); `search()` becomes language-agnostic through the query builder |
| `model/table/articulo_descripciones.xml` | `codidioma` FK/cleanup decision — in scope for the change, mechanism (`ON DELETE CASCADE` + idempotent migration vs application-level cleanup) decided in `design` |

### Language-management UI (catalogo_core)

`#idiomas` section on `ventas_articulos` (D9), reusing the page's already-loaded
`$this->idiomas` and its `validateFormToken()` CSRF gate. Controller actions are
POST-only and administrator-only (D12). New es/en translation keys for the full
CRUD vocabulary. The `catalogo_idioma::url()` target stops dangling.

### Article editor selector (catalogo_core)

Replace the duplicated base textarea (`ventas_articulo.html.twig:121`) and the
per-language loop (`partials/articulos/tab_multiidioma.html.twig:11`) with **one**
selector-driven description / short-description pair. `saveMultiidiomaDescriptions()`
drops the default-language `continue` branch (defect **g**) and stops skipping empty
input (defect **b**). The `#multiidioma` anchor is preserved.

### Consumer migration (D3 + D10 + D11)

- **Search**: language-agnostic predicate in `ArticuloSearchQueryBuilder`, base
  column `OR` a join over `articulo_descripciones` (D11), plus mandatory
  `articulos_search_*` invalidation on every description write (defect **f**).
- **Excel export/import** (`catalogo_core`): one column per active language with
  locale-suffixed headers (D8), additive to the locked base header list.
- **Import wizard**: targets an explicit language, defaulting to the configured
  default (R5) — closes the D10 ambiguity for writes.
- **No-context readers** resolve to the configured default (D10):
  `Model/CatalogoApiService.php`, `model/tarif_articulo_precio.php`,
  `model/tarif_tarifa_articulo.php` SQL joins, `Controller/VentasOpcional.php`,
  and the tarifario readers `tarif_grupo_articulo`, `tarif_historial_precios`,
  `tarif_actualizar_precios`, `tarif_roles`.
- **tarifario dead code**: fix the misused `descripcion($this->codidioma, 50)` and
  the nonexistent `get_descripcion($this->codidioma)` in
  `extras/tarif_controller.php:240,243` (defect **a**). The tarifario readers that
  are already correct (`ExcelRowUpdater`, `ArticuloListActionHandler`,
  `tarif_catalogo_view`, `tarif_configurador_opcionales`, the catalogo list state
  trait) are **verified, not rewritten**.

### Spec deltas

Authored under this change, mirroring the `caracteristicas-producto` layout:

| Delta | Capability | Nature |
|---|---|---|
| `specs/catalogo-core/gestion-idiomas/spec.md` | `gestion-idiomas` | **New** — language registry management + invariants + editor selector |
| `specs/catalogo-core/articulo-detalle-canonico/spec.md` | `articulo-detalle-canonico` | **MODIFIED** — ART-01, ART-05 |
| `specs/catalogo-core/articulos-excel-import-export/spec.md` | `articulos-excel-import-export` | **MODIFIED** — D8 shape |
| `specs/tarifario/catalogo-integration/spec.md` | `tarifario/catalogo-integration` | **MODIFIED** — no-context readers → configured default |

If `spec` finds the tarifario Excel reader contract normative, a
`specs/tarifario/catalogo-excel-import-export/spec.md` delta is added; otherwise
tarifario's Excel spec is untouched (its `ExcelRowUpdater` is already the canonical
correct implementation).

## Scope

### In Scope

- **Model foundation** — `catalogo_idioma` (total default, one-active-default
  invariant, default-cannot-be-inactive, last-language guard, `set_default`,
  `delete()` cleanup); `articulo_descripcion` (clearing semantics, cache
  invalidation); `articulo` language API (dynamic default, mirror removal, optional
  short-description accessor); the `codidioma` FK/cleanup obligation (mechanism in
  `design`).
- **Language-management UI** — `#idiomas` section on `ventas_articulos` +
  controller actions (admin-only, POST-only, CSRF-validated; the page keeps **no**
  `#[AdminOnly]`) + partial + es/en translation keys.
- **Article editor language selector** — single pair per selected language; default
  duplication and discarded-input branch removed; `#multiidioma` preserved.
- **Consumer migration in `catalogo_core`** — language-agnostic search, Excel
  export/import, import target-language parameter, `CatalogoApiService`, model SQL
  joins, `VentasOpcional`, search-cache invalidation.
- **Consumer migration in `tarifario`** — no-context readers, plus the dead-code fix
  in `extras/tarif_controller.php`; already-correct readers verified only.
- **Tests** — new model / UI / consumer tests; locked tests updated in lockstep
  (see "Locked-contract impact").
- **Spec deltas** — new `gestion-idiomas`; MODIFIED
  `articulo-detalle-canonico`, `articulos-excel-import-export`,
  `tarifario/catalogo-integration`.

### Out of Scope

- **No backfill** of `articulos.descripcion` into `articulo_descripciones` (D1).
- **No dropping** of the `articulos.descripcion` column — it stays as the frozen
  legacy shim.
- **No per-language descriptions for other entities** (familias, opcionales,
  documents) — not requested.
- **No UI-locale i18n changes** — the plugin's Symfony-Translation es/en UI is a
  separate concern from description content (R9). Symfony's UI fallback chain is not
  the description read chain.
- **No supersession of the `tarif_idioma` / `tarif_descripcion` legacy aliases** —
  they already extend the new models; deleting them is a separate cleanup.
- Any core (`base/`, `src/`, root `controller|model`) change — core `openspec/`
  receives nothing.

## Evidence-backed obligations

Carried from `preproposal.md` §"Evidence-backed refinements" and `research.md`
R1–R9. These are obligations on `spec` / `design`, not optional commentary.

1. **D11 is a deliberate deviation and must be documented as such (R6).** Every
   retrieved system scopes search to a language or store view (PrestaShop `id_lang`
   index words S6, Akeneo working locale S20, Odoo `COALESCE(lang, en_US)` S1,
   Magento store-view-scoped search S28/S29). It is defensible here **only** because
   the fork has ~25 legacy readers and a frozen base column whose text lives outside
   the translation table. The spec MUST state the deviation and justify it from that
   constraint so a later contributor does not "fix" it into a regression, and it
   MUST require `articulos_search_*` invalidation on **every** description write
   (defect **f**).
2. **D1 has a real cost.** PrestaShop fills empty required translations from the
   default on write (S5) and Odoo always persists `en_US` (S1); D1 forbids both.
   `articulos.descripcion` can only go stale. The spec MUST NOT present it as an
   equivalent of the default language — it is a deprecated compatibility shim.
3. **R2 — never materialise fallbacks**, on read or on write. Do not copy Sylius's
   write-on-read (S18). Consequence: remove the mirror branch in
   `set_descripcion_idioma()` and never let `saveMultiidiomaDescriptions()` copy
   `$art->descripcion` into a language slot.
4. **R3 — clearing deletes the row.** PrestaShop updates the `_lang` row in place
   and Odoo drops the language key (S1, S5); both treat a cleared translation as
   *absence for that language*, with the fallback supplying the visible text. The
   save path deletes the row when both fields are empty; **keeping the row when only
   `descripcion_corta` is set is a local decision** and MUST be marked as such.
5. **R1 — default resolution must be total.** PrestaShop resolves an invalid/removed
   language to `PS_LANG_DEFAULT` and documents it as an invariant (S5). This is the
   evidence basis for fixing defect **d**.
6. **R4/D8 — the locale-suffixed export header is a local convention.** No retrieved
   system uses locale-suffixed headers normatively (S1, S4); the fork's own precedent
   (`descripcion_es` / `descripcion_en` in tarifario) does. The spec MUST define the
   ordering (default language first, then remaining active languages by `codidioma`),
   the handling of deactivated languages (omitted from new exports), and that unknown
   or removed locale columns on import are **ignored** — never an error, never a
   silent language creation, never a registry mutation.
7. **R5 — import targets an explicit language**, defaulting to the configured
   default (Akeneo edits/imports happen inside a working context, S20).
8. **Carried gaps G1, G3, G6, G8 are closed during `spec`**, not by another research
   round: G1 (read-path fallback confirmation) is a design assumption, G3/G6
   (normative export header convention) are settled by R4 as a local convention, and
   G8 ("adding a language later" versus already-exported files) is settled by the R4
   deactivated/unknown-column rules. No confirmed decision depends on them.

## Locked-contract impact

The selector work collides directly with existing canonical contracts. They are
updated **in lockstep**, in the same slice as the code that breaks them — never
afterwards, never silently:

- `openspec/specs/articulo-detalle-canonico/spec.md` — **ART-01** locks the public
  `idiomas` array, `saveMultiidiomaDescriptions` and the `#multiidioma` partial
  include; **ART-05** locks the tab set and the four frozen hook markers. Both are
  **MODIFIED requirements**.
- `tests/VentasArticuloControllerTest.php` — asserts the view contains
  `fsc.articulo.descripcion`, the `#multiidioma` anchor and the
  `tab_multiidioma.html.twig` include; asserts the controller's `idiomas` array and
  `saveMultiidiomaDescriptions`. Updated to assert the selector and the single-pair
  save path.
- `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` — exercises the
  POST `sdescripcion` path and carries `tab_multiidioma.html.twig` in its locked
  marker list. Updated for per-language writes and clearing.
- `tests/Integration/CatalogoCoreHookMarkersTest.php` — the four frozen markers must
  stay frozen (name and position); the selector must not touch them.

The `#multiidioma` anchor and the four hook markers survive the change. Everything
else in ART-01/ART-05 that the selector invalidates is re-expressed, not deleted.

## Size Forecast

| Signal | Value |
|---|---|
| Production touch points | ~25 (≈10 `catalogo_core` + ≈15 `tarifario`) |
| Locked specs updated | 2 `catalogo_core` (+ 1 new) + 1 tarifario delta |
| Locked test files updated | ≥ 3 (`VentasArticuloControllerTest`, `VentasArticuloArticleEditAbsorptionTest`, `CatalogoCoreHookMarkersTest`) |
| New tests | model foundation, language-management UI, editor selector, search, export/import, consumers |
| Authored lines (est.) | **2,000–3,500+** |
| 800-line budget risk | **High** |
| Decision needed before apply | **Yes** |
| Chained PRs recommended | **Yes** |

`auto-chain` cannot fit this in one PR: it is multi-hundred-line work across two
plugins plus locked-spec and locked-test rewrites. The change **must** be chained.
`tasks` starts from the five slices proposed in `explore.md` §8, in dependency
order:

| Slice | Scope | Depends on |
|---|---|---|
| **1. Model foundation** | `catalogo_idioma` invariants + `articulo_descripcion` clearing + `articulo` language API + FK/cleanup + tests | — |
| **2. Language-management UI** | `#idiomas` section, controller actions, partial, es/en keys, tests | 1 |
| **3. Article editor selector** | Selector-driven single pair, `saveMultiidiomaDescriptions` rewrite, ART-01/ART-05 delta, locked test updates | 1 |
| **4. `catalogo_core` consumer migration** | Search + cache invalidation, Excel export/import, API, SQL joins, `VentasOpcional`; split 4a/4b if the export shape pushes past budget | 1 (cache) |
| **5. `tarifario` consumer migration** | No-context readers + dead-code fix; verify already-correct readers; tarifario delta + tests | 4 |

Slices 2 and 3 are independent of each other and may be ordered either way after
slice 1. The `articulo-detalle-canonico` delta belongs to slice 3; the
`articulos-excel-import-export` delta belongs to slice 4.

## Risks

| Risk | L | Mitigation |
|---|---|---|
| **Search regression + stale cache** — a naive single-language search hides non-default-language articles; cached `articulos_search_*` results survive description writes (defect f) | High | One isolated language-agnostic predicate (D11); mandatory invalidation on every description write; tests that a non-default term is findable and a cleared translation stops matching |
| **Export/import header contract** — the base header list is locked and D8 changes the file shape; unknown columns on re-import could mutate the language registry | High | Additive locale columns only; base headers byte-identical; R4 rules (ordering, deactivated omitted, unknown columns ignored, never a registry mutation); import targets an explicit language |
| **Locked-contract collision** — ART-01/ART-05 + three locked test files constrain the selector work | High | Explicit MODIFIED deltas and test updates in the same slice as the code; `#multiidioma` anchor and the four hook markers preserved |
| **Default-less / multi-default states** — `get_default()` can return `false`; a deactivated default desynchronises the selector from the read chain; deleting the last language would orphan consumers | Med | Slice 1 makes resolution total and the invariants (exactly one active default; default cannot be inactive; last language not deletable) enforced and idempotent, with tests |
| **Cross-plugin legacy boot path** — tarifario still boots `tarif_idioma`/`tarif_descripcion` and four controllers extend `tarif_controller` | Med | Fix/remove the dead buggy method without breaking its subclasses; legacy aliases untouched; verify the already-correct readers rather than rewriting them |
| **Orphan rows on language delete** — no FK today, `catalogo_idioma::delete()` does no cleanup | Med | FK with `ON DELETE CASCADE` or application-level cleanup (decided in `design`, R7); idempotent migration if a schema change is added |
| **Permission / CSRF surface** — mutations are configuration actions on a non-admin page | Med | Administrator-only (`$user->admin`), POST-only, existing `validateFormToken()` gate; denial tests; page keeps no `#[AdminOnly]` |
| **Cache invalidation omissions** — any new cached per-language read path must be invalidated on write | Med | Invalidation is an explicit spec obligation, not an incident of `articulo::save()` |
| **Test isolation** — `processIsolation="true"`; new model static state and `$GLOBALS['plugins']` | Low | Follow the existing plugin test conventions |

## Success Criteria

- [ ] A language can be created, renamed, activated, deactivated, set as default
      and deleted from the `#idiomas` section; exactly one active default always
      resolves; the default cannot be inactive; the last language cannot be deleted.
- [ ] Language mutations are administrator-only, POST-only and CSRF-validated; the
      `ventas_articulos` page keeps **no** `#[AdminOnly]`.
- [ ] The article editor shows one description / short-description pair for the
      selected language; the default language is not rendered twice and the posted
      value is never discarded.
- [ ] Clearing both fields for a language deletes its row; a `descripcion_corta`-only
      row is preserved (documented as a local decision).
- [ ] No consumer reads `articulos.descripcion` as its primary path; no-context
      consumers resolve the configured default; the legacy column is documented as a
      deprecated shim.
- [ ] Search is language-agnostic, the D11 deviation is stated in the spec, and
      `articulos_search_*` is invalidated on every description write.
- [ ] Excel export carries one column per active language in deterministic order;
      base headers are byte-identical; the import targets an explicit language and
      ignores unknown locale columns.
- [ ] `articulo-detalle-canonico` ART-01/ART-05, `articulos-excel-import-export` and
      the tarifario integration delta are updated, and the locked tests are green.
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` passes,
      plus the root Plugins suite; PHPStan clean.

## Dependencies and Rollback

- `strict_tdd: true` — tests land with each slice (RED → GREEN); runner
  `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`.
- No new Composer dependency → no `vendor/` commit concern.
- The change is **non-destructive**: no column is dropped and no legacy data is
  rewritten. Slices 1–5 revert by reverting their commits; the FK/cleanup migration
  (if chosen in `design`) is idempotent and reversible, and a pre-migration dump is
  the operator-level safety net. Clear the Twig cache after any revert of the view
  layer.
