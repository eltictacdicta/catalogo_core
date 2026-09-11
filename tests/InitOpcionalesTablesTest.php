<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for Init::ensureOpcionalesTarifaTables() — the standalone
 * bootstrap that creates the five moved tarif_* opcional tables when tarifario
 * is inactive (spec: "Standalone FK-safe table bootstrap").
 *
 * DB-free by design: no fs_model instantiation happens here (that requires a
 * real database); the live create path is covered by the verify-phase smoke.
 * These tests pin the FK-safe model sequence, the on-disk model/XML presence,
 * the guard structure, the canonical-dependency touch order, and the Init
 * wiring.
 */
final class InitOpcionalesTablesTest extends TestCase
{
    /** FK-safe creation order (spec: "Standalone FK-safe table bootstrap"). */
    private const FK_SAFE_SEQUENCE = [
        'tarif_opcional_ext',
        'tarif_tarifa_opcional_etiqueta',
        'tarif_opcional_precio_historial',
        'tarif_tarifa_opcional_familia',
        'tarif_tarifa_articulo_opcional',
    ];

    /** XML schema file for each bootstrapped model. */
    private const XML_BY_MODEL = [
        'tarif_opcional_ext' => 'tarif_opcional_ext.xml',
        'tarif_tarifa_opcional_etiqueta' => 'tarif_tarifa_opcional_etiqueta.xml',
        'tarif_opcional_precio_historial' => 'tarif_opcional_precio_historial.xml',
        'tarif_tarifa_opcional_familia' => 'tarif_tarifa_opcional_familia.xml',
        'tarif_tarifa_articulo_opcional' => 'tarif_tarifa_articulo_opcional.xml',
    ];

    private function initSource(): string
    {
        $path = FS_FOLDER . '/plugins/catalogo_core/Init.php';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** Extracts a method body by brace matching from its signature. */
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

    /** Extracts the full body of Init::ensureOpcionalesTarifaTables() by brace matching. */
    private function ensureMethodSource(string $src): string
    {
        return $this->methodSource($src, 'public static function ensureOpcionalesTarifaTables()');
    }

    /** Extracts the FK-safe model list from the bootstrap's foreach literal. */
    private function bootstrapModelList(): array
    {
        $method = $this->ensureMethodSource($this->initSource());
        if (!preg_match('/foreach\s*\(\s*\[(.*?)\]\s*as\s+\$modelName\s*\)/s', $method, $m)) {
            self::fail('ensureOpcionalesTarifaTables() does not iterate a model-name list');
        }
        preg_match_all("/'([a-z_]+)'/", $m[1], $names);

        return $names[1];
    }

    public function test_bootstrap_model_list_matches_fk_safe_sequence(): void
    {
        $this->assertSame(
            self::FK_SAFE_SEQUENCE,
            $this->bootstrapModelList(),
            'The bootstrap must instantiate the moved models in FK-safe order'
        );
    }

    public function test_listed_models_and_xmls_exist_at_catalogo_core_paths(): void
    {
        foreach ($this->bootstrapModelList() as $model) {
            $this->assertFileExists(
                FS_FOLDER . '/plugins/catalogo_core/model/' . $model . '.php',
                'Moved model ' . $model . '.php must exist in catalogo_core'
            );
            $this->assertArrayHasKey($model, self::XML_BY_MODEL, 'No XML mapping for ' . $model);
            $this->assertFileExists(
                FS_FOLDER . '/plugins/catalogo_core/model/table/' . self::XML_BY_MODEL[$model],
                'Moved XML ' . self::XML_BY_MODEL[$model] . ' must exist in catalogo_core'
            );
        }
    }

    public function test_ensure_method_guards_instantiation_behind_class_checks(): void
    {
        $method = $this->ensureMethodSource($this->initSource());

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*is_file\(\$file\s*\)\s*\)\s*\{\s*require_once\s+\$file;\s*\}/',
            $method,
            'Model requires must be guarded by is_file'
        );
        $this->assertMatchesRegularExpression('/class_exists\(\$fqcn,\s*false\)/', $method);
        $this->assertMatchesRegularExpression('/is_subclass_of\(\$fqcn,\s*\\\\fs_model::class\)/', $method);

        $posGuard = strpos($method, 'class_exists($fqcn, false)');
        $posNew = strpos($method, 'new $fqcn()');
        $this->assertNotFalse($posGuard);
        $this->assertNotFalse($posNew);
        $this->assertLessThan($posNew, $posGuard, 'Instantiation must be guarded by the class_exists check');
    }

    /**
     * catalogo_opcional::install() instantiates catalogo_lista_precio, so the
     * bootstrap must touch catalogo_lista_precio BEFORE catalogo_opcional; the
     * articulo relation comes last. Ordering fix folded into task 1.10 (the
     * pre-fix order fataled on an empty standalone DB).
     */
    public function test_bootstrap_touches_canonical_dependencies_in_fk_safe_order(): void
    {
        $method = $this->ensureMethodSource($this->initSource());

        $posLista = strpos($method, "touchNamespacedModel('catalogo_lista_precio')");
        $posOpcional = strpos($method, "touchNamespacedModel('catalogo_opcional')");
        $posArticulo = strpos($method, "touchNamespacedModel('catalogo_articulo_opcional')");

        $this->assertNotFalse($posLista, 'The bootstrap must touch catalogo_lista_precio');
        $this->assertNotFalse($posOpcional, 'The bootstrap must touch catalogo_opcional');
        $this->assertNotFalse($posArticulo, 'The bootstrap must touch catalogo_articulo_opcional');
        $this->assertLessThan(
            $posOpcional,
            $posLista,
            'catalogo_lista_precio must be touched before catalogo_opcional (catalogo_opcional::install() consumes it)'
        );
        $this->assertLessThan(
            $posArticulo,
            $posOpcional,
            'catalogo_opcional must be touched before catalogo_articulo_opcional'
        );
    }

    public function test_init_wires_bootstrap_after_articulo_opcional_grupo(): void
    {
        $src = $this->initSource();
        $init = $this->methodSource($src, 'public function init(): void');

        $posGrupo = strpos($init, 'self::ensureArticuloOpcionalGrupoTable();');
        $posOpcionales = strpos($init, 'self::ensureOpcionalesTarifaTables();');

        $this->assertNotFalse($posGrupo, 'init() must ensure the articulo_opcional_grupo table');
        $this->assertNotFalse($posOpcionales, 'init() must wire the opcionales bootstrap');
        $this->assertLessThan(
            $posOpcionales,
            $posGrupo,
            'The opcionales bootstrap must run after ensureArticuloOpcionalGrupoTable()'
        );
    }

    public function test_upgrade_wires_bootstrap(): void
    {
        $upgrade = $this->methodSource($this->initSource(), 'public static function upgrade(): void');

        $this->assertStringContainsString(
            'self::ensureOpcionalesTarifaTables();',
            $upgrade,
            'upgrade() must wire the opcionales bootstrap (sibling of ensureFamiliasTarifaTables)'
        );
    }

    public function test_init_owns_the_opcional_extension_migration(): void
    {
        $src = $this->initSource();

        $this->assertStringContainsString(
            'use FSFramework\\Plugins\\catalogo_core\\Services\\TarifOpcionalExtMigration;',
            $src,
            'catalogo_core Init must own the moved extension migration service'
        );
        $this->assertStringContainsString(
            'TarifOpcionalExtMigration::migrateIfNeeded(',
            $src,
            'Init must invoke the opcional extension migration (after migrateLegacyTables)'
        );
        $this->assertStringNotContainsString(
            'FSFramework\\Plugins\\tarifario\\Services\\TarifOpcionalExtMigration',
            $src,
            'Init must not reference the old tarifario service namespace'
        );
    }
}
