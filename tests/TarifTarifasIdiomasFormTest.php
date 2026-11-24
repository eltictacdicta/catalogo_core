<?php
declare(strict_types=1);
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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * R-TAR-IDI-003: el formulario de tarifa elige sus idiomas y su idioma por
 * defecto entre los activos, y la vista lo ofrece.
 */
final class TarifTarifasIdiomasFormTest extends TestCase
{
    private const VIEW = '/View/tarif_tarifas.html.twig';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/base/fs_app.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/controller/tarif_tarifas.php';
    }

    public function test_form_without_language_block_leaves_the_configuration_alone(): void
    {
        $this->assertNull($this->controller()->idiomas_formulario(['nombre' => 'T']));
    }

    public function test_a_subset_with_its_default_is_stored(): void
    {
        $result = $this->controller()->idiomas_formulario([
            'idiomas_enviados' => '1',
            'idiomas' => ['es_ES', 'en_GB'],
            'codidioma_defecto' => 'en_GB',
        ]);

        $this->assertSame(['codes' => ['es_ES', 'en_GB'], 'default' => 'en_GB'], $result);
    }

    public function test_all_languages_with_the_global_default_store_no_configuration(): void
    {
        $result = $this->controller()->idiomas_formulario([
            'idiomas_enviados' => '1',
            'idiomas' => ['en_GB', 'es_ES', 'fr_FR'],
            'codidioma_defecto' => 'es_ES',
        ]);

        $this->assertSame(['codes' => [], 'default' => ''], $result, 'the tarifa keeps following the active languages');
    }

    public function test_all_languages_with_another_default_are_stored(): void
    {
        $result = $this->controller()->idiomas_formulario([
            'idiomas_enviados' => '1',
            'idiomas' => ['en_GB', 'es_ES', 'fr_FR'],
            'codidioma_defecto' => 'fr_FR',
        ]);

        $this->assertSame(['codes' => ['en_GB', 'es_ES', 'fr_FR'], 'default' => 'fr_FR'], $result);
    }

    public function test_no_language_is_rejected(): void
    {
        $result = $this->controller()->idiomas_formulario(['idiomas_enviados' => '1', 'codidioma_defecto' => 'es_ES']);

        $this->assertIsString($result);
    }

    public function test_default_outside_the_selection_is_rejected(): void
    {
        $result = $this->controller()->idiomas_formulario([
            'idiomas_enviados' => '1',
            'idiomas' => ['es_ES'],
            'codidioma_defecto' => 'en_GB',
        ]);

        $this->assertIsString($result);
    }

    public function test_unknown_or_inactive_language_is_rejected(): void
    {
        $result = $this->controller()->idiomas_formulario([
            'idiomas_enviados' => '1',
            'idiomas' => ['es_ES', 'de_DE'],
            'codidioma_defecto' => 'es_ES',
        ]);

        $this->assertIsString($result);
    }

    public function test_bare_codes_are_normalized(): void
    {
        $result = $this->controller()->idiomas_formulario([
            'idiomas_enviados' => '1',
            'idiomas' => ['es', 'fr'],
            'codidioma_defecto' => 'fr',
        ]);

        $this->assertSame(['codes' => ['es_ES', 'fr_FR'], 'default' => 'fr_FR'], $result);
    }

    public function test_modal_offers_include_and_default_per_language(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . self::VIEW);

        $this->assertStringContainsString('name="idiomas_enviados"', $source);
        $this->assertStringContainsString('name="idiomas[]"', $source);
        $this->assertStringContainsString('name="codidioma_defecto"', $source);
        $this->assertStringContainsString('seleccionar_idiomas(idiomas)', $source, 'editing loads the tarifa languages');
        $this->assertStringContainsString('seleccionar_idiomas(null)', $source, 'a new tarifa starts with every language');
    }

    public function test_list_shows_the_languages_of_each_tarifa(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . self::VIEW);

        $this->assertMatchesRegularExpression('~<th[^>]*>Idiomas</th>~', $source);
        $this->assertStringContainsString('fsc.idiomas_tarifa(tarifa)', $source);
    }

    private function controller(): \tarif_tarifas
    {
        $controller = (new \ReflectionClass(\tarif_tarifas::class))->newInstanceWithoutConstructor();
        $controller->idiomas = [
            (object) ['codidioma' => 'en_GB', 'nombre' => 'English'],
            (object) ['codidioma' => 'es_ES', 'nombre' => 'Español'],
            (object) ['codidioma' => 'fr_FR', 'nombre' => 'Français'],
        ];
        $controller->codidioma_global = 'es_ES';

        return $controller;
    }
}
