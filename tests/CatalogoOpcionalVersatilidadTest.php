<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\model\catalogo_opcional;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';

/**
 * DB-free contract tests for the `cantidad_max` and `imagen` opcional columns
 * (OVE-01/OVE-03): default 1, below-range rejection, bare-filename store and
 * the `imagen_url()` accessor.
 */
final class CatalogoOpcionalVersatilidadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDbFreeConstruction();
    }

    /**
     * Mark the opcional table as already checked and seed the core log so the
     * real constructor runs its hydration without opening a database
     * connection (fs_model skips check_table for a checked table).
     */
    private function prepareDbFreeConstruction(): void
    {
        $ref = new \ReflectionClass(\fs_model::class);

        $checked = $ref->getProperty('checked_tables');
        $checked->setAccessible(true);
        $checked->setValue(null, ['catalogo_opcionales']);

        $log = $ref->getProperty('core_log');
        $log->setAccessible(true);
        if ($log->getValue() === null) {
            $log->setValue(null, new \fs_core_log());
        }
    }

    /**
     * Hydrating double that skips the DB-backed constructor and collects the
     * reported errors instead of touching the core log.
     *
     * @param array<string, mixed> $data
     */
    private function makeOpcional(array $data = []): catalogo_opcional
    {
        return new class($data) extends catalogo_opcional {
            /** @var list<string> */
            public array $errors = [];

            /** @param array<string, mixed> $data */
            public function __construct(array $data = [])
            {
                $this->table_name = catalogo_opcional::TABLE;
                $this->id = $data['id'] ?? null;
                $this->codigo = $data['codigo'] ?? 'OPC0001';
                $this->nombre = $data['nombre'] ?? 'Opcional';
                $this->descripcion = $data['descripcion'] ?? '';
                $this->precio = $data['precio'] ?? 0.0;
                $this->tipo_precio = $data['tipo_precio'] ?? catalogo_opcional::TIPO_PRECIO_FIJO;
                $this->porcentaje = $data['porcentaje'] ?? null;
                $this->activo = $data['activo'] ?? true;
                $this->cantidad_min = $data['cantidad_min'] ?? null;
                $this->cantidad_max = $data['cantidad_max'] ?? null;
                $this->imagen = $data['imagen'] ?? null;
            }

            protected function new_error_msg($msg)
            {
                $this->errors[] = (string) $msg;
            }

            public function get_by_codigo($codigo)
            {
                return false;
            }
        };
    }

    public function test_cantidad_max_defaults_to_one_when_absent(): void
    {
        $opcional = new catalogo_opcional(false);

        $this->assertSame(1, $opcional->cantidad_min);
        $this->assertSame(1, $opcional->cantidad_max);
        $this->assertNull($opcional->imagen);
    }

    public function test_cantidad_min_defaults_to_one_when_not_provided(): void
    {
        $opcional = new catalogo_opcional([
            'id' => null,
            'codigo' => 'OPC0010',
            'nombre' => 'Extra min',
            'descripcion' => '',
            'precio' => 0,
            'tipo_precio' => catalogo_opcional::TIPO_PRECIO_FIJO,
            'porcentaje' => null,
            'activo' => true,
            'cantidad_max' => 4,
        ]);

        $this->assertSame(1, $opcional->cantidad_min);
        $this->assertSame(4, $opcional->cantidad_max);
    }

    public function test_negative_cantidad_min_is_rejected(): void
    {
        foreach ([-1, -10] as $value) {
            $opcional = $this->makeOpcional(['cantidad_min' => $value, 'cantidad_max' => 5]);

            $this->assertFalse($opcional->test(), 'cantidad_min ' . $value . ' must be rejected');
            $this->assertNotEmpty($opcional->errors);
        }
    }

    public function test_cantidad_min_greater_than_cantidad_max_is_rejected(): void
    {
        $opcional = $this->makeOpcional(['cantidad_min' => 4, 'cantidad_max' => 2]);

        $this->assertFalse($opcional->test(), 'cantidad_min > cantidad_max must be rejected');
        $this->assertNotEmpty($opcional->errors);
    }

    public function test_absent_cantidad_min_is_normalized_to_one_on_validation(): void
    {
        $opcional = $this->makeOpcional(['cantidad_min' => null, 'cantidad_max' => 3]);

        $this->assertTrue($opcional->test());
        $this->assertSame(1, $opcional->cantidad_min);
    }

    public function test_zero_cantidad_min_is_allowed(): void
    {
        $opcional = $this->makeOpcional(['cantidad_min' => 0, 'cantidad_max' => 1]);

        $this->assertTrue($opcional->test(), 'cantidad_min = 0 means "no minimum"');
        $this->assertSame(0, $opcional->cantidad_min);
    }

    public function test_valid_quantity_range_is_preserved(): void
    {
        $opcional = $this->makeOpcional(['cantidad_min' => 2, 'cantidad_max' => 5]);

        $this->assertTrue($opcional->test());
        $this->assertSame(2, $opcional->cantidad_min);
        $this->assertSame(5, $opcional->cantidad_max);
    }

    public function test_cantidad_max_defaults_to_one_when_not_provided(): void
    {
        $opcional = new catalogo_opcional([
            'id' => null,
            'codigo' => 'OPC0009',
            'nombre' => 'Extra',
            'descripcion' => '',
            'precio' => 0,
            'tipo_precio' => catalogo_opcional::TIPO_PRECIO_FIJO,
            'porcentaje' => null,
            'activo' => true,
        ]);

        $this->assertSame(1, $opcional->cantidad_max);
    }

    public function test_cantidad_max_below_one_is_rejected(): void
    {
        foreach ([0, -1, -25] as $value) {
            $opcional = $this->makeOpcional(['cantidad_max' => $value]);

            $this->assertFalse($opcional->test(), 'cantidad_max ' . $value . ' must be rejected');
            $this->assertNotEmpty($opcional->errors);
        }
    }

    public function test_absent_cantidad_max_is_normalized_to_one_on_validation(): void
    {
        $opcional = $this->makeOpcional(['cantidad_max' => null]);

        $this->assertTrue($opcional->test());
        $this->assertSame(1, $opcional->cantidad_max);
    }

    public function test_imagen_url_returns_relative_path_for_a_bare_filename(): void
    {
        $opcional = $this->makeOpcional(['imagen' => 'foto.png']);

        $this->assertSame('imgs/opcionales/foto.png', $opcional->imagen_url());
    }

    public function test_imagen_url_is_empty_when_absent(): void
    {
        $opcional = $this->makeOpcional([]);

        $this->assertSame('', $opcional->imagen_url());
    }

    public function test_imagen_is_normalized_to_a_bare_filename_on_validation(): void
    {
        $opcional = $this->makeOpcional(['imagen' => '/var/www/imgs/opcionales/foto.webp']);

        $this->assertTrue($opcional->test());
        $this->assertSame('foto.webp', $opcional->imagen);
        $this->assertSame('imgs/opcionales/foto.webp', $opcional->imagen_url());
    }
}
