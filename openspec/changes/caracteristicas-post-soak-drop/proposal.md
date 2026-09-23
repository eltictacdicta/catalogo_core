# Proposal: caracteristicas-post-soak-drop

## Intent

Close CAR-15 **clause 2** of `caracteristicas-producto`: drop the legacy
article/family visibility columns (`tarif_articulo_precios`, `tarif_tarifa_articulo`,
`tarif_tarifa_familia`, `tarif_familia_ext` when it carries them, plus the dead
`catalogo_opcionales` flags) after a real production soak of the feature path.

This change exists because the maintainer **deliberately split CAR-15 across two
releases**. Clause 1 (the four D12 opcional flag columns) was delivered in
`caracteristicas-producto` — auto-wired into `Init::upgrade()` through
`CaracteristicaColumnDropMigration::migrateIfNeeded()` (commit `c4fa9ecd`), with no
console and no flag. Clause 2 needs a soak window that cannot be compressed into
that release, so it was deferred rather than shipped half-proven.

## Scope

In scope:

- Run the soak: operate the catalog with the feature path selected (the default)
  and watch `visibilidad_sin_sincronizar` (the DEV-18 metric) during a JSON import
  and normal catalog traffic until the two paths are demonstrably stable.
- Rewrite the DEV-17 membership filters of `tarif_catalogo_view` (and the
  `all_en_catalogo*` / `count_en_*` helpers) to the feature tables **before** the
  drop, as the ordered prerequisite the service docblock and design §8.4/§8.5 record.
- Run the gated clause-2 drop on a pre-drop dump, reversibly.
- Auto-wire the clause-2 drop into `Init::upgrade()` exactly as clause 1 is now,
  so production needs no console and no flag.

Out of scope:

- Clause 1 — already delivered and auto-wired by `caracteristicas-producto`.
- No new feature tables, no change to the resolver's read semantics.
- No core `openspec/` entry; everything stays under `plugins/catalogo_core/openspec/`.

## Prerequisite (the soak)

The drop MUST NOT run until the feature path has been soaked and verified stable.
The feature path is already the production default: `CaracteristicaConfig::read_through()`
returns `!legacy_read_explicitly_enabled()`, so an **undefined** constant selects the
feature tables and only an explicit `FALSE` opts out to the legacy columns. The soak
therefore measures the default path, not a flag flip.

The soak signal is `visibilidad_sin_sincronizar` — the additive stat key DEV-18
introduced for the JSON import mirror. A non-zero value means a write path left the
legacy columns and the feature values divergent, which is exactly what must be zero
before the legacy columns can be dropped.

## Approach

1. **Soak** with the feature path (default) on a staging copy; enable it against real
   catalog traffic and a JSON import; confirm `visibilidad_sin_sincronizar` stays at
   zero and the flag-off/flag-on comparison is byte-identical.
2. **DEV-17 rewrite**: move the membership SQL filters off the legacy columns onto the
   feature tables; the ordered prerequisite is recorded in the service docblock and
   enforced as the required attestation of `runPostSoak()`.
3. **Gated drop**: `CaracteristicaColumnDropMigration::dropLegacyArticleFamilyColumns()`
   then `dropDeadOpcionalColumns()` (or `runPostSoak($db, true)`), on a pre-drop dump.
   Both stay idempotent via `hasColumn()` and both keep the **explicit-opt-out refusal**
   (`legacy_read_explicitly_enabled()`): while the emergency opt-out is selected the
   drop refuses and changes no schema.
4. **Auto-wire**: add `migrateIfNeeded()`-style clause-2 wiring to `Init::upgrade()`
   only (never `Init::init()`), mirroring the clause-1 delivery, with the same
   test-enforced exclusion from the boot/request path.

## Blast Radius

- `Services/CaracteristicaColumnDropMigration.php` (clause-2 auto-wire + opt-out refusal).
- `Init.php` (`upgrade()` wiring for clause 2).
- `plugins/tarifario/controller/tarif_catalogo_view.php` (DEV-17 membership rewrite).
- `tools/run_caracteristica_column_drop.php` (operator runbook stays available).
- Tests: `CaracteristicaColumnDropTest`, `CaracteristicaReversibilityTest`,
  `TarifCatalogoMembershipFilterRatificationTest`.

Rollback: a pre-drop dump plus the re-add-nullable / re-derive-from-resolver path
(`CaracteristicaReversibilityTest`). The drop itself is reversible by construction.
