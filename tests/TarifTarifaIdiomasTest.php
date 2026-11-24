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

use FSFramework\model\tarif_tarifa;
use FSFramework\model\tarif_tarifa_idioma;
use PHPUnit\Framework\TestCase;

// File scope: the doubles below extend tarif_tarifa, and the suite runs tests
// in separate processes that load this file before any setUp hook.
require_once FS_FOLDER . '/base/fs_functions.php';
require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';

/**
 * R-TAR-IDI-001..004: cada tarifa elige en qué idiomas va y cuál es su idioma
 * por defecto; sin configuración usa todos los activos y el por defecto global.
 */
final class TarifTarifaIdiomasTest extends TestCase
{
    public function test_unconfigured_tarifa_uses_every_active_language_and_the_global_default(): void
    {
        $r = tarif_tarifa_idioma::resolve(['fr_FR', 'es_ES', 'en_GB'], [], 'es_ES');

        $this->assertSame(['es_ES', 'en_GB', 'fr_FR'], $r['codes']);
        $this->assertSame('es_ES', $r['default']);
    }

    public function test_configured_subset_and_default_win(): void
    {
        $r = tarif_tarifa_idioma::resolve(
            ['es_ES', 'en_GB', 'fr_FR'],
            ['fr_FR' => false, 'en_GB' => true],
            'es_ES'
        );

        $this->assertSame(['en_GB', 'fr_FR'], $r['codes'], 'default first, then by code');
        $this->assertSame('en_GB', $r['default']);
    }

    public function test_inactive_configured_language_is_dropped(): void
    {
        $r = tarif_tarifa_idioma::resolve(['es_ES', 'en_GB'], ['fr_FR' => true, 'en_GB' => false], 'es_ES');

        $this->assertSame(['en_GB'], $r['codes']);
        $this->assertSame('en_GB', $r['default'], 'an inactive default falls back to an enabled language');
    }

    public function test_default_falls_back_to_the_global_default_when_enabled(): void
    {
        $r = tarif_tarifa_idioma::resolve(['es_ES', 'en_GB', 'fr_FR'], ['fr_FR' => false, 'es_ES' => false], 'es_ES');

        $this->assertSame('es_ES', $r['default']);
        $this->assertSame(['es_ES', 'fr_FR'], $r['codes']);
    }

    public function test_configuration_without_any_active_language_behaves_as_unconfigured(): void
    {
        $r = tarif_tarifa_idioma::resolve(['es_ES', 'en_GB'], ['de_DE' => true], 'es_ES');

        $this->assertSame(['es_ES', 'en_GB'], $r['codes']);
        $this->assertSame('es_ES', $r['default']);
    }

    public function test_empty_registry_is_total(): void
    {
        $r = tarif_tarifa_idioma::resolve([], [], 'es_ES');

        $this->assertSame(['es_ES'], $r['codes']);
        $this->assertSame('es_ES', $r['default']);
    }

    public function test_tarifa_exposes_its_languages_and_default(): void
    {
        $tarifa = $this->tarifa('T1', ['es_ES' => false, 'en_GB' => true]);

        $this->assertSame(['en_GB', 'es_ES'], $tarifa->idiomas_codes());
        $this->assertSame('en_GB', $tarifa->codidioma_defecto());
        $this->assertSame(
            ['en_GB', 'es_ES'],
            array_map(static fn ($i) => $i->codidioma, $tarifa->idiomas()),
            'idiomas() returns registry objects in the resolved order'
        );
    }

    public function test_new_tarifa_without_code_is_unconfigured(): void
    {
        $tarifa = $this->tarifa(null, ['en_GB' => true]);

        $this->assertSame([], $tarifa->idiomas_config());
        $this->assertSame('es_ES', $tarifa->codidioma_defecto());
        $this->assertFalse($tarifa->save_idiomas(['es_ES'], 'es_ES'), 'a tarifa without code cannot store languages');
    }

    public function test_save_idiomas_delegates_to_the_store(): void
    {
        $tarifa = $this->tarifa('T1', []);

        $this->assertTrue($tarifa->save_idiomas(['es_ES', 'fr_FR'], 'fr_FR'));
        $this->assertSame(['T1', ['es_ES', 'fr_FR'], 'fr_FR'], $tarifa->store->saved);
    }

    /**
     * @param array<string, bool> $config
     */
    private function tarifa(?string $cod, array $config): TarifaIdiomasDouble
    {
        $tarifa = new TarifaIdiomasDouble();
        $tarifa->codtarifa = $cod;
        $tarifa->store = new TarifaIdiomaStoreDouble($config);

        return $tarifa;
    }
}

final class TarifaIdiomasDouble extends tarif_tarifa
{
    public TarifaIdiomaStoreDouble $store;

    protected function idioma_registry()
    {
        return new class {
            public function all_activos(): array
            {
                return [
                    (object) ['codidioma' => 'en_GB', 'nombre' => 'English'],
                    (object) ['codidioma' => 'es_ES', 'nombre' => 'Español'],
                    (object) ['codidioma' => 'fr_FR', 'nombre' => 'Français'],
                ];
            }

            public function get_effective_default_code(): string
            {
                return 'es_ES';
            }
        };
    }

    protected function tarifa_idioma_model()
    {
        return $this->store;
    }
}

final class TarifaIdiomaStoreDouble
{
    /** @var array<int, mixed>|null */
    public ?array $saved = null;

    /** @param array<string, bool> $config */
    public function __construct(private array $config)
    {
    }

    /** @return array<string, bool> */
    public function config_from_tarifa($codtarifa): array
    {
        return $this->config;
    }

    public function save_config($codtarifa, array $codes, string $default): bool
    {
        $this->saved = [$codtarifa, $codes, $default];

        return true;
    }
}
