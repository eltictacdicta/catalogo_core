# Proposal: caracteristicas-producto (PrestaShop-style product features)

**Change**: `caracteristicas-producto`
**SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`) — core `openspec/` is NOT a tracker for this change.
**Secondary plugin**: `plugins/tarifario` (registers its two default boolean features only; its consumer deltas are authored under THIS change).
**Artifact store**: `openspec`. **Reference only**: core `openspec/` — no entries created there.
**Supersedes**: `plugins/tarifario/openspec/changes/mover-tarifa-catalogo-opcionales-a-tarifario/` (never applied).
**Delivery**: `delivery_strategy: single-pr`, `review_budget_lines: 400`.
**Inputs**: `explore.md`, `research.md` (PrestaShop Feature/FeatureValue via Context7), `preproposal.md` (CONFIRMED decisions D1–D12).

---

## Intent

Add a generic **feature/characteristic** entity to `catalogo_core` (name + value type `bool|string`), assignable at product / family-subfamily / global scope, with values that may **vary per tarifa** and clone on tarifa copy. The legacy hardcoded `en_catalogo`/`en_tarifa` columns — duplicated across five tables and many consumers — are **replaced now** (D3) by feature values resolved through one uniform resolver. The opcional-level `en_catalogo`/`en_tarifa` flags are removed entirely (D12): an opcional's catalog/tarifa visibility is derived from its parent product.

The change updates locked canonical specs (`articulo-lista-canonica` ALC-02, `articulos-excel-import-export`, `opcionales-tarifa-management` precedence) and touches ~56 production files across two plugins, so scope and size must be honest.

## Scope

### In Scope

- Definition entity `catalogo_caracteristica` + **predefined value catalog** `catalogo_caracteristica_valores` (D4) + optional custom value per product.
- Scope value tables keyed by `(codtarifa, scope_ref)`: global / familia / articulo (D2).
- Uniform resolver: **product > family/subfamily > global**; within a scope **requested tarifa > `DEF` > `valor_defecto`/null** (D5). Lazy inheritance from the `DEF` tarifa; first write materializes.
- Defaults (non-deletable, deactivatable, `origen` persisted — D6): catalogo_core `medidas` (string); tarifario `en_catalogo`, `en_tarifa` (bool). Seeded by **targeted idempotent upserts** (NOT `seed_if_empty`).
- Panel `ventas_caracteristicas`, menu `catalogo` (D9); assignment scopes; per-tarifa values.
- Clone step in `controller/tarif_tarifas.php::heredar_estructura()` via `copy_from_tarifa()`.
- `listable` dynamic columns appended after existing per-tarifa columns; bool → Sí/No; one batched read, no N+1 (D7).
- `importable`/`exportable` additive dynamic columns; base headers byte-identical (D8).
- **Supersession (D3)**: backfill legacy article + family flags into feature values; rewrite all consumers; update locked specs.
- **D12**: drop opcional `en_catalogo`/`en_tarifa`; derive opcional visibility from its parent product.

### Out of Scope

- Types beyond `bool` + `string` (D10) — extension is a later SDD.
- Tarifa-independent localization of names/values (single-language, per research simplification).
- Post-soak physical `DROP COLUMN` (planned here, executed as a gated later work unit — see Backfill).
- Any core (`base/`, `src/`, root `controller|model`) change; core `openspec/` receives nothing.

## Capabilities

### New Capabilities

- `caracteristicas-producto`: definition + predefined value catalog + scope values + resolver + defaults + panel + clone + list/import/export integration.

### Modified Capabilities

- `articulo-lista-canonica`: ALC-02 per-tarifa columns now resolver-driven; `listable` feature columns appended; ALC-04 view hygiene preserved.
- `articulos-excel-import-export`: dynamic `importable`/`exportable` feature columns; base header list stays byte-identical.
- `opcionales-tarifa-management`: master table loses `en_catalogo`/`en_tarifa`; precedence requirement becomes "visibility derived from parent product"; `tarif_opcional_ext` loses both flags.
- `opcionales-management`: OUM-03/OUM-04/OUM-07 columns/toggles/export become derived read-only.
- `familias-tarifa-management`: family `en_catalogo`/`en_tarifa` toggles become feature values.
- `articulo-tarifa-tab-management`: article per-tarifa flags are written as feature values.
- `articulo-detalle-canonico`, `opcionales-tarifa-selector`, `catalogo-render-hooks`: flag references repointed to the resolver.
- `catalogo-excel-import-export` (tarifario): En Tarifa/En Catálogo mapping becomes feature-value mapping.
- `tarifario/catalogo-integration` (tarifario): consumer reads switch to the resolver.

## Approach

**Layer, then replace.** Build the feature layer additively; backfill legacy flags; switch consumers behind a read-through safety flag; then stop writing legacy columns and drop them post-soak.

### Data model (D2 + D4) — 5 tables

| Table | Key / columns | Purpose |
|---|---|---|
| `catalogo_caracteristicas` | PK `id`; `codigo` UNIQUE, `nombre`, `tipo` (`bool|string`), `activo`, `importable`, `exportable`, `listable`, `orden`, `origen`, `valor_defecto` | Definition + flags + provenance |
| `catalogo_caracteristica_valores` | PK `id`; FK `id_caracteristica` CASCADE; `valor`, `orden`, `activo`; UNIQUE `(id_caracteristica, valor)` | Predefined value catalog (PrestaShop `feature_value`) |
| `catalogo_caracteristica_global` | PK `(codtarifa, id_caracteristica)`; `id_valor` NULL, `valor` NULL, `custom` | Global-scope value per tarifa |
| `catalogo_caracteristica_familia` | PK `(codtarifa, codfamilia, id_caracteristica)`; same value triad | Family/subfamily value per tarifa |
| `catalogo_caracteristica_articulo` | PK `(codtarifa, referencia, id_caracteristica)`; same value triad | Product value per tarifa |

Value triad rule: exactly one of `id_valor` (predefined, `custom=FALSE`) or `valor` (custom, `custom=TRUE`). `bool` is closed: only predefined values (seeded `TRUE`/`FALSE` pair). FKs (D11): `codtarifa → tarif_tarifas`, `referencia → articulos`, `codfamilia → familias`, `id_caracteristica`/`id_valor` → feature tables — all CASCADE.

**Resolver** (`Services/CaracteristicaResolver`): scope precedence `articulo > familia (walk `madre` nearest-first) > global`; within a scope `requested tarifa row > DEF tarifa row > valor_defecto > none`. Read never persists. **Batch reader** (`Services/CaracteristicaValorBatchReader`) resolves one page's `listable` columns in one query set, mirroring `ArticuloTarifaPrecioBatchReader`. **Clone**: each scope table exposes transactional `copy_from_tarifa($origen,$destino)` (DELETE + INSERT…SELECT, no-op when equal), added as ordered step 12 of `heredar_estructura()`.

### Defaults (D6)

`Services/CaracteristicaRegistry::registerDefault($codigo,$nombre,$tipo,$origen)` performs a targeted `INSERT … WHERE NOT EXISTS (codigo=?)` upsert and seeds the `bool` predefined pair. `catalogo_core/Init.php::ensureCaracteristicasTables()` + default seeding run in `init()` and `upgrade()`; `tarifario/Init.php` registers its two bool features behind `class_exists` + static guard, re-attempted in `upgrade()`. Defaults keep `origen`; the panel blocks deletion when `origen` is set but allows deactivation/reactivation.

### D12 — opcional visibility derivation

An opcional has no own catalog/tarifa flag. Effective visibility is:
- article-attached (`catalogo_articulo_opcional`) → the **parent article's** effective `en_catalogo`/`en_tarifa` feature value for the tarifa (article scope → family → global);
- family-assigned (`catalogo_opcional_familia`) → the **family's** effective value (scope walk with `madre`);
- unassigned/global → the **global** scope effective value.
- Multiple parents → visible if **any** parent is visible (existential union; stated explicitly in the spec).

`tarif_tarifa_opcional` keeps `activa`/`orden`; the four `en_catalogo`/`en_tarifa` columns drop, and `ventas_opcionales` toggles become a derived read-only indicator linking to the parent product's feature value.

### Supersession / backfill plan (D3)

1. **Create + seed** the 5 tables and the feature defaults (`medidas`, `en_catalogo`, `en_tarifa` + bool value pairs).
2. **Backfill** (idempotent `Services/CaracteristicaBackfillMigration::migrateIfNeeded($db)`, called from `init()`/`upgrade()`; every statement `INSERT … WHERE NOT EXISTS`; double-run = no-op):
   - `tarif_articulo_precios` (`referencia, codtarifa`) → `catalogo_caracteristica_articulo`;
   - `tarif_tarifa_articulo` (`referencia, codtarifa`) → same, when the price row is absent;
   - `tarif_tarifa_familia` (`codfamilia, codtarifa`) → `catalogo_caracteristica_familia`;
   - `tarif_familia_ext` (global family rows) → `DEF` tarifa family rows, only when that
     table carries the visibility columns (the live schema does not — design §8.6);
   - opcional flags are **not** backfilled as opcional rows; they are re-derived from parents (D12). Where an opcional flagged `en_catalogo`/`en_tarifa` had no parent article/family value, the backfill seeds the corresponding parent/global value so visibility is preserved (first parent value wins on conflict; the policy is specified in CAR-13).
3. **Read-through flag**: consumers (list ALC-02, export/import, `tarif_catalogo_view`, familias, opcionales resolver, detail tab) read the resolver through a config constant (default off until backfill verified, then on). Legacy columns keep being written during the soak window (dual-write). The D12 consumers (opcional indicator) and the detail tab have no legacy branch: their flags drop with the D12 derivation, so they are feature-backed in both flag states (design §8.4).
4. **Two independently gated drops** (destructive, separate steps):
   - **D12 drop (not soaked)**: removes `en_catalogo`/`en_tarifa` from `tarif_opcional_ext` and `tarif_tarifa_opcional`, gated **only** on the CAR-12 behavior-preservation parity test — no soak is required because the derived indicator replaces the flags immediately.
   - **Legacy article/family drop (post-soak)**: removes `en_catalogo`/`en_tarifa` from `tarif_articulo_precios`, `tarif_tarifa_articulo`, `tarif_tarifa_familia` and `tarif_familia_ext` (the last when present), gated on the read-through flag being enabled and verified stable **and** on the DEV-17 membership-filter rewrite (ordered prerequisite).
   Both steps are idempotent, refuse to run before their own gate, and are **reversible**: feature values remain source of truth, so columns can be re-added and repopulated by re-deriving from the resolver.
5. **Rollback story**: WU-1/WU-2/WU-3 are additive and revert by dropping the new tables and the backfill (feature writes are non-destructive to legacy data). The backfill is `NOT EXISTS`-guarded and re-runnable. Each drop rolls back under its own gate: the D12 drop by re-adding the four `tarif_opcional_ext`/`tarif_tarifa_opcional` columns and re-running the CAR-12 parity derivation; the post-soak article/family drop additionally requires disabling the read-through flag and re-running the backfill so the two branches agree again. Keep a pre-drop dump for operator-level restore.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `catalogo_core/model/core/caracteristica*.php`, `model/table/catalogo_caracteristica*.xml` | New (5 models + 5 XML) | Definition, value catalog, 3 scope tables |
| `catalogo_core/Services/Caracteristica{Resolver,ValorBatchReader,Registry,BackfillMigration}.php` | New | Resolver, batch read, defaults, backfill |
| `catalogo_core/Controller/VentasCaracteristicas.php` + `controller/ventas_caracteristicas.php` + `View/ventas_caracteristicas.html.twig` | New | Panel `ventas_caracteristicas`, menu `catalogo` |
| `catalogo_core/Init.php` | Modified | `ensureCaracteristicasTables()` + defaults + backfill in `init()`/`upgrade()` |
| `catalogo_core/controller/tarif_tarifas.php` | Modified | `heredar_estructura()` step 12 (feature clone) |
| `catalogo_core/extras/VentasArticulosListTrait.php`, `Controller/VentasArticulos.php`, `View/ventas_articulos.html.twig` | Modified | ALC-02 resolver-driven + `listable` columns |
| `catalogo_core/Services/ArticuloExcel{Export,ImportWizard}Service.php`, `Services/ArticuloTarifaPrecioBatchReader.php` | Modified | Dynamic feature columns; base headers byte-identical |
| `catalogo_core/extras/VentasOpcionalesListTrait.php`, `Controller/VentasOpcionales.php`, `View/ventas_opcionales.html.twig` | Modified | D12 derived columns; toggles removed |
| `catalogo_core/model/tarif_{opcional_ext,tarifa_opcional,tarifa_familia,familia_ext,articulo_precio,tarifa_articulo}.php` + XML | Modified | Flags dropped / repointed |
| `catalogo_core/controller/{tarif_opcional_edit,tarif_opcional_tab,tarif_familias}.php` + their views/macros/partials | Modified | Flag toggles → feature values |
| `plugins/tarifario/Init.php` | Modified | Registers `en_catalogo`/`en_tarifa` defaults |
| `plugins/tarifario/Services/ExcelHierarchyService.php`, `process_excel_wizard.php`, `model/tarif_articulo.php` | Modified | Mapping/reads → feature values |
| `plugins/tarifario/controller/{tarif_catalogo_view,tarif_configurador_opcionales}.php` + views | Modified | Resolver reads; opcional flags removed |
| Locked specs + deltas (both plugins) | Modified | 8 catalogo_core specs + 2 tarifario specs + new `caracteristicas-producto` |

## Risks

| Risk | L | Mitigation |
|---|---|---|
| Locked contracts break (ALC-02/04, export header list, precedence, hook markers) | High | Additive + gated; spec deltas authored in this change; run locked tests unedited |
| Backfill drift vs legacy during soak | High | Dual-write + read-through flag; comparison tests; `NOT EXISTS` idempotency |
| Destructive column drop | Med | Post-soak gated drop, reversible via feature re-derivation, pre-drop dump |
| N+1 regression on `listable` columns | Med | Batched reader (one query set per page) |
| Cross-plugin boot order (tarifario before catalogo_core) | Med | `class_exists` guard + static guard + re-attempt in `upgrade()` |
| Semantic duplication/misreading of derived opcional visibility | Med | Read-only indicator + explicit spec rule (any-parent union) |
| Value typing edge cases (`''` vs null vs `false`) | Med | Explicit `custom` discriminator; `str2bool`/`no_html`; whitelist in `test()` |
| Test isolation (`processIsolation`, static guards) | Low | Follow existing plugin test patterns |

## Rollback Plan

WU-1–WU-4 (feature layer, backfill, read-through, opcional derivation) are additive: revert the commits, drop the 5 new tables, disable the read-through flag, and legacy columns remain authoritative. WU-5 (clone) and WU-6 (tarifario registration) are independently revertible. Each destructive drop (WU-7) is a separate, independently gated commit: the D12 opcional drop reverts by re-adding the four columns and re-running the CAR-12 parity derivation, while the post-soak article/family drop also requires disabling the read-through flag and re-running the backfill. Keep a pre-drop dump as the operator-level safety net and clear the Twig cache after any revert.

## Dependencies

- `tarifario` requires `catalogo_core` (dependency direction unchanged).
- `strict_tdd: true` — tests land with each work unit; `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` and the root Plugins suite.
- No new Composer dependency → no `vendor/` commit concern.

## Size Forecast

| Signal | Value |
|---|---|
| Production files touching flags | ~56 (37 catalogo_core + 19 tarifario) |
| New files (models/XML/services/panel) | ~15–20 |
| Locked specs rewritten | 8 catalogo_core + 2 tarifario + 1 new |
| Authored lines (est.) | **4,000–7,000+** |
| 400-line budget risk | **High** |
| Decision needed before apply | **Yes** |
| Chained PRs recommended | **Yes** |

`single-pr` **cannot** fit this change: it is multi-thousand lines across two plugins plus locked-spec rewrites. Landing it as one PR **requires a maintainer-approved `size:exception`**. Recommended work-unit split (also usable as a chained alternative; PR #1 targets the tracker branch):

| WU | Deliverable | Autonomous? |
|---|---|---|
| WU-1 | Definition + predefined value catalog + 3 scope tables + models/XML + defaults seeding + panel page | Yes (foundation) |
| WU-2 | Resolver + precedence + batch reader (read path) | Needs WU-1 |
| WU-3 | Backfill migration + read-through flag + article list/export/import rewrites + ALC-02/export spec rewrites | Needs WU-2 |
| WU-4 | D12 opcional derivation + column/toggle removal + `opcionales-tarifa-management`/`opcionales-management` spec rewrites | Needs WU-3 |
| WU-5 | `heredar_estructura()` clone step + clone tests | Needs WU-2 |
| WU-6 | tarifario default registration + tarifario consumer rewrites + tarifario spec deltas | Needs WU-2 |
| WU-7 | Post-soak `DROP COLUMN` migration (destructive, gated) | Needs WU-4 soak |

WU-1 + WU-2 are the atomic foundation (the layer is not usable until both land) — keep them as one work unit or one chained PR pair.

## Success Criteria

- [ ] Features can be created/edited/assigned at product, family/subfamily and global scope; values vary per tarifa and clone on tarifa copy.
- [ ] `medidas` (string, catalogo_core) and `en_catalogo`/`en_tarifa` (bool, tarifario) are registered on fresh and existing installs via idempotent upserts; defaults cannot be deleted.
- [ ] Effective-value precedence (product > family/subfamily > global; requested tarifa > `DEF` > default) is implemented and spec-tested.
- [ ] Legacy article + family flags are backfilled idempotently (`NOT EXISTS`) and every consumer reads the resolver behind the read-through flag.
- [ ] Opcional `en_catalogo`/`en_tarifa` columns are gone; visibility is derived from the parent product (any-parent union) and matches pre-change behavior.
- [ ] `listable` columns render after the per-tarifa columns with one batched read (no N+1); bool → Sí/No.
- [ ] Excel export/import feature columns are additive; base headers byte-identical.
- [ ] Locked specs updated (ALC-02, export, precedence) and locked tests green; plugin + root Plugins suites pass; PHPStan clean.
- [ ] Size decision resolved: maintainer-approved `size:exception` for `single-pr`, or the work-unit split adopted as chained PRs.
