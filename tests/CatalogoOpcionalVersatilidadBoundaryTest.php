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

use PHPUnit\Framework\TestCase;

/**
 * OVE-08 — change boundary.
 *
 * The `opcionales-versatilidad` change MUST NOT modify any file owned by the
 * concurrent `gate-tarifario-surfaces-by-plugin-activation` change, and MUST
 * NOT create an entry in the core `openspec/` tree.
 *
 * The scan asserts the concurrent-owned files carry no symbol introduced by
 * this change, and that no core `openspec/changes/opcionales-versatilidad/`
 * directory exists.
 */
final class CatalogoOpcionalVersatilidadBoundaryTest extends TestCase
{
    /**
     * Files owned by the concurrent change. This change must never touch them.
     *
     * @var list<string>
     */
    private const CONCURRENT_OWNED = [
        'View/ventas_opcionales.html.twig',
        'Controller/VentasOpcionales.php',
        'extras/VentasOpcionalesListTrait.php',
        'Services/CaracteristicaResolver.php',
        'controller/tarif_opcional_edit.php',
        'View/tarif_opcional_edit.html.twig',
    ];

    /**
     * Symbols introduced by this change. None may appear in the files above.
     *
     * @var list<string>
     */
    private const VERSATILIDAD_SYMBOLS = [
        'catalogo_flujo',
        'FlujoResolver',
        'OpcionalImagenService',
        'OpcionalExcel',
        'catalogo_opcional_idioma',
        'cantidad_min',
        'cantidad_max',
    ];

    public function test_concurrent_owned_files_carry_no_versatilidad_symbol(): void
    {
        foreach (self::CONCURRENT_OWNED as $relative) {
            $path = FS_FOLDER . '/plugins/catalogo_core/' . $relative;
            if (!is_file($path)) {
                continue;
            }

            $source = (string) file_get_contents($path);
            foreach (self::VERSATILIDAD_SYMBOLS as $symbol) {
                $this->assertStringNotContainsString(
                    $symbol,
                    $source,
                    $relative . ' is owned by the concurrent change and must not reference ' . $symbol
                );
            }
        }
    }

    public function test_no_core_openspec_entry_exists(): void
    {
        $this->assertDirectoryDoesNotExist(
            FS_FOLDER . '/openspec/changes/opcionales-versatilidad',
            'plugin-local changes must never create a core openspec entry'
        );
    }
}
