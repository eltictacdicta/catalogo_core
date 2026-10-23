# Verify Report: remove-fs-demo

- **Change**: `remove-fs-demo`
- **SDD owner**: `plugins/catalogo_core/openspec/` (plugin-local, `strict_tdd: true`)
- **Artifact store**: openspec
- **Implementation commit**: `1399f58` (`v1.6.3`, 2026-09-20)
- **Verified at**: 2026-10-23
- **Verifier**: retroactive closure verification against the shipped code

## Verdict

**`pass`**

Every success criterion in `proposal.md` is satisfied by the shipped code and the
re-run evidence below. No `FS_DEMO` read remains anywhere in the plugin; the two
absorption tests now pin the removal instead of the guard.

## Evidence

| Run | Command | Result |
|-----|---------|--------|
| Guard audit (controllers) | `grep -rn FS_DEMO --include='*.php' Controller/` | 0 matches |
| Guard audit (whole plugin) | `grep -rn FS_DEMO --include='*.php' .` | 2 matches — both `assertStringNotContainsString` assertions |
| Spec audit | `grep -rn FS_DEMO openspec/specs/` | 0 matches (no requirement delta to sync) |
| Focused absorption tests | `phpunit .../VentasArticuloArticleEditAbsorptionTest.php .../VentasArticulosListAbsorptionTest.php` | OK (31 tests, 197 assertions, 1 pre-existing warning — see below) |
| Plugin suite | `phpunit -c plugins/catalogo_core/phpunit.xml` | **1374 tests, 5660 assertions, 0 failures** (2 warnings, 1 skipped — pre-existing) |
| Root regression | `phpunit` | **3589 tests, 13229 assertions, 0 failures** (10 skipped — pre-existing) |

## Success criteria coverage

| Criterion (proposal) | Result |
|---|---|
| No `FS_DEMO` read remains in `plugins/catalogo_core/Controller/` | pass — 0 matches |
| The two absorption tests pin the chosen D1 contract | pass — both assert `FS_DEMO` is absent from the delete method |
| Plugin suite green | pass — 0 failures |
| Root `Plugins` suite green | pass — 0 failures in the root run |
| `vendor/` committed with any dependency change | N/A — no dependency change; `vendor/` untouched |
| Core/plugin landing order | pass — guards were `defined()`-guarded, so the plugin is inert with an undefined constant |

## Files modified by the change

| File | Change |
|---|---|
| `Controller/VentasFamilia.php` | guard removed |
| `Controller/VentasFabricante.php` | guard removed |
| `Controller/VentasFabricantes.php` | guard removed |
| `Controller/VentasArticulos.php` | guard removed |
| `Controller/VentasOpcional.php` | guard removed |
| `Controller/VentasArticulo.php` | guard removed |
| `Controller/AdminPaises.php` | guard removed |
| `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | assertion inverted |
| `tests/Controller/VentasArticulosListAbsorptionTest.php` | assertion inverted |
| `fsframework.ini` | version `1.6.3` |

## Warnings and residual references

1. **Pre-existing PHP warning, unrelated to this change.**
   `plugins/catalogo_core/model/core/articulo.php:713` emits
   `foreach() argument must be of type array|object, null given` when
   `self::$impuestos` has not been initialised (`set_impuesto()`). The
   `VentasArticulosListAbsorptionTest` focused run triggers it; it is a static
   cache initialisation gap in the model, present before this change and out of
   scope. Worth a separate fix.
2. **Residual `FS_DEMO` references** are exactly the two inverted assertions that
   enforce its absence — intentional, not dead guards.

## Open issues

None blocking archive.
