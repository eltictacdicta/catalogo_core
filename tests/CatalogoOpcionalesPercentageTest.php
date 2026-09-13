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

require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/base/fs_core_log.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional_precio.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional.php';

use PHPUnit\Framework\TestCase;

/**
 * Percentage pricing across the opcionales surfaces (OPG-01, OUM-03/OUM-07;
 * design AD-1..AD-4, AD-9, AD-13).
 *
 * Behaviour coverage with overridable seams (no live DB):
 *  - writer symmetry: `tarif_opcional::set_porcentaje_tarifa()` stores the
 *    percentage and zeroes the price, while `set_precio_tarifa()` clears it;
 *  - the `tarif_opcional_precio::limpiar_porcentaje()` force flag survives the
 *    adapter's read-modify-write;
 *  - `get_porcentaje()` falls back to the global mode value;
 *  - the unified list price cell renders the effective percentage label;
 *  - the Excel export resolves the percentage value and its number format;
 *  - the injected Tarifas tab persists a percentage row and zeroes the price.
 */
final class CatalogoOpcionalesPercentageTest extends TestCase
{
    private const TRAIT_FILE = 'plugins/catalogo_core/extras/VentasOpcionalesListTrait.php';

    private static bool $baseLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/base/fs_app.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/extras/fs_divisa_tools.php';
        if (file_exists(FS_FOLDER . '/plugins/catalogo_core/controller/tarif_opcional_tab.php')) {
            require_once FS_FOLDER . '/plugins/catalogo_core/controller/tarif_opcional_tab.php';
        }
        self::$baseLoaded = true;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function loadTrait(): void
    {
        $path = FS_FOLDER . '/' . self::TRAIT_FILE;
        if (!is_file($path)) {
            self::fail('missing unified list trait: ' . self::TRAIT_FILE);
        }

        require_once $path;
    }

    private function invoke(object $subject, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($subject, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($subject, $args);
    }

    /**
     * `tarif_opcional` double with the price-model seam overridden.
     */
    private function opcionalWithPriceModel(PercentagePriceModelDouble $priceModel): object
    {
        return new class($priceModel) extends \FSFramework\model\tarif_opcional {
            private PercentagePriceModelDouble $priceModel;

            public function __construct(PercentagePriceModelDouble $priceModel)
            {
                // Skip the DB constructor: the writer only needs id + the seam.
                $this->id = 7;
                $this->priceModel = $priceModel;
            }

            public function opcional_precio_model(): \FSFramework\model\tarif_opcional_precio
            {
                return $this->priceModel;
            }
        };
    }

    /**
     * Tarifas-tab controller double: CSRF/permission stubbed true and the
     * re-render neutralized, so the percentage persistence branch is isolated.
     */
    private function tabController(PercentagePriceModelDouble $priceModel, object $opcional): object
    {
        return new class($priceModel, $opcional) extends \tarif_opcional_tab {
            private PercentagePriceModelDouble $priceModel;
            private object $opcional;

            public function __construct(PercentagePriceModelDouble $priceModel, object $opcional)
            {
                // Skip the heavy fbase_controller constructor (no DB, no menu).
                $this->priceModel = $priceModel;
                $this->opcional = $opcional;
                $this->tarifas = [];
            }

            protected function requireCsrf(?string $tokenId = null): bool
            {
                return true;
            }

            protected function puede_editar_opcional(string $codtarifa): bool
            {
                return true;
            }

            protected function opcional_precio_model(): \FSFramework\model\tarif_opcional_precio
            {
                return $this->priceModel;
            }

            protected function opcional_model(): \FSFramework\model\tarif_opcional
            {
                return $this->opcional;
            }

            protected function render_rows_fragment(): string
            {
                return '';
            }

            public function call_guardar_precio_tab(): void
            {
                $method = new \ReflectionMethod(\tarif_opcional_tab::class, 'guardar_precio_tab');
                $method->setAccessible(true);
                $method->invoke($this);
            }
        };
    }

    // =====================================================================
    // AD-2/AD-3 — writer symmetry
    // =====================================================================

    public function test_set_porcentaje_tarifa_sets_pct_and_zeroes_precio(): void
    {
        $priceModel = new PercentagePriceModelDouble();
        $priceModel->getResult = $priceModel; // existing row
        $priceModel->precio = 9.99;
        $priceModel->porcentaje = null;

        $opcional = $this->opcionalWithPriceModel($priceModel);

        self::assertTrue($opcional->set_porcentaje_tarifa('T1', 12.5));
        self::assertSame(12.5, $priceModel->porcentaje, 'the percentage must be stored');
        self::assertSame(0.0, $priceModel->precio, 'percentage mode must zero the price');
        self::assertSame(1, $priceModel->saveCalls);
    }

    public function test_set_precio_tarifa_clears_porcentaje(): void
    {
        $priceModel = new PercentagePriceModelDouble();
        $priceModel->getResult = $priceModel; // existing row previously in percentage mode
        $priceModel->precio = 0.0;
        $priceModel->porcentaje = 12.5;

        $opcional = $this->opcionalWithPriceModel($priceModel);

        self::assertTrue($opcional->set_precio_tarifa('T1', 9.99));
        self::assertNull($priceModel->porcentaje, 'a fixed-price save must clear the stale percentage');
        self::assertSame(9.99, $priceModel->precio);
        self::assertTrue($priceModel->limpiarCalled, 'the adapter force flag must be used to express the clear');
        self::assertSame(1, $priceModel->saveCalls);
    }

    public function test_set_porcentaje_tarifa_creates_the_row_when_absent(): void
    {
        $priceModel = new PercentagePriceModelDouble();
        $priceModel->getResult = false; // no stored row yet

        $opcional = $this->opcionalWithPriceModel($priceModel);

        self::assertTrue($opcional->set_porcentaje_tarifa('T2', 5.0));
        self::assertSame(7, $priceModel->id_opcional, 'the new row must carry the opcional id');
        self::assertSame('T2', $priceModel->codlista);
        self::assertSame(5.0, $priceModel->porcentaje);
        self::assertSame(0.0, $priceModel->precio);
    }

    public function test_limpiar_porcentaje_forces_null_through_save(): void
    {
        $row = new class() extends \FSFramework\model\tarif_opcional_precio {
            public array $executed = [];

            public function __construct()
            {
                // Skip the DB constructor; only the read-modify-write logic matters.
                $this->table_name = 'catalogo_opcional_precios';
                $this->id_opcional = 7;
                $this->codlista = 'T1';
                $this->codtarifa = 'T1';
                $this->precio = 0.0;
                $this->porcentaje = 12.5;
                $this->en_catalogo = false;
                $this->db = new PercentageSpyDb();
            }

            public function get($id_opcional, $codlista)
            {
                return (object) ['precio' => 0.0, 'porcentaje' => 12.5, 'en_catalogo' => false];
            }

            public function exists()
            {
                return true;
            }

            protected function registrar_cambio_historial($id_opcional, $codlista, $precio_anterior, $precio_nuevo): bool
            {
                return true;
            }

            public function spy()
            {
                return $this->db->executed;
            }
        };

        $row->limpiar_porcentaje();
        self::assertNull($row->porcentaje, 'limpiar_porcentaje() must null the instance value');
        self::assertTrue($row->save());
        self::assertStringContainsString(
            'porcentaje = NULL',
            $row->spy()[0] ?? '',
            'the explicit clear must reach the UPDATE even when a stored value exists'
        );
    }

    public function test_save_preserves_stored_porcentaje_when_instance_absent(): void
    {
        $row = new class() extends \FSFramework\model\tarif_opcional_precio {
            public array $executed = [];

            public function __construct()
            {
                $this->table_name = 'catalogo_opcional_precios';
                $this->id_opcional = 7;
                $this->codlista = 'T1';
                $this->codtarifa = 'T1';
                $this->precio = 0.0;
                $this->porcentaje = null;
                $this->en_catalogo = false;
                $this->db = new PercentageSpyDb();
            }

            public function get($id_opcional, $codlista)
            {
                return (object) ['precio' => 0.0, 'porcentaje' => 12.5, 'en_catalogo' => false];
            }

            public function exists()
            {
                return true;
            }

            protected function registrar_cambio_historial($id_opcional, $codlista, $precio_anterior, $precio_nuevo): bool
            {
                return true;
            }

            public function spy()
            {
                return $this->db->executed;
            }
        };

        self::assertTrue($row->save());
        self::assertStringContainsString(
            "porcentaje = '12.5'",
            $row->spy()[0] ?? '',
            'legacy callers that do not touch the percentage must keep the stored value'
        );
    }

    // =====================================================================
    // AD-1 — effective reader fallback
    // =====================================================================

    public function test_get_porcentaje_falls_back_to_global(): void
    {
        $opcional = new class() extends \FSFramework\model\tarif_opcional {
            public function __construct()
            {
                $this->id = 7;
                $this->tipo_precio = 'porcentaje';
                $this->porcentaje = 12.5;
            }

            public function get_precio_lista($codlista)
            {
                return false; // no per-lista row => global fallback
            }
        };

        self::assertTrue($opcional->es_precio_porcentaje());
        self::assertSame(12.5, $opcional->get_porcentaje('T1'));
        self::assertSame('12,5%', $opcional->etiqueta_precio_lista('T1'));
    }

    public function test_get_porcentaje_prefers_the_per_lista_value(): void
    {
        $opcional = new class() extends \FSFramework\model\tarif_opcional {
            public function __construct()
            {
                $this->id = 7;
                $this->tipo_precio = 'porcentaje';
                $this->porcentaje = 12.5;
            }

            public function get_precio_lista($codlista)
            {
                return (object) ['porcentaje' => 9.0, 'precio' => 0.0];
            }
        };

        self::assertSame(9.0, $opcional->get_porcentaje('T1'), 'the per-lista value must win over the global fallback');
    }

    // =====================================================================
    // OPG-01 — unified list percentage rendering
    // =====================================================================

    public function test_show_precio_opcional_renders_percentage_label(): void
    {
        $priceModel = new class() {
            public function get($id_opcional, $codlista)
            {
                return (object) ['precio' => 0.0, 'porcentaje' => null, 'en_catalogo' => false];
            }
        };

        $subject = $this->traitSubject($priceModel);
        $subject->resultados = [new PercentageOpcionalDouble(7, true, 12.5)];
        $subject->tarifa_seleccionada = (object) ['codtarifa' => 'T1', 'coddivisa' => 'EUR'];

        $this->invoke($subject, 'load_precios_cache');

        self::assertSame(
            '12,5%',
            $subject->show_precio_opcional(7),
            'a percentage opcional must render the effective label, never a zero currency amount'
        );
    }

    public function test_show_precio_opcional_uses_global_fallback_without_a_row(): void
    {
        $priceModel = new class() {
            public function get($id_opcional, $codlista)
            {
                return false; // no per-tarifa row
            }
        };

        $subject = $this->traitSubject($priceModel);
        $subject->resultados = [new PercentageOpcionalDouble(7, true, 8.0)];
        $subject->tarifa_seleccionada = (object) ['codtarifa' => 'T1', 'coddivisa' => 'EUR'];

        $this->invoke($subject, 'load_precios_cache');

        self::assertSame('8%', $subject->show_precio_opcional(7));
    }

    // =====================================================================
    // AD-9 — Excel export percentage format
    // =====================================================================

    public function test_valor_precio_export_uses_percentage_format(): void
    {
        $subject = $this->traitSubject(new class() {
            public function get($id_opcional, $codlista)
            {
                return false;
            }
        });
        $subject->tarifa_seleccionada = (object) ['codtarifa' => 'T1'];

        $percentage = $this->invoke($subject, 'valor_precio_export', [new PercentageOpcionalDouble(7, true, 12.5)]);
        self::assertSame(12.5, $percentage['value']);
        self::assertSame('0.00"%"', $percentage['format'], 'percentage rows must carry a percentage number format');

        $fixed = new class() {
            public function es_precio_porcentaje(): bool
            {
                return false;
            }

            public function get_precio_tarifa($codtarifa)
            {
                return (object) ['precio' => 9.99];
            }
        };
        $fixedData = $this->invoke($subject, 'valor_precio_export', [$fixed]);
        self::assertSame(9.99, $fixedData['value']);
        self::assertSame('#,##0.00', $fixedData['format'], 'fixed rows keep the currency number format');
    }

    // =====================================================================
    // AD-13 — injected Tarifas tab percentage persistence
    // =====================================================================

    public function test_tab_save_persists_porcentaje_and_zeroes_precio(): void
    {
        $priceModel = new PercentagePriceModelDouble();
        $priceModel->getResult = false;

        $opcional = new class() extends \FSFramework\model\tarif_opcional {
            public function __construct()
            {
                $this->id = 7;
                $this->tipo_precio = 'porcentaje';
                $this->porcentaje = 12.5;
            }

            public function es_precio_porcentaje(): bool
            {
                return true;
            }
        };

        $controller = $this->tabController($priceModel, $opcional);

        $_POST = [
            'guardar_precio_tab' => '1',
            'referencia' => '7',
            'codtarifa' => 'T1',
            'modo_porcentaje' => '1',
            'porcentaje' => '12,5',
        ];
        $_REQUEST = $_POST;

        ob_start();
        $controller->call_guardar_precio_tab();
        $json = (string) ob_get_clean();

        $payload = json_decode($json, true);
        self::assertIsArray($payload, 'the tab endpoint must answer JSON, got: ' . $json);
        self::assertTrue($payload['ok'], 'the percentage row must be accepted');
        self::assertSame(12.5, $priceModel->porcentaje, 'the comma decimal percentage must be parsed');
        self::assertSame(0.0, $priceModel->precio, 'the percentage row must zero the price');
        self::assertSame(1, $priceModel->saveCalls);
        self::assertSame(7, $priceModel->id_opcional);
        self::assertSame('T1', $priceModel->codlista);
    }

    /**
     * Trait-using subject with only the price-model seam overridden.
     */
    private function traitSubject(object $priceModel): object
    {
        $this->loadTrait();

        return new class($priceModel) {
            use \VentasOpcionalesListTrait;

            /** @var mixed */
            public $db;

            private object $priceModel;

            public function __construct(object $priceModel)
            {
                $this->priceModel = $priceModel;
            }

            protected function opcional_precio_model()
            {
                return $this->priceModel;
            }
        };
    }
}

/**
 * Price-model double for the `tarif_opcional` writers: records the read and
 * the saved snapshot, and can act as the row itself.
 */
final class PercentagePriceModelDouble extends \FSFramework\model\tarif_opcional_precio
{
    public int $saveCalls = 0;

    public bool $limpiarCalled = false;

    /** @var mixed existing row handed back by get(), or false */
    public $getResult = false;

    public function __construct()
    {
        // Skip the DB constructor.
        $this->porcentaje = null;
        $this->precio = 0.0;
        $this->en_catalogo = false;
    }

    public function get($id_opcional, $codlista)
    {
        $this->id_opcional = $id_opcional;
        $this->codlista = $codlista;

        return $this->getResult;
    }

    public function limpiar_porcentaje(): void
    {
        $this->limpiarCalled = true;
        parent::limpiar_porcentaje();
    }

    public function save(): bool
    {
        $this->saveCalls++;

        return true;
    }
}

/**
 * Opcional double exposing the percentage mode/effective value.
 */
final class PercentageOpcionalDouble
{
    public int $id;

    public bool $percentageMode;

    /** @var float|null */
    public $porcentaje;

    public function __construct(int $id, bool $percentageMode, ?float $porcentaje = null)
    {
        $this->id = $id;
        $this->percentageMode = $percentageMode;
        $this->porcentaje = $porcentaje;
    }

    public function es_precio_porcentaje(): bool
    {
        return $this->percentageMode;
    }

    public function get_porcentaje($codlista = null)
    {
        return $this->porcentaje;
    }
}

/**
 * Spy database for the adapter read-modify-write unit tests.
 */
final class PercentageSpyDb
{
    /** @var list<string> */
    public array $executed = [];

    public function var2str($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    public function exec($sql)
    {
        $this->executed[] = (string) $sql;

        return true;
    }

    public function select($sql)
    {
        return [];
    }
}
