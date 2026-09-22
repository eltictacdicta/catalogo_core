# Design: gestion-idiomas-catalogo

**Change**: `gestion-idiomas-catalogo`
**Artifact store**: `openspec` (plugin-local) — core `openspec/` receives nothing.
**Inputs**: `proposal.md` (authoritative), `specs/**` (4 deltas), `explore.md`, `research.md` (R1–R9), `preproposal.md` (D1–D12).
**Runner**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`. `strict_tdd: true`.
**Delivery**: `auto-chain`, `review_budget_lines: 800`; this design keeps the proposal's 5 slices.
**Note on size**: the phase skill's 800-word soft budget is exceeded deliberately — the task mandates ten concrete deferred-mechanism decisions plus a file map, migration, locked-contract and test strategy. No section is padding.

---

## Technical Approach

`articulo_descripciones` becomes the authoritative store for every language; `articulos.descripcion` becomes a frozen, read-only legacy shim. Three seams are changed, in this order of dependency:

1. **Model foundation** — the registry (`catalogo_idioma`) gets total, transactional invariants and a dynamic default pointer; the description model (`articulo_descripcion`) gets clearing semantics and a cache-invalidation obligation; `articulo` swaps the hard-coded `'es'` parameter default for the configured default and drops its write mirror.
2. **Surfaces** — a `#idiomas` management section on `ventas_articulos` (POST-only, CSRF-gated, `$user->admin`), and a server-rendered language selector in `ventas_articulo` that replaces the duplicated base textarea and the per-language loop.
3. **Consumers** — language-agnostic search (one isolated predicate), additive locale Excel columns with an explicit import target language, and the no-context readers in both plugins resolving the configured default.

### Read chain (D6 / GDI-05)

```
articulo::get_descripcion_idioma($cod = null)
  |
  +-- referencia === null .................... return $this->descripcion
  |
  +-- $cod in (null, '') ..................... $cod = catalogo_idioma::get_effective_default_code()
  |
  +-- SELECT ... WHERE referencia=:ref AND codidioma=:$cod
  |        hit? .............................. return row->descripcion
  |
  +-- $def = get_effective_default_code();  $def !== $cod ?
  |        SELECT ... WHERE referencia=:ref AND codidioma=:$def
  |        hit? .............................. return row->descripcion
  |
  +-- return $this->descripcion .............. frozen shim; '' when empty
```

No leg writes. Resolution is pure (GDI-05 "Reads materialise nothing").

### Write path (D1/D2/R2/GDI-06/GDI-07/GDI-08)

```
VentasArticulo::editarArticulo()          (base textarea + `sdescripcion` write REMOVED)
  |
  +-- $cod = resolve_codidioma(request)  (posted `codidioma` -> active? else effective default)
  |
  +-- saveMultiidiomaDescriptions($art, $request)
        $desc  = posted "descripcion_<$cod>"        (may be '')
        $corta = posted "descripcion_corta_<$cod>"  (may be '')
        $art->set_descripcion_idioma($cod, $desc, $corta)
          |
          +-- articulo_descripcion::save()
                +-- empty pair? ---- row exists? -> delete()  -> invalidate cache
                |                    no row?    -> no-op (true)
                +-- else ---------- INSERT/UPDATE ----------> invalidate cache
        (no mirror: `articulos.descripcion` is never written)
```

Every writer of the table (`set_descripcion_idioma`, the editor, the Excel import, any future caller) converges on `articulo_descripcion::save()`/`delete()`, so the cache obligation cannot be bypassed.

---

## Deferred-Mechanism Decisions

### D-01 — Orphan cleanup: application-level in `catalogo_idioma::delete()` (option b), no XML FK

**Choice**: `catalogo_idioma::delete()` deletes the language's `articulo_descripciones` rows and then the registry row in one transaction. `model/table/articulo_descripciones.xml` is **not** changed (no `codidioma` FK). One idempotent defensive purge is added to `Services/CatalogLegacyTableMigration`.

**Rationale (evidence)**:
- PrestaShop deletes child `_lang` rows in `ObjectModel::delete()` rather than relying on a DB cascade (S5); the app-level path is the evidenced precedent.
- A DB FK here would be **unreliable in this framework**: `base/fs_mysql.php:259` and `:1455` add XML `FOREIGN KEY` constraints only when `FS_FOREIGN_KEYS` is truthy (`base/config2.php:90` defaults it to `1`, but it is an operator toggle). With the toggle off, GDI-03 would silently fail.
- Adding the FK to the XML on an install that **already has orphan rows** makes `ALTER TABLE ... ADD CONSTRAINT` fail; `fs_model::check_table()` (`base/fs_model.php:277-316`) then logs `ERR_CHECK_TABLE` and the whole lazy-schema pass for that table degrades. Pimcore's counter-precedent (remove the language, keep the data, expose a cleanup command, S24) shows deletion semantics are a product choice; this fork chooses delete-with-the-language.
- MySQL's `compare_constraints()` also drops/re-adds matching FKs (`base/fs_mysql.php:243-273`), so an XML FK would churn on every constraint drift.

**Defensive purge (idempotent, no flag)** — `CatalogLegacyTableMigration::purgeOrphanDescriptions(\fs_db2 $db)`, called from `migrateIfNeeded()` (already invoked by `Init::init()` and `Init::upgrade()`):

```php
if (!self::tableExists($db, 'articulo_descripciones') || !self::tableExists($db, 'catalogo_idiomas')) {
    return;
}
// cheap pre-check, mirroring hasPendingRows()/copyPendingPrices(): steady state runs one LIMIT 1
$pending = $db->select(
    'SELECT 1 FROM articulo_descripciones d LEFT JOIN catalogo_idiomas i '
    . 'ON i.codidioma = d.codidioma WHERE i.codidioma IS NULL LIMIT 1;'
);
if (!$pending) {
    return;
}
if (self::isPostgres($db)) {
    $db->exec('DELETE FROM articulo_descripciones d WHERE NOT EXISTS '
        . '(SELECT 1 FROM catalogo_idiomas i WHERE i.codidioma = d.codidioma);');
} else {
    $db->exec('DELETE d FROM articulo_descripciones d LEFT JOIN catalogo_idiomas i '
        . 'ON i.codidioma = d.codidioma WHERE i.codidioma IS NULL;');
}
```

Idempotent by construction (second run touches 0 rows). Degradation on a DB with pre-existing orphans: the purge removes them **once**, before any read is served; no error, no FK failure. Product consequence, stated: an orphan row for a removed code was invisible to the selector but *visible to language-agnostic search* (D11); after the purge it stops matching — which is the desired semantic.

**Rollback**: the purge deletes rows that referenced a non-existent language (unreachable through the API). Reverting the commit cannot restore them; the proposal's pre-migration dump is the operator-level safety net. The delete-with-language path reverts by reverting the commit.

### D-02 — Default resolution: configured active default → lowest active `codidioma` → `DEFAULT_CODE`; invariants in a transaction

**Choice**: new `catalogo_idioma::get_effective_default_code(): string`:

```php
public function get_effective_default_code(): string
{
    $default = $this->get_default();                    // por_defecto = TRUE AND activo = TRUE
    if ($default) {
        return (string) $default->codidioma;
    }
    $activos = $this->db->select('SELECT codidioma FROM ' . $this->table_name
        . ' WHERE activo = TRUE ORDER BY codidioma ASC LIMIT 1;');
    if ($activos) {
        return (string) $activos[0]['codidioma'];
    }
    return self::DEFAULT_CODE;                          // 'es' — unreachable under the invariants
}
```

Precedence: the configured **active** `por_defecto` row wins; otherwise the lowest `codidioma` among active rows (deterministic, R1); `DEFAULT_CODE` (`'es'`) is the terminal fallback and is documented as unreachable while the invariants hold (last language undeletable + default cannot be inactive ⇒ at least one active language always exists). `get_default()` gains `AND activo = TRUE`; it stays the raw pointer read.

**Invariant enforcement, atomically** (`$db->begin_transaction()` / `commit()` / `rollback()` — `base/fs_db2.php:65-93`, `base/fs_mysql.php:57,112,945`):

`save()`:
1. `test()` first.
2. If the row exists, is currently `por_defecto = TRUE` and the incoming `activo` is false → `new_error_msg`, return false (**default cannot be deactivated**).
3. Transaction:
   - if `por_defecto` is true: `UPDATE catalogo_idiomas SET por_defecto = FALSE WHERE codidioma != :cod;` then force `activo = TRUE` on this row;
   - upsert this row;
   - `normalize_default()`: (a) `UPDATE ... SET por_defecto = FALSE WHERE por_defecto = TRUE AND activo = FALSE;` (b) if `SELECT COUNT(*) ... WHERE por_defecto = TRUE` is 0 → read the lowest `codidioma` among active rows and `UPDATE ... SET por_defecto = TRUE WHERE codidioma = :candidate`. Two PHP steps avoid MySQL error 1093 (updating a table while selecting from it).
   - commit; on any failure rollback and return false.

`set_default(string $cod): bool` — transaction: target must exist and be active (else explicit error, false); `UPDATE ... SET por_defecto = FALSE WHERE por_defecto = TRUE;` then `UPDATE ... SET por_defecto = TRUE, activo = TRUE WHERE codidioma = :cod;`; then `normalize_default()`. No `articulo_descripciones` statement is issued (D5 scenario "flag flip").

`delete()`:
1. last-language guard: `SELECT COUNT(*)` → if `<= 1` → error, false (GDI-03).
2. `por_defecto` guard stays (default cannot be deleted).
3. Transaction: `DELETE FROM articulo_descripciones WHERE codidioma = :cod;` then `DELETE FROM catalogo_idiomas WHERE codidioma = :cod;` then `normalize_default()`; commit.

Ordering note: the default-flag statement precedes the row upsert inside one transaction, so an observer can never see zero or two active defaults at a commit boundary.

### D-03 — Cache invalidation: one public static entry point on `articulo`, called by every description write

**Choice**: add to `model/core/articulo.php`:

```php
public static function invalidate_search_cache(): void
{
    $cache = new \fs_cache();
    $tags = $cache->get_array('articulos_searches');
    if (!is_array($tags)) {
        return;
    }
    foreach ($tags as $value) {
        if (!empty($value['tag'])) {
            $cache->delete('articulos_search_' . $value['tag']);
        }
    }
}
```

`articulo::clean_cache()` (`:1126-1144`) is refactored to keep its once-per-request `self::$cleaned_cache` guard and delegate the loop to this method (the guard exists to limit work during mass updates and is preserved). Why a static `fs_cache` and not `new articulo()`: constructing the model would trigger the lazy schema path, and the invalidator needs no instance state.

Callers: `articulo_descripcion::save()` (after a successful insert/update **and** after the empty-pair branch delegates to `delete()`) and `articulo_descripcion::delete()`. `articulo::save()` already invalidates via `clean_cache()`.

**Proof the clearing path cannot bypass it**: the clearing semantics live *inside* `articulo_descripcion::save()` (empty pair → `delete()`), and both `save()` and `delete()` call `invalidate_search_cache()` unconditionally. `articulo::set_descripcion_idioma()` routes through those two methods and never writes the table any other way. The only remaining writer is the legacy→canonical copy in `CatalogLegacyTableMigration`, which runs at plugin activation, before any search tag can exist. Invariant worth a test: "no code path issues an `INSERT|UPDATE|DELETE` against `articulo_descripciones` outside `articulo_descripcion`" (grep gate).

### D-04 — Language-agnostic search predicate: one helper, `LEFT JOIN` + `OR`, alias `a`/`d`, `SELECT DISTINCT`

**Choice** (mirrors `plugins/tarifario/model/tarif_articulo.php:229-283`): in `Services/ArticuloSearchQueryBuilder.php`, the description term becomes a single isolated predicate:

```php
/**
 * DELIBERATE DEVIATION (GDI-09 / D11) — article search is language-agnostic.
 * Every retrieved system scopes search to a language or store view
 * (PrestaShop `id_lang` S6; Akeneo working locale S20; Odoo
 * `COALESCE(lang, en_US)` S1; Magento store view S28/S29). This fork
 * intentionally matches across all languages because ~25 legacy readers and
 * the frozen `articulos.descripcion` column keep text outside the translation
 * table; a locale-scoped search would silently hide legacy articles.
 * Do NOT "fix" this into a locale-scoped search (GDI-09).
 */
public static function languageDescriptionPredicate(string $like, callable $quote): string
{
    return '(lower(a.descripcion) LIKE ' . $like . " ESCAPE '|'"
        . ' OR lower(d.descripcion) LIKE ' . $like . " ESCAPE '|')";
}

public static function languageDescriptionJoin(): string
{
    return ' LEFT JOIN articulo_descripciones d ON d.referencia = a.referencia';
}
```

Every base-column reference in the builder becomes `a.`-qualified (`a.referencia`, `a.partnumber`, `a.equivalencia`, `a.codbarras`, and the per-token description term above). `articulo::search()` (`:1158-1180`):

```php
$sql = 'SELECT DISTINCT a.* FROM ' . $this->table_name . ' a'
     . ArticuloSearchQueryBuilder::languageDescriptionJoin();
```

plus `FROM articulos a` aliasing and **`ORDER BY a.referencia ASC`** (replacing `ORDER BY lower(referencia) ASC`, which is invalid with `DISTINCT` on both MySQL≥5.7 and PostgreSQL: the ORDER BY expression must appear in the DISTINCT select list). MySQL's default collation is case-insensitive, so ordering behaviour is preserved in practice; note it in the code comment. `buildSearchWhereClause()` (`:1198-1217`) qualifies `codfamilia`, `stockfis`, `bloqueado` with `a.` for consistency (only `referencia`/`descripcion` would be ambiguous today, but qualifying all base references is the durable rule).

Composition with the existing logic: the token-AND structure is unchanged; only each `lower(descripcion) LIKE X` term is replaced by `languageDescriptionPredicate(X)`. `escapeForLike()` and `ESCAPE '|'` are untouched. The single-word and multi-word paths use the same helper, so exactly one language-agnostic predicate exists (the GDI-09 grep gate).

Duplicates and indexes:
- **Row multiplication**: the join yields one row per (article, description); `SELECT DISTINCT a.*` collapses them. `a.*` is a superset of `self::$column_list`; `articulo` hydrates by key, so extra columns are inert.
- **N+1**: none — the join is evaluated by the engine, not per row.
- **Cross-language AND**: for a multi-word query, token 1 may match `es` and token 2 `en` for the same article (the join is ungrouped). This is exactly the proven `search_tarifario` behaviour; the spec's GDI-09 scenarios do not require same-row token grouping. Documented as accepted.
- **Index impact**: the join needs an index on `articulo_descripciones.referencia` — already implied by `UNIQUE (referencia, codidioma)` (`model/table/articulo_descripciones.xml:35-38`), whose leftmost column is `referencia`. `DISTINCT` on a `text` column can force a temporary table on MySQL; the search is already a bounded, cached, `LIMIT`-ed query, and this is the same shape tarifario already runs in production.

### D-05 — Editor selector: server-rendered link selector, query-param carried, dynamic pair field names

**Choice**: the selector is a **button group of links** (no nested `<form>`, no JS endpoint):

```twig
{# tab_multiidioma.html.twig — rewritten in place; the include path is locked #}
<div class="panel panel-default" id="multiidioma">           {# anchor preserved #}
  <div class="btn-group btn-group-xs">
    {% for idioma in fsc.idiomas %}
      <a class="btn btn-default {% if idioma.codidioma == fsc.codidioma %}active{% endif %}"
         href="{{ fsc.url() }}&codidioma={{ idioma.codidioma }}#multiidioma">
        {{ idioma.nombre }}{% if idioma.por_defecto %} <span class="label label-primary">{{ 'default-language'|trans }}</span>{% endif %}
      </a>
    {% endfor %}
  </div>
  <input type="hidden" name="codidioma" value="{{ fsc.codidioma }}"/>
  <div class="form-group">
    <textarea class="form-control" name="descripcion_{{ fsc.codidioma }}" rows="3"
              autocomplete="off">{{ fsc.articulo.get_descripcion_idioma(fsc.codidioma) }}</textarea>
    <input class="form-control" type="text" name="descripcion_corta_{{ fsc.codidioma }}"
           value="{{ fsc.articulo.get_descripcion_corta_idioma(fsc.codidioma) }}" maxlength="150"/>
  </div>
</div>
```

- **Server-rendered**, selected language carried in the **query param** `codidioma` and re-posted as a hidden field. No session (no stale state), no JS (no nested form, no new endpoint, no CSP surface).
- **Field names are dynamic** (`descripcion_<selected>`), so the POST body already tells the server which language was written; the controller **also** validates the posted `codidioma` against the active set and never iterates `descripcion_*` keys — that is the structural guarantee behind "A posted value MUST never be written into a different language's slot" (GDI-11 "No cross-language write").
- **Removed**: the base textarea at `View/ventas_articulo.html.twig:121`, its hint (122-124), and `Controller/VentasArticulo.php:461` (`$art->descripcion = sdescripcion`). `sdescripcion` is no longer rendered or read for updates. This is the D1 consequence: the base column stops being presented as the default language.
- **Preserved**: the in-pane `href="#multiidioma"` anchor (`:106`), the `partials/articulos/tab_multiidioma.html.twig` include (`:342`), the two `render_hook(...)` lines (`:90`, `:384`) with their four frozen keys, and the tab set.
- Controller additions: `public string $codidioma = ''`, `public array $idiomas_todos = []` (management table); `resolve_codidioma()` = posted `codidioma` → GET `codidioma` → validate against `all_activos()` → `get_effective_default_code()`.

`saveMultiidiomaDescriptions()` is **rewritten** (not deleted — the method name is locked by ART-01 and `VentasArticuloControllerTest`):

```php
private function saveMultiidiomaDescriptions(\articulo $art, Request $request): void
{
    if ($art->referencia === null || $art->referencia === '') {
        return;                                  // no language write for a brand-new article
    }
    $codidioma = $this->resolve_codidioma($request);           // single destination
    $descripcion = (string) $request->request->get('descripcion_' . $codidioma, '');
    $descripcionCorta = (string) $request->request->get('descripcion_corta_' . $codidioma, '');
    // empty pair -> the model deletes the row (GDI-07); no `continue`, no empty-skip, no mirror
    $art->set_descripcion_idioma($codidioma, $descripcion, $descripcionCorta !== '' ? $descripcionCorta : null);
}
```

The default-language `continue` branch (`:833-836`) and the empty-input `continue` (`:847-849`) are both gone; the trailing `$art->save()` (`:858`) is gone too (the mirror is removed and the base column is no longer this path's concern).

### D-06 — Language-management UI: `#idiomas` section, `idioma_action` POST dispatch

**Choice**: one POST field `idioma_action` with values `save` | `toggle_active` | `set_default` | `delete`, dispatched in `VentasArticulos::privateCore()` after `load_list_idiomas()`:

```php
if ($this->request->isMethod('POST') && $this->request->request->has('idioma_action')) {
    $this->gestionarIdioma($this->request);
}
```

`gestionarIdioma()`:

```php
if (!$this->validateFormToken()) {                    // src/Core/Base/Controller.php:470
    $this->new_error_msg(/* language-csrf-invalid */);
    return;
}
if (empty($this->user->admin)) {                      // D12 — partial guard, not page-level
    $this->new_error_msg(/* language-admin-only */);
    return;
}
$action = (string) $this->request->request->get('idioma_action', '');
...
```

- **GET cannot mutate**: an `idioma_action` in the query string is never read; the guard requires `isMethod('POST')`.
- **`#[AdminOnly]` stays off `ventas_articulos`** (verified absent today; the attribute is page-level and would break the list for authorized non-admins). The mutation guard is per-action.
- **Templates**: new section in `View/ventas_articulos.html.twig` (a `#idiomas` panel between the list and the modals) with a table of all languages (`activo` badge, `por_defecto` badge) and one form per row plus an "add" form, each with `{{ csrf_field() }}`. The page keeps `load_list_idiomas()`'s active list for Excel and gains `$this->idiomas_todos = (new catalogo_idioma())->all();` from `VentasArticulosListTrait::load_list_idiomas()` for the table.
- **Translation keys** (`translations/messages.es_ES.yaml` + `messages.en_EN.yaml`), prefixed `language-`: `language-management`, `language-code`, `language-name`, `language-active`, `language-set-default`, `language-add`, `language-save`, `language-delete`, `language-cannot-delete-default`, `language-cannot-delete-last`, `language-cannot-deactivate-default`, `language-admin-only`, `language-csrf-invalid`, `language-empty-registry`, `language-created`, `language-updated`, `language-deleted`, `language-default-changed`. Existing keys (`languages`, `default-language`, `article-multi-language`, `short-description`, `no-languages-configured`) are reused, not redefined.

### D-07 — Excel export/import: additive locale columns, target-language import

**Export** (`Services/ArticuloExcelExportService.php`):
- `EXPORT_HEADERS` stays **byte-identical** (const untouched, `:33-41`).
- `buildSpreadsheet()` gains trailing optional params (positional back-compat): `array $idiomas = [], string $codidioma_defecto = ''`. New helper `descriptionHeaders(array $idiomas, string $codidioma_defecto): array` orders **default first, then remaining active languages by `codidioma`**, emitting `descripcion_<cod>` then `descripcion_corta_<cod>` per language. Layout: base 7 → locale pairs → feature columns; `writeFeatureCells()`'s offset (`:166`) becomes `count(self::EXPORT_HEADERS) + count($descriptionHeaders)`.
- Base `Descripción` cell resolves the configured default language (GDI-10): when the row is an `articulo`, `(string) $art->get_descripcion_idioma(null)`; for the array/example row, the `descripcion` key (no article object to resolve with).
- Deactivated languages are omitted because the caller passes `all_activos()`. With a single active language the appended set is its own pair and the seven base headers are unchanged.

**Import wizard** (`Services/ArticuloExcelImportWizardService.php`, `process_excel_wizard_dispatch.php`):
- `FIELD_CATALOG` stays byte-identical (CAR-17). Add `languageFieldCatalog(array $idiomas): array` returning `descripcion_<cod>` / `descripcion_corta_<cod>` entries (label `Descripción (<nombre>)`), and extend `preview(..., array $idiomas = [])` to feed those aliases into `suggestMapping()`'s `$extraFields` and merge their options into `fieldOptions()`. With `$idiomas = []` the wizard behaves exactly as today (legacy workbook scenario).
- **Target language** (R5): the SSE request carries `target_codidioma`. `handleCatalogoStart()` validates it against `all_activos()`; absent/unknown/inactive → `get_effective_default_code()`. It is never used to create or mutate a language row.
- **Destination rule** (literal reading of the import-wizard delta): every mapped locale `descripcion_<cod>` / `descripcion_corta_<cod>` column whose `<cod>` is an **active** language is applied to the **single target language's** row; the suffix qualifies importability, not destination. With several mappable locale columns the source is resolved deterministically: the column whose suffix equals the target language wins; otherwise the first mapped locale column in default-first-then-`codidioma` order. Unknown or deactivated suffixes are ignored silently.
- **Registry immutability**: the resolver only reads `all_activos()` and only calls `set_descripcion_idioma()`; no `catalogo_idioma::save()` exists anywhere on the import path. Test asserts the registry is byte-identical after importing `descripcion_zz` / `descripcion_fr`(inactive).
- **Base `descripcion` header keeps its legacy meaning** (writes `articulos.descripcion`, required for create) — the "Legacy workbook without locale columns imports unchanged" scenario. Locale columns never touch the base column (GDI-06).
- UI: `View/partials/articulos/modal_importar_excel_wizard.html.twig` gains a target-language `<select>`, and `View/js/articulos-excel-import-wizard.js:531-538` pushes `target_codidioma=` alongside `default_action`/`round_price`.
- `ArticuloExcelImportWizardService::CACHE_KEYS_TO_INVALIDATE` (`['articulo_search']`) is untouched; per-row invalidation is covered by D-03.

### D-08 — `descripcion_corta` accessor: `articulo::get_descripcion_corta_idioma($codidioma = null)`

**Choice**: add on `articulo`, implemented over the existing per-article cache:

```php
public function get_descripcion_corta_idioma($codidioma = null)
{
    if (is_null($this->referencia)) {
        return '';
    }
    if ($codidioma === null || $codidioma === '') {
        $codidioma = (new catalogo_idioma())->get_effective_default_code();
    }
    foreach ($this->get_descripciones() as $d) {          // one cached query, no N+1
        if ((string) $d->codidioma === (string) $codidioma) {
            return (string) ($d->descripcion_corta ?? '');
        }
    }
    return '';
}
```

**Composes with the read chain** by resolving the *language* through `get_effective_default_code()` (same default pointer), but it does **not** inherit the description fallback chain: it returns only the same-language row's short text, `''` otherwise. Rationale: the short description is presentation metadata; showing another language's short text as the selected language's would be a display-time materialisation of a fallback (R2) and would confuse the editor. This is a **local design decision with no external evidence** and is recorded as such in the code comment.

### D-09 — `get_descripcion_idioma($codidioma = null)` and `descripcion_idioma($codidioma = null, $len = 120)`

- Parameter default becomes `null`; `null`/`''` resolves `get_effective_default_code()` **once** into a local variable (avoid two lookups).
- **Unknown language code**: no error, no row creation. The requested code misses, resolution falls to the configured default, then the base column, then `''`. That is the pre-existing fallback generalized; stated explicitly because the selector can post a code that was deactivated between render and save.
- **Backward compatibility**: verified by grep — **zero** call sites pass no argument (`grep -rn "get_descripcion_idioma()"` and `descripcion_idioma()` in `plugins/` return nothing); all 21 `get_descripcion_idioma(` call sites pass an explicit code, and all 14 `set_descripcion_idioma(` sites are unaffected. The signature change is therefore behaviour-preserving for every existing caller; only the parameter default semantics change, which is the D6 fix.

### D-10 — `set_descripcion_idioma()` mirror removal, and why `tarifario/ExcelRowUpdater:256-259` is preserved

**Choice**: the mirror branch (`articulo.php:1451-1455`) is removed and the trailing `$art->save()` is not re-added. New behaviour:

```php
$this->descripciones_catalogo = null;
return $d->save();      // articulo_descripcion::save() owns clearing + cache invalidation
```

Behaviour change: setting a language no longer mutates `$this->descripcion` (neither in memory nor in the DB), so the base column can only go stale — which is the D1 contract, and the reason `articulos.descripcion` must never be presented as the default language.

**Reconciliation with `plugins/tarifario/Services/ExcelRowUpdater.php:256-259`**: that code writes the base column when `$codidioma === 'es' && empty($art->descripcion)` and calls `$art->save()`. It is **not** a mirror and is preserved because:
1. it is conditional on the base being **empty** (it never overwrites existing legacy text);
2. it is an explicit statement in a tarifario import service, not a side effect of `articulo::set_descripcion_idioma()` or `articulo_descripcion::save()`;
3. the spec README "Spec-phase decisions" item 2 declares it "preserved as the frozen legacy-compat behavior and is not a mirror of the language store", and the tarifario delta's "Already-correct readers are unchanged" scenario requires `ExcelRowUpdater.php:234-290` to remain unchanged.

GDI-06's "no save path MAY write a language's text into the base column" is therefore scoped to the **catalogo_core language API and description save paths** (the removed mirror, `saveMultiidiomaDescriptions`, the Excel locale columns, the import `Persistencia` rule). `verify` must treat `ExcelRowUpdater:256-259` as compliant, not as a violation — flagged so nobody "fixes" a canonical reader into a regression.

---

## Data Model / Migration

**Schema deltas: none.** `model/table/articulo_descripciones.xml` and `model/table/catalogo_idiomas.xml` are unchanged by this design (D-01 rejects the FK). No column is added, dropped or retyped; `articulo_descripciones.descripcion` is already `text NOT NULL`, so a `descripcion_corta`-only row can persist with `descripcion = ''`.

**Migrations required**: exactly one — the idempotent orphan purge (D-01). Hooks: `Services/CatalogLegacyTableMigration::migrateIfNeeded()`, already invoked from `Init::init()` (line 57) and `Init::upgrade()` (line 116). No `fs_var` flag is needed (the pre-check makes steady state a single `LIMIT 1`); this follows the class's existing `hasPendingRows()` / `copyPendingPrices()` convention.

**Idempotency proof**: the purge is "delete rows whose `codidioma` has no registry row"; after the first run the predicate matches nothing, and the pre-check returns early. Re-running any number of times is a no-op.

**Rollback**: revert the commits. The purge is the only irreversible step and it removes only unreachable rows; a pre-migration dump is the operator safety net (already in the proposal). Clearing the Twig cache after reverting the view layer is required (view changes).

---

## Locked-Contract Strategy

| Contract | How it is re-expressed | Where |
|---|---|---|
| ART-01 `$idiomas`, `saveMultiidiomaDescriptions`, `#multiidioma` include, `get()` acceptance | All four survive by name; the method body is rewritten (D-05) and the `$idiomas` array is extended with `idiomas_todos`, never removed. Assertions that locked the duplicated `fsc.articulo.descripcion` textarea are rewritten to assert the selector and `get_descripcion_idioma(fsc.codidioma)`. | `VentasArticulo.php`, `ventas_articulo.html.twig`, `tests/VentasArticuloControllerTest.php` |
| ART-05 tabs + four frozen hook markers | `#multiidioma` nav anchor (`:106`), the `tab_multiidioma.html.twig` include (`:342`), and both `render_hook(...)` lines with their four keys are untouched. The partial is rewritten **in place** (path is asserted by two locked tests), so no include moves. No marker is added, renamed or reordered. | `ventas_articulo.html.twig`, `partials/articulos/tab_multiidioma.html.twig` |
| `#multiidioma` anchor | Kept as the panel's `id` **and** reused as the link-selector's fragment target (`...&codidioma=xx#multiidioma`), so switching language returns to the section. | `partials/articulos/tab_multiidioma.html.twig` |
| `articulos-excel-import-export` base headers | `EXPORT_HEADERS` const is not edited; locale columns are computed additively after it. | `ArticuloExcelExportService.php` |
| tarifario canonical readers | `ExcelRowUpdater`, `ArticuloListActionHandler`, `tarif_catalogo_view`, `tarif_configurador_opcionales`, `tarif_articulo::search_tarifario` are verified only (D-10 reconciliation). | slice 5 |

Locked-test handling: `VentasArticuloControllerTest.php` and `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` are **updated in slice 3, in the same commit as the view/controller change** (never afterwards); `tests/Integration/CatalogoCoreHookMarkersTest.php` is expected to pass **unchanged** and is the regression guard for the markers.

---

## File-by-File Change Map

### Slice 1 — Model foundation

| File | Action | Change |
|---|---|---|
| `model/core/catalogo_idioma.php` | Modify | `get_default()` (+`activo = TRUE`); new `get_effective_default_code()`, `set_default()`; `save()` invariants in a transaction (force-active when default, reject deactivating the default, `normalize_default()`); `delete()` last-language guard + orphan cleanup + `normalize_default()`; private `normalize_default()` |
| `model/core/articulo_descripcion.php` | Modify | `test()` accepts an empty `descripcion`; `save()` empty-pair → `delete()` (or no-op); `save()`/`delete()` call `articulo::invalidate_search_cache()` |
| `model/core/articulo.php` | Modify | new `public static invalidate_search_cache()`; `clean_cache()` delegates to it; `get_descripcion_idioma($codidioma = null)`; `descripcion_idioma($codidioma = null, $len = 120)`; new `get_descripcion_corta_idioma($codidioma = null)`; `set_descripcion_idioma()` mirror removal; `search()` alias + join + `DISTINCT` + `ORDER BY a.referencia`; `buildSearchWhereClause()` `a.`-qualification |
| `Services/ArticuloSearchQueryBuilder.php` | Modify | `languageDescriptionPredicate()` (the one isolated predicate + deviation note), `languageDescriptionJoin()`, `a.`-qualified base columns |
| `Services/CatalogLegacyTableMigration.php` | Modify | `purgeOrphanDescriptions()` (pre-check + per-driver DELETE), called from `migrateIfNeeded()` |
| `model/table/articulo_descripciones.xml` | **Unchanged** | no FK (D-01) |
| `tests/CatalogoIdiomaInvariantsTest.php` | Create | GDI-02 (4 scenarios) |
| `tests/CatalogoIdiomaDeleteCleanupTest.php` | Create | GDI-03 (2 scenarios) |
| `tests/ArticuloDescripcionFrozenBaseTest.php` | Create | GDI-06 (2 scenarios) |
| `tests/ArticuloDescripcionClearingTest.php` | Create | GDI-07 (4 scenarios) |
| `tests/ArticuloSearchCacheInvalidationTest.php` | Create | GDI-08 (2 scenarios) |
| `tests/ArticuloSearchMultiidiomaTest.php` | Create | GDI-09 (3 scenarios, incl. the grep gate) |
| `tests/ArticuloMultiidiomaTest.php` | Modify | requested→default→base chain with rows, no-materialisation, `null` default |
| `tests/Services/ArticuloSearchQueryBuilderTest.php` | Modify | expected strings become `a.`-qualified; new assertion that the language predicate exists once |
| `tests/CatalogoIdiomaTest.php` | Modify | `get_default`, `all_activos`, `get_effective_default_code`, `set_default` |

### Slice 2 — Language-management UI (depends on slice 1)

| File | Action | Change |
|---|---|---|
| `Controller/VentasArticulos.php` | Modify | `idioma_action` POST dispatch; `gestionarIdioma()` with `validateFormToken()` + `$this->user->admin` gates and the four actions |
| `extras/VentasArticulosListTrait.php` | Modify | `public array $idiomas_todos = []`; `load_list_idiomas()` also loads `all()` |
| `View/ventas_articulos.html.twig` | Modify | new `#idiomas` panel (table + per-row forms + add form, each `{{ csrf_field() }}`) |
| `translations/messages.es_ES.yaml` | Modify | `language-*` keys (es) |
| `translations/messages.en_EN.yaml` | Modify | `language-*` keys (en) |
| `tests/CatalogoIdiomaManagementTest.php` | Create | GDI-01 (2 scenarios) + full lifecycle round trip |
| `tests/CatalogoIdiomaPermissionTest.php` | Create | GDI-04 (3 scenarios: non-admin, CSRF, GET) |

### Slice 3 — Editor selector (depends on slice 1)

| File | Action | Change |
|---|---|---|
| `Controller/VentasArticulo.php` | Modify | `public string $codidioma = ''`; `resolve_codidioma()`; `loadCatalogData()` sets it; `saveMultiidiomaDescriptions()` rewritten (D-05); remove `$art->descripcion = sdescripcion` (`:461`) |
| `View/ventas_articulo.html.twig` | Modify | remove the base textarea (`:121`) + hint (`:122-124`); keep `#multiidioma` (`:106`), the include (`:342`) and both markers |
| `View/partials/articulos/tab_multiidioma.html.twig` | Modify (in place) | link selector + hidden `codidioma` + exactly one description/short-description pair |
| `specs/catalogo-core/articulo-detalle-canonico/spec.md` | Already authored | ART-01/ART-05 MODIFIED (delta) |
| `tests/VentasArticuloControllerTest.php` | Modify | line 193 assertion → selector + `get_descripcion_idioma(fsc.codidioma)`; the four locked literals stay |
| `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | Modify | `sdescripcion` posts → `codidioma` + `descripcion_<cod>`; add posted-default-not-discarded, cross-language-write, clearing assertions |
| `tests/Integration/CatalogoCoreHookMarkersTest.php` | Unchanged | must stay green (regression guard) |

### Slice 4 — `catalogo_core` consumer migration (depends on slice 1)

| File | Action | Change |
|---|---|---|
| `Services/ArticuloExcelExportService.php` | Modify | `descriptionHeaders()`, locale columns after `Bloqueado`, base cell resolves the default language, feature offset shift |
| `Services/ArticuloExcelImportWizardService.php` | Modify | `languageFieldCatalog()`, `preview(..., array $idiomas = [])` wiring locale aliases into `suggestMapping()`/`fieldOptions()` |
| `Services/ArticuloExcelRowUpdater.php` | Modify | new `applyDescripcionIdioma()` helper for the dispatcher (base `applyDescripcion` case untouched) |
| `process_excel_wizard_dispatch.php` | Modify | read/validate `target_codidioma`; split mapped rows into base vs locale fields; locale fields → language API |
| `View/partials/articulos/modal_importar_excel_wizard.html.twig` | Modify | target-language `<select name="wizard_target_codidioma">` |
| `View/js/articulos-excel-import-wizard.js` | Modify | push `target_codidioma=` in `startApply()` |
| `Model/CatalogoApiService.php` | Modify | `'descripcion' => $a->get_descripcion_idioma()` (`:50`) |
| `model/tarif_articulo_precio.php` | Modify | `all_by_familias()` (`:421`): `LEFT JOIN articulo_descripciones d ON d.referencia = a.referencia AND d.codidioma = :def` + `COALESCE(d.descripcion, a.descripcion)` |
| `model/tarif_tarifa_articulo.php` | Modify | the six joins (`:171,269,295,321,347,376`) get the same driver-specific join; the `LIKE` at `:385` becomes `COALESCE(d.descripcion, a.descripcion)` |
| `Controller/VentasOpcional.php` | Modify | `buscarArticulo()` (`:394,397`) → `descripcion_idioma(null, 50)` / `get_descripcion_idioma()` |
| `extras/TarifarioOpcionalStateTrait.php` | Modify | `:111` → `get_effective_default_code()` |
| `tests/Services/ArticuloExcelIdiomasTest.php` | Create | export + import + Persistencia scenarios (14) |
| `tests/ConsumidoresIdiomaDefaultTest.php` | Create | GDI-10 catalogo_core scenarios |
| `tests/Services/ArticuloExcelExportServiceTest.php` | Modify | base headers still byte-identical; locale layout |

Quick-create (`VentasArticulos::nuevoArticulo()`, `:488`) intentionally keeps writing `articulos.descripcion` only: it is the legacy shim seeding path, not a language slot, and no language row exists yet at `$art->save()` time. Documented; `tasks` must not add a language write there.

### Slice 5 — `tarifario` consumer migration (depends on slice 4)

| File | Action | Change |
|---|---|---|
| `model/tarif_grupo_articulo.php` | Modify | `:150` join + `COALESCE`; `:401-405` `LOWER(...) LIKE` over the resolved value |
| `controller/tarif_historial_precios.php` | Modify | `:366` → language API with the configured default |
| `controller/tarif_actualizar_precios.php` | Modify | `:228` raw SQL join + `COALESCE` |
| `controller/tarif_roles.php` | Modify | `:718` → language API |
| `extras/tarif_controller.php` | Modify | `:240` → `$art->descripcion_idioma($this->codidioma, 50)`; `:243` → `$art->get_descripcion_idioma($this->codidioma)`; `:104` → `get_effective_default_code()` |
| `Services/ExcelRowUpdater.php`, `Services/ArticuloListActionHandler.php`, `controller/tarif_catalogo_view.php`, `controller/tarif_configurador_opcionales.php`, `model/tarif_articulo.php` | **Verify only** | no edit (D-10) |
| `specs/tarifario/catalogo-integration/spec.md` | Already authored | `R-TAR-HOOK-013` ADDED |
| `tests/Model/TarifGrupoArticuloIdiomaTest.php` | Create | R-TAR-HOOK-013 group-article scenario |
| `tests/Controller/TarifHistorialPreciosIdiomaTest.php` | Create | price-history export scenario |
| `tests/Controller/TarifControllerLanguageCallsTest.php` | Create | grep gate + no fatal |
| `tests/Integration/TarifIdiomaLegacyAliasTest.php` | Create | `tarif_idioma`/`tarif_descripcion` stay loadable |

---

## Interfaces / Contracts

```php
// catalogo_idioma — new/changed public surface
public function get_default();                       // now: por_defecto = TRUE AND activo = TRUE
public function get_effective_default_code(): string;
public function set_default(string $codidioma): bool;

// articulo — new/changed public surface
public static function invalidate_search_cache(): void;
public function get_descripcion_idioma($codidioma = null);
public function descripcion_idioma($codidioma = null, $len = 120);
public function get_descripcion_corta_idioma($codidioma = null);
public function set_descripcion_idioma($codidioma, $descripcion, $descripcion_corta = null);

// ArticuloSearchQueryBuilder — new static surface
public static function languageDescriptionPredicate(string $like, callable $quote): string;
public static function languageDescriptionJoin(): string;

// Excel export — trailing optional params (positional back-compat)
public function buildSpreadsheet(array $articulos, bool $includeExampleRow = false,
    string $codtarifa = '', array $exportable = [], array $idiomas = [],
    string $codidioma_defecto = ''): Spreadsheet;

// Import wizard — additive
public function languageFieldCatalog(array $idiomas): array;
public function preview(string $filePath, string $sheetName, int $maxRows = 10, array $idiomas = []): array;
```

Query shape produced by `articulo::search()` with a text query:

```sql
SELECT DISTINCT a.* FROM articulos a
LEFT JOIN articulo_descripciones d ON d.referencia = a.referencia
WHERE a.bloqueado = FALSE
  AND (lower(a.referencia) = 'x' OR lower(a.referencia) LIKE '%x%' ESCAPE '|'
       OR lower(a.partnumber) LIKE '%x%' ESCAPE '|'
       OR lower(a.equivalencia) LIKE '%x%' ESCAPE '|'
       OR lower(a.codbarras) = 'x'
       OR (lower(a.descripcion) LIKE '%x%' ESCAPE '|'
           OR lower(d.descripcion) LIKE '%x%' ESCAPE '|'))
ORDER BY a.referencia ASC
```

---

## Test Strategy

| Spec requirement | Test file (spec-named) | What it proves | Mode |
|---|---|---|---|
| GDI-01 | `tests/CatalogoIdiomaManagementTest.php` | lifecycle round trip; invalid payload rejected; `url()` resolves | unit/source |
| GDI-02 | `tests/CatalogoIdiomaInvariantsTest.php` | flag-flip moves no description row; deactivate-default rejected; total resolution; invariant after every mutation | unit |
| GDI-03 | `tests/CatalogoIdiomaDeleteCleanupTest.php` | last-language refusal; delete removes its rows, leaves others | unit |
| GDI-04 | `tests/CatalogoIdiomaPermissionTest.php` | non-admin no-op; CSRF no-op; GET cannot mutate; no `#[AdminOnly]` | unit/source |
| GDI-05 | `tests/ArticuloMultiidiomaTest.php` | requested wins; configured default (not `'es'`); base/`''` terminal; reads materialise nothing | unit |
| GDI-06 | `tests/ArticuloDescripcionFrozenBaseTest.php` | `set_descripcion_idioma` leaves the base unchanged; no backfill at boot | unit/source |
| GDI-07 | `tests/ArticuloDescripcionClearingTest.php` | both-empty deletes; short-only preserved; empty `descripcion` accepted; fallback after clearing | unit |
| GDI-08 | `tests/ArticuloSearchCacheInvalidationTest.php` | non-default write invalidates; clearing invalidates; no writer outside the model | unit |
| GDI-09 | `tests/ArticuloSearchMultiidiomaTest.php` | non-default term findable; cleared translation stops matching; one isolated predicate + deviation note | unit/grep |
| GDI-10 | `tests/ConsumidoresIdiomaDefaultTest.php`; `tests/Services/ArticuloExcelIdiomasTest.php` | API/opcional resolve the default; base column never written by the language path | unit |
| GDI-11 | `tests/VentasArticuloControllerTest.php`; `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | one pair per selected language; posted default never discarded; no cross-language write | source/controller |
| GDI-12 | `tests/Integration/CatalogoCoreHookMarkersTest.php` | `#multiidioma` + four markers keep names/positions; no new marker | source/render |
| Export/Import | `tests/Services/ArticuloExcelIdiomasTest.php` | base headers byte-identical; deterministic locale order; inactive omitted; target language defaults/overrides; unknown/inactive columns ignored with a byte-identical registry; clearing deletes the row | unit |
| R-TAR-HOOK-013 | `plugins/tarifario/tests/**` (4 files) | no-context readers use the default; dead calls gone; subclasses load; legacy aliases loadable | unit/grep |
| Slice 1 search builder | `tests/Services/ArticuloSearchQueryBuilderTest.php` | every base reference is `a.`-qualified (ambiguity guard) | unit |

**Reconciliation of spec-named test paths** (the spec README calls them pointers, not locks):
- `ConsumidoresIdiomaDefaultTest.php` is a Spanish name in an otherwise English test tree; keep the spec name to preserve traceability, or let `tasks` rename to `NoContextConsumersLanguageTest.php` — `verify` must follow whichever path `tasks` records.
- `tests/VentasArticuloControllerTest.php` and `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` **already exist** and are MODIFIED, not created.
- `tests/Services/ArticuloExcelIdiomasTest.php` is new; the existing `ArticuloExcelExportServiceTest.php`/`ArticuloExcelImportWizardServiceTest.php`/`ArticuloExcelRowUpdaterTest.php` are updated only where the additive shape changes expectations.
- Every scenario in the four deltas maps to a row above; no scenario is left without a target.

Test mechanics (existing conventions): `plugins/catalogo_core/phpunit.xml` uses `processIsolation="true"`; model unit tests use an anonymous `fs_model` subclass with an injected `db` stub or a DB-free fixture (see `tests/CatalogoIdiomaTest.php`, `tests/ArticuloMultiidiomaTest.php`); search-cache tests must reset `articulo::$search_tags`/`$cleaned_cache` by Reflection in `setUp()` (same pattern as `fs_core_log` in `AGENTS.md`); the static `fs_cache` in `invalidate_search_cache()` must be injectable or the test must run with the test cache adapter.

---

## Threat Matrix

N/A — this design introduces no routing change to `index.php`/`api.php`, no shell/subprocess execution, no VCS/PR automation, no executable-file classification and no new process integration. The one adjacent boundary is the Excel wizard's existing SSE endpoint, which is extended with a validated parameter, not newly exposed. Authorization on the new mutation surface (POST-only + `validateFormToken()` + `$user->admin`, GET structurally unable to mutate, `#[AdminOnly]` deliberately **not** applied) is covered as spec requirement GDI-04 with dedicated denial tests, not as a threat-matrix row.

---

## Alternatives Considered and Rejected

| Option | Rejected because | Decision |
|---|---|---|
| DB FK `articulo_descripciones.codidioma → catalogo_idiomas` `ON DELETE CASCADE` + migration | Only applied when `FS_FOREIGN_KEYS` is truthy (`fs_mysql.php:259,1455`), so GDI-03 can silently fail; `ADD CONSTRAINT` fails on installs with pre-existing orphans and degrades `fs_model::check_table()`; MySQL constraint drift churns FKs. PrestaShop itself deletes `_lang` children in `ObjectModel::delete()` (S5). | D-01 (app-level) |
| Make `get_default()` itself total and drop `get_effective_default_code()` | `get_default()` returns a row object used by several callers; making it total would hide "no persisted pointer" from code that needs to detect it. The string accessor is the single seam for the ~25 consumers. | D-02 |
| Instrument `articulo_descripcion` with its own duplicated cache-key logic | Duplicates the `articulos_search_<tag>` / `articulos_searches` contract in a second class; a refactor of the key format would silently half-invalidate. | D-03 |
| `EXISTS (SELECT 1 FROM articulo_descripciones d WHERE d.referencia = articulos.referencia AND lower(d.descripcion) LIKE ...)` instead of the join | Avoids `DISTINCT`, alias churn and MySQL DISTINCT+ORDER BY restrictions, but abandons the spec-cited proven precedent (`tarif_articulo::search_tarifario:259-329`) and gives the codebase two different shapes for the same semantic. Chosen: join + `DISTINCT`, with `ORDER BY a.referencia` to satisfy MySQL/Postgres DISTINCT ordering. | D-04 |
| Locale-scoped search (the industry default, S1/S6/S20/S28/S29) | Would silently hide articles whose only text is legacy base text or in another language, given ~25 legacy readers and the frozen column. Deviation is deliberate and documented (GDI-09). | D-04 |
| JS-driven selector (rename inputs + fetch the pair via a new endpoint) | Needs a new endpoint, `fetch`/CSP surface, and a JS state machine; nested forms are invalid HTML; a source-assertion test cannot pin the behaviour. Server-rendered links need no JS and are deterministic. | D-05 |
| Keep `sdescripcion` (base textarea) and add the pair only for non-default languages | Reproduces defect (g): the default language rendered twice, two writable representations of the same semantic. GDI-11 requires the base textarea removed. | D-05 |
| Destructure the import destination from the locale column suffix (`descripcion_en` → `en`) | Contradicts the import-wizard delta scenario, which states a mapped `descripcion_<codidioma>` column writes the **configured default** row unless an explicit target language overrides it for the whole import. Literal target-language semantics chosen; tie-break documented. | D-07 |
| Remove `tarif_controller::tarif_buscar_articulo()` entirely | It is dead in-repo, but it is `protected` and removable only safely for the four known subclasses; correcting the two calls has a strictly smaller blast radius and satisfies the grep gate. | slice 5 |
| Give `get_descripcion_corta_idioma()` the full description fallback chain | Would display another language's short text as the selected language's, materialising a fallback at display time (violates R2) and misleading the editor. Same-language-only is the chosen local decision. | D-08 |

---

## Risks and Mitigations

| # | Risk (design-specific) | Mitigation |
|---|---|---|
| 1 | **SQL ambiguity after the join**: any base reference left unqualified (`referencia`, `descripcion`) makes MySQL/Postgres raise "column is ambiguous" and search breaks entirely. | Qualify **all** base references with `a.` in `ArticuloSearchQueryBuilder` and `buildSearchWhereClause()`; add a builder unit test asserting every emitted base column is `a.`-qualified; smoke test the composed SQL against a real DB in slice 4. |
| 2 | **`DISTINCT` + `ORDER BY lower(a.referencia)`** raises error 3065 on MySQL ≥5.7 and an equivalent error on Postgres (ORDER BY expression not in the DISTINCT select list). | Change to `ORDER BY a.referencia ASC`; MySQL's default `utf8mb4_general_ci`/`..._0900_ai_ci` collation is case-insensitive, so ordering behaviour is preserved. Assert the ORDER BY form in the builder test. |
| 3 | **`SELECT DISTINCT a.*` fetches a superset** of `self::$column_list`, possibly a wider `text` payload. | `articulo` hydrates by key and ignores unknown columns; the join source is the same table. Verify with the existing article hydration tests; if a regression appears, project the explicit column list with `a.` prefixes instead of `a.*`. |
| 4 | **`DISTINCT` on a `text` column** can force a temporary table on MySQL for large tables. | Search is already `LIMIT`-ed and cached per query; the same shape runs in production in `search_tarifario`. If it regresses, the fallback is the `EXISTS` shape (explicitly noted as the plan-B in D-04). |
| 5 | **Removing the `#datos` description textarea** removes the only UI that edits `articulos.descripcion`; a product reviewer may read it as data loss. | Accepted consequence of D1/GDI-06: the column is a deprecated shim. Quick-create still seeds it, and the pair's prefill shows it through the read chain. Documented here so it is not "fixed" by re-adding a base field. |
| 6 | **GDI-06 vs `ExcelRowUpdater:256-259`** reads as a contradiction and a well-meaning fix breaks a canonical reader. | Explicit reconciliation in D-10 (the spec README decision 2 and the tarifario "already-correct readers unchanged" scenario settle it); `verify` must treat that writer as compliant. |
| 7 | **Import target-language interpretation**: the delta's literal reading (suffix qualifies importability, target language decides the destination) is unusual and could be implemented as suffix-as-destination by mistake. | Documented tie-break in D-07 and exercised by the "Target language defaults to the configured default" + "explicit target overrides" scenarios; flag for `tasks`/`verify`. |
| 8 | **`invalidate_search_cache()` static dependency on `fs_cache`** may be unavailable or unmockable in `processIsolation="true"` tests. | Keep the helper static and dependency-free (`new \fs_cache()`); tests either exercise it through the real test cache adapter or assert the delegation by source/Reflection. `tasks` decides; flagged in Open Questions. |
| 9 | **Cross-language token AND** in search returns an article whose tokens match different languages. | Accepted and documented as the proven `search_tarifario` behaviour; no spec scenario forbids it. Called out so a later contributor does not "fix" it into per-language grouping (which would re-scope the search and undo D11). |
| 10 | **Orphan purge deletes rows that were previously searchable** through the language-agnostic predicate. | Product-intended: a row for a language that no longer exists is unreachable from every surface. Called out in D-01 and covered by the delete-cleanup tests. |
| 11 | **Transactional invariants** are new to these models and a partially applied sequence would leave a multi-default state. | All multi-statement invariant work is wrapped in `begin_transaction()`/`commit()` with `rollback()` on failure; `normalize_default()` is idempotent and also runs as a safety net after every mutation, so a crashed request converges on the next mutation. |

---

## Decisions Index

| ID | Decision | Satisfies | Rationale anchor |
|---|---|---|---|
| D-01 | App-level orphan cleanup in `catalogo_idioma::delete()` + idempotent defensive purge; **no** XML FK, no schema change | GDI-03, defect c, R7 | PrestaShop `ObjectModel::delete()` (S5); `FS_FOREIGN_KEYS` gate (`fs_mysql.php:259,1455`); FK-add failure on existing orphans; Pimcore counter-precedent (S24) |
| D-02 | `get_effective_default_code()` = active default → lowest active `codidioma` → `'es'`; invariants enforced in one transaction; `set_default()`; last-language guard | GDI-02, GDI-03, defect d, R1, R8 | PrestaShop total default resolution (S5); no data move on default change (S5, S24, S18) |
| D-03 | `articulo::invalidate_search_cache()` (public static, one entry point) called by every `articulo_descripcion` write | GDI-08, defect f, R6, D11 | Existing `clean_cache()` contract (`articulo.php:1126-1144`); the once-per-request guard is preserved for mass updates |
| D-04 | Isolated `languageDescriptionPredicate()` + `languageDescriptionJoin()`; alias `a`/`d`; `SELECT DISTINCT a.*`; `ORDER BY a.referencia` | GDI-09, D11, R6 | `tarif_articulo::search_tarifario` (lines 229-283) is the proven in-repo precedent |
| D-05 | Server-rendered link selector, `codidioma` in the query + hidden field, one dynamic pair, `saveMultiidiomaDescriptions()` rewritten | GDI-11, defect g, defect b, D2, ART-01, GDI-12 | Akeneo working-context selector (S20); invalid nested forms; locked `#multiidioma` and include path |
| D-06 | `#idiomas` section; `idioma_action` POST dispatch; `validateFormToken()`; `$user->admin`; no `#[AdminOnly]` | GDI-01, GDI-04, D9, D12 | Framework gates (`src/Core/Base/Controller.php:470`); `catalogo_idioma::url()` seam |
| D-07 | Additive locale columns (default first, then by `codidioma`), base headers byte-identical, explicit import target language, unknown/inactive columns ignored | Export Excel, Import wizard 3 pasos, Persistencia, D8, R4, R5, G8 | Akeneo per-locale export selection (S22); no external locale-suffix convention (S1, S4) → local convention |
| D-08 | `articulo::get_descripcion_corta_idioma($codidioma = null)`, same-language only, `''` when absent | GDI-11 (prefill), R2 | Local decision: no external evidence; avoids materialising a display fallback |
| D-09 | `get_descripcion_idioma($codidioma = null)` / `descripcion_idioma($codidioma = null, …)`; unknown code falls through | GDI-05, D6 | PrestaShop resolves an invalid language to the default (S5); zero no-arg call sites today |
| D-10 | Mirror branch removed from `set_descripcion_idioma()`; `ExcelRowUpdater:256-259` explicitly preserved | GDI-06, D1, R2 | Spec README decision 2; tarifario "already-correct readers unchanged" scenario; the writer is conditional and explicit, not a mirror |
| D-11 | Clearing semantics live inside `articulo_descripcion::save()` (empty pair → row delete) | GDI-07, R3, defect b | PrestaShop in-place update vs Odoo key drop both mean "absence" (S1, S5); keeping `descripcion_corta`-only rows is a marked local decision |
| D-12 | `articulo_descripcion::test()` accepts an empty `descripcion` | GDI-07 | Absence is the stored representation of a cleared translation |
| D-13 | Quick-create keeps writing only `articulos.descripcion`; no language row is seeded there | GDI-06, D3 scope | The base column is the legacy shim; no language slot exists at create time |
| D-14 | The `EXISTS` search shape is the documented plan-B for D-04 | GDI-09 | Performance escape hatch if `DISTINCT` regresses on large tables |

---

## Open Questions (for `tasks` / `apply`)

- [ ] **Test seam for `invalidate_search_cache()`**: assert by injected cache adapter, by Reflection on `articulo::$search_tags`, or by a source-level delegation test. `tasks` picks one; the observable obligation (GDI-08) is fixed either way.
- [ ] **`ConsumidoresIdiomaDefaultTest.php` naming**: keep the spec-named Spanish path for traceability, or rename to `NoContextConsumersLanguageTest.php`. `tasks` records the final path; `verify` follows it.
- [ ] **Final directory grouping of the new Excel test** (`tests/Services/ArticuloExcelIdiomasTest.php` vs extending the three existing Excel test files) — the spec names the path; `tasks` may keep or split it as long as every scenario is covered.
- [ ] **Import tie-break messaging**: whether the UI warns when a file carries several mappable locale columns targeting one language. Not required by any scenario; `apply` may omit it.
