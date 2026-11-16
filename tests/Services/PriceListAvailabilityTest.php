<?php
declare(strict_types=1);

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\CatalogoOptions;
use FSFramework\Plugins\catalogo_core\Services\PriceListAvailability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoOptions.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceListAvailability.php';

/**
 * The price-list UI is offered only with the multi-tariff option on and no
 * active tariff: with tariffs, prices are edited per tariff.
 */
final class PriceListAvailabilityTest extends TestCase
{
    /** @return array<string, array{bool, bool, bool}> */
    public static function cases(): array
    {
        return [
            'option on, no tariffs' => [true, false, true],
            'option on, tariffs active' => [true, true, false],
            'option off, no tariffs' => [false, false, false],
            'option off, tariffs active' => [false, true, false],
        ];
    }

    #[DataProvider('cases')]
    public function testListUiNeedsTheOptionAndNoActiveTariff(bool $option, bool $tariffs, bool $expected): void
    {
        $options = new CatalogoOptions([CatalogoOptions::KEY_MULTI_TARIFF => $option ? 'true' : 'false']);
        $availability = new PriceListAvailability($options, static fn (): bool => $tariffs);

        $this->assertSame($tariffs, $availability->tariffsActive());
        $this->assertSame($expected, $availability->listUiEnabled());
    }

    public function testEveryPriceListSurfaceUsesTheSameRule(): void
    {
        foreach ([
            'Controller/VentasArticulo.php',
            'Controller/VentasArticulos.php',
            'controller/ventas_listas_precio.php',
            'controller/catalogo_actualizar_precios.php',
        ] as $file) {
            $source = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/' . $file);
            $this->assertStringContainsString('listUiEnabled()', $source, $file . ' must gate the list UI through PriceListAvailability');
        }
    }
}
