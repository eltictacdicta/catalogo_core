<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\PriceBookInterface;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateTargetInterface;
use FSFramework\Plugins\catalogo_core\Services\TarifaArticlePriceBook;
use FSFramework\Plugins\catalogo_core\Services\TarifaPriceUpdateTarget;
use PHPUnit\Framework\TestCase;

/**
 * R-TAR-PRC-001 / R-TAR-PRC-002 / R-MT-011: the base `tarifa` price update target and its
 * article price book. Collaborators are injected, so no database is needed.
 */
final class TarifaPriceUpdateTargetTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceUpdateTargetRegistry.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/TarifaArticlePriceBook.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/TarifaPriceUpdateTarget.php';
    }

    /** @param list<array{0: string, 1: string}> $rows codtarifa, nombre */
    private function tarifaModel(array $rows): object
    {
        return new class ($rows) {
            /** @param list<array{0: string, 1: string}> $rows */
            public function __construct(private readonly array $rows)
            {
            }

            public function all_activas(): array
            {
                return array_map(
                    static fn (array $r): object => (object) ['codtarifa' => $r[0], 'nombre' => $r[1]],
                    $this->rows
                );
            }
        };
    }

    /**
     * Fake of tarif_articulo_precio over an in-memory `tarif_articulo_precios`.
     *
     * @param array<string, float> $table 'referencia|codtarifa' => precio
     */
    private function precioModel(array &$table, array &$log): object
    {
        return new class ($table, $log) {
            /** @param array<string, float> $table */
            public function __construct(private array &$table, private array &$log)
            {
            }

            public function all_for_referencias(array $refs, string $codtarifa): array
            {
                $this->log[] = 'all_for_referencias:' . $codtarifa;
                $map = [];
                foreach ($refs as $ref) {
                    $k = $ref . '|' . $codtarifa;
                    if (array_key_exists($k, $this->table)) {
                        $map[$ref] = ['precio' => $this->table[$k], 'activo' => true];
                    }
                }

                return $map;
            }

            public function get($ref, $codtarifa)
            {
                $this->log[] = 'get:' . $ref . '|' . $codtarifa;
                $k = $ref . '|' . $codtarifa;
                if (!array_key_exists($k, $this->table)) {
                    return false;
                }
                $table = &$this->table;
                $log = &$this->log;

                return new class ($k, $table[$k], $table, $log) {
                    public float $precio;

                    public function __construct(private string $k, float $precio, private array &$table, private array &$log)
                    {
                        $this->precio = $precio;
                    }

                    public function save(): bool
                    {
                        $this->log[] = 'save:' . $this->k . '=' . $this->precio;
                        $this->table[$this->k] = $this->precio;

                        return true;
                    }
                };
            }
        };
    }

    public function testTargetImplementsTheOwnerContractAndIdentity(): void
    {
        $target = new TarifaPriceUpdateTarget($this->tarifaModel([]));

        $this->assertInstanceOf(PriceUpdateTargetInterface::class, $target);
        $this->assertSame('tarifa', $target->code());
        $this->assertSame('Tarifa', $target->label());
    }

    public function testTargetKeyFieldAndOptions(): void
    {
        $target = new TarifaPriceUpdateTarget($this->tarifaModel([['T1', 'Uno'], ['T2', 'Dos']]));

        $this->assertSame('codtarifa', $target->keyField());
        $this->assertSame('tarifa', $target->keyLabel());
        $this->assertSame(
            [['value' => 'T1', 'label' => 'Uno'], ['value' => 'T2', 'label' => 'Dos']],
            $target->keyOptions()
        );
    }

    public function testTargetAvailabilityRequiresActiveTariff(): void
    {
        $this->assertTrue((new TarifaPriceUpdateTarget($this->tarifaModel([['T1', 'Uno']])))->isAvailable());
        $this->assertFalse((new TarifaPriceUpdateTarget($this->tarifaModel([])))->isAvailable());
    }

    public function testAvailabilityFailsClosedWhenTheTariffModelThrows(): void
    {
        $broken = new class {
            public function all_activas(): array
            {
                throw new \RuntimeException('db down');
            }
        };

        $log = tempnam(sys_get_temp_dir(), 'prc');
        $previous = ini_set('error_log', $log);
        try {
            $available = (new TarifaPriceUpdateTarget($broken))->isAvailable();
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertFalse($available);
        $this->assertStringContainsString('db down', (string) file_get_contents($log), 'the failure is logged');
        unlink($log);
    }

    public function testKeyFromFiltersTrimsAndMapsEmptyToNull(): void
    {
        $target = new TarifaPriceUpdateTarget($this->tarifaModel([]));

        $this->assertSame('T1', $target->keyFromFilters(['codtarifa' => ' T1 ']));
        $this->assertNull($target->keyFromFilters(['codtarifa' => '   ']));
        $this->assertNull($target->keyFromFilters([]));
    }

    public function testBookForArticulosReadsTarifArticuloPrecios(): void
    {
        $table = ['A1|T1' => 10.0, 'A2|T1' => 20.5, 'A1|T2' => 99.0];
        $log = [];
        $target = new TarifaPriceUpdateTarget($this->tarifaModel([]), $this->precioModel($table, $log));

        $book = $target->bookFor('articulos', 'T1');

        $this->assertInstanceOf(PriceBookInterface::class, $book);
        $this->assertSame(['A1' => 10.0, 'A2' => 20.5], $book->currentPrices(['A1', 'A2']));
        $this->assertSame(['all_for_referencias:T1'], $log, 'one query, scoped to the tariff');
    }

    public function testArticleWithoutTariffRowIsOmittedNeverZero(): void
    {
        $table = ['A1|T1' => 10.0];
        $log = [];
        $book = new TarifaArticlePriceBook('T1', $this->precioModel($table, $log));

        $prices = $book->currentPrices(['A1', 'MISSING']);

        $this->assertSame(['A1' => 10.0], $prices);
        $this->assertArrayNotHasKey('MISSING', $prices);
    }

    public function testBookWithNoKeysDoesNotQuery(): void
    {
        $table = [];
        $log = [];
        $book = new TarifaArticlePriceBook('T1', $this->precioModel($table, $log));

        $this->assertSame([], $book->currentPrices([]));
        $this->assertSame([], $log);
    }

    public function testBookForOpcionalesReturnsNull(): void
    {
        $target = new TarifaPriceUpdateTarget($this->tarifaModel([]));

        $this->assertNull($target->bookFor('opcionales', 'T1'));
        $this->assertNull($target->bookFor('unknown', 'T1'));
    }

    public function testBookForEmptyKeyReturnsNull(): void
    {
        $this->assertNull((new TarifaPriceUpdateTarget($this->tarifaModel([])))->bookFor('articulos', ''));
    }

    public function testBookPersistsViaGetAndSaveToKeepHistory(): void
    {
        $table = ['A1|T1' => 10.0];
        $log = [];
        $book = new TarifaArticlePriceBook('T1', $this->precioModel($table, $log));

        $this->assertTrue($book->persist('A1', 12.5));

        $this->assertSame(['get:A1|T1', 'save:A1|T1=12.5'], $log);
        $this->assertSame(12.5, $table['A1|T1']);
    }

    public function testPersistNeverInsertsWhenTheRowIsMissing(): void
    {
        $table = [];
        $log = [];
        $book = new TarifaArticlePriceBook('T1', $this->precioModel($table, $log));

        $this->assertFalse($book->persist('A1', 12.5));

        $this->assertSame([], $table);
        $this->assertSame(['get:A1|T1'], $log, 'no save() attempt for a missing row');
    }

    public function testBookDoesNotTouchTheCanonicalStorage(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/Services/TarifaArticlePriceBook.php');

        $this->assertStringNotContainsString('catalogo_articulo_precio', $source);
        $this->assertStringNotContainsString('CanonicalPriceBooks', $source);
        $this->assertStringNotContainsString('pvp', $source);
    }
}
