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
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_idioma.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';

/**
 * La búsqueda de opcionales también encuentra el nombre traducido (cualquier
 * idioma configurado), no solo la columna base del idioma por defecto.
 */
final class CatalogoOpcionalNombreIdiomaSearchTest extends TestCase
{
    public function test_match_condition_looks_into_the_language_table(): void
    {
        $sql = $this->match('o', 'hello');

        $this->assertStringContainsString('EXISTS (SELECT 1 FROM catalogo_opcional_idiomas oi', $sql);
        $this->assertStringContainsString('oi.codigo = o.codigo', $sql);
        $this->assertStringContainsString("lower(oi.nombre) LIKE '%hello%'", $sql);
    }

    public function test_match_condition_uses_the_given_outer_reference(): void
    {
        $this->assertStringContainsString('oi.codigo = catalogo_opcionales.codigo', $this->match('catalogo_opcionales', 'x'));
    }

    public function test_every_search_path_uses_the_language_match(): void
    {
        $core = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php');
        $tarif = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional.php');

        // search(): joined + plain branch (core); search() + count_filtered(): joined + plain (tarif).
        // 1 definition + 2 call sites.
        $this->assertSame(3, substr_count($core, 'nombre_idioma_match('), 'core search() joined + plain branches');
        $this->assertSame(3, substr_count($tarif, 'nombre_idioma_match('), 'tarif search() joined + count_filtered() joined + plain branches');
    }

    private function match(string $alias, string $query): string
    {
        $model = new class() extends catalogo_opcional {
            public function __construct()
            {
            }

            public function expose(string $alias, string $query): string
            {
                return $this->nombre_idioma_match($alias, $query);
            }
        };

        return $model->expose($alias, $query);
    }
}
