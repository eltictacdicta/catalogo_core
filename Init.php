<?php
/**
 * Plugin initialization for catalogo_core.
 */
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core;

use FSFramework\Plugins\catalogo_core\Services\CatalogLegacyTableMigration;

final class Init
{
    private const WIZARD_TMP_TTL = 86400;

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
        self::ensureArticuloOpcionalGrupoTable();
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
            self::ensureCatalogTables();
            foreach (self::DEFAULT_SEED_MODELS as $modelName) {
                self::seedNamespacedModel($modelName);
            }
        } catch (\Throwable $e) {
            error_log('[catalogo_core] Default seed failed: ' . $e->getMessage());
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
