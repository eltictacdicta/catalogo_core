# Design: unificar superficies de precio en catalogo_core

- Change: `unificar-superficies-precio-catalogo-core`
- SDD owner: `plugins/catalogo_core/openspec/` (plugin-local, `strict_tdd: true`)
- Artifact store: openspec
- Inputs: `proposal.md`; deltas `price-surface-ownership` (R-PSO-001..007),
  `batch-price-update` (R-BU-001..008), `catalogo-render-hooks` (R-CRH-001..002)
- Core `openspec/` entry: NONE (100 % dentro de `plugins/catalogo_core/`)
- Baselines verificados: `catalogo_core` 1593 tests / 6503 assertions;
  `tarifario` 278 tests / 1174 assertions
- Versiones de partida: `catalogo_core` 1.15.2, `tarifario` 3.4.2

---

## 1. Resumen ejecutivo

La página `catalogo_actualizar_precios` pasa a ser la única superficie de
actualización masiva y se generaliza sobre dos ejes ortogonales:

- **scope** (`articulos` | `opcionales`): vive en el motor base; selecciona
  filas, define el orden y valida los filtros del scope.
- **target** (`lista` | `tarifa`): se resuelve por registro; aporta la clave de
  la operación (`codlista` / `codtarifa`) y el **libro de precios** que lee el
  precio actual y persiste.

El motor no conoce ninguna tabla de consumidor. La matriz de almacenamiento se
codifica una sola vez, en el scope: los opcionales tienen libro canónico
independiente del target; los artículos dependen del target. La composición es
**canónico primero**, de modo que un target no puede desviar el storage de
opcionales ni aunque lo intente.

`tarifario` deja de publicar página: registra su target en `Init::init()`,
aporta el filtro de grupo de rol por el hook
`catalogo_actualizar_precios_filters_after` y convierte
`tarif_actualizar_precios` en un redirector sin menú. No se fusionan storages ni
se migran datos.

---

## 2. El problema central: la matriz de almacenamiento

Verificada en el código, no inferida:

| scope | target = `lista` | target = `tarifa` |
|---|---|---|
| `articulos` | canónico `catalogo_articulo_precios` (vía `CatalogoPriceResolver`) | legacy `tarif_articulo_precios`, clave `(referencia, codtarifa)` |
| `opcionales` | `catalogo_opcional_precios`, clave `(id_opcional, codlista)` | **la misma tabla canónica**, clave `(id_opcional, codlista = codtarifa)` |

Evidencia de la asimetría:

- `model/tarif_opcional_precio.php:35` → `class tarif_opcional_precio extends
  catalogo_opcional_precio`. El opcional tarifario **es** la fila canónica.
- `plugins/tarifario/controller/tarif_actualizar_precios.php` ya lee y escribe
  `catalogo_opcional_precios` con `codlista = codtarifa` para opcionales y
  `tarif_articulo_precios` para artículos. La página legacy ya es mixta.
- `Services/CatalogLegacyTableMigration.php` espeja **filas de lista**
  (`catalogo_listas_precio`) y **precios de opcionales**
  (`catalogo_opcional_precios`, `migrateOptionalPrices()` /
  `copyPendingOptionalPrices()`), pero **nunca** copia
  `tarif_articulo_precios` a `catalogo_articulo_precios`.

### 2.1 Divergencia de lectura (riesgo R-BU-007)

| camino | precio actual de un artículo |
|---|---|
| motor base actual | `CatalogoPriceResolver::effective($ref, $codlista)` = fila canónica ?? fallback a `articulos.pvp` si es la lista por defecto |
| página legacy | `tarif_articulo_precios.precio` directo (sin fallback) |

Si el motor base resolviera el precio con el resolver también para `tarifa`, un
artículo con fila canónica y fila de tarifa daría dos precios distintos y el
preview dejaría de ser paritario. El diseño lo cierra así: **el motor pregunta
"precio actual" al libro y nunca decide el proveedor**; el libro de `tarifa` lee
solo `tarif_articulo_precios`, el de `lista` lee solo el canónico. Con ambas
filas presentes, cada target lee la suya y la paridad se cumple por
construcción, no por coincidencia.

---

## 3. Decisiones de arquitectura

### D1 — Dos ejes con contratos separados, composición canónico-primero

**Decisión**: el motor compone el libro así:

```php
$book = $scope->canonicalBook($key) ?? $target->bookFor($scope->code(), $key);
```

- `ArticulosPriceScope::canonicalBook()` devuelve `null` → el storage depende
  del target, que **debe** aportar libro o la operación falla cerrada.
- `OpcionalesPriceScope::canonicalBook()` devuelve el libro canónico de
  opcionales → el target se ignora para ese scope.

**Alternativas consideradas**:

- *El target decide el libro de ambos scopes*: obliga a que el target
  `tarifa` conozca y repita la tabla canónica de opcionales. La paridad pasa a
  ser incidental (depende de que cada target se acuerde de mapear opcionales) y
  un target futuro podría desviarla.
- *Un único `surfaceFor(scope, target)` con mapa (scope, target) → libro*: más
  flexible, pero codifica la misma asimetría en una tabla de despacho y permite
  registrar una combinación incorrecta. El scope como dueño de la asimetría
  tiene una sola fuente de verdad y es verificable por test unitario.

**Rationale**: la asimetría es un hecho de dominio verificado (solo los
artículos tienen storage legacy). Codificarla en el scope hace que la paridad
de opcionales sea **estructural**: no existe camino por el que un target lea o
escriba otra tabla para `opcionales`.

### D2 — `PriceBookInterface`: un solo contrato de lectura y escritura

**Decisión**:

```php
interface PriceBookInterface
{
    /** @param list<string> $keys
     *  @return array<string, float> key => precio actual; clave ausente = sin precio en este libro */
    public function currentPrices(array $keys): array;

    public function persist(string $key, float $price): bool;
}
```

**Alternativas consideradas**:

- *Separar `PriceReaderInterface` y `PriceWriterInterface`*: permitiría registrar
  un lector y un escritor distintos, que es exactamente la divergencia que
  R-BU-007 prohíbe (preview leyendo un storage y apply escribiendo otro). Se
  descarta.
- *`currentPrice(string $key): ?float` por fila*: mantiene el N+1 actual y
  obliga a una consulta por fila; la legacy resuelve el conjunto con un solo
  JOIN. La lectura batch preserva el rendimiento y el conjunto.
- *Persistencia batch*: se descarta porque R-BU-005 exige la lista de
  referencias fallidas por fila; un upsert masivo pierde el resultado
  individual.

**Rationale**: la ausencia de clave en `currentPrices()` es significativa y
debe seguir siéndolo: es el equivalente al `INNER JOIN` de la página legacy
(fila sin precio en el target → no aparece en el preview). Un libro **no puede**
devolver `0.0` para una fila ausente. Por eso no se reutiliza
`ArticuloTarifaPrecioBatchReader`, que rellena defaults de display
(`precio 0.0`).

### D3 — `PriceUpdateTargetRegistry` con `resolve()`, no `dispatch()`

**Decisión**: se replica la forma de `Services/ArticuloListActionRegistry.php`
(interfaz + `private static array` + `register()` + `reset()`), pero el verbo
público es `resolve()`:

```php
final class PriceUpdateTargetRegistry
{
    public static function register(PriceUpdateTargetInterface $target): void;
    public static function reset(): void;
    public static function registerDefaults(): void;                 // idempotente: registra el target base
    public static function resolve(string $code): ?PriceUpdateTargetInterface;
    /** @return array<string, string> code => label, en orden de registro */
    public static function available(): array;
    public static function defaultCode(): string;                    // 'lista' si está disponible; si no, el primero
}
```

**Alternativas consideradas**:

- *`dispatch()` como en `ArticuloListActionRegistry`*: allí `dispatch()` ejecuta
  un handler con efectos. Aquí la operación es una búsqueda de colaborador; el
  motor lo invoca después. `resolve()` describe el contrato y evita sugerir
  ejecución.
- *`supports(string $code)` por target*: `code()` ya identifica al target; un
  predicado solo aportaría alias, que hoy no existen.
- *Registro por DI/Symfony*: el contenedor no está disponible en el arranque
  legacy de plugins y el precedente del proyecto es el registro estático.

**Rationale**: `resolve()` devuelve `null` para un código desconocido y el
controlador falla cerrado (R-BU-002). `registerDefaults()` lo invoca
`catalogo_core/Init.php` (archivo ya allowlisted) y también los tests; así el
target base no queda implícito ni escondido en el registro.

### D4 — El scope vive en el motor base, sin registro

**Decisión**: dos clases base-owned y una fábrica cerrada:

```php
interface PriceUpdateScopeInterface
{
    public function code(): string;                     // 'articulos' | 'opcionales'
    public function label(): string;                    // etiqueta de UI y de mensajes
    public function keyLabel(): string;                 // 'Referencia' | 'Código'
    /** @return list<array{key: string, label: string}> orden canónico del scope */
    public function match(array $filters): array;
    /** Libro independiente del target, o null si el storage depende del target. */
    public function canonicalBook(string $key): ?PriceBookInterface;
}

final class PriceUpdateScopes
{
    public static function forCode(string $code): ?PriceUpdateScopeInterface;
    public static function defaultCode(): string;       // 'articulos'
}
```

**Alternativas consideradas**: un `PriceUpdateScopeRegistry` simétrico al de
targets. Se descarta: el conjunto de scopes es cerrado y propiedad del core
(R-BU-006 fija exactamente dos); un registro sugeriría que un consumidor puede
añadir un tercero, lo que sería un cambio de core disfrazado.

**Rationale**: `forCode()` devuelve `null` para un scope desconocido → el
controlador falla cerrado con cero escrituras. El scope no ve nunca al target:
no recibe su código ni su libro.

Reparto de responsabilidades (el scope nunca depende del target):

| Responsabilidad | Dónde vive | Qué no hace |
|---|---|---|
| Selección de la matriz (scope × target → libro) | Motor: `canonicalBook() ?? bookFor()` | No ramifica por código de target |
| Construcción de filtros del scope (familia, subfamilias, grupo canónico, AND) | Scope (`match()`) | No conoce `codlista`/`codtarifa` |
| Filtros adicionales del consumidor | `PriceUpdateFilterRegistry` | No conoce el storage |
| Lectura del precio efectivo | Libro (`currentPrices()`) | No selecciona filas |
| Escritura | Libro (`persist()`) | No decide el precio |
| Validación de request (target/scope/clave/CSRF/admin/confirm) | Controlador | No conoce storage |
| Validación de aplicabilidad de filtros por scope | Scope (`match()` ignora `codgrupo` en opcionales) | No valida el target |

### D5 — Seam de filtros adicional: el hook de vista no puede acotar filas

**Decisión**: además del hook de vista, se añade un registro neutral de filtros:

```php
interface PriceUpdateFilterInterface
{
    public function supports(string $scope): bool;
    /** @return list<string> campos de request propios (se repostean en apply) */
    public function keys(string $scope): array;
    /** @return array<string, mixed> datos opacos para la plantilla del hook */
    public function viewContext(string $scope): array;
    /** @param list<array{key: string, label: string}> $rows */
    public function narrow(string $scope, array $filters, array $rows): array;
}

final class PriceUpdateFilterRegistry
{
    public static function register(PriceUpdateFilterInterface $filter): void;
    public static function reset(): void;
    public static function keys(string $scope): array;
    public static function viewContext(string $scope): array;
    public static function narrow(string $scope, array $filters, array $rows): array;
}
```

**Alternativas consideradas**:

- *Que el target acote las filas* (`narrow()` en el target): contradice
  R-BU-001 (el scope selecciona; el target resuelve precio y storage).
- *Que el hook renderice el control y el motor lea `$_REQUEST` a ciegas*:
  mete conocimiento del consumidor en el motor (prohibido por R-PSO-001/003).
- *Una función Twig registrada por el consumidor para los datos del filtro*:
  parte una misma extensión en dos seams y no resuelve el acotado de filas.

**Rationale**: el hook (R-CRH-001) solo renderiza HTML. Para que el filtro de
grupo de rol acote filas hace falta un seam PHP neutral; el registro de filtros
es el único lugar que respeta R-BU-001 y la dirección consumer → core. El
registro también resuelve dos necesidades derivadas:

1. **Reposteo en apply**: `keys()` declara los campos del consumidor
   (`codgrupo_rol`), que el controlador repostea como `hidden` sin aceptar
   claves arbitrarias.
2. **Datos para el control**: `viewContext()` entrega las opciones (grupos de
   rol) a la plantilla del hook vía `fsc.price_update_filter_context()`.

### D6 — Selección de target y scope en el controlador, con fallo cerrado

**Decisión**: el controlador resuelve, el motor no:

```text
target pedido  = $_POST['target'] ?? $_GET['target'] ?? ''
scope pedido   = $_POST['scope']  ?? $_GET['scope']  ?? 'articulos'

targets = PriceUpdateTargetRegistry::available()      // code => label
si targets == []                      → página inerte, mensaje neutral, cero escrituras
si target pedido == ''                → PriceUpdateTargetRegistry::defaultCode()
si target pedido ∉ targets            → error, cero escrituras (R-BU-002)
scope = PriceUpdateScopes::forCode(scope pedido); null → error, cero escrituras
key = target->keyFromFilters(filters); null → error "selecciona <keyLabel>"
```

**Alternativas consideradas**:

- *Ramificar por `target === 'tarifa'` en el controlador*: viola R-BU-002 y
  reintroduce conocimiento del consumidor en el core.
- *Exigir siempre selección explícita de target*: rompe el flujo de una sola
  opción (instalación standalone) y los tests de F1; el default determinista
  (`lista` si está disponible) conserva el comportamiento actual.

**Rationale**: el controlador solo maneja códigos y etiquetas; no conoce
storage ni plugin. La página deja de tener la rama `tariffs_active` que hoy
aborta todo el POST: la disponibilidad ahora la declara cada target. El target
`lista` reutiliza `PriceListAvailability::listUiEnabled()` (misma regla que las
demás superficies de lista); el target `tarifa` declara su disponibilidad como
"hay al menos una tarifa activa" (misma condición que usaba la página legacy).

### D7 — Hook `catalogo_actualizar_precios_filters_after`

**Decisión**: se declara en `view/catalogo_actualizar_precios.html.twig`, dentro
del formulario de filtros, después del control de porcentaje y antes del botón
de envío, con la forma exacta que exige R-CRH-001:

```twig
{{- render_hook('catalogo_actualizar_precios_filters_after', {'fsc': fsc, 'user': user, 'empresa': empresa, 'i18n': i18n}) -}}
```

Notas verificadas:

- `user`, `empresa` e `i18n` no son globales de Twig (`src/Core/Html.php` solo
  expone `fsc`, `nlogin` y los globals listados). En las vistas anfitrionas ya
  resuelven a `null` con `strict_variables` desactivado; replicar la misma
  expresión mantiene el contrato idéntico al de los cuatro hooks congelados.
- La forma `{{- -}}` garantiza cero bytes sin registro (R-CRH-001).
- El nombre es neutral: no contiene el token `tarifa`.

El consumidor registra la plantilla
`@tarifario/Hooks/catalogo_actualizar_precios_filters_after.html.twig` desde
`Init::init()` con guarda estática; si `tarifario` no está activo, el hook queda
sin registrar y rinde `''`.

### D8 — Redirect shim de `tarif_actualizar_precios` (F4)

**Mecanismo verificado**:

- `base/fs_controller.php:221` → `__construct($name, $title, $folder, $admin =
  FALSE, $shmenu = TRUE, $important = FALSE)`.
- `base/fs_controller.php:875` y `:911` → `check_fs_page()` persiste
  `show_on_menu = $shmenu` en `fs_pages`.
- `base/fs_controller.php:648` (`pages()`) filtra por `show_on_menu` y `:701`
  (`select_default_page()`) lo ignora: la página resuelve pero **no aparece en
  el menú renderizado**.
- `model/core/fs_user.php:334` (`get_menu()`) devuelve todas las páginas a un
  administrador, incluidas las ocultas. Por eso el test de "una sola entrada de
  menú" debe medir el menú **renderizado** (`pages()` / `show_on_menu = TRUE`),
  no el array crudo de `get_menu()`.
- `src/Core/Http/PageDispatcher.php`: tras instanciar el controlador legacy,
  `if ($fsc->template)` decide el render y luego llama a `$fsc->close()`. Con
  `$this->template = FALSE` no hay cuerpo y la conexión se cierra normalmente;
  no hace falta `exit`.

**Forma del archivo** (~55 líneas):

```php
class tarif_actualizar_precios extends fs_controller
{
    public function __construct()
    {
        parent::__construct(__CLASS__, 'Actualizar Precios', 'tarifario', FALSE, FALSE);
    }

    protected function private_core()
    {
        $this->template = FALSE;

        if (!is_file(FS_FOLDER . '/plugins/catalogo_core/controller/catalogo_actualizar_precios.php')
            || !class_exists(\FSFramework\Plugins\catalogo_core\Services\PriceUpdateTargetRegistry::class)) {
            header('Location: index.php');       // catalogo_core anterior al change
            return;
        }

        $codtarifa = trim((string) ($_REQUEST['codtarifa'] ?? ''));
        $url = self::build_redirect(
            $codtarifa,
            (new \FSFramework\Plugins\catalogo_core\Services\PriceListAvailability())->tariffsActive()
        );

        header('Location: ' . $url);
    }

    /** @internal puro y testeable sin BD */
    public static function build_redirect(string $codtarifa, bool $tarifaAvailable): string;
}
```

No extiende `tarif_controller`: así no carga modelos de tarifa ni ejecuta
`tarifario_check_tables()`. Solo necesita `base/fs_controller.php`, el modelo
canónico de lista y el servicio de disponibilidad.

**Regla de redirect (R-BU-008, enmendado) — sin mapeo a lista**:

| # | Condición | Destino |
|---|---|---|
| 1 | `codtarifa` vacío | `index.php?page=catalogo_actualizar_precios` (sin preselección, sin aviso) |
| 2 | el target `tarifa` está disponible | `…&target=tarifa&codtarifa=<codtarifa>` (conserva el storage que usaba la página legacy) |
| 3 | el target `tarifa` no está disponible | `…&aviso=seleccion_requerida` (sin target ni preselección, con aviso, sin aplicar) |

El aviso lo pinta el controlador base a partir de un código en whitelist
(`seleccion_requerida`) con un mensaje neutral ("No se ha podido preseleccionar
el destino solicitado. Selecciona un destino y vuelve a intentarlo."). Códigos
desconocidos se ignoran.

**Por qué NO hay mapeo `codtarifa` → `codlista`** (R-BU-008 enmendado, §14 C2):
`CatalogLegacyTableMigration::syncMissingPriceLists()` crea las entidades de
lista a partir de las tarifas, pero **nunca** copia `tarif_articulo_precios` a
`catalogo_articulo_precios`. Redirigir un `codtarifa` a
`target=lista&codlista=codtarifa` haría que el preview leyera el fallback
`articulos.pvp` (si es la lista por defecto) y el apply escribiera una fila
canónica que la UI de tarifa no lee: una actualización sin efecto visible o con
un precio inventado. Por eso `$mirrorExists` no existe como parámetro y el único
camino seguro es `target=tarifa&codtarifa`; sin target disponible, el operador
elige destino a mano.

**Sin aplicación en el redirect**: es un `GET`, no emite preview ni apply, no
requiere CSRF (no muta) y no reenvía el porcentaje.

### D9 — `#[AdminOnly]` en la página base (R-BU-004)

**Decisión**: declarar `#[\FSFramework\Attribute\AdminOnly]` en
`catalogo_actualizar_precios` y pasar el 4.º argumento del constructor a
`FALSE` (el 5.º sigue en `TRUE` para el menú).

**Hecho verificado**: hoy la página **no** es solo-administrador.
`base/fs_controller.php:961-972` (`isAccessAllowed()`) solo consulta el atributo
`#[AdminOnly]`; el 4.º argumento `$admin` es obsoleto e ignorado (AGENTS.md lo
documenta). El test actual
`CatalogoActualizarPreciosControllerTest::test_controller_is_admin_gated_in_ventas_folder`
afirma el argumento obsoleto, no una compuerta real. Sin este cambio, el
escenario "non-admin denied" de R-BU-004 no se cumple.

**Alternativas consideradas**: mantener el rol y no declarar el atributo
(incumple R-BU-004); declararlo en `fs_pages` a mano (el índice se reescribe en
la siguiente visita; la fuente de verdad es el código, según AGENTS.md).

**Rationale**: el atributo es la frontera de acceso real y además excluye la
página del listado de roles, coherente con una operación masiva de precios. El
rollback es automático: al quitar el atributo, `updateExistingPage()` degrada
`fs_pages.admin_only` a `FALSE` en la siguiente visita.

### D10 — Cómo se sostiene la paridad (R-BU-007)

| Caso del fixture | `target=tarifa` (base) | página legacy | ¿Paridad? |
|---|---|---|---|
| artículo con fila de tarifa y sin fila canónica | lee `tarif_articulo_precios` | lee `tarif_articulo_precios` | sí |
| artículo con fila canónica **y** fila de tarifa | lee solo la de tarifa (el libro canónico no se consulta: `canonicalBook()` es `null` para artículos) | lee la de tarifa | sí |
| artículo sin fila de tarifa | libro devuelve clave ausente → se omite | `INNER JOIN` → no aparece | sí |
| opcional con fila canónica para `codlista = codtarifa` | libro canónico (idéntico para ambos targets) | `catalogo_opcional_precios` | sí |

Reglas adicionales que el diseño fija para que la paridad sea real:

1. **Orden determinista**: el scope ordena por su clave canónica (artículos por
   `referencia`, opcionales por `codigo`), que es el orden de la página legacy
   (`ORDER BY a.referencia ASC` / `o.codigo ASC`). Sin esto, el motor base
   conservaría el orden de iteración de familias/grupos y R-BU-007 fallaría por
   orden.
2. **Filtro de grupo**: el `b_codgrupo` de la página legacy es un **grupo de
   rol** (`tarif_grupo_rol` en el selector, membresía en
   `tarif_grupo_articulos`), mientras que el `codgrupo` del motor base es el
   grupo canónico de artículos (`catalogo_grupo_articulos`). Son conceptos
   distintos. La paridad con el filtro de grupo se prueba mapeando
   `b_codgrupo` ↔ `codgrupo_rol` (filtro del consumidor), no `codgrupo`.
3. **Selección de filas en apply**: la página legacy aplica solo las filas
   marcadas; su vista las marca todas por defecto. El motor base aplica el
   preview confirmado completo. La paridad de apply se define con **todas las
   filas seleccionadas** (estado por defecto de la legacy). La selección fila a
   fila queda fuera de alcance (ver §14, C5).
4. **Sin filtros de familia ni grupo**: la legacy actualiza toda la tarifa; el
   motor base exige al menos un filtro de acotado y devuelve `[]` sin él. Es una
   decisión de seguridad deliberada; los fixtures de paridad incluyen siempre al
   menos un filtro.

### D11 — Correcciones obligatorias que F1 debe asumir

Estas correcciones son necesarias para cumplir los specs y **no** son "cero
cambio funcional" en sentido literal (ver §14, C4):

1. **Corte silencioso de 50 filas**: `articulo::all_from_familia()` usa
   `FS_ITEM_LIMIT` por defecto y el motor actual lo llama sin límite. Una
   familia con más de 50 artículos se actualiza a medias. El scope debe paginar
   hasta agotar la familia. Test RED con 120 filas.
2. **Apply roto en la página base**: el formulario de apply actual solo envía
   `action=apply` y `confirm`; no reposta `codlista` ni `pct`, por lo que
   `runApply()` cae en "Debes seleccionar una lista". El diseño reposta todos
   los filtros como `hidden` (incluidos los del registro de filtros). Sin esto,
   el escenario "apply persiste el preview" (R-BU-001) no se cumple.
3. **Orden determinista** (D10.1).
4. **`#[AdminOnly]`** (D9).
5. **Sustantivos de mensaje**: "Ningún artículo coincide…" pasa a un texto
   neutral según el scope ("Ningún elemento coincide…"); el resumen conserva el
   contrato de R-BU-005 (conteo + lista de afectados).

### D12 — Retiro de la fila `fs_pages` y el alcance de R-PSO-005

El shim con `$shmenu = FALSE` **escribe** una fila en `fs_pages`
(`check_fs_page()` la crea o actualiza). Por tanto, "no existe una segunda
página registrada en `fs_pages`" (R-PSO-005) no es alcanzable sin tocar
`base/` (prohibido). El diseño garantiza la parte observable y segura:

- `fs_pages.show_on_menu = FALSE` → sin entrada en el menú renderizado.
- F4 borra la fila preexistente con `fs_page::delete()` (limpia también
  `m_fs_page_all`) en `tarifario/Init::upgrade()`, de forma idempotente. Tras la
  primera visita a la URL legacy, el framework recrea la fila ya oculta.
- **No** se tocan las filas de `fs_roles_access`: así el rollback restaura el
  acceso por rol sin re-conceder permisos.

El escenario "exactamente una fila en `fs_pages`" requiere una enmienda de spec
(§14, C1); la garantía durable es "una sola página publicada y una sola entrada
de menú".

---

### D13 — Evento neutro `catalogo_core.price_update_persisted` (R-BU-009)

**Decisión del usuario (D-OPC-HIST, opción 2):** el historial de opcionales de una
tarifa lo registra el consumidor; el core no lo escribe. La página legacy guardaba
los opcionales con `tarif_opcional_precio::save()`, que añade una fila a
`tarif_opcional_precio_historial`. El libro canónico de opcionales guarda con
`catalogo_opcional_precio::save()`, que no registra historial. El único caso donde
esa auditoría existía y se perdería es `target=tarifa × scope=opcionales`.

- `Event/PriceUpdatePersistedEvent.php` (nuevo, `Symfony\Contracts\...\Event`,
  sin cancelación): `scope`, `targetCode`, `targetKey`, `rows` (`key`,
  `precio_anterior`, `precio_nuevo`). Nombre `catalogo_core.price_update_persisted`.
- `CatalogoPriceUpdateService::apply()`: si hay listeners
  (`FSEventDispatcher::getInstance()->hasListeners(NAME)`), lee `currentPrices()`
  de **todas** las claves a persistir **antes** del bucle (una consulta, no una
  por fila), persiste, y despacha un solo evento con las filas que `persist()`
  aceptó. El despacho va en `try/catch` con `error_log()`.
- Los artículos no necesitan el evento: el libro de tarifa ya registra historial
  con `get()` + `save()`. El listener del consumidor solo actúa sobre
  `opcionales` + `tarifa`, así que no hay doble registro.
- Neutralidad: el archivo del evento entra en `SEAM_FILES`; no nombra al consumidor
  ni sus tablas.

**Alternativa descartada:** hacer que el libro canónico de opcionales guarde con
`tarif_opcional_precio` (que ya registra historial). Mezcla un concepto de tarifa
en el storage canónico y registraría historial también para listas, que nunca lo
tuvieron.

## 4. Flujo de datos

```text
request (target, scope, filtros, pct)
        │
        ▼
catalogo_actualizar_precios (controlador)
  ├─ PriceUpdateTargetRegistry::available()  ── selector (code => label)
  ├─ PriceUpdateTargetRegistry::resolve(code) ── target | null → fail closed
  ├─ PriceUpdateScopes::forCode(scope)        ── scope   | null → fail closed
  └─ target->keyFromFilters(filters)          ── codlista | codtarifa | null
        │
        ▼
CatalogoPriceUpdateService
  ├─ scope->match(filters)                    ── [{key,label}] orden canónico
  ├─ PriceUpdateFilterRegistry::narrow(...)   ── extensiones del consumidor
  ├─ key = target->keyFromFilters(filters)
  ├─ book = scope->canonicalBook(key) ?? target->bookFor(scope, key)
  │            │ opcionales: canónico siempre
  │            │ artículos: lo aporta el target (lista | tarifa)
  ├─ preview: book->currentPrices(keys)       ── clave ausente → fila omitida
  │           computePrice(p, pct)            ── round half-up, 2 decimales
  └─ apply:   book->persist(key, precio_nuevo) ── por fila, con failed[]
        │
        ▼
view/catalogo_actualizar_precios.html.twig
  ├─ selector target / scope (desde el controlador)
  ├─ campo de clave neutral: name = target->keyField()
  ├─ filtros del scope + hook catalogo_actualizar_precios_filters_after
  ├─ hidden de reposteo (filtros + claves del registro de filtros)
  └─ tabla de preview (label / precio_actual / precio_nuevo)
```

---

## 5. Contratos

```php
namespace FSFramework\Plugins\catalogo_core\Services;

// --- PriceUpdateTargetRegistry.php (interfaz + registro en un archivo, como ArticuloListActionRegistry) ---

interface PriceBookInterface
{
    /** @param list<string> $keys @return array<string, float> clave ausente = sin precio */
    public function currentPrices(array $keys): array;
    public function persist(string $key, float $price): bool;
}

interface PriceUpdateTargetInterface
{
    public function code(): string;                 // 'lista' | 'tarifa'
    public function label(): string;
    public function isAvailable(): bool;            // disponibilidad en tiempo de request
    public function keyField(): string;             // 'codlista' | 'codtarifa'
    public function keyLabel(): string;             // 'lista de precio' | 'tarifa'
    /** @return list<array{value: string, label: string}> */
    public function keyOptions(): array;
    public function keyFromFilters(array $filters): ?string;
    public function bookFor(string $scope, string $key): ?PriceBookInterface;
}

final class PriceUpdateTargetRegistry { /* register, reset, registerDefaults, resolve, available, defaultCode */ }

// --- PriceUpdateScopes.php (interfaz + fábrica + scopes en un archivo) ---

interface PriceUpdateScopeInterface
{
    public function code(): string;
    public function label(): string;
    public function keyLabel(): string;
    /** @return list<array{key: string, label: string}> */
    public function match(array $filters): array;
    public function canonicalBook(string $key): ?PriceBookInterface;
}

final class PriceUpdateScopes
{
    public static function forCode(string $code): ?PriceUpdateScopeInterface;
    public static function defaultCode(): string;
}

// --- motor ---

final class CatalogoPriceUpdateService
{
    /** @return list<array{key: string, label: string, precio_actual: float, precio_nuevo: float}> */
    public function preview(PriceUpdateScopeInterface $scope, PriceUpdateTargetInterface $target, array $filters, float $pct): array;

    /** @return array{updated: int, failed: list<string>} */
    public function apply(PriceUpdateScopeInterface $scope, PriceUpdateTargetInterface $target, array $filters, array $rows): array;

    public function computePrice(float $p, float $pct): float; // sin cambios (R-BU-003)
}
```

Notas de contrato:

- El motor **no** recibe códigos de target/scope: recibe los colaboradores ya
  resueltos. Así no hay ninguna rama sobre `'lista'`/`'tarifa'` en el motor.
- `apply()` recalcula la clave y el libro desde `filters`; nunca confía en
  precios del cliente. El controlador vuelve a ejecutar `preview()` en el POST
  de apply y persiste esas filas (mismo comportamiento que hoy).
- `currentPrices()` devuelve solo claves con precio. Un libro que devuelva
  `0.0` para una fila ausente rompe R-BU-007 y el test de paridad.

---

## 6. Cambios por archivo

### `catalogo_core`

| Archivo | Acción | Fase | Descripción |
|---|---|---|---|
| `Services/PriceUpdateTargetRegistry.php` | Nuevo | F1 | `PriceBookInterface` + `PriceUpdateTargetInterface` + registro estático |
| `Services/PriceUpdateScopes.php` | Nuevo | F1 / F2 | Interfaz de scope, fábrica cerrada y los dos scopes (opcionales en F2) |
| `Services/CanonicalPriceBooks.php` | Nuevo | F1 / F2 | Libro canónico de artículos (F1) y de opcionales (F2) |
| `Services/ListaPriceUpdateTarget.php` | Nuevo | F1 | Target base: clave `codlista`, opciones = listas activas, disponibilidad = `PriceListAvailability::listUiEnabled()` |
| `Services/PriceUpdateFilterRegistry.php` | Nuevo | F3 | Interfaz + registro de filtros (D5) |
| `Services/CatalogoPriceUpdateService.php` | Modificado | F1–F3 | Motor `scope × target`; conserva `computePrice()` |
| `Services/CatalogoPriceResolver.php` | Sin cambios | — | Sigue siendo la lectura de precio único de las demás superficies; el libro canónico implementa la lectura batch con la misma regla |
| `Services/PriceListAvailability.php` | Sin cambios | — | Reutilizado por el target `lista` |
| `controller/catalogo_actualizar_precios.php` | Modificado | F1 / F3 / F4 | Resolución target/scope, `#[AdminOnly]`, aviso, contexto del hook, reposteo de filtros |
| `view/catalogo_actualizar_precios.html.twig` | Modificado | F1 / F3 | Selectores, campo de clave neutral, `hidden` de reposteo, hook de filtros |
| `Init.php` | Modificado | F1 | `PriceUpdateTargetRegistry::registerDefaults()` con guarda estática |
| `tests/…` | Nuevos/Modificados | F0–F5 | Ver §7 |

### `tarifario`

| Archivo | Acción | Fase | Descripción |
|---|---|---|---|
| `Services/TarifaPriceUpdateTarget.php` | Nuevo | F3 | Target `tarifa`: clave `codtarifa`, opciones = tarifas activas, disponibilidad = hay tarifa activa |
| `Services/TarifaArticlePriceBook.php` | Nuevo | F3 | Libro de artículos sobre `tarif_articulo_precios`; lee con `all_for_referencias()`, persiste con `get()` + `save()` (conserva historial) |
| `Services/TarifaRoleGroupPriceFilter.php` | Nuevo | F3 | Filtro de grupo de rol (`codgrupo_rol`, `tarif_grupo_articulos`) + `viewContext()` con los grupos |
| `Services/LegacyPricePageRetirement.php` | Nuevo | F4 | Borrado idempotente de la fila `fs_pages` de `tarif_actualizar_precios` |
| `View/Hooks/catalogo_actualizar_precios_filters_after.html.twig` | Nuevo | F3 | Control del filtro de grupo de rol (solo `scope = articulos`) |
| `Init.php` | Modificado | F3 / F4 | Registra target + filtro + hook; invoca el retiro en `upgrade()` |
| `controller/tarif_actualizar_precios.php` | Modificado | F4 | Shim de redirect (D8) |
| `View/tarif_actualizar_precios.html.twig` | Borrado | F4 | Superficie retirada (R-PSO-006) |
| `View/tarif_articulo.html.twig` | Borrado | F5 | Vista muerta (R-PSO-006) |
| `tests/…` | Nuevos/Modificados | F3–F5 | Ver §7 |

No se crea ni modifica ningún archivo bajo `base/`, `src/`, `controller/` ni
`model/` de la raíz. No hay dependencias Composer nuevas ni cambios en
`vendor/`.

---

## 7. Estrategia de pruebas por fase

Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
y `-c plugins/tarifario/phpunit.xml`. Análisis estático:
`ddev exec composer phpstan` (el gate de plugins solo incluye
`plugins/catalogo_core`; `tarifario` no está en `tools/phpstan-plugins.php`).

| Fase | Capa | Qué se prueba | Enfoque |
|---|---|---|---|
| **F0** | Guardrail / grep audit | El motor no nombra tablas de consumidor; la allowlist `TARIFARIO_ALLOWLIST` no crece; los cuatro hooks congelados intactos | Nuevo `tests/PriceSurfaceOwnershipTest.php`: escanea `CatalogoPriceUpdateService.php` y (desde F1) la lista `SEAM_FILES` buscando tokens `tarif_articulo_precios`, `tarif_opcional_precios`, `tarif_grupo_articulos`, `tarif_tarifas`; fija el tamaño de la allowlist |
| **F1** | Unit | Registro de targets: `register/reset/resolve/available/defaultCode`, duplicados (gana el primero), código desconocido → `null` | Nuevo `tests/Services/PriceUpdateTargetRegistryTest.php` |
| **F1** | Unit | Scope de artículos: recursión de subfamilias, AND con grupo, sin filtros → `[]`, **paginación > 50** | Nuevo `tests/Services/ArticulosPriceUpdateScopeTest.php` con dobles de `familia`/`articulo`/`catalogo_grupo_articulo` (patrón `CatalogoPriceUpdateServiceTest`) |
| **F1** | Unit | Libro canónico de artículos: fila de lista gana; fallback a `pvp` solo en la lista por defecto; clave ausente fuera del default; `persist` por fila | Nuevo `tests/Services/CanonicalArticlePriceBookTest.php` |
| **F1** | Unit | Motor: composición canónico-primero; `apply` persiste solo las filas del preview; `failed[]`; fórmula sin cambios | `tests/Services/CatalogoPriceUpdateServiceTest.php` migrado al seam de scope con **expectativas idénticas** |
| **F1** | Estructural | `#[AdminOnly]`, selectores target/scope, campo de clave neutral, reposteo de filtros, rama inerte sin targets | `tests/CatalogoActualizarPreciosControllerTest.php` (actualizado) |
| **F1** | Contrato de test | La compuerta de listas se movió al target: el test que exigía `listUiEnabled()` en el controlador apunta a `Services/ListaPriceUpdateTarget.php` | `tests/Services/PriceListAvailabilityTest.php` (actualizado) |
| **F2** | Unit | Scope de opcionales: membresía por `catalogo_opcional_familias`, orden por `codigo`, grupo de artículo no aplica, paginación de `catalogo_opcional::all()` | Nuevo `tests/Services/OpcionalesPriceUpdateScopeTest.php` |
| **F2** | Unit | Libro canónico de opcionales: `catalogo_opcional_precios` con `(id_opcional, codlista)`; ausencia → omitido; sin inserción desde apply | Nuevo `tests/Services/CanonicalOpcionalPriceBookTest.php` |
| **F2** | Paridad | Opcionales: base (`scope=opcionales`, `target=lista`, `codlista=T`) vs página legacy (`tipo=opcionales`, `codtarifa=T`): filas, orden, totales y escrituras | `plugins/tarifario/tests/PriceUpdateParityTest.php` (el storage es independiente del target; no requiere el target `tarifa`) |
| **F3** | Unit | Target `tarifa`: `keyField`, `keyOptions`, disponibilidad, `bookFor('articulos')`, `bookFor('opcionales') === null`; el libro lee solo `tarif_articulo_precios` y persiste con `save()` (historial) | Nuevo `plugins/tarifario/tests/Services/TarifaPriceUpdateTargetTest.php` |
| **F3** | Unit | Filtro de grupo de rol: `keys()`, `viewContext()`, `narrow()` sobre `tarif_grupo_articulos`; `supports('opcionales') === false` | Nuevo `plugins/tarifario/tests/Services/TarifaRoleGroupPriceFilterTest.php` |
| **F3** | Hook | `catalogo_actualizar_precios_filters_after`: una sola marca, `{{- -}}`, cero bytes sin registro, fragmento sin escapar con registro, nombre neutral, ausente de las vistas anfitrionas | `tests/CatalogoCoreHookMarkersTest.php` (ampliado) + `tests/Integration/CatalogoCoreHookMarkersTest.php` (aditividad) |
| **F3** | Paridad | Artículos con `target=tarifa` vs página legacy: mismo fixture, mismos filtros (incluido el grupo de rol), filas/orden/totales/escrituras idénticos; preview no escribe | `plugins/tarifario/tests/PriceUpdateParityTest.php` |
| **F3** | Integración | Registro idempotente desde `Init::init()`; sin `catalogo_core` nuevo, no hay target y no hay fatal | Test de `Init` con doble del registro / `class_exists` |
| **F4** | Estructural | Controlador/vista legacy ausentes; el shim no contiene preview/apply ni entrada de menú; `show_on_menu = FALSE` en el constructor | `tests/PriceSurfaceOwnershipTest.php` (ampliado) |
| **F4** | Unit | `build_redirect()`: los tres casos de la tabla de D8 (incluido el aviso sin target disponible) | Nuevo `plugins/tarifario/tests/LegacyRedirectShimTest.php` |
| **F4** | Integración | El retiro borra la fila `fs_pages` y es idempotente; no toca `fs_roles_access` | Nuevo `plugins/tarifario/tests/LegacyPricePageRetirementTest.php` con `fs_db2` falso |
| **F4** | Limpieza | Sin referencias que exijan las vistas retiradas; sin `tarif_actualizar_precios` en listas de controladores | `ArticuloListaCanonicaOwnershipTest`, `VentasArticuloArticleEditAbsorptionTest`, `CatalogoOpcionalesUnifiedControllerTest`, `TarifControllerLanguageCallsTest` |
| **F4** | Regresión de paridad | Reproducción del golden JSON capturado en F3 contra el motor base (la legacy ya no existe) | `plugins/tarifario/tests/Fixtures/price_update_parity_legacy.json` + test de replay |
| **F5** | Estructural | `tarif_articulo.html.twig` ausente; sin referencias; dedup de presentación sin cambio semántico | `tests/PriceSurfaceOwnershipTest.php` + grep de vistas |

### 7.1 Fixture de paridad sin BD (F2/F3)

El fixture vive en `plugins/tarifario/tests/Support/` (el test de paridad
necesita el consumidor y la página legacy; `catalogo_core` debe seguir siendo
probable en standalone, por eso el test **no** vive allí):

1. **`PriceUpdateParityFixture`**: semilla única en arrays — familias y árbol,
   artículos (`referencia`, `codfamilia`), opcionales (`id`, `codigo`, `nombre`,
   familias), filas canónicas de artículo, filas de tarifa de artículo, filas
   canónicas de opcional, grupos de rol y membresías.
2. **`LegacyPriceUpdateFakeDb`**: `fs_db2` falso con `select`, `select_limit`,
   `exec`, `var2str` e `intval`. Responde las consultas exactas de la página
   legacy (reconoce `COUNT(*)`, `tarif_articulo_precios`, `catalogo_opcional_precios`,
   `codtarifa`, `codlista`, familias y `codgrupo`) desde la semilla y registra
   las escrituras. La página legacy está congelada (se retira en F4), por lo que
   el acoplamiento al SQL es aceptable y está contenido en un solo doble.
3. **`LegacyPriceUpdateDriver`**: `newInstanceWithoutConstructor()` sobre
   `tarif_actualizar_precios`; inyecta `db`, `familia`, `codtarifa`, `tipo`,
   `b_codfamilia`, `incluir_subfamilias`, `b_codgrupo`, `porcentaje` y `offset`;
   invoca `load_preview()` / `aplicar_cambios_articulos()` /
   `aplicar_cambios_opcionales()` por reflexión. Una subclase anónima anula
   `new_message`, `new_error_msg`, `var2str` y `default_codidioma`.
4. **Camino base**: `CatalogoPriceUpdateService` real + scopes reales + target y
   libro reales del consumidor, con modelos inyectados como dobles anónimos
   (mismo patrón que `CatalogoPriceUpdateServiceTest::fakePrecioModel`).
5. **Aserciones**: tripletas `(key, precio_actual, precio_nuevo)` iguales,
   mismo orden, mismo conteo y suma, mismo conjunto de escrituras
   (`(referencia, codtarifa, precio)` / `(id_opcional, codlista, precio)`) y
   cero escrituras en preview. Un caso dedicado con fila canónica **y** fila de
   tarifa del mismo artículo prueba D10.
6. **Golden**: una vez verde, se graba la salida legacy esperada en
   `Fixtures/price_update_parity_legacy.json`. F4 borra el driver legacy y deja
   el test de replay contra el golden, preservando la garantía tras el retiro.

Contingencia si la reflexión resulta demasiado acoplada: capturar el golden una
vez con `ddev` sobre una BD sembrada y mantener solo el replay. Se prefiere el
oráculo real porque demuestra la paridad contra el código que se retira.

---

## 8. Secuencia strict TDD (RED-first, resumen)

- **F0**: RED `PriceSurfaceOwnershipTest` (falla por ausencia del test/reglas);
  GREEN sin tocar producción.
- **F1a**: RED `PriceUpdateTargetRegistryTest`, `ArticulosPriceUpdateScopeTest`
  (incluye el caso > 50), `CanonicalArticlePriceBookTest`, casos nuevos de
  `CatalogoPriceUpdateServiceTest`; GREEN registro + scope + libro + motor.
- **F1b**: RED controller/view (AdminOnly, selectores, clave neutral, reposteo,
  rama inerte); GREEN controlador + vista + `Init`.
- **F2**: RED `OpcionalesPriceUpdateScopeTest`,
  `CanonicalOpcionalPriceBookTest`, paridad de opcionales; GREEN scope + libro.
- **F3a**: RED hook markers + `PriceUpdateFilterRegistry`; GREEN marca en la
  vista + registro.
- **F3b**: RED target/libro/filtro del consumidor + paridad de artículos;
  GREEN implementación + registro en `Init` + golden.
- **F4**: RED shim (`build_redirect`), retiro, limpieza de fixtures y golden
  replay; GREEN shim + retiro + borrado de vista/controlador legacy.
- **F5**: RED ausencia de `tarif_articulo.html.twig` y de referencias; GREEN
  borrado + dedup.

Cada fase corre las dos suites completas antes de cerrar el slice.

---

## 9. Threat matrix

| Superficie | Riesgo | Mitigación |
|---|---|---|
| Redirect (`?page=tarif_actualizar_precios`) | Open redirect | Destino fijo `index.php?page=catalogo_actualizar_precios`; el `codtarifa`/`codlista` solo se interpola con `rawurlencode()` en query string |
| Redirect | Redirect a una página inexistente (core anterior) | Guarda `is_file()` del controlador destino → `index.php` |
| Apply | Manipulación del cliente (target/scope/precios) | El controlador re-resuelve target/scope, re-ejecuta `preview()` y persiste solo filas recalculadas; códigos desconocidos → cero escrituras |
| Apply | CSRF | `isCsrfValid()` antes de cualquier escritura (sin cambios, R-BU-004) |
| Acceso | Usuario no administrador | `#[AdminOnly]` + `isAccessAllowed()` (D9) |
| Filtros del consumidor | Claves arbitrarias reposteadas | Solo se repostean las claves declaradas por `PriceUpdateFilterRegistry::keys()`; Twig escapa los valores |
| SQL | Inyección en los nuevos libros/scopes | Modelos existentes (`var2str`/`intval`) o parámetros; ningún SQL nuevo concatenado con entrada de usuario |
| Evento `price_update_persisted` | Un listener roto altera o aborta el apply | `try/catch` + `error_log()`; el evento no es cancelable y el resultado no depende de él |
| Evento `price_update_persisted` | Precio anterior manipulado por el cliente | Se lee del libro en el apply, no de las filas reposteadas |
| Hook | XSS en el fragmento | `render_hook` es `is_safe: ['html']` sobre plantillas del propio plugin, no sobre datos de usuario; los valores se escapan en la plantilla |

---

## 10. Ownership safety

- Los archivos nuevos de `catalogo_core` no contienen el token `tarifario` ni
  rutas `plugins/tarifario/`: el guardrail
  `CatalogoCoreHookMarkersTest::testNoTarifarioReferencesOutsideDocumentedAllowlist`
  sigue fallando para un archivo nuevo. La allowlist **no** se amplía.
- Los docblocks de los seams usan vocabulario neutral ("target", "consumer",
  "price book", "scope"), nunca el nombre del plugin consumidor.
- La dirección es consumer → core: `tarifario` referencia
  `PriceUpdateTargetRegistry`, `PriceUpdateFilterRegistry`,
  `PriceListAvailability` y modelos canónicos; nunca al revés.
- `fsframework.ini` de ambos plugins no cambia (`tarifario` ya declara
  `require = "catalogo_core"`; no admite restricción de versión, ver §13).
- El core no gana ninguna dependencia nueva hacia el consumidor:
  `ListaPriceUpdateTarget` reutiliza `PriceListAvailability`, que ya
  referenciaba el modelo absorbido `tarif_tarifa`. El guardrail es por token
  `tarifario`; el diseño no añade ninguna referencia nueva.
- Los cuatro hooks congelados no se tocan: el hook nuevo vive solo en
  `view/catalogo_actualizar_precios.html.twig`.

---

## 11. Preservación de datos y migración

- Sin cambios de esquema y sin migración de datos: los dos storages de artículos
  quedan como están.
- Sin copia de `tarif_articulo_precios` a `catalogo_articulo_precios`: la
  lectura y la escritura del target `tarifa` siguen usando la tabla legacy.
- El redirect de F4 no escribe precios; solo redirige.
- El retiro borra una fila de metadatos (`fs_pages`) y limpia
  `m_fs_page_all`; no borra `fs_roles_access`, precios ni historial.
- El historial de precios se conserva: el libro de tarifa persiste con
  `tarif_articulo_precio::save()`, que mantiene el registro en
  `tarif_precio_historial` cuando cambia el precio.

---

## 12. Chained PRs y presupuesto

Presupuesto: **800 líneas cambiadas por slice** (política de este SDD).

| Slice | Contenido | Base | Verde independiente |
|---|---|---|---|
| **PR-F0/F1a** | Guardrail + registro + scopes (artículos) + libros canónicos (artículos) + motor + tests | `main` | Sí (sin cambio de UI) |
| **PR-F1b** | Controlador + vista (selectores, `#[AdminOnly]`, reposteo) + `Init` + tests de contrato | PR-F1a | Sí |
| **PR-F2** | Scope y libro de opcionales + paridad de opcionales | PR-F1b | Sí |
| **PR-F3a** | Hook + registro de filtros + marca en la vista + tests de hook | PR-F2 | Sí (hook sin registrar = cero bytes) |
| **PR-F3b** | Target/libro/filtro del consumidor + registro en `Init` + paridad de artículos + golden | PR-F3a | Sí |
| **PR-F4** | Shim + retiro + limpieza de fixtures + golden replay | PR-F3b | Sí |
| **PR-F5** | Borrado de la vista muerta + dedup de presentación | PR-F4 | Sí |

Estimación: F1 ~1100–1300 líneas (por eso dos slices), F2 ~450, F3 ~950 (dos
slices), F4 ~350, F5 ~250. Ningún slice supera 800.

**Compatibilidad entre F1a y F1b**: F1a añade los seams y el motor nuevo pero
conserva los métodos públicos actuales (`matchArticles()`, `preview($filters,
$pct)`, `apply($rows, $codlista)`) como delegados al target `lista` y al scope
`articulos`; así el controlador sigue funcionando y el slice queda verde. F1b
migra el controlador a la API `scope × target` y retira los delegados.

---

## 13. Rollback y secuencia de releases

### Rollback por fase

| Fase | Rollback | Datos |
|---|---|---|
| F0 | `git revert` del commit | Ninguno |
| F1 | `git revert`; quitar `#[AdminOnly]` degrada `fs_pages.admin_only` en la siguiente visita | Sin datos que deshacer |
| F2 | `git revert`; el scope de opcionales desaparece y la página sigue con artículos | Las filas escritas son las mismas que escribe la página legacy |
| F3 | `git revert`; el target `tarifa` deja de registrarse, el hook queda sin registrar (cero bytes) | Las filas escritas en `tarif_articulo_precios` son las mismas de la legacy |
| F4 | `git revert` restaura controlador y vista; `check_fs_page()` recrea la fila `fs_pages` con el menú original. Alternativa: `_back` del plugin | `fs_roles_access` intacto → los permisos por rol vuelven sin re-conceder |
| F5 | `git revert` restaura la vista muerta | Ninguno |

### Secuencia de versiones (tags independientes, sin release de core)

| Release | Versión | Contenido | Orden |
|---|---|---|---|
| `catalogo_core` | **1.16.0** | F0 + F1 (registro, scopes de artículos, target `lista`, motor, UI) | 1.º |
| `catalogo_core` | **1.17.0** | F2 (opcionales) | 2.º |
| `catalogo_core` | **1.18.0** | F3a (hook + registro de filtros) | 3.º |
| `catalogo_core` | **1.19.0** | F4 lado core (manejo de `aviso`, limpieza de fixtures) | 4.º |
| `tarifario` | **3.5.0** | F3b + F4 lado consumer (target, filtro, retiro, shim y golden replay) | 5.º (después de 1.19.0) |
| `catalogo_core` | **1.20.0** | F5 (vista muerta + dedup) | 6.º |

- `VERSION` del core (0.35.11) **no** se toca: no hay cambios en `base/`,
  `src/`, `controller/` ni `model/` raíz.
- `require = "catalogo_core"` no admite versión; la compatibilidad se garantiza
  con guardas `class_exists` por pieza: el target exige el registro de targets
  (≥1.16.0), el filtro exige `PriceUpdateFilterRegistry` (≥1.18.0) y el shim
  exige el registro de targets (si falta, redirige a `index.php`). La secuencia
  de releases garantiza 1.18.0 antes de `tarifario` 3.5.0; con una versión
  intermedia el target funciona y el filtro simplemente no se registra.
- `catalogo_core` 1.18.0 sin `tarifario` 3.5.0 deja la página solo con `lista`:
  comportamiento seguro.

---

## 14. Contradicciones detectadas (no se resuelven en silencio)

| # | Fuente | Contradicción verificada | Resolución del diseño |
|---|---|---|---|
| **C1** | R-PSO-005 vs R-PSO-006/R-BU-008 | "No existe una segunda página registrada en `fs_pages`" no es alcanzable: cualquier controlador legacy resoluble por `?page=` llama a `check_fs_page()` y escribe/actualiza su fila (`base/fs_controller.php:875,911`). Solo se evita con un controlador duck-typed fuera de `fs_controller` (frágil) o tocando `base/` (prohibido) | Shim con `show_on_menu = FALSE` + borrado de la fila preexistente en F4. Garantía durable: una sola página **publicada** y una sola entrada de menú renderizada. **Requiere enmienda de R-PSO-005** ("publicada", no "registrada") o aceptación explícita — **ENMENDADO**: R-PSO-005 ahora habla de página publicada y entrada de menú visible, no de filas en `fs_pages`. |
| **C2** | R-BU-008 | El mapeo `codtarifa → codlista` cuando existe espejo es inseguro para artículos: `CatalogLegacyTableMigration` espeja listas y precios de opcionales, pero **nunca** `tarif_articulo_precios → catalogo_articulo_precios`. El preview leería el fallback `pvp` y el apply escribiría una fila canónica que no corresponde a la tarifa | Orden de D8: `target=tarifa&codtarifa` cuando el target está disponible; el mapeo a lista queda como fallback. **Requiere enmienda de R-BU-008** o aceptación explícita — **ENMENDADO**: R-BU-008 preserva `target=tarifa&codtarifa` y prohíbe explícitamente el mapeo a lista. |
| **C3** | Proposal, Evidence/Risks | "`CatalogLegacyTableMigration` solo espeja la tarifa por defecto" es inexacto: `syncMissingPriceLists()` espeja todas las tarifas referenciadas por precios de opcionales y todas las de `tarif_tarifas` cuando hay pendientes; lo que no se espeja nunca son los precios de artículos | El diseño usa el hecho corregido (C2); el riesgo de "lista equivocada" cambia de causa: no es el espejo parcial, es la ausencia total de espejo de artículos |
| **C4** | Proposal, Fase F1 | "Sin cambio funcional" no es literalmente alcanzable: la corrección del corte de 50 filas (necesaria para R-BU-007), `#[AdminOnly]` (R-BU-004), el orden determinista, los selectores de UI y el reposteo de filtros (apply hoy roto) son cambios observables | Se mantiene la intención (misma fórmula, mismos filtros, mismas claves de storage, mismos mensajes de resumen) y se listan las correcciones obligatorias en D11. **Requiere ajustar el gate de F1** |
| **C5** | R-BU-007 | La página legacy aplica solo las filas marcadas; el motor base aplica el preview completo | Paridad definida con todas las filas seleccionadas (estado por defecto de la legacy). La selección fila a fila se declara fuera de alcance |
| **C6** | R-BU-001/R-BU-002 vs R-CRH-001 | El hook de vista no puede acotar filas; R-BU-001 asigna la selección al scope y prohíbe que el target la posea. El proposal solo menciona el hook para el filtro de grupo de rol | Se añade `PriceUpdateFilterRegistry` (D5). **Recomendación**: declararlo en el delta de `batch-price-update` (nuevo R-BU-009) o aceptarlo como seam de diseño — **ENMENDADO**: R-BU-002 incorpora la cláusula aditiva de filtros y un escenario propio. |
| **C7** | Supuesto de partida del encargo | Se dio por hecho que el `TARIFARIO_ALLOWLIST` está en `tests/CatalogoCoreHookMarkersTest.php` "y su gemelo de `tests/Integration`". Verificado: solo existe en `plugins/catalogo_core/tests/CatalogoCoreHookMarkersTest.php`; el gemelo de Integration no tiene allowlist | Se usa el hecho verificado; el guardrail vive en un solo archivo |
| **C8** | `PriceListAvailabilityTest` | `testEveryPriceListSurfaceUsesTheSameRule` exige `listUiEnabled()` en el controlador; al mover la compuerta al target `lista` el test falla | El test pasa a auditar `Services/ListaPriceUpdateTarget.php` (cambio de contrato de test, justificado) |
| **C9** | Proposal, Affected Areas | Lista `CatalogoPriceResolver.php` como modificado | Se deja sin cambios: la lectura batch del libro canónico implementa la misma regla y el resolver sigue sirviendo a las demás superficies. Menos riesgo de regresión |
| **C10** | `CatalogoActualizarPreciosControllerTest` | Afirma que el 4.º argumento `admin=TRUE` es la compuerta; es obsoleto e ignorado (`isAccessAllowed()` solo mira el atributo) | Se declara `#[AdminOnly]` y el test pasa a verificar el atributo por reflexión (D9) |

---

## 15. Decisiones abiertas / bloqueantes

1. **C1 y C2**: resueltas por enmienda de spec (`R-PSO-005`, `R-BU-008`). El
   diseño implementa la opción segura descrita en D8.
2. **C6**: resuelta; `batch-price-update` incorpora la cláusula aditiva de
   filtros y el escenario correspondiente.
3. **F5b (dedup de macros y `ventas_listas_precio` como vista de storage)** no
   tiene requisito funcional en los deltas. Se recomienda ejecutar solo F5a
   (borrado de `tarif_articulo.html.twig`, exigido por R-PSO-006) y diferir la
   dedup visual a un change propio si amenaza el presupuesto.
4. **Paginación del preview**: la página base no pagina (la legacy sí). Con
   catálogos grandes el preview puede ser muy largo. Se mantiene el
   comportamiento actual en este change; se registra como limitación conocida,
   no como regresión (la página base ya era así).
