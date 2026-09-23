# Delta for gestion-idiomas

New capability owned by `catalogo_core`. All requirements are ADDED; there is no
prior canonical `gestion-idiomas` spec and no MODIFIED/REMOVED block for this
domain. The locked specs that consume this capability
(`articulo-detalle-canonico`, `articulos-excel-import-export`, tarifario
`catalogo-integration`) carry their own deltas in this change.

Scope of this capability: the `catalogo_idiomas` registry lifecycle and its
invariants, the `articulo_descripciones` clearing semantics, the description read
chain and the deprecated frozen base column, the article search-cache
invalidation and the language-agnostic search deviation, the no-context consumer
resolution, and the article editor language selector.

The registry is seeded `es` (default) + `en` by `catalogo_idioma::install()` /
`ensure_defaults()` (`plugins/catalogo_core/model/core/catalogo_idioma.php:43-63`);
`catalogo_idioma::url()` already targets `index.php?page=ventas_articulos#idiomas`
(`:65-68`), a section that does not exist today.

## ADDED Requirements

### Requirement: GDI-01 — Language registry lifecycle and management surface

`catalogo_core` MUST own the full `catalogo_idiomas` lifecycle through
`model/core/catalogo_idioma.php`: create, rename, activate, deactivate, set
default and delete. The management surface MUST be a `#idiomas` section on
`ventas_articulos` (D9): `catalogo_idioma::url()` MUST resolve to a real section,
and the change MUST NOT introduce a new page or a new menu row. `codidioma` MUST
stay the stable PK (2–5 chars) and `nombre` MUST be 1–50 chars, validated by
`test()` (`:99-115`).

#### Scenario: Full lifecycle round trip

- GIVEN an administrator on the `#idiomas` section
- WHEN a language is created, renamed, deactivated and reactivated
- THEN each mutation persists and the section reflects it
- AND `catalogo_idioma::url()` resolves to the section (no dangling anchor)
- Test: `plugins/catalogo_core/tests/CatalogoIdiomaManagementTest.php`

#### Scenario: Invalid payload is rejected

- GIVEN a `codidioma` shorter than 2 chars or a `nombre` longer than 50 chars
- WHEN the save runs
- THEN `test()` returns FALSE, an error is reported and nothing persists
- Test: `plugins/catalogo_core/tests/CatalogoIdiomaManagementTest.php`

### Requirement: GDI-02 — Exactly one active default; total, deterministic resolution

`catalogo_idiomas.por_defecto` MUST be the only default pointer (D5). After
**any** mutation (save, set-default, deactivate, delete) exactly one **active**
language MUST carry `por_defecto = TRUE`, and default resolution MUST be total and
deterministic (R1, defect **d**): it MUST always return the code of an active
language — never `false`, never `''`, never an inactive code — and repeated calls
for the same database state MUST return the same code. Setting a new default MUST
atomically clear the flag on the previous default and MUST touch no
`articulo_descripciones` row (D5). Deactivating the default language MUST be
rejected. When the persisted state has no `por_defecto` row, resolution MUST
degrade deterministically to an active language instead of returning `false`.

#### Scenario: Setting a new default is a flag flip

- GIVEN `es` is the default with a description row
- WHEN `en` is set as the default
- THEN `en.por_defecto = TRUE` and `es.por_defecto = FALSE` in the same operation
- AND no `articulo_descripciones` row was created, updated or moved
- Test: `plugins/catalogo_core/tests/CatalogoIdiomaInvariantsTest.php`

#### Scenario: Deactivating the default is rejected

- GIVEN the default language
- WHEN it is deactivated
- THEN the deactivation is refused with an explicit error and it stays active
- Test: `plugins/catalogo_core/tests/CatalogoIdiomaInvariantsTest.php`

#### Scenario: Default resolution is total

- GIVEN a registry with no `por_defecto` row (or a removed/invalid default)
- WHEN the default is resolved twice for the same state
- THEN both calls return the same **active** language code and never `false`
- Test: `plugins/catalogo_core/tests/CatalogoIdiomaInvariantsTest.php`

#### Scenario: The invariant holds after every mutation

- GIVEN any sequence of create/rename/activate/deactivate/set-default/delete
- WHEN the registry is inspected afterwards
- THEN exactly one active language carries `por_defecto = TRUE`
- Test: `plugins/catalogo_core/tests/CatalogoIdiomaInvariantsTest.php`

### Requirement: GDI-03 — Last-language guard and delete cleanup

The last remaining language MUST NOT be deletable. Deleting a language MUST NOT
leave its `articulo_descripciones` rows readable: the delete MUST remove that
language's description rows. The mechanism — a `codidioma` FK with
`ON DELETE CASCADE` plus an idempotent migration, or application-level cleanup in
`catalogo_idioma::delete()` (`:144-152`) — is a design decision (R7); if it is a
schema change the migration MUST be idempotent. `articulo_descripciones.xml` has
no `codidioma` FK today (`:31-44`), which is defect **c**.

#### Scenario: Deleting the last language is refused

- GIVEN a registry with exactly one language
- WHEN its deletion is requested
- THEN it is refused with an explicit error and the row survives
- Test: `plugins/catalogo_core/tests/CatalogoIdiomaDeleteCleanupTest.php`

#### Scenario: Deleting a language removes its descriptions

- GIVEN two languages and description rows for both
- WHEN one language is deleted
- THEN no readable `articulo_descripciones` row remains for it
- AND the other language's rows are untouched
- Test: `plugins/catalogo_core/tests/CatalogoIdiomaDeleteCleanupTest.php`

### Requirement: GDI-04 — Administrator-only, POST-only, CSRF-validated mutations

Every language mutation MUST be administrator-only (`$user->admin`), POST-only and
CSRF-validated through the existing `validateFormToken()` gate (D12). A missing or
invalid token, or a non-administrator actor, MUST persist nothing. The
`ventas_articulos` page itself MUST stay accessible and MUST NOT carry an
`#[AdminOnly]` attribute; the guard is per-action, not page-level.

#### Scenario: Non-administrator persists nothing

- GIVEN an authenticated non-administrator
- WHEN a language mutation is posted with a valid CSRF token
- THEN the mutation is refused and no registry row changes
- Test: `plugins/catalogo_core/tests/CatalogoIdiomaPermissionTest.php`

#### Scenario: CSRF failure persists nothing

- GIVEN an administrator and a POST with a missing or invalid CSRF token
- WHEN the mutation is processed
- THEN no registry row changes and the rejection is explicit
- Test: `plugins/catalogo_core/tests/CatalogoIdiomaPermissionTest.php`

#### Scenario: GET cannot mutate and the page stays accessible

- GIVEN the `ventas_articulos` page and a language action requested via GET
- WHEN the request is processed
- THEN nothing mutates
- AND the page has no `#[AdminOnly]` attribute and remains accessible to authorized non-admins
- Test: `plugins/catalogo_core/tests/CatalogoIdiomaPermissionTest.php`

### Requirement: GDI-05 — Read chain and no fallback materialisation

The description read chain MUST be: **requested language → configured default →
`articulos.descripcion` → `''`** (D6). `articulo::get_descripcion_idioma()` /
`descripcion_idioma()` (`model/core/articulo.php:1392-1427`) MUST resolve the
configured default dynamically; the hard-coded `'es'` parameter default MUST NOT
decide the resolved language. Resolution MUST be a pure read: reading MUST NOT
create, update or materialise any `articulo_descripciones` row, on any leg of the
chain (R2 — explicitly not Sylius's write-on-read, S18).

#### Scenario: Requested language wins

- GIVEN rows for the requested language and the default
- WHEN the description is resolved for the requested language
- THEN the requested row's text is returned
- Test: `plugins/catalogo_core/tests/ArticuloMultiidiomaTest.php`

#### Scenario: Fallback uses the configured default, not a hard-coded 'es'

- GIVEN the configured default is `en` and the requested language has no row
- WHEN the description is resolved
- THEN the `en` row's text is returned
- Test: `plugins/catalogo_core/tests/ArticuloMultiidiomaTest.php`

#### Scenario: Base column and empty terminal

- GIVEN neither the requested language nor the default has a row, and `articulos.descripcion` is set (then empty)
- WHEN the description is resolved
- THEN the base column text is returned, and `''` when it is empty too
- Test: `plugins/catalogo_core/tests/ArticuloMultiidiomaTest.php`

#### Scenario: Reads materialise nothing

- GIVEN no rows for the requested language
- WHEN the description is resolved several times
- THEN `articulo_descripciones` is byte-identical before and after
- Test: `plugins/catalogo_core/tests/ArticuloMultiidiomaTest.php`

### Requirement: GDI-06 — `articulos.descripcion` is a deprecated frozen compatibility shim

`articulos.descripcion` MUST NOT be backfilled from, or mirrored to,
`articulo_descripciones` (D1). `articulo::set_descripcion_idioma()`
(`:1432-1460`) MUST NOT copy the value into `$this->descripcion` (the mirror branch
at `:1451-1455` is removed), and no save path MAY write a language's text into the
base column. The base column MUST NOT be presented, rendered or documented as an
equivalent of the default language: it is a **deprecated compatibility shim** whose
text can only go stale. The column MUST NOT be dropped by this change; it survives
only as the third leg of the read chain for pre-existing articles (defect **e**).

#### Scenario: Saving a default-language description does not write the base column

- GIVEN an article with a base description and a default-language save
- WHEN `set_descripcion_idioma()` persists the default language's text
- THEN the `articulo_descripciones` row carries the new text
- AND `articulos.descripcion` is unchanged
- Test: `plugins/catalogo_core/tests/ArticuloDescripcionFrozenBaseTest.php`

#### Scenario: No backfill runs

- GIVEN pre-existing articles with base text and no description rows
- WHEN the plugin boots and the change is applied
- THEN no `articulo_descripciones` row is created from the base column
- AND the base text stays readable through the read chain
- Test: `plugins/catalogo_core/tests/ArticuloDescripcionFrozenBaseTest.php`

### Requirement: GDI-07 — Clearing semantics

A language's description is present or absent. `articulo_descripcion::test()`
(`:96-117`) MUST accept an empty `descripcion`. When a save submits an empty
`descripcion` **and** an empty `descripcion_corta` for a language, the save path
MUST delete that language's row. A row whose `descripcion` is empty but whose
`descripcion_corta` is set MUST be preserved. **Keeping the row when only
`descripcion_corta` is set is a local decision with no external evidence — the
maintainer owns it** (R3; PrestaShop updates the `_lang` row in place and Odoo
drops the language key, S1/S5).

#### Scenario: Both fields empty deletes the row

- GIVEN an existing description row for a language
- WHEN a save submits an empty `descripcion` and an empty `descripcion_corta`
- THEN the row is deleted for that language
- Test: `plugins/catalogo_core/tests/ArticuloDescripcionClearingTest.php`

#### Scenario: Short-description-only row is preserved

- GIVEN a row with an empty `descripcion` and a non-empty `descripcion_corta`
- WHEN the pair is saved
- THEN the row is kept with its `descripcion_corta`
- Test: `plugins/catalogo_core/tests/ArticuloDescripcionClearingTest.php`

#### Scenario: Empty description is accepted by validation

- GIVEN an empty `descripcion`
- WHEN `test()` runs
- THEN it returns TRUE and the clearing path can proceed
- Test: `plugins/catalogo_core/tests/ArticuloDescripcionClearingTest.php`

#### Scenario: A cleared language falls back at read time

- GIVEN a language whose row was cleared
- WHEN its description is resolved
- THEN the fallback supplies the text and no empty row was materialised
- Test: `plugins/catalogo_core/tests/ArticuloDescripcionClearingTest.php`

### Requirement: GDI-08 — Search-cache invalidation on every description write

Every write that creates, updates or deletes an `articulo_descripciones` row —
**including non-default languages** — MUST invalidate the article search cache
(`articulos_search_*`). Today `articulo_descripcion::save()` (`:119-148`)
invalidates nothing and only `articulo::save()` calls `clean_cache()`
(`articulo.php:1128-1140`) — defect **f**. The invalidation MUST be an explicit
obligation of the description write path, not an incident of an unrelated
`articulo::save()`.

#### Scenario: Non-default language write invalidates the cache

- GIVEN a cached `articulos_search_<query>` result
- WHEN a description row is written for a non-default language
- THEN the cached result is invalidated
- Test: `plugins/catalogo_core/tests/ArticuloSearchCacheInvalidationTest.php`

#### Scenario: Clearing a description invalidates the cache

- GIVEN a cached result that matched a language's description
- WHEN that language's row is deleted by the clearing path
- THEN the cached result is invalidated
- Test: `plugins/catalogo_core/tests/ArticuloSearchCacheInvalidationTest.php`

### Requirement: GDI-09 — Language-agnostic article search is a deliberate deviation

Article search (`articulo::search()`, `Services/ArticuloSearchQueryBuilder.php`)
MUST be **language-agnostic**: the predicate MUST match `articulos.descripcion`
`OR` any `articulo_descripciones` row, following the proven
`tarif_articulo::search_tarifario` `LEFT JOIN articulo_descripciones` + `OR`
pattern (`plugins/tarifario/model/tarif_articulo.php:259-329`). This is a
**deliberate, documented deviation**: every retrieved system scopes search to a
language or store view (PrestaShop `id_lang` S6, Akeneo working locale S20, Odoo
`COALESCE(lang, en_US)` S1, Magento store view S28/S29). It is defensible here
**only** because this fork has ~25 legacy readers and a frozen base column whose
text lives outside the translation table; a locale-scoped search would silently
hide legacy articles. The deviation MUST be implemented as one isolated predicate
and MUST NOT be "fixed" into a locale-scoped search by a later contributor.

#### Scenario: A non-default-language term is findable

- GIVEN an article whose text exists only in a non-default language
- WHEN the term is searched
- THEN the article is returned
- Test: `plugins/catalogo_core/tests/ArticuloSearchMultiidiomaTest.php`

#### Scenario: A cleared translation stops matching

- GIVEN an article whose only match was a language row
- WHEN that row is cleared and the term is searched again
- THEN the article is no longer returned
- Test: `plugins/catalogo_core/tests/ArticuloSearchMultiidiomaTest.php`

#### Scenario: One isolated predicate

- GIVEN `Services/ArticuloSearchQueryBuilder.php` after the change
- WHEN its description predicate is inspected
- THEN exactly one language-agnostic predicate exists and it carries the deviation note
- Test: `plugins/catalogo_core/tests/ArticuloSearchMultiidiomaTest.php` (grep gate)

### Requirement: GDI-10 — No-context consumers resolve the configured default

Every `catalogo_core` consumer with no language context MUST read descriptions
through the configured default language (D10): `Model/CatalogoApiService.php:50`,
`model/tarif_articulo_precio.php:421`,
`model/tarif_tarifa_articulo.php:171,269,295,321,347,376,385`, and
`Controller/VentasOpcional.php:394,397`. The tarifario no-context readers are
covered by the `tarifario/catalogo-integration` delta. The misused calls in
`plugins/tarifario/extras/tarif_controller.php:240,243`
(`descripcion($this->codidioma, 50)` and the nonexistent
`get_descripcion($this->codidioma)`, defect **a**) MUST be corrected to the
language API or removed. **"Silence means default" is a local simplification**
(R5/D10); the evidence-backed part is only "default as the last resort".

#### Scenario: API returns the configured default language

- GIVEN a configured default different from `es` and a matching description row
- WHEN `CatalogoApiService` serialises the article
- THEN the returned `descripcion` is the configured default language's text
- Test: `plugins/catalogo_core/tests/ConsumidoresIdiomaDefaultTest.php`

#### Scenario: Opcional search uses the configured default

- GIVEN a configured default and a matching description row
- WHEN `VentasOpcional::buscarArticulo()` returns results
- THEN each `descripcion` is the configured default language's text
- Test: `plugins/catalogo_core/tests/ConsumidoresIdiomaDefaultTest.php`

#### Scenario: Dead language calls are fixed

- GIVEN `extras/tarif_controller.php` after the change
- WHEN its source is inspected
- THEN neither `descripcion($this->codidioma, 50)` nor `get_descripcion($this->codidioma)` remains
- Test: `plugins/tarifario/tests/Controller/TarifControllerLanguageCallsTest.php` (grep gate)

### Requirement: GDI-11 — Article editor language selector

The article editor MUST render **exactly one** description / short-description
pair for the currently selected language, driven by a language selector over the
active languages (D2). The duplicated default-language field MUST be removed: the
base textarea at `View/ventas_articulo.html.twig:121` and the per-language loop at
`View/partials/articulos/tab_multiidioma.html.twig:11` are replaced. The default
language MUST NOT be rendered twice. `VentasArticulo::saveMultiidiomaDescriptions()`
(`Controller/VentasArticulo.php:824-859`) MUST persist the posted pair for the
selected language, MUST NOT discard the posted default-language value (remove the
`continue` branch at `:833-836`, defect **g**), and MUST NOT skip empty input
(`:847-849`, defect **b** — see GDI-07). A posted value MUST never be written into
a different language's slot.

#### Scenario: One pair per selected language

- GIVEN an article with several active languages
- WHEN the editor renders
- THEN exactly one description / short-description pair is shown for the selected language
- AND the default language is not rendered twice
- Test: `plugins/catalogo_core/tests/VentasArticuloControllerTest.php`

#### Scenario: The posted default-language value is never discarded

- GIVEN a POST whose `descripcion_<default>` differs from `$art->descripcion`
- WHEN `saveMultiidiomaDescriptions()` runs
- THEN the posted value is persisted for the default language
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

#### Scenario: No cross-language write

- GIVEN a POST that sets language X's pair
- WHEN the save runs
- THEN language Y's row is unchanged
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

### Requirement: GDI-12 — Locked anchors, markers and re-expressed ART contracts

The `#multiidioma` in-pane anchor (`ventas_articulo.html.twig:106`), the
`tab_multiidioma.html.twig` include (`:342`) and the four frozen host hook markers
MUST keep their names and positions. The selector work MUST re-express the
`articulo-detalle-canonico` ART-01/ART-05 contracts in lockstep, not delete them:
the public `$idiomas` array and `saveMultiidiomaDescriptions` stay, and the
assertions that locked the duplicated `fsc.articulo.descripcion` textarea are
updated with the code they cover.

#### Scenario: Anchor and markers survive

- GIVEN the migrated view
- WHEN `CatalogoCoreHookMarkersTest` and the detail view assertions run
- THEN `#multiidioma` and the four frozen markers keep their names and positions
- AND the selector introduces no new, renamed or moved marker
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`
