# Tasks: migrar-spec-tarifa-coddivisa

- [x] **T1.1** Extraer los 11 requirements de
      `plugins/tarifario/openspec/specs/tarifario/tarifa/spec.md` preservando
      bytes.
- [x] **T1.2** Instalar
      `plugins/catalogo_core/openspec/specs/tarif-tarifa-divisa/spec.md` con
      cabecera canónica (`# Capability:`, `## Requirements`) y nota de origen.
- [x] **T1.3** Verificar que el bloque de requirements del destino es
      byte-idéntico al de origen (`diff` vacío).
- [x] **T2.1** Reemplazar el archivo de `tarifario` por un puntero al nuevo hogar
      canónico, sin requirements duplicados.
- [x] **T2.2** Confirmar que no quedan otras referencias canónicas a
      `R-TAR-CUR-*` fuera del nuevo hogar.
- [x] **T3.1** Copiar el artifact al change (`specs/tarif-tarifa-divisa/spec.md`)
      para que el archive sea autocontenido; `diff` contra el canónico vacío.
- [x] **T3.2** Verificar que no se modificó ningún archivo de código.

## Definition of done

1. Un solo hogar canónico para `R-TAR-CUR-*`: `catalogo_core`.
2. El archivo de `tarifario` no contiene requirements, solo el puntero.
3. Cero cambios de comportamiento: ningún `.php` ni `.twig` tocado.
4. Los ids de requirement no cambian.
