# catalogo_core

Plugin núcleo de catálogo para FSFramework. Concentra los modelos base compartidos por otros plugins funcionales del ecosistema.

## Modelos incluidos

| Modelo | Namespace | Tabla | Descripción |
|--------|-----------|-------|-------------|
| `articulo` | `FacturaScripts\model` | `articulos` | Artículos del catálogo |
| `familia` | `FacturaScripts\model` | `familias` | Familias de artículos (jerárquicas) |
| `fabricante` | `FacturaScripts\model` | `fabricantes` | Fabricantes de artículos |
| `impuesto` | `FacturaScripts\model` | `impuestos` | Impuestos (IVA, etc.) |
| `divisa` | global | `divisas` | Divisas/monedas |
| `almacen` | global | `almacenes` | Almacenes físicos |
| `pais` | global | `paises` | Países |

## Compatibilidad moderna y legacy

- Los modelos legacy siguen disponibles con sus nombres históricos: `almacen`, `divisa`, `pais`.
- El plugin expone wrappers modernos PSR-4 en `FacturaScripts\Plugins\catalogo_core\Model\*`.
- Los controladores modernos viven en `Controller/` y renderizan Twig nativo desde `View/`.
- Los wrappers legacy siguen en `controller/` con los nombres `admin_almacenes` y `admin_divisas` para no romper plugins antiguos como `facturacion_base`.

## Controladores

- `AdminDivisas` — Gestión de divisas (`index.php?page=admin_divisas`)
- `AdminAlmacenes` — Gestión de almacenes (`index.php?page=admin_almacenes`)

## Extras

- `fbase_controller.php` — Controlador base para herencia legacy (proporciona `$allow_delete`, `$multi_almacen`, `fbase_paginas()`)
- `fs_divisa_tools.php` — Herramientas de formateo de precios y conversión de divisas

## Dependencias

Este plugin **no depende** de ningún otro plugin. Es la base sobre la que se construyen:

- `facturacion_base` (requiere `catalogo_core`)
- `business_data` (requiere `catalogo_core`)

## Uso

Los plugins que necesiten heredar de `articulo`, `familia` o usar divisas/almacenes deben declarar dependencia de `catalogo_core` en su `fsframework.ini`:

```ini
require = "catalogo_core"
```

Y hacer `require_once` a los modelos necesarios:

```php
require_once 'plugins/catalogo_core/model/core/articulo.php';

class mi_articulo extends \FacturaScripts\model\articulo
{
    // ...
}
```
