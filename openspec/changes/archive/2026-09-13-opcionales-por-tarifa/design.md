# Design: Opcionales organized by tarifa

## Technical Approach

Mirror `tarif_tarifa_familia`: add a master `tarif_tarifa_opcional(codtarifa,id_opcional,en_catalogo,en_tarifa,activa,orden)` as the authoritative per-`(tarifa, opcional)` state. The master model owns precedence resolution; controllers, views and the tarifario export consume it. Lifecycle: default-tarifa install seed, non-persisting lazy inherit, `copy_from_tarifa()` from `heredar_estructura()`. Migration is additive; `tarif_opcional_ext`, `catalogo_opcionales` and `tpvmod` stay untouched. Spec: `opcionales-tarifa-management`.

## Architecture Decisions

| # | Decision | Choice | Alternatives | Rationale |
|---|---|---|---|---|
| AD1 | Where resolution lives | Inside `tarif_tarifa_opcional` (`effective()`), via a `ext_defaults()` test seam | New DI resolver service; extend `tarif_tarifa_opcional_resolver` | Mirrors the self-contained `tarif_tarifa_familia`; the existing resolver is per-article/tag (wrong scope); controllers already instantiate models directly |
| AD2 | Activation authority | Master `activa` only; price-row existence never activates | Keep price-row as activation (status quo) | Confirmed decision 1; removes the list/export divergence |
| AD3 | Catalog / export flag | Master `en_catalogo` wins when a row exists; else `tarif_opcional_ext.en_catalogo`; price-level `en_catalogo` stays per-lista inclusion | Collapse the three flags | Confirmed decision 3; avoids data loss and keeps pricing UI semantics |
| AD4 | Order | Master `orden` = default; family/article `orden` = scoped override (existing resolvers unchanged) | Move all ordering to master | Confirmed decision 4; preserves existing overrides |
| AD5 | Missing master row | Resolve to ext/default values, never persist; the first explicit toggle creates the row | Backfill on read | Confirmed decisions 2/5/7; additive, non-destructive |
| AD6 | Bootstrap | New master ensured by a dedicated FK-safe step after the existing 5-table `foreach` | Append to the existing `foreach` literal | Keeps `InitOpcionalesTablesTest`'s pinned `FK_SAFE_SEQUENCE` literal green |

Lazy-inherit defaults (AD5): `activa=TRUE`, `en_catalogo=en_tarifa` from `tarif_opcional_ext`, `orden=0` — same missing-row-⇒-inherited-active convention as `tarif_tarifa_opcional_familia::is_activo_en_tarifa()`. Consequence: `solo_activos` filters only opcionales explicitly set `activa=FALSE`.

## Data Flow

    CLI/install ──seed_default_tarifa()──► tarif_tarifa_opcional
    heredar_estructura ──copy_from_tarifa(origen,destino)──┘
    view/controller ──effective()──► [master row? ─yes─► row
                                              └─no──► ext_defaults (no persist)]
    export (tarifario) ──effective()──► en_catalogo/en_tarifa/activa

## File Changes

| File | Action | Description |
|---|---|---|
| `plugins/catalogo_core/model/table/tarif_tarifa_opcional.xml` | Create | PK `(codtarifa,id_opcional)`; FK CASCADE → `tarif_tarifas`,`catalogo_opcionales`; defaults TRUE/FALSE/TRUE/0 |
| `plugins/catalogo_core/model/tarif_tarifa_opcional.php` | Create | `install()`+seed, CRUD, selectors, toggles, `effective()` |
| `plugins/catalogo_core/Init.php` | Modify | New FK-safe ensure step after the 5-table loop |
| `plugins/catalogo_core/model/tarif_opcional.php` | Modify | `search`/`count_filtered` `solo_activos` predicate → master `activa`; signatures unchanged |
| `plugins/catalogo_core/controller/tarif_opcionales.php` | Modify | Master cache; per-tarifa `activa`; `toggle_*` endpoints |
| `plugins/catalogo_core/controller/tarif_opcional_edit.php` | Modify | Load/write master in matrix; read master accessors |
| `plugins/catalogo_core/controller/tarif_opcional_precios.php` | Modify | `esta_activo_en_tarifa()`/`esta_en_catalogo()` read master; save master |
| `View/tarif_opcionales.html.twig`, `tarif_opcional_edit.html.twig`, `tarif_opcional_precios.html.twig` | Modify | "Estado" = per-tarifa; reuse `toggle_button_group` |
| `plugins/tarifario/controller/tarif_catalogo_view.php` | Modify (hybrid) | Export reads `effective()` |
| `plugins/tarifario/controller/tarif_tarifas.php` | Modify (hybrid) | Step 11 master copy in `heredar_estructura()` |

## Interfaces / Contracts

```php
namespace FSFramework\model;
class tarif_tarifa_opcional extends \fs_model {
    public $codtarifa, $id_opcional, $en_catalogo=true, $en_tarifa=false, $activa=true, $orden=0;
    protected function install(): string;              // new tarif_tarifa(); new catalogo_opcional(); return $this->seed_default_tarifa();
    protected function ext_defaults($id_opcional): array; // seam: ['en_catalogo'=>bool,'en_tarifa'=>bool]
    public function get($codtarifa, $id_opcional); public function exists(); public function save(); public function delete();
    public function all_from_tarifa($codtarifa): array; public function count_from_tarifa($codtarifa): int;
    public function copy_from_tarifa($origen, $destino): bool;
    public function set_activa($codtarifa,$id,$v): bool; public function set_en_catalogo(...): bool;
    public function set_en_tarifa(...): bool; public function set_orden(...): bool;
    // master row ? row values : ext/defaults, never persisted
    public function effective($codtarifa, $id_opcional): array; // {activa,en_catalogo,en_tarifa,orden,source:'master'|'inherit'}
    public function resolve_activa($codtarifa,$id): bool; public function resolve_en_catalogo($codtarifa,$id): bool; public function resolve_orden($codtarifa,$id): int;
}
```

Seed SQL (mirrors `migrate_existing_familias()`): `INSERT INTO tarif_tarifa_opcional (codtarifa,id_opcional,en_catalogo,en_tarifa,activa,orden) SELECT <default>, o.id, COALESCE(e.en_catalogo,TRUE), COALESCE(e.en_tarifa,FALSE), TRUE, 0 FROM catalogo_opcionales o LEFT JOIN tarif_opcional_ext e ON o.id=e.id_opcional WHERE NOT EXISTS (...)`. `copy_from_tarifa`: DELETE destination, then INSERT…SELECT all six columns. Export: `$s = $master->effective($this->codtarifa, $opc->id);` → `en_tarifa/en_catalogo/activa` from `$s`; skip when `$s['activa'] === false`.

## Testing Strategy

`ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (strict TDD, RED first).

| Layer | What | File |
|---|---|---|
| Unit | CRUD/save/delete, spy-DB SQL shape | `tests/TarifTarifaOpcionalTest.php` |
| Unit | Precedence: master wins; missing inherits; price never activates; orden | `tests/TarifTarifaOpcionalPrecedenceTest.php` |
| Unit | Seed SQL, `copy_from_tarifa`, toggle create/update | `tests/TarifTarifaOpcionalLifecycleTest.php` |
| Contract | Bootstrap ensures master after the 5, XML exists | `tests/InitTarifTarifaOpcionalBootstrapTest.php` |
| Contract/runtime | Controllers resolve/list via master | `tests/TarifOpcionalesControllerMasterStateTest.php` |
| Hybrid contract | Export reads master; `heredar_estructura` copies | `plugins/tarifario/tests/Controller/TarifCatalogoOpcionalMasterExportTest.php`, `.../TarifTarifasHeredarOpcionalMasterTest.php` |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary is introduced.

## Migration / Rollout

Additive. `fs_model` creates the table on bootstrap/upgrade; existing DBs stay valid via lazy inherit (no mandatory backfill). Rollback: `git revert` drops model/XML and restores prior reads; `Init` stops bootstrapping it; clear Twig cache. `tarif_opcional_ext`/`catalogo_opcionales` data is never mutated.

## Open Questions

- [ ] `copy_precios_opcionales()` drops `en_catalogo`/`porcentaje` on tarifa copy (pre-existing): align with the master copy or leave as-is?
