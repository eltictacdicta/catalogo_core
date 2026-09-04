# Design: Multi-Tariff Pricing for Catalogo Core

## Technical Approach

Extend `catalogo_core` with per-list article pricing on top of the existing `catalogo_listas_precio` entity, a batch price-update flow ported from `tarif_actualizar_precios`, a namespaced options store (`CatalogoOptions`) that absorbs `catalogo_excel_roles`, and an optional first-party group-gating listener on the existing `ArticlePermissionFilterEvent`. Legacy `tarifa` model and `tarifario` plugin are untouched; no data migration. All work stays inside `plugins/catalogo_core/`.

## Architecture Decisions

### D1: Single-default invariant on `catalogo_listas_precio`

**Choice**: keep `catalogo_lista_precio::save()` as the enforcement point — it already clears `por_defecto` on all other rows before upsert (`UPDATE ... SET por_defecto = FALSE WHERE codlista != X`). Wrap the two statements in a DB transaction (`$this->db->beginTransaction()` / `commit()` / `rollback()`) so a failed demotion never leaves zero defaults. Add a post-save invariant read in `get_default()` (falls back to `DEFAULT_CODE='DEF'` if none) plus unit tests: "second default demotes the first", "failed save leaves previous default". Install already seeds `DEF` (install() + `ensure_defaults()` via `Init::DEFAULT_SEED_MODELS`) — no change needed.

**Alternatives**: DB trigger (no precedent, engine-specific), controller-level enforcement (bypassable by other callers). Rejected.

### D2: Event API — no modification, reuse `edit_article` semantics

**Choice**: do NOT touch `ArticlePermissionFilterEvent` (spec R-RG-002 forbids it). List-price CRUD and `opciones_catalogo` are admin-gated pages (no gating needed). Per-article price editor and batch-update apply both mutate *articles*, so they dispatch the existing event with `ACTION_EDIT_ARTICLE` and the affected `referencia`. The catalogo listener gates those dispatches with the group matrix; unknown/new actions never exist, so tarifario's external listener (which denies on any non-admin dispatch) behaves exactly as today.

**Alternatives**: adding `ACTION_EDIT_PRICES`/`ACTION_EDIT_LIST` constants (violates spec; would also need tarifario to ignore them). Rejected.

### D3: Settings migration — read-fallback shim, no copy, no dual write

**Choice**: `CatalogoOptions` is the only writer; it writes the new key `catalogo_core.excel_roles`. `ArticleExcelAccessPolicy::settingRaw()` (the private settings reader in `Services/ArticleExcelAccessPolicy.php`) switches to `CatalogoOptions::excelRoles()`, which reads the new key and falls back to legacy `catalogo_excel_roles` when absent. R-CO-002's "legacy reader" scenario is resolved by this single-read-path choice: the legacy reader IS the policy itself — there is no second independent reader to preserve; if the delta spec's scenario wording suggests a separate legacy reader, read it under this D3 interpretation. No one-time migration step. `setSettingRaw()` test override is preserved by making `CatalogoOptions::excelRoles()` consult an injectable raw override (same pattern as today), keeping `ExcelAccessControlBootstrapRegressionTest` and `CatalogoExcelSettingsPageTest` (updated to `opciones_catalogo`) green. Fail-closed parsing unchanged.

### D4: Listener gating — always registered, short-circuiting

**Choice**: register `Services/GroupPermissionListener.php` unconditionally from `Init::init()` on `ArticlePermissionFilterEvent::NAME`; first line reads the master setting via `CatalogoOptions` and returns (allow) when off. `fs_settings` reads are cheap and single-request; no registration races, and "provably inert when off" is one assertable code path.

**Alternatives**: conditional registration when the setting is on (needs re-init on toggle, harder to test registration invariants). Rejected. Listener mirrors tarifario's invokable + lazy-model + fail-closed Throwable→deny pattern.

### D5: Install ordering

**Choice**: extend `Init::ensureCatalogTables()` (and `DEFAULT_SEED_MODELS` unchanged): insert `catalogo_articulo_precio` immediately after `catalogo_lista_precio`; then `catalogo_grupo`, `catalogo_grupo_roles`, `catalogo_grupo_usuarios`, `catalogo_grupo_tarifas`, `catalogo_grupo_articulos` (definition first, dependents after) — the `CatalogLegacyTableMigration`/`ensureCatalogTables` precedent. XML tables mirror `catalogo_opcional_precios.xml` (composite PK + FKs with CASCADE).

### D6: Controller/view shape

| Page | Kind | Notes |
|------|------|-------|
| `controller/ventas_listas_precio.php` + `View/ventas_listas_precio.html.twig` | New legacy fs_controller (admin, folder `ventas`) | CRUD for lists: create/edit/activate/set-default/delete |
| `Controller/VentasArticulo.php` + `View/ventas_articulo.html.twig` | Modified | New "Precios por lista" tab (per-list editor, fallback marker); dispatches filter event on save |
| `controller/catalogo_actualizar_precios.php` + Twig view | New | Preview (POST + CSRF, no persist) → confirm checkbox → apply (POST + CSRF); formula `round(p*(1+pct/100),2)`. **Filter set (R-BU-002)**: target list (`codlista`, required), family (`codfamilia`, optional), include-subfamilies flag resolved recursively via the existing `familia` hierarchy (`madre`/`hijas()`/`aux_all()` — no model change; when off, only direct family members match), article group (`codgrupo` filter over `catalogo_grupo_articulos`). Optional filters combine with **AND**; `codlista` is mandatory so every apply is scoped. **Resumen/flash (R-BU-005)**: after apply, the controller renders a summary via `$this->new_message()` (affected row count) plus the list of affected references (`new_error_msg()` for any row that failed to persist); preview with **zero matches → warning via `new_message()` (advice tone), nothing applied, no write issued**. |
| `controller/opciones_catalogo.php` + `View/opciones_catalogo.html.twig` | New | Admin gate; whitelist: multi-tariff flag, groups master flag, Excel roles list (reuse `catalogo_excel_roles_normalize()`, moved to a shared helper) |
| `controller/opciones_catalogo.php` + `View/opciones_catalogo.html.twig` | New | Admin gate; whitelist: multi-tariff flag, groups master flag, Excel roles list (reuse `catalogo_excel_roles_normalize()`, moved to a shared helper) |
| `controller/catalogo_excel_settings.php`, `view/catalogo_excel_settings.html.twig` | **Delete** | Replaced by `opciones_catalogo` (spec R-CO-004: page REMOVED, no redirect — menu registers the new page; tests updated) |

Multi-tariff flag off ⇒ controllers/views hide list CRUD, per-article tab and actualizar_precios entry (safe default per R-CO-003). **Inactive lists excluded from public flows (R-MT-001)**: the list selectors in the per-article price editor ("Precios por lista" tab) and in `catalogo_actualizar_precios` only offer lists with `activa = TRUE`; when the multi-tariff flag is off the selectors are hidden entirely (whole tab/page, not just list filtering). Inactive lists remain visible only in the admin CRUD page (`ventas_listas_precio`) for reactivation.

### D7: Naming — `codlista` everywhere

Canonical key is `codlista` (varchar 20, matching `catalogo_listas_precio.codlista`) across articles, opcionales, groups and UI. `codtarifa` is tarifario-only legacy vocabulary and MUST NOT appear in new catalogo_core code (grep-auditable, mirrors the spec's naming scenario).

## Data Flow

```
opciones_catalogo ──fs_settings──▶ CatalogoOptions ──▶ ArticleExcelAccessPolicy (fallback shim)
Init.php ──register──▶ GroupPermissionListener ──(master on)──▶ grupo_* models ──▶ deny(reason)
VentasArticulo (save precios) ──dispatch ArticlePermissionFilterEvent──▶ listener ──▶ catalogo_articulo_precios
catalogo_actualizar_precios: POST preview ─▶ computed rows (no write) ─▶ POST apply ─▶ UPDATE rows
  filters (AND): codlista (required) + codfamilia (+ incluir_subfamilias → familia hijas()/aux_all()) + codgrupo (catalogo_grupo_articulos)
  preview zero matches ─▶ warning message, nothing applied (no write)
  apply done ─▶ new_message(count) + affected references list; failures ─▶ new_error_msg()
effectivePrice(articulo, lista): catalogo_articulo_precios row ?? (lista==default ? articulos.pvp : null)
list selectors (price editor, batch update): only activa=TRUE lists; multi-tariff off ⇒ hidden entirely (R-MT-001)
```

## File Changes

| File | Action |
|------|--------|
| `model/table/catalogo_articulo_precios.xml`, `catalogo_grupo.xml`, `catalogo_grupo_roles.xml`, `catalogo_grupo_usuarios.xml`, `catalogo_grupo_tarifas.xml`, `catalogo_grupo_articulos.xml` | Create |
| `model/core/catalogo_articulo_precio.php`, `catalogo_grupo.php`, `catalogo_grupo_rol.php`, `catalogo_grupo_usuario.php`, `catalogo_grupo_tarifa.php`, `catalogo_grupo_articulo.php` | Create |
| `Services/CatalogoOptions.php`, `Services/GroupPermissionListener.php`, `Services/CatalogoRoleListNormalizer.php` | Create |
| `model/core/catalogo_lista_precio.php` | Modify (transactional save) |
| `Services/ArticleExcelAccessPolicy.php` | Modify (read via CatalogoOptions) |
| `Controller/VentasArticulo.php`, `Controller/VentasArticulos.php` | Modify (prices tab, event dispatch on price saves) |
| `Init.php` | Modify (register listener; extend ensureCatalogTables) |
| `controller/ventas_listas_precio.php`, `controller/catalogo_actualizar_precios.php`, `controller/opciones_catalogo.php` + views | Create |
| `controller/catalogo_excel_settings.php`, `view/catalogo_excel_settings.html.twig` | Delete |
| `tests/` (models, options store, shim regression, listener, batch update, listas controller) | Create/Update |

## Interfaces / Contracts

```php
final class CatalogoOptions {
    public function multiTariffEnabled(): bool;          // default false
    public function groupsEnabled(): bool;               // default false (master)
    public function excelRoles(): ?string;               // null = absent (triggers legacy fallback)
    public function setExcelRoles(string $commaList): void;
}
final class GroupPermissionListener {
    public function __invoke(ArticlePermissionFilterEvent $event): void; // silent return = allow
}
interface PriceResolver { public function effective(string $referencia, string $codlista): ?float; }
```

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Unit | Single-default invariant; precio model upsert; options round-trip/defaults; role normalizer; listener matrix (gestor/editor/revisor, admin dominance, master-off inert) | PHPUnit, mock/no-DB patterns per `fsframework-test-writing`, `-c plugins/catalogo_core/phpunit.xml` |
| Unit/regression | Excel shim (legacy fallback, new-store-wins, fail-closed) | Extend `ExcelAccessControlBootstrapRegressionTest` |
| Integration | Batch update preview vs apply (zero-write preview, resumen, CSRF rejection; filter combination AND semantics incl. subfamily recursion and article-group filter, zero-match warning) ; install ordering via Init upgrade test | Existing controller-test patterns |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. Mutations are CSRF-gated controller POSTs using existing framework guards.

## Migration / Rollout

No data migration. New tables are additive (drop = rollback). Settings: read-fallback shim means reverting the options store cannot orphan Excel access. Feature flags default off (multi-tariff, groups) ⇒ upgrade is inert until enabled.

## Open Questions

None blocking — all seven flagged questions resolved above.
