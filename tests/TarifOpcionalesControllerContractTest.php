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
 * Source contract for the four opcional controllers absorbed from tarifario.
 *
 * After the move they must live under plugins/catalogo_core/controller/ keeping
 * their original slugs/class names, extend catalogo_core's fbase_controller, use
 * TarifarioOpcionalStateTrait (design D2), and drop every dependency on
 * tarifario's opcional code: zero tarif_controller references and zero
 * plugins/tarifario/model/tarif_*opcional* requires
 * (spec "Moved controllers decoupled from tarifario opcional code" and
 * "Opcional pages served by catalogo_core").
 */
final class TarifOpcionalesControllerContractTest extends TestCase
{
    /** Original slugs — slugs, class names and the fs_pages registry stay frozen. */
    private const CONTROLLER_SLUGS = [
        'tarif_opcionales',
        'tarif_opcional_edit',
        'tarif_opcional_precios',
        'tarif_configurador_opcionales',
    ];

    private function controllerPath(string $slug): string
    {
        return FS_FOLDER . '/plugins/catalogo_core/controller/' . $slug . '.php';
    }

    private function controllerSource(string $slug): string
    {
        $path = $this->controllerPath($slug);
        if (!is_file($path)) {
            self::fail(
                'missing catalogo_core path: plugins/catalogo_core/controller/' . $slug
                . '.php (moved controller not present)'
            );
        }

        return (string) file_get_contents($path);
    }

    public function test_all_four_controllers_are_declared_in_catalogo_core(): void
    {
        foreach (self::CONTROLLER_SLUGS as $slug) {
            $this->assertFileExists(
                $this->controllerPath($slug),
                'page=' . $slug . ' must be served from plugins/catalogo_core/controller/'
            );

            $this->assertMatchesRegularExpression(
                '/class\s+' . preg_quote($slug, '/') . '\b/',
                $this->controllerSource($slug),
                'The moved file must keep its original class name ' . $slug
            );
        }
    }

    public function test_all_four_controllers_extend_fbase_controller(): void
    {
        foreach (self::CONTROLLER_SLUGS as $slug) {
            $src = $this->controllerSource($slug);

            $this->assertMatchesRegularExpression(
                '/class\s+' . preg_quote($slug, '/') . '\s+extends\s+fbase_controller\b/',
                $src,
                $slug . ' must extend catalogo_core fbase_controller'
            );

            $this->assertDoesNotMatchRegularExpression(
                '/\bextends\s+tarif_controller\b/',
                $src,
                $slug . ' must not extend tarif_controller anymore'
            );
        }
    }

    public function test_all_four_controllers_have_zero_tarif_controller_references(): void
    {
        foreach (self::CONTROLLER_SLUGS as $slug) {
            $this->assertSame(
                0,
                substr_count($this->controllerSource($slug), 'tarif_controller'),
                $slug . ' must not reference tarif_controller after the reclass'
            );
        }
    }

    public function test_all_four_controllers_have_zero_tarifario_opcional_requires(): void
    {
        foreach (self::CONTROLLER_SLUGS as $slug) {
            $this->assertSame(
                0,
                (int) preg_match_all(
                    "#require(_once)?\s*\(?\s*['\"]plugins/tarifario/model/tarif_[a-z0-9_]*opcional[a-z0-9_]*\.php#i",
                    $this->controllerSource($slug)
                ),
                $slug . ' must not require plugins/tarifario/model/tarif_*opcional*'
            );
        }
    }

    public function test_all_four_controllers_use_the_opcional_state_trait(): void
    {
        foreach (self::CONTROLLER_SLUGS as $slug) {
            $this->assertStringContainsString(
                'use TarifarioOpcionalStateTrait;',
                $this->controllerSource($slug),
                $slug . ' must use TarifarioOpcionalStateTrait (design D2)'
            );
        }
    }
}
