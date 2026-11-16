```yaml
schema: gentle-ai.archive-result/v1
change: unificar-superficies-precio-catalogo-core
sdd_home: plugins/catalogo_core/openspec/
ownership: plugin-local
archived_at: 2026-11-16
archive_path: plugins/catalogo_core/openspec/changes/archive/2026-11-16-unificar-superficies-precio-catalogo-core/
verify_verdict: pass
critical_findings: 0
archive_status: complete
specs_merged: 3   # price-surface-ownership (nueva), catalogo-render-hooks (+2), batch-price-update (4 MODIFIED, +4)
deferred: [WU-5.2]
release: catalogo_core 1.20.0
```

## Archive Report

**Change**: `unificar-superficies-precio-catalogo-core`
**Archive status**: **COMPLETE** — 0 CRITICAL.

### Resumen

Una sola página de actualización masiva de precios, propiedad de `catalogo_core`,
con `scope × target`. El consumidor se registra (target, filtro, listener) y no
publica superficies propias; la página legacy de tarifa es un redirect y las
vistas `tarif_actualizar_precios` y `tarif_articulo` están borradas.

### Specs sincronizadas

- `openspec/specs/price-surface-ownership/spec.md` — nueva (R-PSO-001..007).
- `openspec/specs/catalogo-render-hooks/spec.md` — añadidos R-CRH-001 y R-CRH-002.
- `openspec/specs/batch-price-update/spec.md` — R-BU-001..004 reemplazados por el
  delta; añadidos R-BU-006..009; R-BU-005 sin cambios.

### Cierre

- Tareas: todas cerradas; **WU-5.2 diferida** por GATE-5b (si se retoma, change propio).
- Releases: una única `catalogo_core` 1.20.0 (1.16.0–1.19.0 no se cortaron) y
  `tarifario` 3.5.0. Core sin release (0.35.11).
- Change del consumidor: `plugins/tarifario/openspec/changes/archive/2026-11-16-aportar-target-tarifa-a-actualizar-precios/`.
- Sin entrada en el `openspec/` del core.
