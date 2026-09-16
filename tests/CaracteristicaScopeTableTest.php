<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the three scope tables (CAR-05).
 *
 * Layer 1: XML contract — PK, unique scope key and cascading FKs.
 * Layer 2: model contract — `scope()`, `key_columns()` and `key_values()`
 * exposed DB-free through anonymous subclasses.
 */
final class CaracteristicaScopeTableTest extends TestCase
{
    /** @var array<string, array{pk: string, extra_fk: string}> */
    private const SCOPES = [
        'global' => [
            'xml' => 'catalogo_caracteristica_global.xml',
            'pk' => 'PRIMARY KEY\s*\(\s*codtarifa\s*,\s*id_caracteristica\s*\)',
            'extra_fk' => '',
        ],
        'familia' => [
            'xml' => 'catalogo_caracteristica_familia.xml',
            'pk' => 'PRIMARY KEY\s*\(\s*codtarifa\s*,\s*codfamilia\s*,\s*id_caracteristica\s*\)',
            'extra_fk' => 'FOREIGN KEY\s*\(\s*codfamilia\s*\)\s*REFERENCES\s+familias\s*\(\s*codfamilia\s*\)\s*'
                . 'ON DELETE CASCADE ON UPDATE CASCADE',
        ],
        'articulo' => [
            'xml' => 'catalogo_caracteristica_articulo.xml',
            'pk' => 'PRIMARY KEY\s*\(\s*codtarifa\s*,\s*referencia\s*,\s*id_caracteristica\s*\)',
            'extra_fk' => 'FOREIGN KEY\s*\(\s*referencia\s*\)\s*REFERENCES\s+articulos\s*\(\s*referencia\s*\)\s*'
                . 'ON DELETE CASCADE ON UPDATE CASCADE',
        ],
    ];

    private static bool $baseLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_valor.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/caracteristica_scope_value.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_global.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_familia.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_articulo.php';
        self::$baseLoaded = true;
    }

    public function test_keys_and_cascades_resolve(): void
    {
        foreach (self::SCOPES as $scope => $spec) {
            $path = FS_FOLDER . '/plugins/catalogo_core/model/table/' . $spec['xml'];
            $this->assertFileExists($path, $scope . ' XML must exist');
            $xml = (string) file_get_contents($path);

            $this->assertMatchesRegularExpression('/' . $spec['pk'] . '/i', $xml, $scope . ' PK mismatch');
            $this->assertMatchesRegularExpression(
                '/FOREIGN KEY\s*\(\s*codtarifa\s*\)\s*REFERENCES\s+tarif_tarifas\s*\(\s*codtarifa\s*\)\s*'
                . 'ON DELETE CASCADE ON UPDATE CASCADE/i',
                $xml,
                $scope . ' codtarifa must cascade from tarif_tarifas'
            );
            $this->assertMatchesRegularExpression(
                '/FOREIGN KEY\s*\(\s*id_caracteristica\s*\)\s*REFERENCES\s+catalogo_caracteristicas\s*\(\s*id\s*\)\s*'
                . 'ON DELETE CASCADE ON UPDATE CASCADE/i',
                $xml,
                $scope . ' id_caracteristica must cascade from catalogo_caracteristicas'
            );
            $this->assertMatchesRegularExpression(
                '/FOREIGN KEY\s*\(\s*id_valor\s*\)\s*REFERENCES\s+catalogo_caracteristica_valores\s*\(\s*id\s*\)\s*'
                . 'ON DELETE CASCADE ON UPDATE CASCADE/i',
                $xml,
                $scope . ' id_valor must cascade from catalogo_caracteristica_valores'
            );

            if ($spec['extra_fk'] !== '') {
                $this->assertMatchesRegularExpression('/' . $spec['extra_fk'] . '/i', $xml, $scope . ' FK mismatch');
            }
        }
    }

    public function test_deleting_a_product_only_cascades_its_own_rows(): void
    {
        // The articulo scope table cascades from `articulos(referencia)`, so a
        // product delete removes only that product's value rows; the FK is the
        // executable proof that product B's rows survive.
        $xml = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/table/catalogo_caracteristica_articulo.xml'
        );

        $this->assertMatchesRegularExpression(
            '/FOREIGN KEY\s*\(\s*referencia\s*\)\s*REFERENCES\s+articulos\s*\(\s*referencia\s*\)\s*'
            . 'ON DELETE CASCADE ON UPDATE CASCADE/i',
            $xml
        );
        $this->assertStringNotContainsString('ON DELETE SET NULL', $xml);
    }

    public function test_scope_models_expose_their_scope_and_key_columns(): void
    {
        $global = new \FSFramework\model\catalogo_caracteristica_global();
        $this->assertSame('global', $global->scope());
        $this->assertSame([], $global->key_columns());
        $this->assertSame([], $global->key_values());

        $familia = new \FSFramework\model\catalogo_caracteristica_familia();
        $this->assertSame('familia', $familia->scope());
        $this->assertSame(['codfamilia'], $familia->key_columns());
        $familia->codfamilia = 'F1';
        $this->assertSame(['F1'], $familia->key_values());

        $articulo = new \FSFramework\model\catalogo_caracteristica_articulo();
        $this->assertSame('articulo', $articulo->scope());
        $this->assertSame(['referencia'], $articulo->key_columns());
        $articulo->referencia = 'REF-1';
        $this->assertSame(['REF-1'], $articulo->key_values());
    }
}
