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
 * Contract tests for Init::ensureFamiliasTarifaTables() — the standalone
 * bootstrap that creates the four familias-tarifa tables when tarifario is
 * inactive (spec: "Standalone table bootstrap").
 *
 * DB-free by design: no fs_model instantiation happens here (that requires a
 * real database); the live create path is covered by the verify-phase smoke.
 * These tests pin the FK-safe model sequence, the on-disk model/XML presence,
 * the guard structure, and the no-op safety when model files are absent.
 */
final class InitFamiliasTablesTest extends TestCase
{
    /** FK-safe creation order (mirrors tarifario_init.php). */
    private const FK_SAFE_SEQUENCE = [
        'tarif_tarifa',
        'tarif_familia_ext',
        'tarif_tarifa_etiqueta_familia',
        'tarif_tarifa_familia',
    ];

    /** XML schema file for each bootstrapped model. */
    private const XML_BY_MODEL = [
        'tarif_tarifa' => 'tarif_tarifas.xml',
        'tarif_familia_ext' => 'tarif_familia_ext.xml',
        'tarif_tarifa_etiqueta_familia' => 'tarif_tarifa_etiqueta_familia.xml',
        'tarif_tarifa_familia' => 'tarif_tarifa_familia.xml',
    ];

    private function initSource(): string
    {
        $path = FS_FOLDER . '/plugins/catalogo_core/Init.php';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** Extracts the full body of Init::ensureFamiliasTarifaTables() by brace matching. */
    private function ensureMethodSource(string $src): string
    {
        $sig = 'public static function ensureFamiliasTarifaTables()';
        $start = strpos($src, $sig);
        if ($start === false) {
            self::fail('missing catalogo_core method: Init::ensureFamiliasTarifaTables()');
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

        self::fail('ensureFamiliasTarifaTables() body is not terminated');
    }

    /** Extracts the FK-safe model list from the bootstrap's foreach literal. */
    private function bootstrapModelList(): array
    {
        $method = $this->ensureMethodSource($this->initSource());
        if (!preg_match('/foreach\s*\(\s*\[(.*?)\]\s*as\s+\$modelName\s*\)/s', $method, $m)) {
            self::fail('ensureFamiliasTarifaTables() does not iterate a model-name list');
        }
        preg_match_all("/'([a-z_]+)'/", $m[1], $names);

        return $names[1];
    }

    public function test_bootstrap_model_list_matches_fk_safe_sequence(): void
    {
        $this->assertSame(
            self::FK_SAFE_SEQUENCE,
            $this->bootstrapModelList(),
            'The bootstrap must instantiate the models in FK-safe order'
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

    public function test_noop_when_model_files_absent(): void
    {
        $first = FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';
        if (is_file($first)) {
            $this->markTestSkipped(
                'Model files present in shipped tree; live create path is the '
                . 'verify-phase smoke per design (no fs_model instantiation in unit tests)'
            );

            return;
        }

        require_once FS_FOLDER . '/plugins/catalogo_core/Init.php';
        \FSFramework\Plugins\catalogo_core\Init::ensureFamiliasTarifaTables();

        foreach (self::FK_SAFE_SEQUENCE as $model) {
            $this->assertFalse(
                class_exists('FSFramework\\model\\' . $model, false),
                'Absent model files must not define ' . $model
            );
        }
    }
}
