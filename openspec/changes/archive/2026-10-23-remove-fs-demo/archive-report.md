# Archive Report: remove-fs-demo

- **Change**: `remove-fs-demo`
- **SDD owner**: `plugins/catalogo_core/openspec/` (plugin-local, `ownership: plugin-local`, `strict_tdd: true`)
- **Artifact store**: openspec
- **Archived at**: 2026-10-23
- **Archive path**: `plugins/catalogo_core/openspec/changes/archive/2026-10-23-remove-fs-demo/`
- **Implementation**: commit `1399f58`, released as `v1.6.3` (2026-09-20)
- **Core `openspec/` entry**: NONE for this plugin change — verified clean.

## Verdict

**`pass`** — carried from `verify-report.md`.

All success criteria are met by the shipped code: zero `FS_DEMO` reads remain in
the plugin, the two absorption tests pin the removal, and both the plugin and
root suites are green. No CRITICAL findings, no blocking warnings.

## Delivered work units

Single implementation commit `1399f58`, which also bumped `fsframework.ini` to
`1.6.3`:

| Surface | Change |
|---|---|
| `Controller/` (7 controllers) | dropped the `defined('FS_DEMO') && FS_DEMO` delete guards |
| Absorption tests (2) | inverted to assert `FS_DEMO` is absent from delete methods |
| `fsframework.ini` | version `1.6.3` |

## D1 decision recorded

**Option A — drop the guard.** The core retired `FS_DEMO` as an authority
posture, so the plugin guards had become dead code. Deletion is now governed by
`allow_delete` and the role system. Option B (a named read-only setting) remains
available if a real shared-demo deployment needs the protection.

## Spec sync (performed at archive)

| Domain | Action |
|---|---|
| — | None. The change removes a dead flag branch and changes no requirement; `grep -rn FS_DEMO openspec/specs/` returns zero matches. |

## Core counterpart (not this change)

The root `openspec/changes/remove-fs-demo/` is the **core** change (it touches
`base/`, `model/` and root `controller/`). It is a distinct, legitimate entry —
not a duplicate of this plugin change and not contamination. It ships with its
own 25/25 tasks and PASS verify report; it remains unarchived as of this report
and must be archived from the core lifecycle.

## Warnings (non-blocking)

1. **Pre-existing PHP warning in `model/core/articulo.php:713`**
   (`foreach() argument must be of type array|object, null given` when
   `self::$impuestos` is uninitialised). Surfaces in the isolated
   `VentasArticulosListAbsorptionTest` run; unrelated to this change. Follow-up
   candidate.

## Boundary

No dependency was added, so there is no `vendor/` change to verify. Guard
removals are additive-safe: they delete branches that could no longer be true,
leaving role-based deletion intact.
