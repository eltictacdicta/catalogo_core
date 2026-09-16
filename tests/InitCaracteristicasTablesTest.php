<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for Init::ensureCaracteristicasTables() (CAR-05).
 *
 * DB-free: pins the FK-safe model sequence, the on-disk model/XML presence,
 * the class guard structure and the Init wiring. The live create path is
 * covered by the verify-phase smoke.
 */
final class InitCaracteristicasTablesTest extends TestCase
{
    /** FK-safe creation order (design §9.1). */
    private const FK_SAFE_SEQUENCE = [
        'catalogo_caracteristica',
        'catalogo_caracteristica_valor',
        'articulo',
        'familia',
        'self::ensureFamiliasTarifaTables()',
        'catalogo_caracteristica_global',
        'catalogo_caracteristica_familia',
        'catalogo_caracteristica_articulo',
    ];

    private const XML_BY_MODEL = [
        'catalogo_caracteristica' => 'catalogo_caracteristicas.xml',
        'catalogo_caracteristica_valor' => 'catalogo_caracteristica_valores.xml',
        'catalogo_caracteristica_global' => 'catalogo_caracteristica_global.xml',
        'catalogo_caracteristica_familia' => 'catalogo_caracteristica_familia.xml',
        'catalogo_caracteristica_articulo' => 'catalogo_caracteristica_articulo.xml',
    ];

    private function initSource(): string
    {
        $path = FS_FOLDER . '/plugins/catalogo_core/Init.php';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function methodSource(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            self::fail('missing catalogo_core method: ' . $signature);
        }

        $open = (int) strpos($src, '{', (int) $start);
        $depth = 0;
        $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, (int) $start, $i - $start + 1);
                }
            }
        }

        self::fail('method body is not terminated: ' . $signature);
    }

    public function test_bootstrap_creates_models_in_fk_safe_order(): void
    {
        $method = $this->methodSource(
            $this->initSource(),
            'public static function ensureCaracteristicasTables()'
        );

        $sequence = [];
        preg_match_all(
            "/touchNamespacedModel\('([a-z_]+)'\)|(self::ensureFamiliasTarifaTables\(\))/",
            $method,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            $sequence[] = $match[2] ?? $match[1];
        }

        $this->assertSame(
            self::FK_SAFE_SEQUENCE,
            $sequence,
            'the bootstrap must touch the models in FK-safe order'
        );
    }

    public function test_bootstrap_models_and_xmls_exist(): void
    {
        foreach (self::XML_BY_MODEL as $model => $xml) {
            $this->assertFileExists(
                FS_FOLDER . '/plugins/catalogo_core/model/core/' . $model . '.php',
                'Model ' . $model . '.php must exist'
            );
            $this->assertFileExists(
                FS_FOLDER . '/plugins/catalogo_core/model/table/' . $xml,
                'XML ' . $xml . ' must exist'
            );
        }
    }

    public function test_bootstrap_is_guarded_and_idempotent_by_design(): void
    {
        $method = $this->methodSource(
            $this->initSource(),
            'public static function ensureCaracteristicasTables()'
        );

        // Delegates to the shared guarded loader: is_file + class_exists + subclass check.
        $this->assertStringContainsString('touchNamespacedModel(', $method);
        $this->assertStringContainsString('ensureFamiliasTarifaTables()', $method, 'tarif_tarifas must exist before FK tables');
        $this->assertStringNotContainsString('seed_if_empty', $method, 'no feature table uses seed_if_empty');
    }

    public function test_init_wires_the_bootstrap(): void
    {
        $src = $this->initSource();
        $init = $this->methodSource($src, 'public function init(): void');

        $posDetalle = strpos($init, 'self::ensureArticuloDetalleTables();');
        $posCaracteristicas = strpos($init, 'self::ensureCaracteristicasTables();');
        $this->assertNotFalse($posCaracteristicas, 'init() must ensure the feature tables');
        $this->assertNotFalse($posDetalle);
        $this->assertLessThan($posCaracteristicas, $posDetalle, 'the feature ensure runs after the existing ones');
    }

    public function test_upgrade_wires_the_bootstrap(): void
    {
        $upgrade = $this->methodSource($this->initSource(), 'public static function upgrade(): void');

        $this->assertStringContainsString('self::ensureCaracteristicasTables();', $upgrade);
    }

    public function test_default_seed_models_are_not_extended(): void
    {
        $src = $this->initSource();
        $start = strpos($src, 'DEFAULT_SEED_MODELS = [');
        $this->assertNotFalse($start);
        $end = strpos($src, '];', (int) $start);
        $this->assertNotFalse($end);
        $const = substr($src, (int) $start, (int) $end - (int) $start);

        foreach (array_keys(self::XML_BY_MODEL) as $model) {
            $this->assertStringNotContainsString($model, $const, 'feature models must not enter DEFAULT_SEED_MODELS');
        }
    }
}
