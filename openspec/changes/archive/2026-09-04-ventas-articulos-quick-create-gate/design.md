# Design: Quick-Create Article Form Price Gate (ventas-articulos-quick-create-gate)

**Change**: `ventas-articulos-quick-create-gate` · **Domain**: `articulos-quick-create-permission` · **Artifact store**: OpenSpec plugin-local (catalogo_core)
**Spec**: delta spec R-QCRT-001..007 + behavioral-test discipline (14 scenarios, frozen) · **Config**: `plugins/catalogo_core/openspec/config.yaml` (`strict_tdd: true`)

## Technical Approach

Route the quick-create `pvp` write through the frozen `ArticlePermissionFilterEvent` contract, mirroring the article-page ordering (`VentasArticulo.php:208-224`: dispatch before mutation/save, deny → early return) with the Excel gate's fail-closed catch style (`ArticuloExcelPermissionGate.php:73-82`: `\Throwable` → generic reason, **no `error_log`** — empirically confirmed: `error_log` under the host suite's `processIsolation="true"` surfaces as `PHPUnit\Framework\Exception`, probe-verified). Admin skips dispatch entirely (Excel-gate AD-5 construction, `ArticuloExcelPermissionGate.php:55-57`). Deny blocks the whole create (no separate article/pvp commit points — single `save()` at `VentasArticulos.php:480`). Host-neutral: zero listeners ⇒ default allow (event contract).

## Architecture / Flow

```
Quick-create POST (nreferencia) → VentasArticulos.php:96 → nuevoArticulo() (:444)
  → validateFormToken() (:446)  [invalid → msg + return; NO dispatch, NO create]
  → read fields (:451-456)
  → referencia/descripcion validation (:458-461)  [fail → msg + return]
  → duplicate check: $this->articulo->get() (:463-466)  [dup → msg + return]
  → [NEW] admin? ($this->user->admin) : skip → ALLOW      (R-QCRT-002, zero dispatch)
  → [NEW] dispatch ArticlePermissionFilterEvent(referencia, ACTION_EDIT_ARTICLE, nick)
          wrapped in try/catch \Throwable → deny('permission filter error')  (R-QCRT-004)
  → denied?  → new_error_msg('No tienes permisos para crear este artículo: ' . getDenialReason())
              + return  →  save() unreachable, no article row            (R-QCRT-003)
  → allowed? → $art = new \articulo() (:468) → $art->pvp = $pvp (:473)
              → set_impuesto (:478) → save() (:480) → success msg
```

Denial UX: `new_error_msg` → `fs_core_log` static stack → rendered by `themes/AdminLTE/view/header.html.twig:258-260` (`fsc.get_errors()`) at the top of the re-rendered list page (POST targets `{{ fsc.url() }}`, `modal_nuevo_articulo.html.twig:4`; `privateCore` continues to render the list after `nuevoArticulo`). No JSON, no JS change. Whole create blocked: no separate article/pvp commit points — one `save()` at `:480`; deny returns before `new \articulo()` (`:468`). No partial state.

## Architecture Decisions

### D1 — Dispatch placement in `nuevoArticulo()`
| Option | Tradeoff | Decision |
|---|---|---|
| Between duplicate check (`:466` `}`) and `$art = new \articulo();` (`:468`) | Gate precedes model construction; deny ⇒ `\articulo` never instantiated (no DB/cache side effects); cleanest "deny blocks whole create" | **Adopt** — insert the dispatch block at `:467` |
| Between `$art->pvp = $pvp` (`:473`) and `save()` (`:480`) | Spec-compliant (before mutation) but model already constructed on deny; weaker isolation | Reject |

Event construction: `new ArticlePermissionFilterEvent($referencia, ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE, $this->user->nick)` — frozen `NAME`/`ACTION_EDIT_ARTICLE` (`Event/ArticlePermissionFilterEvent.php`), no tarifa. Catch shape mirrors `ArticuloExcelPermissionGate.php:73-82` exactly: `catch (\Throwable)` → `$event->deny('permission filter error')` — **no `error_log`** (R-QCRT-004; probe-verified failure mode). Message prefix mirrors `VentasArticulo.php:222` convention, adapted: `'No tienes permisos para crear este artículo: '`.

### D2 — Admin short-circuit
| Option | Tradeoff | Decision |
|---|---|---|
| `if (!$this->user->admin) { …dispatch… }` | Zero construction/dispatch for admins; outcome never depends on listener correctness (R-QCRT-002 by construction) | **Adopt** |
| Dispatch and trust the listener's `isAdmin` | One dispatch still runs; outcome depends on listener correctness | Reject |

Admin determination on this path: `$this->user->admin` — the controller's user object (`Controller.php:51` `public $user`, legacy `fs_user` model), same property used at `VentasArticulos.php:71` and mocked in `CatalogoExcelWizardAccessTest::mockUser()` as an anonymous `\FSFramework\model\fs_user` subclass setting `nick`/`admin`. **Correction to proposal**: `editarArticulo` (`VentasArticulo.php:191-224`) has **no** admin skip — it dispatches for all users; admin dominance there is delegated to the listener. The skip-dispatch construction is the `ArticuloExcelPermissionGate::check` precedent only.

### D3 — Behavioral test harness
| Option | Tradeoff | Decision |
|---|---|---|
| Reflection: `newInstanceWithoutConstructor()` + inject `user`/`articulo`/`request`/`core_log` + invoke private `nuevoArticulo()` via reflection | Drives the REAL method + real dispatch; no legacy boot; precedented in-plugin (`ExcelAccessControlBootstrapRegressionTest.php:105-112`) and fully blueprinted (`VentasClientesDispatchTest` setUp: `core_log`/`request`/`db` wiring, `CsrfManager::generateToken()`) | **Adopt** |
| HTTP-level full round-trip | Needs Kernel/DB/session boot — the exact "legacy request/session stack" barrier `VentasArticuloFilterPlacementTest` documented | Reject |
| Model-spy injection only | `$art = new \articulo()` is hardcoded at `:468`; the local model cannot be injected | Reject (used only for the `$this->articulo->get()` duplicate-check stub) |

Harness specifics: `$controller->articulo` = anonymous `\articulo` subclass with empty constructor + `get()` → false (AGENTS.md pattern). `$controller->request` = `Request::create('/', 'POST', […fields…, CsrfManager::FIELD_NAME => CsrfManager::generateToken()])`. `$controller->core_log` = `new \fs_core_log('VentasArticulos')` (reflection-set, protected `Controller.php:97`); `$controller->className` set for safety. `fs_core_log` statics reset in setUp/tearDown (VentasClientesDispatchTest `resetCoreLog()`). Real listeners on `FSEventDispatcher` (reset per test, `ArticlePermissionFilterDispatchTest` pattern). **Engine stub**: allow-path tests reach `new \articulo()` → `fs_model::__construct` → `check_table()` → `$this->db->table_exists()` (`fs_model.php:287`) and `save()` → `exec()`. Stub `fs_db2::$engine` (private static, `fs_db2.php:39`) via reflection with a benign fake (`table_exists` → true, `exec` → true, `select` → [], `var2str`/`escape_string`/`select_limit`) so the allow path completes DB-free. Deny-path tests never construct the model (return before `:468`) → no engine dependency. Root-suite safety: `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]` (VentasClientesDispatchTest precedent) so the engine stub and loaded `\articulo` don't leak into the shared root process.

**"Save never called" assertion (behavioral)**: deny tests assert the message stack contains **exactly** the denial message (with `getDenialReason()`) and **neither** the success message `'Artículo X guardado correctamente.'` (`:481`) **nor** the save-failure `'¡Imposible guardar el artículo!'` (`:483`) — any regression that reaches `save()` produces one of the two; either breaks the assertion. Optional strengthening: the engine stub records `exec()` calls; assert zero exec during a deny drive. Admin path asserts zero dispatch via a recording listener count (event never constructed/dispatched), not merely "no deny".

### D4 — Cross-plugin composition (root suite)
| Option | Tradeoff | Decision |
|---|---|---|
| Direct registration: test requires `ArticlePermissionListener` + registers it + injects model stubs | Deterministic in both suites; precedent `ArticlePermissionListenerImportContextTest`; guard `file_exists(plugins/tarifario/...)` → `markTestSkipped` when tarifario absent (host-only deployment) | **Adopt** |
| Rely on Init.php auto-registration in the root run | `tests/bootstrap.php` never executes plugin `Init.php` (verified — no Init in bootstrap); production init wiring is tarifario's own `PermissionListenerInitRegistrationTest` concern; non-deterministic | Reject |

Caveat (deviation from real init): the test proves controller-dispatch ↔ real-listener resolution composition, not the production init wiring — that stays tarifario's suite. The spec tags this scenario `root-suite`; because catalogo_core's host phpunit.xml auto-discovers the file too, the guarded test also runs (and passes) in the host suite — deterministic in both.

### D5 — Deny UX
Denial surfaces via `new_error_msg` → `core_log` → `header.html.twig:258-260` on the re-rendered list (standard POST flow; modal closed on reload — no JS). Whole create blocked: single `save()` at `:480`; deny returns at the new block before `new \articulo()` (`:468`). No partial state (flow section above).

### D6 — PR split + budget
| Estimate | Lines |
|---|---|
| Controller `nuevoArticulo()` insertion | ~25-30 |
| `VentasArticulosQuickCreateGateTest.php` (harness boilerplate ~80 + ~14 scenario methods + helpers) | ~180-220 |
| **Total** | **~205-250** — single PR, well under the 400-line budget; test file dominates (repo norm) |

No chaining recommended. Forecast guard: `Decision needed before apply: No` · `Chained PRs recommended: No` · `400-line budget risk: Low`.

### D7 — Archive note (for tasks)
The delta spec is English; the archive phase translates it into the Spanish canonical at `plugins/catalogo_core/openspec/specs/articulos-quick-create-permission/spec.md` (established ruling: canonical specs are Spanish). `tasks.md` must record this so sdd-archive performs the translation during sync.

## File Changes

| File | Action | Description |
|---|---|---|
| `plugins/catalogo_core/Controller/VentasArticulos.php` | Modify | Insert admin-guard + dispatch block in `nuevoArticulo()` between `:466` and `:468` (event construct, try/catch `\Throwable` → generic deny, deny branch → `new_error_msg` + `return`). ~25-30 lines. |
| `plugins/catalogo_core/tests/VentasArticulosQuickCreateGateTest.php` | Create | Behavioral suite: reflection harness, engine stub, recording/denying/throwing listeners, real tarifario-listener composition test. ~180-220 lines. |
| Event / gate / listener / Twig / config | Untouched | Frozen contract; Excel gate stays orthogonal (R-QCRT-007). No new services (a static gate method is not warranted for a single call site — the in-controller block mirrors `editarArticulo`). |

## Interfaces / Contracts

Consumed, never modified: `ArticlePermissionFilterEvent::NAME` = `catalogo_core.article_permission_filter`, `ACTION_EDIT_ARTICLE` = `edit_article`, `deny(reason)`/`isAllowed()`/`getDenialReason()`. No new constants, tables, grants.

```php
// Insertion at VentasArticulos.php:467 (after duplicate check, before model construction)
if (!$this->user->admin) {
    $filterEvent = new ArticlePermissionFilterEvent(
        $referencia,
        ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
        $this->user->nick
    );
    try {
        FSEventDispatcher::getInstance()->dispatch($filterEvent, ArticlePermissionFilterEvent::NAME);
    } catch (\Throwable) {
        // Fail-closed (R-QCRT-004). NO error_log — host suite processIsolation=true.
        $filterEvent->deny('permission filter error');
    }
    if (!$filterEvent->isAllowed()) {
        $this->new_error_msg('No tienes permisos para crear este artículo: ' . $filterEvent->getDenialReason());
        return;
    }
}
```

## Testing Strategy (STRICT TDD — RED first, behavioral only)

Harness: reflection controller + real `FSEventDispatcher` + real listeners + engine stub. **No strpos/line pins on the controller** (REL-2 lesson, archived verify-report: structural pins hid CRITICAL-2 fatals).

| RED case (component) | Assertion approach | Suite |
|---|---|---|
| Deny path executed — save never called (R-QCRT-003, spec scenario 1) | Denying listener; message stack = exactly the denial msg (reason embedded); no success/save-failure msgs; optional zero `exec()` on engine stub | host |
| `npvp` defaults 0.0 → still denied (R-QCRT-003, scenario 2) | Deny w/o `npvp` field; whole create blocked (same invariant) | host |
| Gestor allowed → created with pvp (R-QCRT-003, scenario 3) | Allowing listener (or real tarifario listener w/ gestor stubs); success msg `'Artículo X guardado correctamente.'` present; recorded event `isAllowed()` true | host |
| Non-admin dispatch fires once before mutation/save (R-QCRT-001) | Recording listener count == 1; event carries referencia/nick/ACTION_EDIT_ARTICLE | host |
| Admin zero dispatch (R-QCRT-002) | Admin mock user; recording listener count == 0; article created (success msg) | host |
| Throwing listener → fail-closed, no `error_log` (R-QCRT-004) | Custom throwing listener; generic `permission filter error` in denial msg; error_log redirected via `ini_set('error_log', tmpfile)` (CsrfStorageNoErrorLogTest pattern) — assert log file empty of listener lines | host |
| Zero listeners → default allow (R-QCRT-005) | No listeners; success msg present | host |
| CSRF invalid → blocks before dispatch/create (R-QCRT-006) | Invalid token; error_log redirected (validateFormToken logs "CSRF: Invalid token" — would otherwise fail host suite, probe-verified); assert CSRF msg + recording listener count == 0 | host |
| Cross-plugin composition (behavioral-discipline scenario 4) | Real tarifario `ArticlePermissionListener` + model stubs (ImportContextTest pattern); gestor → success msg, unassigned editor → denial msg; `file_exists` guard → skip if tarifario absent | root (+host, deterministic) |
| No structural pins (behavioral-discipline scenario 3) | Enforced by construction — audit the test file in review; no source-string assertions | both |
| Excel gate + article page untouched (R-QCRT-007, scenario 1) | Existing excel/article-page suites as regression signal (unchanged) | host |
| No new constants/tables/grants/UI (R-QCRT-007, scenario 2) | Diff audit at **verify** phase (a runtime test cannot audit a diff; an in-test audit would be a structural pin). Frozen constant value assertion is the only non-structural proxy: `ACTION_EDIT_ARTICLE === 'edit_article'` | verify |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.

## Migration / Rollout

No migration, no schema, no config changes. Rollback = revert the controller diff + test file.

## Out of Scope

List-page EDIT of existing articles' `pvp` (future inline-edit) stays deferred; no UI/JS/template changes beyond the denial message; no tarifario changes; other plugins' `articulo` creation paths (tpvmod, facturacion_base) out of catalogo_core ownership.

## Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Editor behavior change (loses quick-create-with-price) | Certain | Intended (RISK-2); clear denial message; gestor/admin path documented in proposal |
| Excel-gate orthogonality drift | Low | Dispatch lives only in `nuevoArticulo`; Excel gate untouched; R-QCRT-007 audit at verify |
| Composition test's real-listener dependency | Medium | Direct registration + stubs (deterministic); `file_exists` skip; deviation-from-init caveat documented (D4) |
| `error_log` in tested path breaks host suite | Low | No `error_log` in new code (R-QCRT-004); CSRF scenario redirects `error_log` via `ini_set` (probe-verified failure mode) |
| New bypass surface | Low | Other create paths already gated (article page `VentasArticulo.php:208-224`, Excel `process_excel_wizard_dispatch.php:361`); verified |
| Engine stub leaks into root suite | Low | `RunTestsInSeparateProcesses` + `PreserveGlobalState(false)` (D3) |