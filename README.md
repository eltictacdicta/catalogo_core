# catalogo_core

Plugin núcleo de catálogo para FSFramework. Concentra los modelos base del catálogo (7 core + 12 adyacentes) compartidos por otros plugins funcionales del ecosistema.

## Modelos incluidos

### 7 Core models

| Modelo | Namespace | Tabla | Descripción |
|--------|-----------|-------|-------------|
| `articulo` | `FSFramework\model` | `articulos` | Artículos del catálogo |
| `familia` | `FSFramework\model` | `familias` | Familias de artículos (jerárquicas) |
| `fabricante` | `FSFramework\model` | `fabricantes` | Fabricantes de artículos |
| `impuesto` | `FSFramework\model` | `impuestos` | Impuestos (IVA, etc.) |
| `divisa` | global | `divisas` | Divisas/monedas |
| `almacen` | global | `almacenes` | Almacenes físicos |
| `pais` | global | `paises` | Países |

### 12 Adjacent models

| Modelo | Namespace | Tabla | Descripción |
|--------|-----------|-------|-------------|
| `articulo_combinacion` | `FSFramework\model` | `articulo_combinaciones` | Combinaciones atributo-valor por artículo |
| `articulo_propiedad` | `FSFramework\model` | `articulo_propiedades` | Propiedades extra de artículos |
| `articulo_proveedor` | `FSFramework\model` | `articulosprov` | Artículos de proveedores |
| `articulo_traza` | `FSFramework\model` | `articulo_trazas` | Trazabilidad de artículos |
| `atributo` | `FSFramework\model` | `atributos` | Atributos (talla, color, etc.) |
| `atributo_valor` | `FSFramework\model` | `atributos_valores` | Valores de atributos |
| `stock` | `FSFramework\model` | `stocks` | Stock por artículo y almacén |
| `recalcular_stock` | `FSFramework\model` | `stocks` (helper) | Recálculo de stock |
| `regularizacion_stock` | `FSFramework\model` | `lineasregstocks` | Regularizaciones de stock |
| `transferencia_stock` | `FSFramework\model` | `transstock` | Transferencias entre almacenes |
| `linea_transferencia_stock` | `FSFramework\model` | `lineastransstock` | Líneas de transferencia de stock |
| `tarifa` | `FSFramework\model` | `tarifas` | Tarifas de precios |

Todos los 19 modelos residen en `plugins/catalogo_core/model/core/` con sus esquemas XML en `plugins/catalogo_core/model/table/`.

## Carga de modelos

Los modelos se cargan automáticamente mediante el `fs_model_autoloader` del framework, que respeta el orden de dependencias entre plugins y permite overrides. No se requiere configuración adicional en `Init.php`.

Para más detalles sobre el mecanismo de autoloading, consulta la documentación del framework en `base/fs_model_autoloader.php`.

## Compatibilidad de nombres

El framework crea automáticamente alias globales para los modelos con namespace:

- **Modelos con namespace** (`FSFramework\model\*`): `articulo`, `familia`, `fabricante`, `impuesto` pueden instanciarse tanto con el namespace completo como con el nombre global.
- **Modelos globales**: `almacen`, `divisa`, `pais` siempre fueron clases globales y continúan siéndolo.
- **Modelos adyacentes**: Los 12 modelos adyacentes solo existen con namespace (`FSFramework\model\*`) y requieren `use` statements o el FQCN completo.

El mecanismo de override del framework permite que plugins dependientes sobrescriban modelos de sus dependencias definiendo clases con el mismo nombre.

## Wrappers PSR-4 (Model/)

Los 7 core models tienen wrappers PascalCase en `plugins/catalogo_core/Model/`:

`Almacen.php`, `Articulo.php`, `Divisa.php`, `Fabricante.php`, `Familia.php`, `Impuesto.php`, `Pais.php`

## Compatibilidad moderna y legacy

- Los modelos legacy siguen disponibles con sus nombres históricos: `almacen`, `divisa`, `pais`.
- El plugin expone wrappers modernos PSR-4 en `FSFramework\Plugins\catalogo_core\Model\*`.
- Los controladores modernos viven en `Controller/` y renderizan Twig nativo desde `View/`.
- Los wrappers legacy siguen en `controller/` con los nombres históricos para no romper plugins antiguos como `facturacion_base`.

## Controladores

### Modernos (PSR-4 en `Controller/`)

| Controller | Página | Descripción |
|------------|--------|-------------|
| `AdminDivisas` | `admin_divisas` | Gestión de divisas |
| `AdminAlmacenes` | `admin_almacenes` | Gestión de almacenes |
| `AdminPaises` | `admin_paises` | Gestión de países |
| `VentasArticulos` | `ventas_articulos` | Listado de artículos |
| `VentasArticulo` | `ventas_articulo` | Detalle de artículo |
| `VentasFamilias` | `ventas_familias` | Listado de familias |
| `VentasFamilia` | `ventas_familia` | Detalle de familia |
| `VentasFabricantes` | `ventas_fabricantes` | Listado de fabricantes |
| `VentasFabricante` | `ventas_fabricante` | Detalle de fabricante |

### Wrappers legacy (en `controller/`)

Cada controlador PSR-4 tiene un wrapper legacy snake_case que preserva el routing:
`admin_divisas.php`, `admin_almacenes.php`, `admin_paises.php`, `ventas_articulos.php`, `ventas_articulo.php`, `ventas_familias.php`, `ventas_familia.php`, `ventas_fabricantes.php`, `ventas_fabricante.php`.

## Extras

- `fbase_controller.php` — Controlador base para herencia legacy (proporciona `$allow_delete`, `$multi_almacen`, `fbase_paginas()`)
- `fs_divisa_tools.php` — Herramientas de formateo de precios y conversión de divisas

## Dependencias

Este plugin **no depende** de ningún otro plugin. Es la base sobre la que se construyen:

- `facturacion_base` (requiere `catalogo_core`)
- `business_data` (requiere `catalogo_core`)

## Uso

Los plugins que necesiten heredar de los modelos de catálogo deben declarar dependencia de `catalogo_core` en su `fsframework.ini`:

```ini
require = "catalogo_core"
```

Los modelos se resuelven automáticamente vía el autoloader de `Init.php`:

```php
use FSFramework\model\articulo;

class mi_articulo extends articulo
{
    // ...
}
```

Para los 4 core models, el nombre global legacy sigue funcionando (pero está `@deprecated`):

```php
// Legacy (deprecated) — funciona pero debería migrarse
$art = new \articulo();

// Recomendado — usa el namespace
$art = new \FSFramework\model\articulo();

// Recomendado — usa el wrapper PSR-4
$art = new \FSFramework\Plugins\catalogo_core\Model\Articulo();
```
