<?php
declare(strict_types=1);

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\CanonicalArticlePriceBook;
use FSFramework\Plugins\catalogo_core\Services\CatalogoOptions;
use FSFramework\Plugins\catalogo_core\Services\ListaPriceUpdateTarget;
use FSFramework\Plugins\catalogo_core\Services\PriceListAvailability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoOptions.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceListAvailability.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CanonicalPriceBooks.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/ListaPriceUpdateTarget.php';

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
            // The price update gate moved into the base target (WU-1.3).
            'Services/ListaPriceUpdateTarget.php',
        ] as $file) {
            $source = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/' . $file);
            $this->assertStringContainsString('listUiEnabled()', $source, $file . ' must gate the list UI through PriceListAvailability');
        }
    }

    // ---- WU-1.3: base target "lista" ----

    #[DataProvider('cases')]
    public function testListTargetAvailabilityUsesListUiEnabled(bool $option, bool $tariffs, bool $expected): void
    {
        $options = new CatalogoOptions([CatalogoOptions::KEY_MULTI_TARIFF => $option ? 'true' : 'false']);
        $target = new ListaPriceUpdateTarget(new PriceListAvailability($options, static fn (): bool => $tariffs));

        $this->assertSame($expected, $target->isAvailable());
    }

    public function testListTargetDeclaresCodlistaKeyAndActiveOptions(): void
    {
        $target = new ListaPriceUpdateTarget(null, $this->fakeListaModel([
            ['L1', 'Lista uno', true],
            ['L2', 'Lista inactiva', false],
            ['L3', 'Lista tres', true],
        ]));

        $this->assertSame('lista', $target->code());
        $this->assertSame('codlista', $target->keyField());
        $this->assertSame(
            [
                ['value' => 'L1', 'label' => 'Lista uno'],
                ['value' => 'L3', 'label' => 'Lista tres'],
            ],
            $target->keyOptions(),
            'Inactive lists are excluded from public flows (R-MT-001)'
        );
    }

    public function testListTargetKeyComesFromCodlistaAndFailsClosedWhenEmpty(): void
    {
        $target = new ListaPriceUpdateTarget();

        $this->assertSame('L1', $target->keyFromFilters(['codlista' => ' L1 ']));
        $this->assertNull($target->keyFromFilters(['codlista' => '  ']));
        $this->assertNull($target->keyFromFilters([]));
        $this->assertNull($target->keyFromFilters(['codtarifa' => 'T1']), 'A foreign key is never mapped to a list');
    }

    public function testListTargetBookForArticulosReturnsCanonicalBook(): void
    {
        $target = new ListaPriceUpdateTarget();

        $this->assertInstanceOf(CanonicalArticlePriceBook::class, $target->bookFor('articulos', 'L1'));
        $this->assertNull($target->bookFor('articulos', ''), 'No key, no book');
    }

    public function testListTargetBookForOpcionalesReturnsNull(): void
    {
        $this->assertNull((new ListaPriceUpdateTarget())->bookFor('opcionales', 'L1'));
        $this->assertNull((new ListaPriceUpdateTarget())->bookFor('desconocido', 'L1'));
    }

    /** @param list<array{string, string, bool}> $rows */
    private function fakeListaModel(array $rows): object
    {
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';

        return new class($rows) extends \FSFramework\model\catalogo_lista_precio {
            public function __construct(private readonly array $fRows)
            {
                parent::__construct();
            }

            public function all()
            {
                $out = [];
                foreach ($this->fRows as [$code, $name, $active]) {
                    $lista = new \FSFramework\model\catalogo_lista_precio();
                    $lista->codlista = $code;
                    $lista->nombre = $name;
                    $lista->activa = $active;
                    $out[] = $lista;
                }

                return $out;
            }
        };
    }
}
