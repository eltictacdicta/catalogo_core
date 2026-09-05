# Archive report: catalogo-articulos-excel

**Date:** 2026-09-05
**Verdict:** PASS (con cierre documentado)
**Archive:** `plugins/catalogo_core/openspec/changes/archive/2026-09-05-catalogo-articulos-excel/`

## Verificación histórica (verify-report 2026-08-08)

```
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter ArticuloExcel
OK (11 tests, 22 assertions)
```

Checklist en su momento:

- [x] Import wizard 3 pasos (upload, map, SSE apply)
- [x] Export filtrado, completo y plantilla
- [x] Permiso `can_import_export`
- [x] Campos referencia, descripcion, pvp + opcionales
- [x] SDD en `plugins/catalogo_core/openspec/`
- [x] Sin cambios en core

## Resumen de cierre (estado final al archivar)

### 1. Verificación histórica con scope limitado (blind-spot documentado)

El PASS histórico (2026-08-08) fue **scopado** (`--filter ArticuloExcel`, 11 tests) y
por eso no detectó una regresión transversal: el bloque de configuración del JS del
wizard introdujo 5 usos de `|json_encode|raw` en `View/ventas_articulos.html.twig`,
rompiendo el test de seguridad preexistente CPV-06 ("no `|raw` en vistas Twig").
Es la misma clase de punto ciego documentada en el repo (lección cross-plugin de la
migración 2026-06: lotes de fixes post-archive tras verifies scopados).

### 2. Regresión corregida durante el cierre (2026-09-05, sin commitear)

El bloque de config del wizard se migró a un carrier por data-attributes
(`#catalogo-articulos-excel-config`) y el consumidor
`View/js/articulos-excel-import-wizard.js` ahora lee `dataset`.
Impacto: +10/−2 JS, +7/−10 view (neto −3 líneas). No se modificaron tests ni specs.

Estado post-fix verificado en el cierre:

```
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'ArticuloExcel'
OK (15 tests, 27 assertions)
```

El test CPV-06 del lado plural (`ventas_articulos`, vista de lista) pasa.

### 3. Suite completa del plugin post-fix: 202 tests / 360 assertions / 1 failure

El failure restante (`VentasArticuloControllerTest::testTwigViewUsesAutoEscape`,
vista de edición singular) lo causa el bloque JS i18n de opcionales introducido en
**v1.1.0** (commit c6a7c86), NO este change. Queda fuera de scope aquí y se registra
como follow-up del trabajo v1.1.0.

### 4. Manual smoke pendiente de operador (3 ítems, sin cambios)

- [ ] Subir Excel en `ventas_articulos` y completar wizard
- [ ] Descargar export filtrado con filtros activos
- [ ] Verificar artículo creado/actualizado en BD

### 5. Estado de commits

La corrección de la regresión (JS + vista) y este movimiento a archive están
**sin commitear** en el repo del plugin `catalogo_core`. Los commits son
responsabilidad del operador.

## Specs

- No hay `specs/` delta en el change ni `design.md` (caso normal de este plugin).
- La spec fuente `openspec/specs/articulos-excel-import-export/spec.md` se escribió
  completa y se commiteó junto a la implementación (commit e23f712, v1.0.2,
  2026-08-14). Confirmado en el cierre que sigue describiendo lo que se entregó:
  permiso `can_import_export`, export filtrado/completo/plantilla, wizard 3 pasos
  (upload → map → SSE apply), modos `create_if_missing`/`update_only` con match key
  `referencia`, y persistencia (`pvp` sin IVA, defaults del modelo al crear, update
  solo de campos mapeados no vacíos). No hubo merge de specs.

## Anti-patrón core

Confirmado: `openspec/changes/` del core NO tiene ninguna entrada para
`catalogo-articulos-excel`. El SDD vive íntegramente en el plugin.

## Contenido archivado

- proposal.md ✅
- tasks.md ✅ (10/10 tareas completas)
- verify-report.md ✅ (histórico, PASS 2026-08-08)
- archive-report.md ✅ (este archivo, aditivo)
