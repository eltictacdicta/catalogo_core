# Proposal: Opcionales organized by tarifa

## Intent

No per-`(tarifa, opcional)` source of truth exists: activation is inferred from price-row existence and catalog visibility from global `tarif_opcional_ext` flags, so list and export can disagree with what a tarifa sells. Familias solved this with `tarif_tarifa_familia`; opcionales need the same explicit model.

## Scope

### In Scope
- New master `tarif_tarifa_opcional(codtarifa, id_opcional, en_catalogo, en_tarifa, activa, orden)` mirroring `tarif_tarifa_familia`.
- Master `activa` authoritative (price rows stop defining activation); master `en_catalogo` drives catalog/export; `tarif_opcional_ext` flags demoted to fallback (`ref_sap` global); master `orden` default, family/article `orden` scoped override.
- Lifecycle: default-tarifa install seed, lazy inherit for missing rows, `copy_from_tarifa()` on tarifa creation.
- List "Estado" becomes per-tarifa `activa`; edit/precios matrices and export read the master.

### Out of Scope
- Physical backfill; `tarif_opcional_ext`/`catalogo_opcionales` schema or API changes; configurator scope; `tpvmod`.

## Capabilities

### New Capabilities
- `opcionales-tarifa-management`: per-`(tarifa, opcional)` master state (activation, catalog/export visibility, default order), its seed/inherit/copy lifecycle, precedence rules, and UI/export consumption.

### Modified Capabilities
- None. `openspec/specs/` has only `familias-tarifa-management` and `articulos-excel-import-export`; their requirements are unchanged.

## Approach

Mirror `model/tarif_tarifa_familia.php`: `install()` seeds the default tarifa from `catalogo_opcionales LEFT JOIN tarif_opcional_ext` like `migrate_existing_familias()`; add `copy_from_tarifa()` called from `heredar_estructura()`; extend `Init::ensureOpcionalesTarifaTables()`. Price-level `en_catalogo` stays as per-lista inclusion.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `plugins/catalogo_core/model/table/tarif_tarifa_opcional.xml` + `model/tarif_tarifa_opcional.php` | New | Master schema; seed/inherit/copy; selectors, toggles |
| `plugins/catalogo_core/Init.php` | Modified | FK-safe bootstrap entry |
| `plugins/catalogo_core/controller/tarif_opcionales.php`, `tarif_opcional_edit.php`, `tarif_opcional_precios.php` | Modified | Read/write master flags; per-tarifa Estado |
| `plugins/catalogo_core/View/tarif_opcionales.html.twig`, `tarif_opcional_edit.html.twig`, `tarif_opcional_precios.html.twig` | Modified | Estado/Catálogo columns; matrix toggles |
| `plugins/catalogo_core/tests/` | New | CRUD, seed/inherit/copy, precedence, list |
| `plugins/tarifario/controller/tarif_catalogo_view.php`, `tarif_tarifas.php` | Modified (hybrid) | Export repoint + `heredar_estructura` copy step |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Price-row vs master `activa` divergence | High | Master authoritative; lazy inherit; precedence tests |
| Triple `en_catalogo` drift | Med | Precedence: master > price > ext fallback |
| Cross-plugin export coupling / test-locked contracts | Med | Explicit hybrid touch; ownership in catalogo_core; `catalogo_opcionales`/`tpvmod` untouched |

## Rollback Plan

Additive: `git revert` drops the model/table and restores prior reads; `Init` stops bootstrapping it. `tarif_opcional_ext`/`catalogo_opcionales` data is never mutated. Clear Twig cache after revert.

## Dependencies

- In-flight `absorber-opcionales-tarifa-en-catalogo-core` (unarchived) also declares `opcionales-tarifa-management`; reconcile at spec/archive.
- `tarifario` requires `catalogo_core`; runner `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`.

## Success Criteria

- [ ] Master table created FK-safe; bootstrap idempotent.
- [ ] Activation/catalog/order precedence plus seed, lazy inherit, and `copy_from_tarifa` test-locked.
- [ ] List "Estado" and export reflect per-tarifa master state.
- [ ] `catalogo_opcionales` XML/API and `tpvmod` untouched.
- [ ] Plugin suite green and PHPStan clean.
