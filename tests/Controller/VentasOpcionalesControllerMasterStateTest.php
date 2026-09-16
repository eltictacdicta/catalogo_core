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

namespace Tests\CatalogoCore\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Delta spec `opcionales-management` OUM-03 / OUM-04 (WU-4, D12).
 *
 * The unified `ventas_opcionales` list renders `en_catalogo`/`en_tarifa` as a
 * derived, read-only indicator resolved from the parent product (D12
 * existential union) and exposes `toggle_activa` as its only state toggle.
 */
final class VentasOpcionalesControllerMasterStateTest extends TestCase
{
    private const CONTROLLER = '/plugins/catalogo_core/Controller/VentasOpcionales.php';
    private const TRAIT = '/plugins/catalogo_core/extras/VentasOpcionalesListTrait.php';
    private const VIEW = '/plugins/catalogo_core/View/ventas_opcionales.html.twig';

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('FSFramework\\Plugins\\catalogo_core\\Controller\\VentasOpcionales', false)) {
            require_once FS_FOLDER . self::CONTROLLER;
        }
    }

    // =====================================================================
    // OUM-03 — derived read-only visibility
    // =====================================================================

    public function test_visibility_cache_is_built_from_the_derived_resolver(): void
    {
        $resolver = new OpcionalVisibilityResolverDouble([
            'en_tarifa' => [7 => true, 8 => false, 9 => null],
            'en_catalogo' => [7 => false, 8 => true, 9 => true],
        ]);

        $controller = $this->makeList($resolver);
        $controller->tarifa_seleccionada = (object) ['codtarifa' => 'T1'];
        $controller->resultados = [(object) ['id' => 7], (object) ['id' => 8], (object) ['id' => 9]];

        $controller->build_visibility_cache();

        $this->assertTrue($controller->opcional_en_tarifa_flag(7), 'parent TRUE makes the opcional visible');
        $this->assertFalse($controller->opcional_en_catalogo_tarifa(7), 'parent FALSE keeps it hidden');
        $this->assertFalse($controller->opcional_en_tarifa_flag(8), 'FALSE parent hides the opcional');
        $this->assertTrue($controller->opcional_en_catalogo_tarifa(8));
        $this->assertTrue($controller->opcional_en_catalogo_tarifa(9), 'a NULL derivation resolves to hidden');
        $this->assertFalse($controller->opcional_en_tarifa_flag(9));

        $this->assertSame(
            \FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver::VISIBILITY_CODIGOS,
            $resolver->codigos,
            'both indicators must be derived'
        );
        $this->assertSame([7, 8, 9], $resolver->ids[0], 'one batched call per indicator, never per row');
        $this->assertSame('T1', $resolver->codtarifas[0]);
    }

    public function test_unknown_opcional_resolves_hidden_without_a_second_read(): void
    {
        $resolver = new OpcionalVisibilityResolverDouble(['en_tarifa' => [], 'en_catalogo' => []]);

        $controller = $this->makeList($resolver);
        $controller->tarifa_seleccionada = (object) ['codtarifa' => 'T1'];
        $controller->resultados = [(object) ['id' => 7]];

        $controller->build_visibility_cache();

        $this->assertFalse($controller->opcional_en_tarifa_flag(999));
        $this->assertFalse($controller->opcional_en_catalogo_tarifa(999));
        $this->assertCount(2, $resolver->ids, 'still exactly one batched call per indicator');
    }

    public function test_no_tarifa_selected_issues_no_visibility_read(): void
    {
        $resolver = new OpcionalVisibilityResolverDouble(['en_tarifa' => [], 'en_catalogo' => []]);

        $controller = $this->makeList($resolver);
        $controller->tarifa_seleccionada = null;
        $controller->resultados = [(object) ['id' => 7]];

        $controller->build_visibility_cache();

        $this->assertSame([], $resolver->ids, 'without a selected tarifa nothing can be derived');
        $this->assertFalse($controller->opcional_en_tarifa_flag(7));
    }

    public function test_view_renders_read_only_indicators_and_no_visibility_toggle(): void
    {
        $view = $this->source(self::VIEW);

        $this->assertStringNotContainsString('action=toggle_en_catalogo', $view, 'the catalog toggle must be gone');
        $this->assertStringNotContainsString('action=toggle_en_tarifa', $view, 'the tarifa toggle must be gone');
        $this->assertStringContainsString('action=toggle_activa', $view, 'the surviving activation toggle stays');
        $this->assertStringContainsString('fsc.opcional_en_tarifa_flag(', $view, 'the tarifa cell renders the derived value');
        $this->assertStringContainsString('fsc.opcional_en_catalogo_tarifa(', $view, 'the catalog cell renders the derived value');
    }

    // =====================================================================
    // OUM-04 — only the activation toggle survives
    // =====================================================================

    public function test_toggle_action_registry_lists_only_the_activation_toggle(): void
    {
        $actions = \FSFramework\Plugins\catalogo_core\Controller\VentasOpcionales::TOGGLE_ACTIONS;

        $this->assertContains('toggle_activa', $actions);
        $this->assertNotContains('toggle_en_catalogo', $actions);
        $this->assertNotContains('toggle_en_tarifa', $actions);
    }

    public function test_removed_visibility_toggles_have_no_write_path(): void
    {
        $controller = $this->source(self::CONTROLLER);
        $trait = $this->source(self::TRAIT);

        $this->assertStringNotContainsString('toggle_en_catalogo', $controller);
        $this->assertStringNotContainsString('toggle_en_tarifa', $controller);

        foreach (['set_en_catalogo(', 'set_en_tarifa('] as $removed) {
            $this->assertStringNotContainsString($removed, $trait, "the retired {$removed} write path must be deleted, not aliased");
        }

        $this->assertStringContainsString('set_activa(', $trait, 'activation keeps persisting through the master setter');
    }

    public function test_visibility_writes_never_touch_the_master_columns(): void
    {
        $trait = $this->source(self::TRAIT);

        // The catalog/tarifa indicators are resolved, never persisted from the list.
        $this->assertStringNotContainsString("'en_catalogo', (bool)", $trait);
        $this->assertStringNotContainsString("'en_tarifa', (bool)", $trait);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function makeList(OpcionalVisibilityResolverDouble $resolver): object
    {
        return new class ($resolver) extends \FSFramework\Plugins\catalogo_core\Controller\VentasOpcionales {
            public function __construct(private OpcionalVisibilityResolverDouble $resolver)
            {
            }

            public function build_visibility_cache(): void
            {
                $this->load_opcionales_visibility_cache();
            }

            protected function opcional_visibility_resolver()
            {
                return $this->resolver;
            }
        };
    }

    private function source(string $path): string
    {
        $full = FS_FOLDER . $path;
        $this->assertFileExists($full, "missing catalogo_core file: {$path}");

        return (string) file_get_contents($full);
    }
}

/**
 * Records the batched derivation calls and answers from an in-memory map.
 */
final class OpcionalVisibilityResolverDouble
{
    /** @var list<array<int, int|string>> */
    public array $ids = [];

    /** @var list<string> */
    public array $codigos = [];

    /** @var list<string> */
    public array $codtarifas = [];

    /** @param array<string, array<int, bool|null>> $map */
    public function __construct(private array $map)
    {
    }

    /**
     * @param array<int, int|string> $id_opcionales
     * @return array<int, bool|null>
     */
    public function resolve_opcionales_visibility(array $id_opcionales, string $codtarifa, string $codigo): array
    {
        $this->ids[] = array_values($id_opcionales);
        $this->codigos[] = $codigo;
        $this->codtarifas[] = $codtarifa;

        $rows = $this->map[$codigo] ?? [];
        $result = [];
        foreach ($id_opcionales as $id) {
            $result[(int) $id] = $rows[(int) $id] ?? null;
        }

        return $result;
    }
}
