# Tasks: remove-fs-demo

Plugin-local change (`ownership: plugin-local`, `strict_tdd: true`). Every artifact
lives under `plugins/catalogo_core/openspec/`; the core `openspec/` gets NO entry.

- **Implemented in**: commit `1399f58` — `fix: remove FS_DEMO branches from controllers, update test annotations (v1.6.3)` (2026-09-20).
- **Released as**: catalogo_core `v1.6.3`.
- **Depends on**: core change `remove-fs-demo` (core counterpart tracked in the root `openspec/`, touches `base/`, `model/`, `controller/`).
- **Closure note**: the code landed before this tasks file existed; the work units below are recorded retroactively from the commit diff so the change can close with verifiable evidence.

## Review Workload Forecast

```text
Decision needed before apply: Yes (D1 — what replaces the demo delete guard)
Chained PRs recommended: No
400-line budget risk: Low
```

Seven five-line guard removals plus two assertion updates: one small PR.

## D1 — resolved: option A (drop the guard)

The seven guards blocked deletes when `FS_DEMO` was defined. The core no longer
defines the constant nor treats demo as a privileged posture, so the guards had
become dead code. Option A drops them; the role system and `allow_delete` own
deletion. Option B (a named read-only setting) stays available for a future
deployment that actually needs shared-demo protection.

## Phase 1: Remove the demo delete guards

- [x] 1.1 `Controller/VentasFamilia.php` — remove `defined('FS_DEMO') && FS_DEMO` delete guard.
- [x] 1.2 `Controller/VentasFabricante.php` — same removal.
- [x] 1.3 `Controller/VentasFabricantes.php` — same removal.
- [x] 1.4 `Controller/VentasArticulos.php` — same removal.
- [x] 1.5 `Controller/VentasOpcional.php` — same removal.
- [x] 1.6 `Controller/VentasArticulo.php` — same removal.
- [x] 1.7 `Controller/AdminPaises.php` — same removal.

## Phase 2: Pin the new contract in tests

- [x] 2.1 `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` — assert the delete method no longer contains `FS_DEMO` instead of asserting the guard exists.
- [x] 2.2 `tests/Controller/VentasArticulosListAbsorptionTest.php` — same inverted assertion.

## Phase 3: Regression and release

- [x] 3.1 Plugin suite green: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`.
- [x] 3.2 Root regression green: `ddev exec php vendor/bin/phpunit`.
- [x] 3.3 `vendor/` rule — no dependency added or changed, nothing to commit.
- [x] 3.4 Version bump + release `v1.6.3` in the same commit.

## Success criteria (from proposal.md)

- [x] No `FS_DEMO` read remains in `plugins/catalogo_core/Controller/`.
- [x] The two absorption tests pin the chosen D1 contract.
- [x] Plugin suite green.
- [x] Root suite green.
- [x] `vendor/` untouched (no dependency change).
