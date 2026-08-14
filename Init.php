<?php
/**
 * Plugin initialization for catalogo_core.
 */
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core;

final class Init
{
    private const WIZARD_TMP_TTL = 86400;

    /** @var list<string> Modelos PSR-4 con install() que deben sembrarse al activar. */
    private const DEFAULT_SEED_MODELS = [
        'impuesto',
        'familia',
        'fabricante',
    ];

    public function init(): void
    {
        $this->cleanupOrphanWizardFiles();
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
            foreach (self::DEFAULT_SEED_MODELS as $modelName) {
                self::seedNamespacedModel($modelName);
            }
        } catch (\Throwable $e) {
            error_log('[catalogo_core] Default seed failed: ' . $e->getMessage());
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
