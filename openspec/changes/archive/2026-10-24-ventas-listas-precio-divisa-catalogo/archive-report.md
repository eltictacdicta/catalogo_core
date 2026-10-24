```yaml
schema: gentle-ai.archive-result/v1
change: ventas-listas-precio-divisa-catalogo
sdd_home: plugins/catalogo_core/openspec/
ownership: plugin-local
archived_at: 2026-10-24
archive_path: plugins/catalogo_core/openspec/changes/archive/2026-10-24-ventas-listas-precio-divisa-catalogo/
verify_verdict: pass
critical_findings: 0
archive_status: complete
specs_merged: 1   # multi-tariff-pricing: R-MT-004 MODIFIED
in_repo_tasks: 11/11
note: >
  Registro retroactivo. La implementación es anterior al SDD: el código estaba
  escrito y probado pero sin commitear ni documentar.
```

## Archive Report

**Change**: `ventas-listas-precio-divisa-catalogo`
**SDD home**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
**Archived to**: `plugins/catalogo_core/openspec/changes/archive/2026-10-24-ventas-listas-precio-divisa-catalogo/`
**Archive status**: **COMPLETE** — 0 CRITICAL.

### Resumen

La divisa de una lista de precio dejó de ser un input de texto libre de tres
caracteres y pasó a elegirse entre las divisas configuradas en `admin_divisas`.
El controlador carga el catálogo, resuelve el default desde la divisa de
sesión/empresa (con respaldo `EUR` y luego la primera configurada), valida el POST
contra lo configurado y conserva la divisa histórica de una lista existente para
no bloquear ediciones no relacionadas. Sin catálogo, degrada al chequeo de
formato del modelo.

### Naturaleza del registro

Este change se documenta **después** de implementado. La sesión que lo escribió
dejó el código sin commitear y sin artefactos SDD. Al retomarlo se verificó contra
la suite y se escribió el registro completo; no se agregó alcance nuevo ni se
maquillaron tareas.

### Spec sincronizada

`openspec/specs/multi-tariff-pricing/spec.md` — requirement `R-MT-004`
(`Admin CRUD UI for price lists`) reemplazado por la versión del delta: conserva
la introducción original, agrega el párrafo de la regla de divisas y cuatro
escenarios nuevos.

Además se normalizó ese canónico, que seguía siendo el delta copiado tal cual:

| Antes | Después |
|---|---|
| `# Delta for multi-tariff-pricing` | `# Capability: multi-tariff-pricing` |
| `## ADDED Requirements` | `## Requirements` |

`diff` confirma que solo cambiaron esas dos cabeceras: 5 requirements y 14
escenarios intactos.

### Evidencia

```
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
Tests: 1378, Assertions: 5673, Warnings: 2, Skipped: 1   (preexistentes)

--filter VentasListaPrecio
OK (16 tests, 37 assertions)
```

### Limitaciones

- El perfil `phpstan-plugins.neon` indexa `catalogo_core` solo en
  `scanDirectories`: este controlador no pasa por análisis estático.
- Sin smoke en navegador.

### Deuda registrada (no incluida)

`controller/tarif_tarifas.php` y `model/tarif_tarifa.php` mantienen el whitelist
hardcodeado `ALLOWED_CURRENCIES = ['EUR', 'MXN', 'USD']` para la misma clase de
dato. La columna `tarifas.coddivisa` es `varchar(3)` sin constraint, así que
ampliarlo es viable, pero toca modelo, controlador y vista de tarifas: merece su
propio change.

### Mecánica de archivo

Snapshot `sha256sum` de los 4 archivos antes del `mv`, comparado con el destino:
**idéntico**.
