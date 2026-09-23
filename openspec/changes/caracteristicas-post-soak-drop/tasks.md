# Tasks: caracteristicas-post-soak-drop

Plugin-local change (`ownership: plugin-local`, `strict_tdd: true`). Every artifact
lives under `plugins/catalogo_core/openspec/`; the core `openspec/` gets NO entry.

- **Relocated from**: `caracteristicas-producto` tasks **7.5** (soak) and **7.6**
  (clause-2 gated drop), which were staged for a later release by maintainer decision
  and removed from that change so it could archive cleanly.
- **Clause 1 is already delivered** by `caracteristicas-producto` (auto-wired into
  `Init::upgrade()` through `CaracteristicaColumnDropMigration::migrateIfNeeded()`,
  commit `c4fa9ecd`). This change owns **clause 2 only**.
- **Depends on**: the `caracteristicas-producto` release being live (the feature path
  is the default; the D12 flags are dropped).

## Review Workload Forecast

```text
Decision needed before apply: Yes
Chained PRs recommended: No
400-line budget risk: Low
```

The work is one operator soak plus a small auto-wire block and one membership-filter
rewrite; the drop engine already exists and is unit-covered.

## Relocated clause-2 work

- [ ] C2.1 **Soak the feature path (relocated from 7.5).** Operate the catalog with
  the feature path selected (the default — `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH`
  undefined or `TRUE`) against a staging copy, compare flag-off/flag-on output, and
  watch `visibilidad_sin_sincronizar` during a JSON import and normal traffic until it
  is demonstrably zero. **Prerequisite already closed:** DEV-18 (JSON import mirror) and
  DEV-17 (membership-filter ratification) landed in `caracteristicas-producto` WU-8.
  **Gate:** the soak MUST stay green for the whole window before C2.3 runs.
- [ ] C2.2 **Rewrite the DEV-17 membership filters to the feature tables (relocated
  ordering prerequisite).** Move the `tarif_catalogo_view` membership SQL filters and the
  `all_en_catalogo*` / `count_en_*` helpers off the legacy columns onto the feature
  tables, so the clause-2 drop cannot leave invalid SQL. The surface is frozen by
  `TarifCatalogoMembershipFilterRatificationTest`; update it with the rewrite.
  **Ordering:** MUST land before C2.3.
- [ ] C2.3 **Run the gated clause-2 drop on a pre-drop dump (relocated from 7.6).**
  `CaracteristicaColumnDropMigration::dropLegacyArticleFamilyColumns($db)` **then**
  `dropDeadOpcionalColumns($db)` — or `runPostSoak($db, true)` once the readiness gates
  hold — on a pre-drop dump, **after** C2.2. The drop MUST keep the
  **explicit-opt-out refusal** (`legacy_read_explicitly_enabled()`): while the emergency
  opt-out is selected it refuses and changes no schema. Idempotent via `hasColumn()`.
  Never executes at boot in this step.
- [ ] C2.4 **Auto-wire the clause-2 drop to `Init::upgrade()` exactly as clause 1 is
  now.** Add the clause-2 entry point to `Init::upgrade()` (the version-change path run
  by `PluginSchemaSynchronizer`), **never** to `Init::init()`, mirroring
  `migrateIfNeeded()`'s clause-1 delivery. Enforce the boot/request exclusion with a test
  (`Init::init()` does not call it), as clause 1 already does.
- [ ] C2.5 **Verify**: `CaracteristicaColumnDropTest` (clause-2 default-runs + opt-out
  refusal + idempotency + the `upgrade()`-only wiring), `CaracteristicaReversibilityTest`
  (clause-2 reversal), `TarifCatalogoMembershipFilterRatificationTest` (post-rewrite),
  both plugin suites green, and the runner dry run reports the clause-2 columns absent.

**Rollback boundary**: a pre-drop dump plus the re-add-nullable / re-derive-from-resolver
path (`CaracteristicaReversibilityTest`). Reverting C2.4 removes the auto-wire; the
columns are re-addable as nullable and re-derivable from the feature tables.

**Explicit non-goals**: clause 1 (delivered); no new tables; no read-semantics change;
no core `openspec/` entry.
