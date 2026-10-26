# Capability: tarif-tarifa-divisa

Per-tariff ISO 4217 currency (`coddivisa`) for `tarif_tarifa`: the tariff
carries its own currency and every price view renders with it. Numeric amounts
are stored without an intrinsic currency tag; `tarif_tarifas.coddivisa` is the
single source of truth. No automatic FX conversion is introduced.

Migrated from `plugins/tarifario/openspec/specs/tarifario/tarifa/spec.md`
when the code moved to `catalogo_core` (change
`migrar-tarif-tarifas-a-catalogo-core`, 2026-09-13). The requirement ids
(`R-TAR-CUR-*`) are preserved so existing references stay valid.

Updated by change `tarifas-divisa-catalogo` (2026-10-26): the closed
`ALLOWED_CURRENCIES` whitelist (`EUR`, `MXN`, `USD`) was replaced by the
`admin_divisas` catalog. R-TAR-CUR-001/003/005/007 changed; the rest is
unchanged.

## Requirements

### Requirement: `tarif_tarifa::coddivisa` property (R-TAR-CUR-001)

`tarif_tarifa` MUST expose `public $coddivisa`. Constructor with no `$data` sets it to `'EUR'`; with `$data['coddivisa']` it normalizes the value via `trim()` + `strtoupper()` before assigning it. The model MUST NOT own a hardcoded currency whitelist; the selectable set is the `admin_divisas` catalog (R-TAR-CUR-007).

#### Scenario: Default currency on empty constructor

- GIVEN `new tarif_tarifa()` with no `$data`
- WHEN the caller reads `coddivisa`
- THEN it equals `'EUR'`

#### Scenario: Currency read from data array

- GIVEN `$data` includes `'coddivisa' => 'usd'`
- WHEN `new tarif_tarifa($data)` runs
- THEN `$model->coddivisa === 'USD'`

### Requirement: Persisted column `tarif_tarifas.coddivisa` (R-TAR-CUR-002)

XML schema MUST add `<columna><nombre>coddivisa</nombre><tipo>character varying(3)</tipo><nulo>NO</nulo><defecto>EUR</defecto></columna>` after `por_defecto`. `save()` MUST include `coddivisa` in INSERT column list and UPDATE SET clause.

#### Scenario: Install emits DEF with coddivisa EUR

- GIVEN `tarif_tarifa::install()` returns its SQL string
- WHEN the string is inspected
- THEN it contains the `DEF` row with `coddivisa = 'EUR'`

#### Scenario: Save update includes coddivisa in SET

- GIVEN an existing tariff with `coddivisa` changed to `'USD'`
- WHEN `save()` runs
- THEN the emitted UPDATE contains `coddivisa = 'USD'`

### Requirement: ISO 4217 format validation, no closed whitelist (R-TAR-CUR-003, R-TAR-CUR-005)

`test()` MUST normalize `coddivisa` with `trim()` + `strtoupper()` and MUST reject (returning FALSE with an explicit `new_error_msg`) any value that does not match the ISO 4217 shape `^[A-Z0-9]{3}$`. The class MUST NOT expose a hardcoded `ALLOWED_CURRENCIES` list and MUST NOT restrict the value to a fixed set of codes: which currencies exist is configuration, owned by `admin_divisas`.

#### Scenario: Any catalog currency passes the model

- GIVEN `$model->coddivisa = 'ars'`
- WHEN `test()` runs
- THEN returns TRUE
- AND `$model->coddivisa === 'ARS'`

#### Scenario: Malformed codes rejected

- GIVEN `coddivisa` in `'EU'`, `'EURO'`, `''`, `'U$D'`
- WHEN `test()` runs for each
- THEN each returns FALSE and emits an error message

### Requirement: DEF seed + idempotent column migration (R-TAR-CUR-004, R-TAR-CUR-006)

`install()` MUST seed `DEF` with `coddivisa='EUR'`. `tarifario_migrate_coddivisa_column()` in `plugins/tarifario/extras/tarifario_init.php` MUST add the column `ADD COLUMN coddivisa VARCHAR(3) NOT NULL DEFAULT 'EUR'` only when `get_columns('tarif_tarifas')` does NOT already contain `coddivisa`. The function MUST be called from `tarifario_check_tables()` after the existing `tarifario_migrate_codtarifa_length()` call. Idempotence guard: `static $executed` (mirrors the codtarifa pattern in the same file).

#### Scenario: Migration skipped when column already exists

- GIVEN `get_columns('tarif_tarifas')` already includes `coddivisa`
- WHEN the function runs
- THEN it returns without calling `exec()` (no `ALTER TABLE` issued)

#### Scenario: Migration adds column when missing

- GIVEN `get_columns('tarif_tarifas')` has NO `coddivisa` entry
- WHEN the function runs
- THEN exactly one `exec()` is called with `ALTER TABLE tarif_tarifas ADD COLUMN coddivisa VARCHAR(3) NOT NULL DEFAULT 'EUR'`

### Requirement: Controller-side catalog validation (R-TAR-CUR-007)

`tarif_tarifas::guardar_tarifa()` MUST read `$_POST['coddivisa']`, apply `trim()` + `strtoupper()`, fall back to the page's default currency when the field is empty, and validate the result against the currency codes configured in `admin_divisas`. A currency already stored in the edited tariff MUST remain acceptable so an unrelated edit is never blocked. On invalid input it MUST call `new_error_msg` and abort BEFORE `$tarifa->save()`. When no currency is configured at all it MUST degrade to the model's ISO format check. The page MUST expose the configured currencies and the default currency to the view, and the currency select MUST be built from them.

#### Scenario: Lowercase input is normalized

- GIVEN POST `coddivisa=usd` with `USD` configured
- WHEN `guardar_tarifa()` runs
- THEN `$tarifa->coddivisa === 'USD'` and `save()` is called

#### Scenario: Currency outside the catalog aborts without save

- GIVEN `EUR` and `USD` configured, `GBP` not configured
- WHEN POST carries `coddivisa=GBP` on a tariff whose stored currency is `EUR`
- THEN `new_error_msg` is called AND `save()` is NOT called

#### Scenario: Configured currency outside the old whitelist is accepted

- GIVEN `ARS` configured in `admin_divisas`
- WHEN POST carries `coddivisa=ARS`
- THEN the save is accepted and `coddivisa = 'ARS'`

#### Scenario: Stored currency removed from the catalog stays editable

- GIVEN a tariff stored with `coddivisa = 'GBP'` after `GBP` was removed from the catalog
- WHEN the operator renames that tariff
- THEN the save is accepted and `GBP` is preserved
- AND `GBP` is not offered when creating a new tariff

#### Scenario: Empty catalog degrades to format validation

- GIVEN no currencies configured in `admin_divisas`
- WHEN POST carries a three-character currency
- THEN the save is accepted
- AND a value that is not exactly three alphanumeric characters is rejected

### Requirement: Copy operations preserve destination currency (R-TAR-CUR-008)

`tarif_tarifas::heredar_estructura($origen, $destino)` and `copy_structure($origen, $destino)` MUST NOT copy `coddivisa` from the source. The destination's `coddivisa` is respected untouched. The docblock MUST state: "La moneda del destino se respeta; los precios copiados heredan el valor numérico tal cual, sin conversión de divisa. La re-evaluación de precios en la nueva moneda es responsabilidad del operador."

#### Scenario: copy_structure from EUR to USD does not overwrite USD

- GIVEN `origen.coddivisa = 'EUR'`, `destino.coddivisa = 'USD'`
- WHEN `copy_structure(origen, destino)` completes
- THEN the destination row's `coddivisa` is still `'USD'`

### Requirement: Multi-tariff table uses selected tariff's currency (R-TAR-CUR-009, Q3=B)

In `tarif_opcional_precios.html.twig` the "Configuración en todas las tarifas" table (rows from `get_precios_todas_tarifas()`) MUST render every price with the currency of `fsc.tarifa_seleccionada.coddivisa`, not the row's own tariff. The `show_precio()` call MUST receive `fsc.tarifa_seleccionada.coddivisa` as its second argument.

#### Scenario: All rows show selected currency symbol

- GIVEN `tarifa_seleccionada.coddivisa = 'USD'`
- WHEN the multi-tariff table renders
- THEN every price cell shows `$` regardless of the row's source tariff

#### Scenario: Switching selected tariff changes all symbols

- GIVEN the operator changes the selector to a tariff with `coddivisa='MXN'`
- WHEN the table re-renders
- THEN every price cell now shows `MX$` (rows tied to EUR or USD tariffs included)

### Requirement: View substitutions for `fsc.simbolo_divisa()` (R-TAR-CUR-010)

The 8 no-arg `fsc.simbolo_divisa()` call sites MUST be replaced with `fsc.simbolo_divisa(<expr>)`:

| File:line | `<expr>` |
|---|---|
| `tarif_articulo_precios.html.twig:105` | `fsc.tarifa_seleccionada.coddivisa` |
| `tarif_articulo_precios.html.twig:180` | `fsc.tarifa_seleccionada.coddivisa` |
| `tarif_opcional_precios.html.twig:124` | `fsc.tarifa_seleccionada.coddivisa` |
| `tarif_articulo.html.twig:87` | `fsc.codtarifa` |
| `tarif_opcional.html.twig:138` | `fsc.codtarifa` |
| `tarif_articulos.html.twig:473` | `value.coddivisa` (iteration over `fsc.tarifas`) |
| `tarif_opcional_edit.html.twig:323` | `tarifa.coddivisa` (iteration) |
| `tarif_opcionales.html.twig:281` | `fsc.codtarifa` |

#### Scenario: No-arg call sites eliminated

- GIVEN the 8 substitutions applied
- WHEN `grep -rn 'fsc\.simbolo_divisa()' plugins/tarifario/View/` runs
- THEN it returns zero matches

### Requirement: Python tool emits `tarifa_coddivisa` in JSON (R-TAR-CUR-011)

`plugins/tarifario/tools/tarifa_excel_to_json.py` MUST emit a `tarifa_coddivisa` field in the JSON metadata, defaulting to `'EUR'`. The class docstring MUST document that the field is an ISO 4217 three-letter code configured in `admin_divisas` (the `divisas` catalog), with `EUR`/`MXN`/`USD` as examples only — not as an exclusive whitelist — and that the current PHP importer ignores the field, so the operator must create the destination tariff with the correct currency before importing.

#### Scenario: Default currency in JSON output

- GIVEN an Excel with no currency column
- WHEN the converter runs
- THEN the JSON's `tarifa_coddivisa` equals `'EUR'`

### Requirement: Out-of-scope — no automatic FX conversion (R-TAR-CUR-012)

The system MUST NOT apply automatic FX conversion when `coddivisa` is changed on an existing tariff. Numeric prices are stored without an intrinsic currency tag; `tarif_tarifas.coddivisa` is the single source of truth.

#### Scenario: Currency change does not recompute prices

- GIVEN a tariff with `precio = 100.00` and `coddivisa = 'EUR'`
- WHEN the operator sets `coddivisa = 'USD'` and saves
- THEN the row's `precio` is still `100.00` (no `tasaconv` is applied)

### Requirement: Out-of-scope — PHP importer not modified (R-TAR-CUR-013)

The PHP JSON importer MUST NOT be modified. The operator MUST create the destination tariff with the correct `coddivisa` BEFORE importing.

#### Scenario: JSON import still targets DEF

- GIVEN a JSON with `tarifa_coddivisa = 'USD'`
- WHEN the operator runs the import via the existing modal
- THEN the import still routes to `DEF` and `DEF`'s `coddivisa` is unchanged
