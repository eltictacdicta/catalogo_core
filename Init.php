<?php
/**
 * Plugin initialization for catalogo_core.
 */
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core;

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Event\TwigInitEvent;
use FSFramework\Event\TwigLoaderEvent;
use FSFramework\Plugins\catalogo_core\Services\CatalogLegacyTableMigration;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaBackfillMigration;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaRegistry;
use FSFramework\Plugins\catalogo_core\Services\TarifOpcionalExtMigration;
use FSFramework\View\ViewHookRegistry;
use Twig\Loader\FilesystemLoader;

final class Init
{
    private const WIZARD_TMP_TTL = 86400;

    /**
     * Frozen opcional view hooks injected into catalogo_core's own
     * ventas_opcional page (OUM-10, AD-8). Mapping is 1:1 hook → template for
     * grep auditability.
     */
    private const OPCIONAL_HOOK_TEMPLATES = [
        'ventas_opcional_tabs_after' => '@catalogo_core/Hooks/ventas_opcional_tabs_after.html.twig',
        'ventas_opcional_tab_pane_after' => '@catalogo_core/Hooks/ventas_opcional_tab_pane_after.html.twig',
    ];

    /**
     * Guards the TwigInitEvent hook registration so repeated Twig builds
     * (env cache cleared) never duplicate the registered hooks.
     */
    private static bool $hooksRegistered = false;

    /**
     * Guards the TwigLoaderEvent/TwigInitEvent listener registration so
     * repeated boots never duplicate the listeners.
     */
    private static bool $viewExtensionsRegistered = false;

    /** @var list<string> Modelos PSR-4 con install() que deben sembrarse al activar. */
    private const DEFAULT_SEED_MODELS = [
        'impuesto',
        'familia',
        'fabricante',
        'catalogo_idioma',
        'catalogo_lista_precio',
    ];

    public function init(): void
    {
        $this->cleanupOrphanWizardFiles();
        self::migrateLegacyTables();
        self::migrateOpcionalExtension();
        self::ensureArticuloOpcionalGrupoTable();
        try {
            self::ensureFamiliasTarifaTables();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] familias tarifa tables ensure failed: ' . $e->getMessage());
        }
        try {
            self::ensureOpcionalesTarifaTables();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] opcionales tarifa tables ensure failed: ' . $e->getMessage());
        }
        try {
            self::ensureArticuloTarifaTables();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] articulo tarifa tables ensure failed: ' . $e->getMessage());
        }
        try {
            self::ensureArticuloDetalleTables();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] articulo detalle tables ensure failed: ' . $e->getMessage());
        }
        try {
            self::ensureCaracteristicasTables();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] caracteristicas tables ensure failed: ' . $e->getMessage());
        }
        try {
            CaracteristicaRegistry::registerDefaults();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] caracteristicas defaults registration failed: ' . $e->getMessage());
        }
        try {
            require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaBackfillMigration.php';
            $db = self::plugin_db();
            if ($db instanceof \fs_db2) {
                CaracteristicaBackfillMigration::migrateIfNeeded($db);
            }
        } catch (\Throwable $e) {
            error_log('[catalogo_core] caracteristicas backfill failed: ' . $e->getMessage());
        }
        try {
            self::registerViewExtensions();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] view extensions registration failed: ' . $e->getMessage());
        }
    }

    /**
     * Siembra datos maestros del catálogo al activar el plugin.
     *
     * Los modelos legacy sin namespace (almacén, divisa, país…) los cubre
     * fs_model::seed_if_empty() en PluginSchemaSynchronizer. Los modelos
     * bajo FSFramework\model\* no pasan por ese refresco y se siembran aquí.
     */
    public static function upgrade(): void
    {
        try {
            self::migrateLegacyTables();
            self::migrateOpcionalExtension();
            self::ensureCatalogTables();
            self::ensureFamiliasTarifaTables();
            self::ensureOpcionalesTarifaTables();
            self::ensureArticuloTarifaTables();
            self::ensureArticuloDetalleTables();
            self::ensureCaracteristicasTables();
            CaracteristicaRegistry::registerDefaults();
            try {
                require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaBackfillMigration.php';
                $db = self::plugin_db();
                if ($db instanceof \fs_db2) {
                    CaracteristicaBackfillMigration::migrateIfNeeded($db);
                }
            } catch (\Throwable $e) {
                error_log('[catalogo_core] caracteristicas backfill failed: ' . $e->getMessage());
            }
            foreach (self::DEFAULT_SEED_MODELS as $modelName) {
                self::seedNamespacedModel($modelName);
            }
        } catch (\Throwable $e) {
            error_log('[catalogo_core] Default seed failed: ' . $e->getMessage());
        }
        try {
            self::retireVentasFamiliasPage();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] ventas_familias page retirement failed: ' . $e->getMessage());
        }
        try {
            self::retireTarifOpcionalesPage();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] tarif_opcionales page retirement failed: ' . $e->getMessage());
        }
        try {
            self::retireTarifOpcionalPreciosPage();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] tarif_opcional_precios page retirement failed: ' . $e->getMessage());
        }
        try {
            self::retireTarifArticuloEditPage();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] tarif_articulo_edit page retirement failed: ' . $e->getMessage());
        }
        try {
            self::retireTarifArticuloPreciosPage();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] tarif_articulo_precios page retirement failed: ' . $e->getMessage());
        }
        try {
            self::retireTarifArticulosPage();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] tarif_articulos page retirement failed: ' . $e->getMessage());
        }
        try {
            self::registerViewExtensions();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] view extensions registration failed: ' . $e->getMessage());
        }
    }

    /**
     * TwigLoaderEvent self-registers the @catalogo_core namespace over this
     * plugin's View dir so the injected tabs are self-contained and testable
     * without $GLOBALS['plugins']; TwigInitEvent registers the opcional hook
     * pair (it needs the registry during the Twig build). Idempotent behind the
     * static guard.
     */
    private static function registerViewExtensions(): void
    {
        if (self::$viewExtensionsRegistered) {
            return;
        }
        self::$viewExtensionsRegistered = true;

        $dispatcher = FSEventDispatcher::getInstance();

        $dispatcher->addListener(TwigLoaderEvent::NAME, static function (TwigLoaderEvent $event): void {
            $loader = $event->getLoader();
            if ($loader instanceof FilesystemLoader) {
                $loader->addPath(__DIR__ . '/View', 'catalogo_core');
            }
        });

        $dispatcher->addListener(TwigInitEvent::NAME, static function (TwigInitEvent $event): void {
            self::registerHooks();
        });
    }

    /**
     * Registers the frozen opcional view hooks, guarded by the static flag so
     * repeated Twig builds never duplicate them (AD-5). The article pair is no
     * longer registered: the Tarifas surface lives in the host's unified
     * `#datos` pane (D4) and the two frozen article markers stay inert.
     */
    private static function registerHooks(): void
    {
        if (self::$hooksRegistered) {
            return;
        }

        foreach (self::OPCIONAL_HOOK_TEMPLATES as $hook => $template) {
            ViewHookRegistry::register($hook, $template);
        }

        self::$hooksRegistered = true;
    }

    /**
     * Asegura las cuatro tablas del paquete familias-tarifa absorbido desde
     * tarifario cuando el plugin tarifario NO está activo (instalación
     * standalone). Idempotente: require_once + fs_model solo crea la tabla
     * si falta. Orden FK-seguro, espejo de tarifario_init.php.
     */
    public static function ensureFamiliasTarifaTables(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';
        foreach ([                       // FK-safe order, mirrors tarifario_init.php:57-59, :93-95, :103-105, :118-120
            'tarif_tarifa',              // tarif_tarifas — no plugin FK deps
            'tarif_familia_ext',         // extends core familias
            'tarif_tarifa_etiqueta_familia',
            'tarif_tarifa_familia',      // FK → tarif_tarifas; install() also re-creates tarif_tarifas (model:128, defense in depth)
        ] as $modelName) {
            $file = FS_FOLDER . '/plugins/catalogo_core/model/' . $modelName . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            $fqcn = 'FSFramework\\model\\' . $modelName;
            if (class_exists($fqcn, false) && is_subclass_of($fqcn, \fs_model::class)) {
                new $fqcn();
            }
        }
    }

    /**
     * Asegura las cinco tablas tarif_* opcionales absorbidas desde tarifario
     * cuando el plugin tarifario NO está activo (instalación standalone).
     * Idempotente: require_once + fs_model solo crea la tabla si falta.
     * Orden FK-seguro, espejo del antiguo tarifario_init.php.
     */
    public static function ensureOpcionalesTarifaTables(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';

        // Destinos FK canónicos + clases que consumen los install() movidos.
        // catalogo_lista_precio MUST go first: catalogo_opcional::install()
        // instantiates it, so its class/table must already exist.
        self::touchNamespacedModel('catalogo_lista_precio');
        self::touchNamespacedModel('catalogo_opcional');
        self::touchNamespacedModel('catalogo_articulo_opcional');

        // tarif_tarifas debe existir antes de las tablas con FK.
        self::ensureFamiliasTarifaTables();

        // tarif_opcional (subclase de catalogo_opcional) lo consumen los install()
        // de las tablas movidas; se precarga desde el árbol de catalogo_core.
        $opcionalFile = FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional.php';
        if (is_file($opcionalFile)) {
            require_once $opcionalFile;
        }

        foreach ([                       // FK-safe order (spec: Standalone FK-safe table bootstrap)
            'tarif_opcional_ext',        // FK → catalogo_opcionales
            'tarif_tarifa_opcional_etiqueta',
            'tarif_opcional_precio_historial',
            'tarif_tarifa_opcional_familia',   // FK → tarif_tarifas, catalogo_opcionales, familias
            'tarif_tarifa_articulo_opcional',  // FK → tarif_tarifas, articulos, catalogo_opcionales
        ] as $modelName) {
            $file = FS_FOLDER . '/plugins/catalogo_core/model/' . $modelName . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            $fqcn = 'FSFramework\\model\\' . $modelName;
            if (class_exists($fqcn, false) && is_subclass_of($fqcn, \fs_model::class)) {
                new $fqcn();
            }
        }

        // Per-(tarifa, opcional) master: FK → tarif_tarifas + catalogo_opcionales,
        // both ensured above. Dedicated step after the moved-table loop (design
        // AD6) so the pinned 5-table FK_SAFE_SEQUENCE literal stays frozen.
        // fs_model ensures the table only when missing, so re-running is a no-op.
        $masterFile = FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa_opcional.php';
        if (is_file($masterFile)) {
            require_once $masterFile;
        }
        $masterFqcn = 'FSFramework\\model\\tarif_tarifa_opcional';
        if (class_exists($masterFqcn, false) && is_subclass_of($masterFqcn, \fs_model::class)) {
            new $masterFqcn();
        }
    }

    /**
     * Asegura la tabla tarif_articulo_precios (precio/estado por tarifa de un
     * artículo) absorbida desde tarifario cuando el plugin tarifario NO está
     * activo (instalación standalone). Idempotente: require_once + fs_model
     * solo crea la tabla si falta. tarif_tarifas se asegura primero porque
     * tarif_articulo_precio::install() la consume (AD-8).
     */
    public static function ensureArticuloTarifaTables(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';

        // tarif_tarifas debe existir antes de la tabla de precios (install()).
        self::ensureFamiliasTarifaTables();

        $file = FS_FOLDER . '/plugins/catalogo_core/model/tarif_articulo_precio.php';
        if (is_file($file)) {
            require_once $file;
        }

        $fqcn = 'FSFramework\\model\\tarif_articulo_precio';
        if (class_exists($fqcn, false) && is_subclass_of($fqcn, \fs_model::class)) {
            new $fqcn();
        }
    }

    /**
     * Asegura las tres tablas del detalle canónico de artículo absorbidas
     * desde tarifario por WU-2 (tarif_tarifa_articulo, su etiqueta y las
     * imágenes) cuando el plugin tarifario NO está activo (instalación
     * standalone). Idempotente: require_once + fs_model solo crea la tabla si
     * falta. Orden FK-seguro: tarif_tarifas primero, luego
     * tarif_tarifa_articulo → tarif_tarifa_articulo_etiqueta →
     * tarif_articulo_imagen (AD-W2-8). No modifica el cuerpo congelado de
     * ensureArticuloTarifaTables() (WU-1).
     */
    public static function ensureArticuloDetalleTables(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';

        // tarif_tarifas debe existir antes de las tablas con FK (install()).
        self::ensureFamiliasTarifaTables();

        $models = [
            FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa_articulo.php',
            FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa_articulo_etiqueta.php',
            FS_FOLDER . '/plugins/catalogo_core/model/tarif_articulo_imagen.php',
        ];

        foreach ($models as $file) {
            if (is_file($file)) {
                require_once $file;
            }

            $fqcn = 'FSFramework\\model\\' . basename($file, '.php');
            if (class_exists($fqcn, false) && is_subclass_of($fqcn, \fs_model::class)) {
                new $fqcn();
            }
        }
    }

    /**
     * Asegura las cinco tablas de características de producto (CAR-05)
     * cuando el plugin tarifario NO está activo o en una instalación fría.
     *
     * Idempotente: `fs_model` solo crea la tabla cuando falta. Orden FK-seguro
     * (design §9.1): definiciones → catálogo de valores → destinos FK
     * (articulos, familias, tarif_tarifas) → las tres tablas de ámbito.
     * No usa `seed_if_empty`: los defaults los registra `CaracteristicaRegistry`.
     */
    public static function ensureCaracteristicasTables(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';

        self::touchNamespacedModel('catalogo_caracteristica');
        self::touchNamespacedModel('catalogo_caracteristica_valor');
        self::touchNamespacedModel('articulo');
        self::touchNamespacedModel('familia');

        // tarif_tarifas debe existir antes de las tablas de ámbito con FK.
        self::ensureFamiliasTarifaTables();

        self::touchNamespacedModel('catalogo_caracteristica_global');
        self::touchNamespacedModel('catalogo_caracteristica_familia');
        self::touchNamespacedModel('catalogo_caracteristica_articulo');
    }

    /**
     * Retira la página plana ventas_familias (Amendment 1). Necesario porque
     * fs_user::get_menu() no filtra páginas muertas — una fila huérfana
     * renderizaría un item de menú roto. Idempotente: fs_page::get() devuelve
     * FALSE si la fila no existe; delete() ejecuta el DELETE y limpia la
     * caché m_fs_page_all.
     */
    private static function retireVentasFamiliasPage(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';
        if (!class_exists('fs_page', false)) {
            require_once FS_FOLDER . '/model/fs_page.php';
        }

        $page = new \fs_page();
        $existing = $page->get('ventas_familias');
        if ($existing !== false) {
            $existing->delete();
        }
    }

    /**
     * Retira la página plana tarif_opcionales, eliminada por la unificación
     * de opcionales en ventas_opcionales (OUM-09, AD-9). Sin alias de
     * redirección: fs_user::get_menu() no filtra páginas muertas, así que una
     * fila huérfana renderizaría un item de menú roto. Idempotente:
     * fs_page::get() devuelve FALSE si la fila no existe; delete() ejecuta el
     * DELETE y limpia la caché m_fs_page_all.
     */
    private static function retireTarifOpcionalesPage(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';
        if (!class_exists('fs_page', false)) {
            require_once FS_FOLDER . '/model/fs_page.php';
        }

        $page = new \fs_page();
        $existing = $page->get('tarif_opcionales');
        if ($existing !== false) {
            $existing->delete();
        }
    }

    /**
     * Retira la página tarif_opcional_precios, absorbida por
     * tarif_opcional_edit y eliminada sin alias (OUM-11, AD-11). Sin alias de
     * redirección: fs_user::get_menu() no filtra páginas muertas, así que una
     * fila huérfana renderizaría un item de menú roto. Idempotente:
     * fs_page::get() devuelve FALSE si la fila no existe; delete() ejecuta el
     * DELETE y limpia la caché m_fs_page_all.
     */
    private static function retireTarifOpcionalPreciosPage(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';
        if (!class_exists('fs_page', false)) {
            require_once FS_FOLDER . '/model/fs_page.php';
        }

        $page = new \fs_page();
        $existing = $page->get('tarif_opcional_precios');
        if ($existing !== false) {
            $existing->delete();
        }
    }

    /**
     * Retira la página tarif_articulo_edit, absorbida por el detalle canónico
     * ventas_articulo y eliminada sin alias (ART-06, AD-W2-5/W2-6). Sin alias
     * de redirección: fs_user::get_menu() no filtra páginas muertas, así que
     * una fila huérfana renderizaría un item de menú roto. Idempotente:
     * fs_page::get() devuelve FALSE si la fila no existe; delete() ejecuta el
     * DELETE y limpia la caché m_fs_page_all.
     */
    private static function retireTarifArticuloEditPage(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';
        if (!class_exists('fs_page', false)) {
            require_once FS_FOLDER . '/model/fs_page.php';
        }

        $page = new \fs_page();
        $existing = $page->get('tarif_articulo_edit');
        if ($existing !== false) {
            $existing->delete();
        }
    }

    /**
     * Retira la página tarif_articulo_precios, absorbida por el detalle
     * canónico ventas_articulo y eliminada sin alias (ART-06, AD-W2-5/W2-6).
     * Sin alias de redirección: fs_user::get_menu() no filtra páginas muertas,
     * así que una fila huérfana renderizaría un item de menú roto. Idempotente:
     * fs_page::get() devuelve FALSE si la fila no existe; delete() ejecuta el
     * DELETE y limpia la caché m_fs_page_all.
     */
    private static function retireTarifArticuloPreciosPage(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';
        if (!class_exists('fs_page', false)) {
            require_once FS_FOLDER . '/model/fs_page.php';
        }

        $page = new \fs_page();
        $existing = $page->get('tarif_articulo_precios');
        if ($existing !== false) {
            $existing->delete();
        }
    }

    /**
     * Retira la página tarif_articulos, absorbida por el listado canónico
     * ventas_articulos y eliminada sin alias (ALC-06, AD-W3-7). Sin alias de
     * redirección: fs_user::get_menu() no filtra páginas muertas, así que una
     * fila huérfana renderizaría un item de menú roto. Idempotente:
     * fs_page::get() devuelve FALSE si la fila no existe; delete() ejecuta el
     * DELETE y limpia la caché m_fs_page_all.
     */
    private static function retireTarifArticulosPage(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';
        if (!class_exists('fs_page', false)) {
            require_once FS_FOLDER . '/model/fs_page.php';
        }

        $page = new \fs_page();
        $existing = $page->get('tarif_articulos');
        if ($existing !== false) {
            $existing->delete();
        }
    }

    private static function migrateLegacyTables(): void
    {
        if (!class_exists('\FSFramework\DependencyInjection\Container', false)) {
            return;
        }

        try {
            $db = \FSFramework\DependencyInjection\Container::db();
            CatalogLegacyTableMigration::migrateIfNeeded($db);
        } catch (\Throwable $e) {
            error_log('[catalogo_core] Legacy table migration failed: ' . $e->getMessage());
        }
    }

    /**
     * DB seam for the boot migrations. Returns null when the container (or a
     * live connection) is unavailable so a cold/failed boot never fatals.
     */
    private static function plugin_db(): ?\fs_db2
    {
        if (!class_exists('\FSFramework\DependencyInjection\Container', false)) {
            return null;
        }

        try {
            return \FSFramework\DependencyInjection\Container::db();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] DB unavailable for a boot migration: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Migra ref_sap hacia tarif_opcional_ext. Absorbido desde
     * tarifario/Init.php::migrateOpcionalExtension() junto con el modelo.
     *
     * `en_catalogo`/`en_tarifa` are no longer copied: the opcional-owned
     * visibility flags were removed by D12 (`caracteristicas-producto` CAR-12 /
     * CAR-15 clause 1) and are derived from the parent product.
     */
    private static function migrateOpcionalExtension(): void
    {
        if (!class_exists('\FSFramework\DependencyInjection\Container', false)) {
            return;
        }

        try {
            $db = \FSFramework\DependencyInjection\Container::db();
            TarifOpcionalExtMigration::migrateIfNeeded($db);
        } catch (\Throwable $e) {
            error_log('[catalogo_core] Opcional extension migration failed: ' . $e->getMessage());
        }
    }

    private static function ensureArticuloOpcionalGrupoTable(): void
    {
        if (!class_exists('\FSFramework\DependencyInjection\Container', false)) {
            return;
        }

        try {
            self::touchNamespacedModel('catalogo_articulo_opcional_grupo');
        } catch (\Throwable $e) {
            error_log('[catalogo_core] articulo_opcional_grupo table ensure failed: ' . $e->getMessage());
        }
    }

    private static function ensureCatalogTables(): void
    {
        foreach ([
            'catalogo_idioma',
            'articulo_descripcion',
            'catalogo_lista_precio',
            'catalogo_opcional',
            'catalogo_opcional_familia',
            'catalogo_articulo_opcional',
            'catalogo_opcional_precio',
            'catalogo_opcional_grupo',
            'catalogo_articulo_opcional_grupo',
        ] as $modelName) {
            self::touchNamespacedModel($modelName);
        }
    }

    private static function touchNamespacedModel(string $modelName): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';

        $fqcn = 'FSFramework\\model\\' . $modelName;

        if (!class_exists($fqcn, false)) {
            $file = FS_FOLDER . '/plugins/catalogo_core/model/core/' . $modelName . '.php';
            if (!is_file($file)) {
                return;
            }

            require_once $file;
        }

        if (class_exists($fqcn, false) && is_subclass_of($fqcn, \fs_model::class)) {
            new $fqcn();
        }
    }

    private static function seedNamespacedModel(string $modelName): void
    {
        $fqcn = 'FSFramework\\model\\' . $modelName;

        if (!class_exists($fqcn, false)) {
            $file = FS_FOLDER . '/plugins/catalogo_core/model/core/' . $modelName . '.php';
            if (!is_file($file)) {
                return;
            }

            require_once $file;
        }

        if (!class_exists($fqcn, false) || !is_subclass_of($fqcn, \fs_model::class)) {
            return;
        }

        $model = new $fqcn();
        $model->seed_if_empty();
    }

    private function cleanupOrphanWizardFiles(): void
    {
        $tmpDir = rtrim(FS_FOLDER, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($tmpDir)) {
            return;
        }

        $now = time();
        foreach (glob($tmpDir . '/catalogo_excel_wizard_*.xlsx') ?: [] as $path) {
            if (is_file($path) && ($now - filemtime($path)) > self::WIZARD_TMP_TTL) {
                @unlink($path);
            }
        }
    }
}
