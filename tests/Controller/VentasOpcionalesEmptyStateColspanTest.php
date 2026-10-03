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

namespace Tests\CatalogoCore\Controller;

use PHPUnit\Framework\TestCase;

/**
 * The empty-state row of the unified opcionales list must span every rendered
 * column. The two visibility columns (`Tarifa` / `Catálogo`) are conditional on
 * `fsc.visibilidad_activa`, so the colspan cannot be a fixed literal: it has to
 * add the number of active visibility flags to the 8 always-visible columns
 * (Código, Ref ERP, Nombre, Familias, Grupo, Precio, Estado, Acciones).
 *
 * A fixed `colspan="10"` desyncs the empty state from the header whenever the
 * visibility columns are hidden.
 */
final class VentasOpcionalesEmptyStateColspanTest extends TestCase
{
    private const VIEW = '/plugins/catalogo_core/View/ventas_opcionales.html.twig';

    public function test_empty_state_colspan_adds_the_conditional_visibility_columns(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString(
            'colspan="{{ 8 + fsc.visibilidad_activa|length }}"',
            $source,
            'the empty-state colspan must be derived from the 8 fixed columns plus the active visibility flags'
        );
    }

    public function test_no_fixed_colspan_ten_remains(): void
    {
        $source = $this->viewSource();

        $this->assertStringNotContainsString(
            'colspan="10"',
            $source,
            'a fixed colspan="10" would desync the empty state from the conditional header'
        );
    }

    public function test_the_always_visible_header_columns_are_present(): void
    {
        $source = $this->viewSource();

        // The fixed header cells the formula accounts for (Código, Ref ERP,
        // Nombre, Familias, Grupo, Precio and Estado; the last column has no
        // label). The two visibility columns are the only gated ones.
        foreach (['Código', 'Ref ERP', 'Nombre', 'Familias', 'Grupo', 'Precio', 'Estado'] as $label) {
            $this->assertStringContainsString($label, $source);
        }

        $this->assertStringContainsString("'en_tarifa' in fsc.visibilidad_activa", $source);
        $this->assertStringContainsString("'en_catalogo' in fsc.visibilidad_activa", $source);
    }

    private function viewSource(): string
    {
        $path = FS_FOLDER . self::VIEW;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
