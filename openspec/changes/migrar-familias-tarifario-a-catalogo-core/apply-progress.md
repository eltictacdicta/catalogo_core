# Apply Progress — `migrar-familias-tarifario-a-catalogo-core`

Plugin-local SDD (`plugins/catalogo_core/openspec/`, `ownership: plugin-local`, `strict_tdd: true`).
This file is the portable handoff record: it replaces the Engram `apply-progress`
observation for anyone working from a different machine.

- **Updated**: 2026-10-23
- **Working branch for Phase 4**: `feature/familias-estructura-pr4` (based on `feature/familias-estructura-pr3`)
- **Status**: Phases 1–3 implemented and verified. Phase 4 (4.1–4.4) and Phase 5 (5.1–5.5) pending.
- **Verdict at last verify**: `pass_with_warnings` (no functional blockers; see "Open warnings").

## Delivery topology (all pushed to `origin`)

| Branch | Base | HEAD | Purpose |
|---|---|---|---|
| `main` | — | `d26d436` | released plugin (v1.9.2) |
| `fix/opciones-catalogo-persistencia` | `main` | `a409e1e` | catalog options persistence fix (PR pending) |
| `feature/familias-estructura-pr2a` | `main` | `695f4bf` | Phase 2a — migration service (754 lines) |
| `feature/familias-estructura-pr2b` | `pr2a` | `b07a477` | Phase 2b — tree guard + Init wiring + idempotency (512 lines) |
| `feature/familias-estructura-pr3` | `pr2b` | `6acae12` | Phase 3 — tree UI + article assignment + remediation |
| `feature/familias-estructura-pr4` | `pr3` | `6acae12` | Phase 4 work branch (no unique commits yet) |

PRs are **not created** (no `gh`/`hub` installed). Create them from:

- fix: `https://github.com/eltictacdicta/catalogo_core/pull/new/fix/opciones-catalogo-persistencia`
- pr2a: `https://github.com/eltictacdicta/catalogo_core/compare/main...feature/familias-estructura-pr2a?expand=1`
- pr2b: `https://github.com/eltictacdicta/catalogo_core/compare/feature/familias-estructura-pr2a...feature/familias-estructura-pr2b?expand=1`
- pr3: `https://github.com/eltictacdicta/catalogo_core/compare/feature/familias-estructura-pr2b...feature/familias-estructura-pr3?expand=1`

Chain strategy: `stacked-to-main`. Merge order: pr2a → pr2b → pr3 → pr4.

## Phase log

### Phase 1 — already on `main`
Extension tables, models, `CatalogoOptions::familyStructureEnabled()`, install ordering.
Tasks 1.1–1.4 complete.

### Phase 2 — migration service and tree guard (branch `pr2a` + `pr2b`)
| Commit | Work |
|---|---|
| `695f4bf` | `FamiliaEstructuraMigration` — set-based, FK-ordered, non-destructive, orphan-skip |
| `5b9b58b` | `FamiliaTreeGuard` — self/direct/deep cycle rejection |
| `c10a99c` | flag-gated migration wired into `Init::upgrade()` only |
| `40b8264` | double-run idempotency test (fake DB) |
| `b07a477` | coupling-string cleanup |

### Phase 3 — tree UI and article assignment (branch `pr3`)
| Commit | Work |
|---|---|
| `17ed9e8` | per-list structure UI on the surviving `VentasFamilia` / `ventas_familia` (menu `catalogo`) |
| `353d2cd` | article-to-family assignment per list in `VentasArticulo` |
| `fdcab83` | page-identity reconciliation + first verify report |
| `4854f55` | family-structure writes use page access, not `ArticlePermissionFilterEvent` |
| `a541c63` | flag-gated link to the canonical editor from `tarif_familias` |
| `bb20833` | resolve active lists in the structure save path (production bug) |
| `1079500` | move assignment forms outside the article form (invalid nested forms) |
| `36b0793` | keep `page`/`cod` on the structure list selector |
| `f2dea3a` | keep the selected list after an assignment save |
| `e863fd2` | resolve active lists at assignment save time (defensive hardening) |
| `e0d441f` | validate the posted family code before upsert |
| `afee374` | report a failed assignment delete as an error |
| `f9b650d` | keep the selected list after a structure save |

Verify rounds: `fdcab83`, `1385b8a`, `5bda94f`, `d35db32`, `0b28082`, plus the hash-reference fix `6acae12`.
CodeRabbit CLI reviewed the branch repeatedly; 3 majors + 4 minors were fixed and re-verified.

## Verification evidence (last full run)

- `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
  → **1441 tests, 5928 assertions, 0 failures, 0 errors, 2 warnings, 1 skipped**.
- Warnings are pre-existing: `model/core/catalogo_opcional.php:39` (undefined `id`),
  `model/core/articulo.php:713` (`foreach()` on null `$impuestos`).
- Skip is pre-existing: `InitFamiliasTablesTest::test_noop_when_model_files_absent`.
- `ArticleFamilyAssignmentTest`: 13/47 · `FamiliaEstructuraTreeTest`: 16/76 ·
  `FamiliaTreeGuardTest`: 6/12 · `FamiliaEstructuraMigrationTest`: 10/37 ·
  `FamiliaEstructuraMigrationIdempotencyTest`: 3/16 · `InitUpgradeTest`: 10/49.
- `HookRegistrationTest` (tarifario): OK (4 tests, 9 assertions).
- PHPStan: **not available** in this environment (`vendor/dev-tools/bin/phpstan` missing).

## Remaining work

### Phase 4 — catalog view and translations (tasks 4.1–4.4)
- **4.1** `Controller/VentasCatalogoView.php` (page `ventas_catalogo_view`, **menu `catalogo`**, admin-only, showonmenu) + `View/ventas_catalogo_view.html.twig`:
  tree filtered `activa = TRUE AND en_catalogo = TRUE` for the selected `codlista`;
  articles from `catalogo_articulo_familia_estructura WHERE en_catalogo = TRUE`;
  list identity only `catalogo_listas_precio.codlista`. TDD: L1-only rendering,
  inactive structures hidden, empty-state message.
- **4.2** `tests/ViewHookPointsTest.php` **before** 4.1 template edits: assert the 4 frozen
  hook points still exist in `ventas_articulo` / `ventas_opcional`.
- **4.3** Prefixed translations in `translations/messages.es.yaml` (+ fallback) for the
  D7 seed set, and convert the Phase 3 hardcoded labels to `catalogo-core-*` keys.
  `tests/TranslationAuditTest.php` must prove every new key is prefixed and resolves.
- **4.4** Dep-free grep audit: no `plugins/tarifario` references and no new legacy
  `tarif_tarifa_*` reads outside `FamiliaEstructuraMigration`. Pre-existing absorbed
  surfaces must be explicitly allowlisted with rationale, not rewritten.

### Phase 5 — regression and verification (tasks 5.1–5.5)
Full suite, live migration idempotency on a real DB, tarifario hook regression,
flag-off inertness, and task/spec coverage map.

## Open warnings (carried from verify)

1. Migration idempotency is proven with an in-memory DB double, **not** a real
   MySQL/PostgreSQL engine (task 5.2).
2. No authenticated browser/real-DB smoke: tree render, re-parent POST, assignment
   round-trip and the article save are source/test verified only.
3. `R-FJ-007` premise is stale: tarifario currently registers zero hooks; the 4
   markers still exist and `HookRegistrationTest` asserts the absence.
4. Task 4.4's literal "no legacy reads outside the migration" is not satisfiable
   because of pre-existing absorbed surfaces; scope the audit to new code plus an
   explicit allowlist.
5. Pre-existing nested image `<form hx-post>` inside the main article form exists on
   `main` too; out of scope but can break the article form in a real parser.
6. Phase 3 is one large PR (~2989 lines vs `pr2b`); splitting requires history surgery
   and was deferred.

## Handoff steps on another machine

```bash
cd <repo>/plugins/catalogo_core
git fetch origin --prune
git checkout feature/familias-estructura-pr4
ddev start
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
```

The Engram observation `sdd/migrar-familias-tarifario-a-catalogo-core/apply-progress`
(#164) is local memory and does **not** transfer automatically; this file is the
portable source of truth. `tasks.md`, `design.md`, the delta specs and
`verify-report.md` are all committed and pushed on `feature/familias-estructura-pr3`.
