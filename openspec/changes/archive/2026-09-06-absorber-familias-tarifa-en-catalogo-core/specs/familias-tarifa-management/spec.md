# Delta for familias-tarifa-management

New capability owned by catalogo_core. No MODIFIED/REMOVED requirements: `articulos-excel-import-export` is unaffected (the moved macro resolves via the global Twig loader).

## ADDED Requirements

### Requirement: Tarif familias page served by catalogo_core

`page=tarif_familias` MUST be served from `plugins/catalogo_core/controller/tarif_familias.php` with unchanged slug, class name, and table names (no data migration). The controller MUST extend `fbase_controller` and MUST NOT require any `plugins/tarifario` file. The view MUST load its JS from `plugins/catalogo_core/View/js/familias/index.js` and keep the menu folder label `tarifario`.

#### Scenario: Page renders after the move

- GIVEN catalogo_core active and the existing `fs_page` row
- WHEN `index.php?page=tarif_familias` is requested (smoke flow)
- THEN the catalogo_core controller serves it and the markup references `plugins/catalogo_core/View/js/familias/index.js`

#### Scenario: Controller decoupled from tarifario

- GIVEN the moved controller source
- WHEN a unit test asserts its parent class and require statements (grep gate)
- THEN the class extends `fbase_controller` and zero `tarif_controller` / `plugins/tarifario` requires exist

### Requirement: Familias hierarchy management

The controller MUST keep the hierarchy API consumed by the view: `get_familias_flat`, reorder that moves descendants with the reordered familia, promote/demote, automatic `capitulo` recalculation, and `get_next_capitulo`.

#### Scenario: Reorder moves the subtree

- GIVEN a familia with descendants in the flat tree
- WHEN the hierarchy unit test reorders it
- THEN descendants follow the new order and `capitulo` values are recalculated

#### Scenario: Promote/demote and next capitulo

- WHEN the unit test promotes a familia and calls `get_next_capitulo`
- THEN the parent/level changes, `capitulo` is recalculated, and `get_next_capitulo` returns the next free number

### Requirement: Toggle state management

The controller MUST expose AJAX toggles for `en_catalogo`, `en_tarifa`, and `activa`, persisting the flipped state and responding with JSON.

#### Scenario: Toggle flips state and answers JSON

- GIVEN a valid `codfamilia`
- WHEN each of the three toggles is invoked
- THEN the JSON response reports success and the new value, and the row persists the flip

#### Scenario: Toggle rejects unknown familia

- WHEN a toggle is invoked with an unknown `codfamilia`
- THEN the response is a JSON error and no HTML is emitted

### Requirement: Add/edit familia in tarifa

The add/edit modal flows MUST work against the moved controller: `get_posibles_madres` supplies candidate parents, and etiquetas persist per-tarifa visibility (`etiqueta_visible`) via `tarif_tarifa_etiqueta_familia`.

#### Scenario: Modal creation with posibles madres

- GIVEN the add/edit modal open for a tarifa
- WHEN the form submits a new familia with a chosen madre
- THEN the familia is created under that madre and appears in `get_familias_flat`

#### Scenario: Etiqueta visibility is per tarifa

- WHEN the unit test saves etiquetas for one familia under two tarifas
- THEN each `(codtarifa, codfamilia)` row stores its own `etiqueta_visible` value

### Requirement: Article count contract

The controller MUST keep public `count_articulos($codfamilia)` as a raw SQL COUNT against the stable table `tarif_tarifa_articulo` (no `tarif_tarifa_articulo` class dependency). The moved macro (`TarifarioComponents.html.twig:443`) calls `fsc.count_articulos`, and `delete_familia` MUST be blocked while the count is positive.

#### Scenario: Count returns the raw SQL total

- GIVEN a familia with N rows in `tarif_tarifa_articulo`
- WHEN the controller test calls `count_articulos($codfamilia)`
- THEN it returns the integer N

#### Scenario: Delete guard blocks non-empty familia

- GIVEN a familia whose count is greater than zero
- WHEN `delete_familia` runs
- THEN deletion is blocked

### Requirement: Excel export and import

The moved view/controller MUST keep the Excel flows: template download (`action=export_excel_template`), chunked import (`action=import_excel_chunk` via `base/fs_chunked_upload`), and export (`action=export_excel`). The view MUST emit CSRF meta so AJAX POSTs pass CSRF validation.

#### Scenario: Excel actions respond

- WHEN the controller test requests each of the three Excel actions
- THEN each responds without fatal error (template, exported file, chunk acknowledgement)

#### Scenario: CSRF meta present

- WHEN the moved view source is inspected
- THEN the CSRF meta helper is rendered in the page head

### Requirement: Standalone table bootstrap

With `tarifario` inactive, catalogo_core MUST create the four moved tables when missing, in FK-safe order: `tarif_tarifas` → `tarif_familia_ext` → `tarif_tarifa_familia` / `tarif_tarifa_etiqueta_familia`. The check MUST be idempotent. `tarifario_init` guards remain while tarifario is active.

#### Scenario: Fresh standalone install creates the tables

- GIVEN a database without the four tables and `tarifario` inactive
- WHEN the catalogo_core bootstrap runs (smoke test)
- THEN all four tables exist with FK dependencies satisfied by the declared order

#### Scenario: Bootstrap is idempotent

- WHEN the bootstrap runs again on an up-to-date database
- THEN it completes without error and changes no schema

### Requirement: Reference integrity after the move

Hardcoded `plugins/tarifario/model/<moved-model>` require paths MUST NOT remain in the repo. `ArticlePermissionListener.php` MUST drop its `model/tarif_tarifa.php` require (line 113). The moved macro `TarifarioComponents.html.twig` MUST keep serving the 5 remaining tarifario importers (`tarif_roles`, `tarif_tarifas`, `tarif_catalogo_view`, `partials/catalogo/toolbar.html.twig`, `tarif_configurador_opcionales`).

#### Scenario: Grep gate passes

- WHEN the verify-phase gate runs `grep -rE 'plugins/tarifario/model/(tarif_tarifa|tarif_familia|tarif_tarifa_familia|tarif_tarifa_etiqueta_familia|tarif_familia_ext)'` (excluding vendor/, openspec/)
- THEN it returns zero hits

#### Scenario: Macro still serves tarifario views

- GIVEN tarifario active alongside catalogo_core
- WHEN a tarifario page importing the macro renders (e.g. `page=tarif_roles`)
- THEN the import resolves to the catalogo_core macro without Twig errors

### Requirement: Test ownership split

`FamiliaOverrideRemovalTest` MUST be split: familias assertions move to `catalogo_core/tests/`, `tarif_catalogo_view` assertions stay in `tarifario/tests`. Both suites MUST cover both plugin activation orders and keep the `$GLOBALS['plugins']` / autoloader state guards.

#### Scenario: Split suites pass

- WHEN the plugin suites run via ddev (each plugin suite and the root Plugins suite)
- THEN the split tests pass in their owning plugin and remain auto-discovered

#### Scenario: Both activation orders safe

- WHEN the moved tests simulate each activation order (catalogo_core first, tarifario first)
- THEN no class redeclaration or missing-class failure occurs

### Requirement: Menu integration & plain list retirement

The moved controller MUST register as title `Familias` under folder `catalogo` (constructor `parent::__construct(__CLASS__, 'Familias', 'catalogo');`), replacing the `Familias Tarifario` entry under `tarifario`; the `fs_pages` row self-updates on next visit (legacy `check_fs_page`, base/fs_controller.php:816-885). The plain familias list page `page=ventas_familias` is RETIRED: its legacy shim (`controller/ventas_familias.php`), PSR-4 controller (`Controller/VentasFamilias.php`), view, and test are deleted, and its `fs_pages` row is removed idempotently via a cleanup hooked in `Init::upgrade()` — the menu does NOT filter dead pages (`fs_user::get_menu`, model/core/fs_user.php:332-351), so an orphaned row would render a dead item. The two remaining consumers of the retired page are repointed: the post-delete redirect in `Controller/VentasFamilia.php:159` → `page=tarif_familias`, and `familia::url()`'s null-code branch (`model/core/familia.php:91`) → `index.php?page=ventas_familia`. `page=ventas_familia` (edit/create form) stays otherwise untouched.

#### Scenario: Menu entry moves to catálogo

- GIVEN catalogo_core active and a visit to `page=tarif_familias`
- WHEN the constructor registers the page and the menu is built
- THEN `Familias` renders under folder `catalogo` and no `Familias Tarifario` entry remains under `tarifario`

#### Scenario: Plain list retired and row cleaned

- GIVEN the four retired artifacts deleted and the idempotent cleanup hooked in `Init::upgrade()`
- WHEN `page=ventas_familias` is requested
- THEN `find_controller` finds no controller (framework not-found behavior) and the `fs_pages` row for `ventas_familias` is absent after the cleanup runs

#### Scenario: Empty-state link targets the create flow

- GIVEN a tarifa with no familias and no familias disponibles in the moved view
- WHEN the empty state renders
- THEN the "Crear familias primero" link points to `index.php?page=ventas_familia` (create form, no `cod`)

#### Scenario: ventas_familia create/edit unaffected

- GIVEN the singular page kept
- WHEN the create flow (no `cod`) and the edit flow (`cod=<code>`) run
- THEN both work unchanged; the post-delete redirect targets `page=tarif_familias` and `familia::url()`'s null-code branch targets `index.php?page=ventas_familia`
