<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
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

use FSFramework\model\catalogo_idioma;
use PHPUnit\Framework\TestCase;
use Tests\CatalogoCore\Support\FakeCatalogoIdioma;
use Tests\CatalogoCore\Support\IdiomaRegistryFake;

require_once __DIR__ . '/Support/IdiomaRegistryFake.php';
require_once __DIR__ . '/Support/FakeCatalogoIdioma.php';

/**
 * GDI-01 management surface (slice 2): the `catalogo_idiomas` lifecycle plus
 * the `#idiomas` section `catalogo_idioma::url()` already points at.
 *
 * The registry is exercised DB-free through the slice-1 in-memory fake: each
 * mutation is issued by a freshly bound double so the emitted SQL mutates the
 * seeded rows exactly like the real driver would.
 */
final class CatalogoIdiomaManagementTest extends TestCase
{
    private const CONTROLLER = '/plugins/catalogo_core/Controller/VentasArticulos.php';
    private const TRAIT = '/plugins/catalogo_core/extras/VentasArticulosListTrait.php';
    private const VIEW = '/plugins/catalogo_core/View/ventas_articulos.html.twig';

    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
    }

    private function registry(IdiomaRegistryFake $db): catalogo_idioma
    {
        return (new FakeCatalogoIdioma())->useFakeDb($db);
    }

    private function source(string $relative): string
    {
        $path = FS_FOLDER . $relative;
        if (!is_file($path)) {
            self::fail('missing path: ' . $relative);
        }

        return (string) file_get_contents($path);
    }

    public function test_url_anchor_resolves_to_a_real_section(): void
    {
        $this->assertSame(
            'index.php?page=ventas_articulos#idiomas',
            (new FakeCatalogoIdioma())->url(),
            'the language registry must keep pointing at the list page anchor'
        );

        $view = $this->source(self::VIEW);
        $this->assertStringContainsString(
            'id="idiomas"',
            $view,
            'the dangling #idiomas anchor must resolve to a real section'
        );
    }

    public function test_full_lifecycle_round_trip_persists_each_mutation(): void
    {
        $db = new IdiomaRegistryFake([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
        ]);

        // Create.
        $create = $this->registry($db);
        $create->codidioma = 'fr';
        $create->nombre = 'Français';
        $create->activo = true;
        $create->por_defecto = false;
        $this->assertTrue($create->save(), 'a new language must persist');
        $this->assertSame('Français', $db->idiomas['fr']['nombre']);

        // Rename (a fresh bound double, like the panel's row form).
        $rename = $this->registry($db);
        $rename->codidioma = 'fr';
        $rename->nombre = 'Francés';
        $rename->activo = true;
        $rename->por_defecto = false;
        $this->assertTrue($rename->save(), 'a rename must persist');
        $this->assertSame('Francés', $db->idiomas['fr']['nombre']);

        // Deactivate.
        $deactivate = $this->registry($db);
        $deactivate->codidioma = 'fr';
        $deactivate->nombre = 'Francés';
        $deactivate->activo = false;
        $deactivate->por_defecto = false;
        $this->assertTrue($deactivate->save(), 'a non-default language must be deactivatable');
        $this->assertFalse($db->idiomas['fr']['activo']);

        // Reactivate.
        $reactivate = $this->registry($db);
        $reactivate->codidioma = 'fr';
        $reactivate->nombre = 'Francés';
        $reactivate->activo = true;
        $reactivate->por_defecto = false;
        $this->assertTrue($reactivate->save(), 'a deactivated language must be reactivatable');
        $this->assertTrue($db->idiomas['fr']['activo']);

        // The registry reflects every mutation through the listing the panel reads.
        $codes = array_map(static fn ($idioma): string => (string) $idioma->codidioma, $this->registry($db)->all());
        $this->assertContains('fr', $codes, 'the management listing must reflect the created language');
        $this->assertSame(['es'], $db->activeDefaults(), 'the lifecycle must not disturb the default invariant');
    }

    public function test_invalid_payload_is_rejected_and_persists_nothing(): void
    {
        $db = new IdiomaRegistryFake([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
        ]);

        $tooShort = $this->registry($db);
        $tooShort->codidioma = 'x';
        $tooShort->nombre = 'X';
        $this->assertFalse($tooShort->test(), 'a codidioma shorter than 2 chars must be rejected');
        $this->assertFalse($tooShort->save(), 'an invalid payload must not persist');
        $this->assertNotEmpty($tooShort->errors, 'the rejection must be reported');
        $this->assertArrayNotHasKey('x', $db->idiomas);

        $tooLong = $this->registry($db);
        $tooLong->codidioma = 'fr';
        $tooLong->nombre = str_repeat('a', 51);
        $this->assertFalse($tooLong->test(), 'a nombre longer than 50 chars must be rejected');
        $this->assertFalse($tooLong->save(), 'an invalid payload must not persist');
        $this->assertNotEmpty($tooLong->errors, 'the rejection must be reported');
        $this->assertArrayNotHasKey('fr', $db->idiomas);
    }

    public function test_management_surface_dispatches_the_four_language_actions(): void
    {
        $controller = $this->source(self::CONTROLLER);

        foreach (['save', 'toggle_active', 'set_default', 'delete'] as $action) {
            $this->assertStringContainsString(
                "'" . $action . "'",
                $controller,
                'the language panel must dispatch the ' . $action . ' action'
            );
        }

        $trait = $this->source(self::TRAIT);
        $this->assertStringContainsString(
            'idiomas_todos',
            $trait,
            'the list trait must expose the full registry for the management table'
        );
        $this->assertStringContainsString(
            '->all()',
            $trait,
            'the management table must read every language, not only the active ones'
        );
    }
}
