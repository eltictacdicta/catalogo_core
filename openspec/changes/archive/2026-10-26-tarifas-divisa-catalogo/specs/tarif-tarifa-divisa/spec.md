# Delta: tarif-tarifa-divisa — moneda desde el catálogo

## MODIFIED Requirements

### Requirement: `tarif_tarifa::coddivisa` property (R-TAR-CUR-001)

`tarif_tarifa` MUST expose `public $coddivisa`. Constructor with no `$data` sets
it to `'EUR'`; with `$data['coddivisa']` it normalizes the value via `trim()` +
`strtoupper()` before assigning it. The model MUST NOT own a hardcoded currency
whitelist; the selectable set is the `admin_divisas` catalog (R-TAR-CUR-007).

#### Scenario: Default currency on empty constructor

- GIVEN `new tarif_tarifa()` with no `$data`
- WHEN the caller reads `coddivisa`
- THEN it equals `'EUR'`

#### Scenario: Currency read from data array

- GIVEN `$data` includes `'coddivisa' => 'usd'`
- WHEN `new tarif_tarifa($data)` runs
- THEN `$model->coddivisa === 'USD'`

### Requirement: ISO 4217 format validation, no closed whitelist (R-TAR-CUR-003, R-TAR-CUR-005)

`test()` MUST normalize `coddivisa` with `trim()` + `strtoupper()` and MUST
reject (returning FALSE with an explicit `new_error_msg`) any value that does not
match the ISO 4217 shape `^[A-Z0-9]{3}$`. The class MUST NOT expose a hardcoded
`ALLOWED_CURRENCIES` list and MUST NOT restrict the value to a fixed set of
codes: which currencies exist is configuration, owned by `admin_divisas`.

#### Scenario: Any catalog currency passes the model

- GIVEN `$model->coddivisa = 'ars'`
- WHEN `test()` runs
- THEN returns TRUE
- AND `$model->coddivisa === 'ARS'`

#### Scenario: Malformed codes rejected

- GIVEN `coddivisa` in `'EU'`, `'EURO'`, `''`, `'U$D'`
- WHEN `test()` runs for each
- THEN each returns FALSE and emits an error message

### Requirement: Controller-side catalog validation (R-TAR-CUR-007)

`tarif_tarifas::guardar_tarifa()` MUST read `$_POST['coddivisa']`, apply `trim()`
+ `strtoupper()`, fall back to the page's default currency when the field is
empty, and validate the result against the currency codes configured in
`admin_divisas`. A currency already stored in the edited tariff MUST remain
acceptable so an unrelated edit is never blocked. On invalid input it MUST call
`new_error_msg` and abort BEFORE `$tarifa->save()`. When no currency is
configured at all it MUST degrade to the model's ISO format check. The page MUST
expose the configured currencies and the default currency to the view, and the
currency select MUST be built from them.

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
