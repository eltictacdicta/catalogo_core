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
 * Ownership contract for the 8 opcional-domain models absorbed from tarifario
 * (spec: "Opcional domain models owned by catalogo_core").
 *
 * DB-free by design: the model files are required (never instantiated, which
 * would hit the database) and their namespace + table_name source contracts
 * are pinned. `tarif_opcional` inherits its canonical table from
 * `catalogo_opcional`, so it is asserted through its base class.
 */
final class OpcionalDomainModelOwnershipTest extends TestCase
{
    /**
     * model => [FQCN, catalogo_core relative path, expected table_name, canonical base or null]
     *
     * @var array<string, array{string, string, string, string|null}>
     */
    private const MODELS = [
        'tarif_opcional' => [
            'FSFramework\\model\\tarif_opcional',
            'plugins/catalogo_core/model/tarif_opcional.php',
            'catalogo_opcionales',
            'catalogo_opcional',
        ],
        'tarif_opcional_ext' => [
            'FSFramework\\model\\tarif_opcional_ext',
            'plugins/catalogo_core/model/tarif_opcional_ext.php',
            'tarif_opcional_ext',
            null,
        ],
        'tarif_opcional_precio' => [
            'FSFramework\\model\\tarif_opcional_precio',
            'plugins/catalogo_core/model/tarif_opcional_precio.php',
            'catalogo_opcional_precios',
            'catalogo_opcional_precio',
        ],
        'tarif_opcional_precio_historial' => [
            'FSFramework\\model\\tarif_opcional_precio_historial',
            'plugins/catalogo_core/model/tarif_opcional_precio_historial.php',
            'tarif_opcional_precio_historial',
            null,
        ],
        'tarif_tarifa_opcional_etiqueta' => [
            'FSFramework\\model\\tarif_tarifa_opcional_etiqueta',
            'plugins/catalogo_core/model/tarif_tarifa_opcional_etiqueta.php',
            'tarif_tarifa_opcional_etiqueta',
            null,
        ],
        'tarif_tarifa_opcional_familia' => [
            'FSFramework\\model\\tarif_tarifa_opcional_familia',
            'plugins/catalogo_core/model/tarif_tarifa_opcional_familia.php',
            'tarif_tarifa_opcional_familia',
            null,
        ],
        'tarif_tarifa_articulo_opcional' => [
            'FSFramework\\model\\tarif_tarifa_articulo_opcional',
            'plugins/catalogo_core/model/tarif_tarifa_articulo_opcional.php',
            'tarif_tarifa_articulo_opcional',
            null,
        ],
        'tarif_tarifa_opcional_resolver' => [
            'FSFramework\\model\\tarif_tarifa_opcional_resolver',
            'plugins/catalogo_core/model/tarif_tarifa_opcional_resolver.php',
            'tarif_tarifa_opcional_familia',
            null,
        ],
    ];

    private function source(string $relative): string
    {
        $path = FS_FOLDER . '/' . $relative;
        if (!is_file($path)) {
            self::fail('missing catalogo_core path: ' . $relative);
        }

        return (string) file_get_contents($path);
    }

    public function test_every_moved_model_lives_in_catalogo_core(): void
    {
        foreach (self::MODELS as $model => [$fqcn, $relative]) {
            $this->assertFileExists(
                FS_FOLDER . '/' . $relative,
                'Moved model ' . $model . ' must exist in catalogo_core'
            );
            $this->assertFileDoesNotExist(
                FS_FOLDER . '/plugins/tarifario/model/' . $model . '.php',
                'Moved model ' . $model . ' must no longer live in tarifario'
            );
        }
    }

    public function test_moved_model_files_declare_original_namespace_and_class(): void
    {
        foreach (self::MODELS as $model => [$fqcn, $relative]) {
            $src = $this->source($relative);

            $this->assertStringContainsString(
                'namespace FSFramework\\model;',
                $src,
                'Moved model ' . $model . ' must keep the FSFramework\\model namespace'
            );
            $this->assertMatchesRegularExpression(
                '/class\s+' . preg_quote($model, '/') . '\b/',
                $src,
                'Moved model ' . $model . ' must keep its class name'
            );
        }
    }

    public function test_moved_model_classes_resolve_from_catalogo_core(): void
    {
        foreach (self::MODELS as $model => [$fqcn, $relative]) {
            if (!is_file(FS_FOLDER . '/' . $relative)) {
                self::fail('missing catalogo_core path/class: ' . $relative);
            }

            require_once FS_FOLDER . '/' . $relative;

            $this->assertTrue(
                class_exists($fqcn, false),
                'Class ' . $fqcn . ' must be defined by ' . $relative
            );
            $this->assertTrue(
                is_subclass_of($fqcn, \fs_model::class),
                'Class ' . $fqcn . ' must extend fs_model'
            );
        }
    }

    public function test_moved_models_keep_their_table_names(): void
    {
        foreach (self::MODELS as $model => [$fqcn, $relative, $table, $base]) {
            $src = $this->source($relative);

            if ($base === null) {
                $this->assertMatchesRegularExpression(
                    "/parent::__construct\('" . preg_quote($table, '/') . "'\)/",
                    $src,
                    'Moved model ' . $model . ' must keep table_name ' . $table
                );

                continue;
            }

            $this->assertMatchesRegularExpression(
                '/class\s+' . preg_quote($model, '/') . '\s+extends\s+' . preg_quote($base, '/') . '\b/',
                $src,
                'Moved model ' . $model . ' must extend the canonical ' . $base
            );

            $baseRelative = 'plugins/catalogo_core/model/core/' . $base . '.php';
            $baseSrc = $this->source($baseRelative);
            $this->assertMatchesRegularExpression(
                "/(TABLE\s*=\s*'|parent::__construct\(')" . preg_quote($table, '/') . "'/",
                $baseSrc,
                'Canonical ' . $base . ' must own table ' . $table
            );
        }
    }

    public function test_tarifario_ext_columns_are_not_promoted_into_catalogo_opcionales(): void
    {
        $extXml = $this->source('plugins/catalogo_core/model/table/tarif_opcional_ext.xml');
        $this->assertStringContainsString(
            '<nombre>ref_sap</nombre>',
            $extXml,
            'tarif_opcional_ext must own ref_sap'
        );

        // D12 / CAR-15 clause 1: the opcional-owned visibility flags were
        // removed from the 1:1 ext table (derived from the parent product).
        foreach (['en_catalogo', 'en_tarifa'] as $removed) {
            $this->assertStringNotContainsString(
                '<nombre>' . $removed . '</nombre>',
                $extXml,
                'tarif_opcional_ext must no longer own ' . $removed
            );
        }

        $canonicalXml = $this->source('plugins/catalogo_core/model/table/catalogo_opcionales.xml');
        foreach (['ref_sap', 'codigo2', 'en_catalogo', 'en_tarifa'] as $column) {
            $this->assertStringNotContainsString(
                '<nombre>' . $column . '</nombre>',
                $canonicalXml,
                'catalogo_opcionales must stay untouched by tarifario-only columns'
            );
        }

        $tarifOpcional = $this->source('plugins/catalogo_core/model/tarif_opcional.php');
        $this->assertStringContainsString(
            "protected \$ext_table = 'tarif_opcional_ext';",
            $tarifOpcional,
            'tarif_opcional must read its extension columns from the 1:1 ext table'
        );
    }
}
