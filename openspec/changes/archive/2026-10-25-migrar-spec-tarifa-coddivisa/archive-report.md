```yaml
schema: gentle-ai.archive-result/v1
change: migrar-spec-tarifa-coddivisa
sdd_home: plugins/catalogo_core/openspec/
ownership: plugin-local
archived_at: 2026-10-25
archive_path: plugins/catalogo_core/openspec/changes/archive/2026-10-25-migrar-spec-tarifa-coddivisa/
verify_verdict: pass
critical_findings: 0
archive_status: complete
specs_created: 1   # tarif-tarifa-divisa (11 requirements moved)
code_changes: 0
cross_repo: plugins/tarifario (pointer + 2 reference fixes)
```

## Archive Report

**Change**: `migrar-spec-tarifa-coddivisa`
**SDD home**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
**Archived to**: `plugins/catalogo_core/openspec/changes/archive/2026-10-25-migrar-spec-tarifa-coddivisa/`
**Archive status**: **COMPLETE** — 0 CRITICAL. Sin cambios de código.

### Qué se arregló

La migración `migrar-tarif-tarifas-a-catalogo-core` (2026-09-13) movió el modelo
`tarif_tarifa` y el controlador `tarif_tarifas` a `catalogo_core` **sin mover su
especificación**; su propio verify-report lo registró como `WARNING-2`. El
resultado era que las requirements que gobiernan ese código (`R-TAR-CUR-001` …
`R-TAR-CUR-013`, incluido el whitelist cerrado `EUR|MXN|USD`) vivían en el store
de `tarifario`, un plugin que ya no es dueño del modelo.

Cualquier cambio en la divisa de tarifa obligaba a editar el store equivocado o a
contradecir una spec sin tocarla. El caso concreto que lo destapó fue la idea de
ampliar el whitelist: parecía deuda técnica y en realidad era una decisión
especificada.

### Resultado

| Artefacto | Estado |
|---|---|
| `catalogo_core/openspec/specs/tarif-tarifa-divisa/spec.md` | CREADA: 11 requirements, 17 escenarios, byte-idénticos al origen |
| `tarifario/openspec/specs/tarifario/tarifa/spec.md` | Reemplazada por puntero; **0 requirements** |
| `tarifario/openspec/specs/tarifario/catalogo-integration/spec.md` | 2 referencias al path viejo corregidas al nuevo hogar |
| Código (`.php`, `.twig`, `.xml`) | **sin cambios** |

Los ids `R-TAR-CUR-*` se preservaron para que las referencias existentes sigan
resolviendo.

### Evidencia

- `diff` del bloque de requirements entre origen y destino → **vacío**.
- `git status` en ambos repos: solo archivos `.md`.
- El whitelist cerrado sigue vigente tal como manda `R-TAR-CUR-003`/`005`.

### Lo que habilita

La decisión pendiente —reemplazar el whitelist fijo por validación contra el
catálogo `divisas`, o dejarlo como está— ahora se puede tomar **en el store
correcto**, con la spec delante. Argumento técnico recogido para ese change
futuro: `simbolo_divisa()` consulta primero el catálogo `divisas` y cae a `'€'`
para códigos desconocidos, así que aceptar cualquier ISO de tres letras
renderizaría el símbolo equivocado en silencio; la validación debe ser contra el
catálogo, no contra un formato.

### Mecánica de archivo

Snapshot `sha256sum` de los 4 archivos antes del `mv`, comparado con el destino:
**idéntico**.
