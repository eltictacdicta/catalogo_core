# Verify report: catalogo-articulos-excel

**Date:** 2026-08-08  
**Verdict:** PASS

## Automated tests

```
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter ArticuloExcel
OK (11 tests, 22 assertions)
```

## Checklist

- [x] Import wizard 3 pasos (upload, map, SSE apply)
- [x] Export filtrado, completo y plantilla
- [x] Permiso `can_import_export`
- [x] Campos referencia, descripcion, pvp + opcionales
- [x] SDD en `plugins/catalogo_core/openspec/`
- [x] Sin cambios en core

## Manual smoke (pendiente operador)

- [ ] Subir Excel en `ventas_articulos` y completar wizard
- [ ] Descargar export filtrado con filtros activos
- [ ] Verificar artículo creado/actualizado en BD
