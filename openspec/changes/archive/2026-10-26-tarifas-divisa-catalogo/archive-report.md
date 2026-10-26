```yaml
schema: gentle-ai.archive-result/v1
change: tarifas-divisa-catalogo
sdd_home: plugins/catalogo_core/openspec/
ownership: plugin-local
archived_at: 2026-10-26
archive_path: plugins/catalogo_core/openspec/changes/archive/2026-10-26-tarifas-divisa-catalogo/
verify_verdict: pass
critical_findings: 0
archive_status: complete
specs_merged: 1   # tarif-tarifa-divisa: R-TAR-CUR-001/003/005/007 MODIFIED
in_repo_tasks: 16/16
```

## Archive Report

**Change**: `tarifas-divisa-catalogo`
**SDD home**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
**Archived to**: `plugins/catalogo_core/openspec/changes/archive/2026-10-26-tarifas-divisa-catalogo/`
**Archive status**: **COMPLETE** — 0 CRITICAL.

### Resumen

Cierra la deuda que dejó registrada `ventas-listas-precio-divisa-catalogo`: la
misma clase de dato (una columna `coddivisa`) tenía dos contratos según la
página. Ahora la moneda de una tarifa también sale del catálogo de
`admin_divisas`:

- se retiró `tarif_tarifa::ALLOWED_CURRENCIES` (`EUR`, `MXN`, `USD`); el modelo
  valida solo la forma ISO `^[A-Z0-9]{3}$`;
- el controlador valida el POST contra los códigos configurados, acepta la
  moneda que la tarifa ya guarda y degrada al formato ISO si no hay ninguna
  configurada;
- el modal de tarifas arma las opciones desde `fsc.divisas` y el JS agrega la
  moneda histórica como `(no configurada)` para no bloquear la edición.

### Spec sincronizada

`openspec/specs/tarif-tarifa-divisa/spec.md` — `R-TAR-CUR-001`,
`R-TAR-CUR-003/005` y `R-TAR-CUR-007` reemplazados por la versión del delta. El
resto de los requisitos (`002`, `004`, `006`, `008`–`013`) queda intacto. El
encabezado del canónico anota el cambio con fecha y requisitos afectados.

### Evidencia

```
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
Tests: 1385, Assertions: 5687, Warnings: 2, Skipped: 1   (2 warnings y 1 skip preexistentes)

ddev exec composer phpstan:plugins
[OK] No errors

grep -rn "ALLOWED_CURRENCIES" plugins/  -> 0 hits
grep -rn "EUR, MXN o USD" plugins/     -> 0 hits
```

TDD: RED verificado antes de implementar (4 fallos en
`TarifTarifasGuardarTest`, 2 en `TarifTarifaCoddivisaFormatTest`), después GREEN.

### Limitaciones

- Sin smoke en navegador (modal admin con datos reales); validado por parse de
  Twig 3 y test estructural de la vista.
- `phpstan-plugins.neon` indexa `catalogo_core` en `scanDirectories`: sin
  errores, pero eso no prueba tipado completo del controlador.

### Deuda registrada (no incluida)

- El `help-block` del modal de artículos que menciona conversión de divisas no
  se revisó en este change.
- Versionado del plugin (`fsframework.ini`) sin tocar: no se pidió release.

### Post-archivo (2026-10-26)

Se cerró el residuo de documentación del convertidor Python: el docstring de
`plugins/tarifario/tools/tarifa_excel_to_json.py` decía `Whitelist: EUR | MXN |
USD`, que dejó de ser cierto con este change. Se reescribió para indicar que el
código debe estar configurado en `admin_divisas` y que EUR/MXN/USD son solo
ejemplos, y se ajustó `R-TAR-CUR-011` en el canónico para exigir esa redacción.
El archivo vive en el repo del plugin `tarifario`; el cambio es solo de
documentación (no altera la salida de la herramienta).

### Mecánica de archivo

`sha256sum` de los 4 archivos antes del `mv` comparado con el destino:
**idéntico**.
