# Capability: tarif-tarifa-divisa

Per-tariff ISO 4217 currency (`coddivisa`) for `tarif_tarifa`: the tariff
carries its own currency and every price view renders with it. Numeric amounts
are stored without an intrinsic currency tag; `tarif_tarifas.coddivisa` is the
single source of truth. No automatic FX conversion is introduced.

Migrated from `plugins/tarifario/openspec/specs/tarifario/tarifa/spec.md`
when the code moved to `catalogo_core` (change
`migrar-tarif-tarifas-a-catalogo-core`, 2026-09-13). The requirement ids
(`R-TAR-CUR-*`) are preserved so existing references stay valid.

## Requirements

### Requirement: `tarif_tarifa::coddivisa` property (R-TAR-CUR-001)

`tarif_tarifa` MUST expose `public string $coddivisa`. Constructor with no `$data` sets it to `'EUR'`; with `$data['coddivisa']` it normalizes the value via `trim()` + `strtoupper()` before assigning it (the controller also validates against the whitelist — R-007).

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

### Requirement: Closed whitelist `ALLOWED_CURRENCIES` (R-TAR-CUR-003, R-TAR-CUR-005)

The class MUST expose `public const ALLOWED_CURRENCIES = ['EUR', 'MXN', 'USD'];`. `test()` MUST reject (case-insensitive, post-trim) any `coddivisa` not in the whitelist, returning FALSE and calling `new_error_msg("Moneda no permitida. Use EUR, MXN o USD.")`.

#### Scenario: Invalid currency rejected

- GIVEN `$model->coddivisa = 'GBP'`
- WHEN `test()` runs
- THEN returns FALSE AND the error message contains `EUR, MXN o USD`

#### Scenario: Valid currencies accepted case-insensitive

- GIVEN the 3 valid currencies in lower/upper/mixed case (9 combinations)
- WHEN `test()` runs for each
- THEN returns TRUE for all 9

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

### Requirement: Controller-side normalization + validation (R-TAR-CUR-007)

`tarif_tarifas::guardar_tarifa()` MUST read `$_POST['coddivisa']`, apply `trim()` + `strtoupper()`, validate against `tarif_tarifa::ALLOWED_CURRENCIES`, then assign to `$tarifa->coddivisa`. On invalid input MUST call `new_error_msg` with the whitelist message and abort BEFORE `$tarifa->save()`.

#### Scenario: Lowercase input is normalized

- GIVEN POST `coddivisa=usd`
- WHEN `guardar_tarifa()` runs
- THEN `$tarifa->coddivisa === 'USD'` and `save()` is called

#### Scenario: Invalid input aborts without save

- GIVEN POST `coddivisa=GBP`
- WHEN `guardar_tarifa()` runs
- THEN `new_error_msg` is called with the whitelist message AND `save()` is NOT called

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

`plugins/tarifario/tools/tarifa_excel_to_json.py` MUST emit a `tarifa_coddivisa` field in the JSON metadata, defaulting to `'EUR'`. The class docstring MUST document: "El JSON puede llevar `tarifa_coddivisa: 'USD' | 'EUR' | 'MXN'`. El importador PHP actual ignora este campo; el operador debe crear la tarifa destino con la moneda correcta antes de importar."

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
