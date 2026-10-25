# Verify Report: migrar-spec-tarifa-coddivisa

**Date:** 2026-10-25
**Status:** PASS

Cambio documental: mueve la especificación de la divisa de tarifa al store del
plugin que ya es dueño del código. No toca comportamiento.

## Comprobaciones

| # | Comprobación | Resultado |
|---|---|---|
| 1 | Requirements movidos | **11** requirements y **17** escenarios |
| 2 | Cuerpos byte-idénticos al origen | `diff` del bloque `### Requirement:` → **vacío** |
| 3 | Marcadores de delta en el destino | 0 (`## Added Requirements`, `## MODIFIED Requirements`) |
| 4 | Requirements en el archivo de `tarifario` | **0** — solo el puntero |
| 5 | Archivos de código modificados | **ninguno** (`git status` en ambos repos: solo `.md`) |
| 6 | Ids de requirement alterados | ninguno; `R-TAR-CUR-001…013` intactos |
| 7 | Referencias canónicas al path viejo | 2 corregidas en `tarifario/openspec/specs/tarifario/catalogo-integration/spec.md` |

## Detalle de la evidencia

Extracción y comparación:

```
diff <(awk '/^### Requirement:/{f=1} f' <origen>) \
     <(awk '/^### Requirement:/{f=1} f' <destino>)
→ sin salida (byte-idénticos)
```

Estado de los repos tras el cambio:

```
catalogo_core: ?? openspec/changes/migrar-spec-tarifa-coddivisa/
               ?? openspec/specs/tarif-tarifa-divisa/
tarifario:     M  openspec/specs/tarifario/tarifa/spec.md
                  M openspec/specs/tarifario/catalogo-integration/spec.md
```

Ningún `.php`, `.twig` ni `.xml` aparece en el diff: el comportamiento es
exactamente el mismo, incluido el whitelist cerrado que exige
`R-TAR-CUR-003`/`R-TAR-CUR-005`.

## Referencias históricas preservadas

Quedan menciones al path viejo dentro de `openspec/changes/archive/**` de
`tarifario` (por ejemplo el archive de `tarifario-tarifa-coddivisa` y el de
`tarifario-catalogo-hook-integration`). **No se reescribieron a propósito**: un
archive es una foto del estado en su fecha, no documentación viva. El puntero
que dejamos en el path viejo cubre a quien llegue desde ahí.

## Verificación proporcional

Cambio pasivo de documentación: la comprobación estructural (conteos, `diff`
byte a byte, ausencia de cambios de código, integridad de referencias) es la
verificación completa. No se ejecutó la suite porque ningún artefacto ejecutable
cambió; correrla no aportaría evidencia sobre este cambio.

## Riesgos residuales

- Referencias externas a este repositorio que apunten al path viejo: el puntero
  las resuelve en lectura, no mediante herramienta.
