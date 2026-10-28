# Verify Report: migrar-familias-tarifario-a-catalogo-core

**Date:** 2026-10-26
**Status:** PASS

## Verdict

| Check | Result |
|---|---|
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **1530 tests / 6325 assertions**, 2 warnings + 1 skipped (pre-existing) |
| `ddev exec php vendor/bin/phpunit` (root) | **4994 tests / 19326 assertions**; 12 failures, **all pre-existing `Tests\OidcProvider\*`** (DB state: missing FK `oidc_cliente_grupos_grupo_fk`, `utf8mb3`/`utf8mb4` collation). Zero `CatalogoCore` failures |
| `ddev exec composer phpstan:plugins` | `[OK] No errors` |
| `tests/TarifFamiliasControllerContractTest.php` | **untouched** (last change `f0513271`, before this change) and green |
| Legacy `tarif_*` writes in the flag-on path | zero |
| `familia.php` / `familias` table | untouched |

## Requirement coverage

| Requirement | Evidence |
|---|---|
| R-FJ-001 hierarchical tree UI + cycles | `FamiliaTreeGuardTest` (8), `TarifFamiliasDualSourceTest` (cycles direct/deep/self), `TarifFamiliasTreeViewTest` (derived depth) |
| R-FJ-002 per-list structure extension | `CatalogoFamiliaEstructuraTest`, `FamiliaEstructuraStoreTest` (19) |
| R-FJ-003 per-list article assignment | `CatalogoArticuloFamiliaEstructuraTest`, `VentasArticuloFamilyAssignmentTest` (14) |
| R-FJ-004 catalog view per list | `VentasCatalogoViewControllerTest` (17) |
| R-FJ-005 non-destructive migration | `FamiliaEstructuraMigrationTest` (11), `FamiliaEstructuraMigrationIdempotencyTest` (4, real DB) |
| R-FJ-006 fs_access preservation | `FamiliasPageAccessPreservationTest` (5) + the untouched legacy contract test |
| R-FJ-007 hook compatibility | `ViewHookPointsTest` (3) + `HookRegistrationTest` in tarifario (4/4) |
| R-FJ-008 plugin-prefixed translations | `TranslationAuditTest` (13) |

## Implementation shape

The families page (`controller/tarif_familias.php`) became **dual-source**, selected
by `CatalogoOptions::familyStructureEnabled()` (default **off**):

- **Flag off** — the shipped behaviour, byte for byte: legacy
  `tarif_tarifa_familia` model, same HTMX actions, same fragments. The pre-existing
  contract test passes unmodified, which is the rollback guarantee.
- **Flag on** — same UX over `catalogo_familia_estructura` keyed by `codlista`:
  active-list selector, one query per render (no N+1), upsert through the
  canonical model, `FamiliaTreeGuard::assertAcyclic()` before any `madre` write,
  and `ArticlePermissionFilterEvent` dispatch on every structure write with
  fail-closed semantics. `tarif_*` receives no writes in this path.

Reads/writes go through `Services/FamiliaEstructuraStore.php`, which keeps the
controller thin and the behaviour testable without a database.

## Findings

### WARNING-1 — Task 3.5 could not be executed as written

3.5 asked to assert that `VentasFamilias::getPageData()` still exposes
`ventas_familias` / menu `ventas`. That page was **retired** before this work:
`3a5a42ef` (2026-09-06) repointed the menu and `Init::retireVentasFamiliasPage()`
deletes its `fs_pages` row on every boot. The task therefore asked to verify the
opposite of what the code does.

Resolved by asserting the real target: `tarif_familias` keeps its page identity
and access resolution, and `ventas_catalogo_view` is registered under `ventas`
(`FamiliasPageAccessPreservationTest`). The discrepancy is documented in
`tasks.md` next to the Phase 3 note.

### WARNING-2 — Excel import/export stays legacy-only when the flag is on

`tarif_familias` refuses the Excel paths with an explicit error in the flag-on
path, because those flows read and write the legacy tables. Enabling Excel over
the canonical structure is a separate change, not a regression: the flag is off
by default and the legacy path is untouched.

### WARNING-3 — No browser smoke

The tree view is verified by rendering the real template in a Twig environment
and by controller-level tests, not by an authenticated session. The operator
should open `?page=tarif_familias` with the flag on once before enabling it in
production.

## Deliberate deviations recorded

- `FamiliaEstructuraStore::assertAcyclicInList()` exists alongside
  `FamiliaTreeGuard`: the canonical tree nests through `estructura.madre`, while
  the guard walks `familias.madre`. Both are needed.
- `ArticlePermissionFilterEvent` carries `referencia = codfamilia` and `codlista`
  in the structure writes: a family-structure write has no article reference.
  `reorder` dispatches once per changed row, in two phases, so a denied write
  leaves no partial order.
- The template branches on the flag only through `fsc.source_query()`, which
  yields `codlista=<code>` or `codtarifa=<code>`. The legacy markup is preserved
  except for whitespace between tags (verified by diffing both renders with the
  same fixture).

## Specs synced

| Domain | Action |
|---|---|
| `familias-jerarquia-catalogo` | **CREATED**: 8 requirements / 20 scenarios, byte-identical to the delta |
| `multi-tariff-pricing` | **MERGED**: `R-MT-002` replaced, `R-MT-006` appended, both byte-identical to the delta |

No canonical spec carries a leftover delta marker.
