<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2025 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore\Controller;

use FSFramework\model\tarif_tarifa;
use PHPUnit\Framework\TestCase;

/**
 * Tests for tarif_tarifas::guardar_tarifa() — coddivisa POST validation.
 * Originally implemented design §7.3 tests #13-#14 of tarifario-tarifa-coddivisa;
 * retargeted by change `tarifas-divisa-catalogo`: the selectable set is now the
 * `admin_divisas` catalog, not the hardcoded ALLOWED_CURRENCIES list.
 *
 * Relocated from tarifario when the tarifas CRUD page moved to catalogo_core.
 *
 * Mocking strategy: anonymous subclass of tarif_tarifas that skips the
 * parent constructor (no DB, no FS init), overrides private_core() to a
 * no-op, re-declares guardar_tarifa() as public and stubs the currency
 * catalog seam. The model ($this->tarifa) is itself an anonymous subclass of
 * tarif_tarifa that records every save() call. get($cod) returns the same
 * tracked object so the controller goes down the "edición" branch (no new
 * instance created) and we can observe what coddivisa value reaches save().
 */
final class TarifTarifasGuardarTest extends TestCase
{
    /** @var object Controller under test (anonymous subclass of tarif_tarifas). */
    private $controller;
    /** @var object Mock model (anonymous subclass of tarif_tarifa) tracked by the test. */
    private $trackedModel;
    public int $saveCount = 0;
    public ?string $lastSavedCoddivisa = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // The controller file pulls in its model dependencies via require_once
        // at the top. Loading the file once per class is enough; subsequent
        // require_once calls are no-ops. fs_controller, fs_app and the
        // tarif_tarifa model are not PSR-4 autoloaded, so we require them
        // explicitly first.
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/base/fs_app.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/controller/tarif_tarifas.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $this->saveCount = 0;
        $this->lastSavedCoddivisa = null;
        $this->resetCoreLog();
        $this->controller = $this->buildController();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    /**
     * Reset the shared fs_core_log state before each test.
     *
     * fs_core_log keeps its entries in a static $data_log array shared by
     * every instance, so constructing a fresh instance is NOT a reset:
     * get_errors() still reads that same static array. Clearing it stops
     * errors raised by an earlier test from being visible here.
     *
     * The static controller_name needs resetting too. fs_core_log's
     * constructor only assigns it when empty, so a previous test's
     * controller name would shadow ours. fs_controller::new_error_msg()
     * writes to the 'errors' channel only when class_name matches
     * controller_name(); otherwise the error is saved to another channel
     * and get_errors() stays empty. Setting it to null lets the fresh
     * instance below claim it.
     *
     * fs_model::$core_log is reassigned unconditionally for the same reason.
     */
    private function resetCoreLog(): void
    {
        (new \fs_core_log())->clear();

        $ref = new \ReflectionClass(\fs_core_log::class);
        $nameProp = $ref->getProperty('controller_name');
        $nameProp->setAccessible(true);
        $nameProp->setValue(null, null);

        $prop = (new \ReflectionClass(\fs_model::class))->getProperty('core_log');
        $prop->setAccessible(true);
        $prop->setValue(null, new \fs_core_log());
    }

    /**
     * Build the controller + tracked model pair. The tracked model is its
     * own get() return value, so guardar_tarifa() will work on the tracked
     * object — every save() call is recorded with the coddivisa that was
     * assigned to the model at that moment.
     */
    private function buildController(): object
    {
        $outer = $this;
        $this->trackedModel = new class($outer) extends tarif_tarifa {
            public $outer;
            public function __construct($outer)
            {
                // Skip parent::__construct to avoid the DB connection in
                // fs_model::__construct (which creates new fs_db2()).
                $this->outer = $outer;
                $this->table_name = 'tarif_tarifas';
                $this->codtarifa = 'TEST';
                $this->nombre = 'Tarifa Test';
                $this->activa = TRUE;
                $this->por_defecto = FALSE;
                $this->coddivisa = 'EUR';
            }
            public function get($cod) { return $this; }
            public function get_new_codigo(): string { return '000001'; }
            public function save(): bool
            {
                $this->outer->saveCount++;
                $this->outer->lastSavedCoddivisa = $this->coddivisa;
                return TRUE;
            }
            public function delete(): bool { return FALSE; }
            public function exists(): bool { return TRUE; }
            public function all(): array { return []; }
        };

        $controller = new class($this, $this->trackedModel) extends \tarif_tarifas {
            public $outer;
            public $tarifa;

            /** @var list<string> stub of the admin_divisas catalog */
            public array $catalog = ['EUR', 'MXN', 'USD'];

            public function __construct($outer, $mockTarifa)
            {
                // Skip parent::__construct — heavy FS init would try to
                // connect to a DB and boot the menu. We only need the
                // controller's methods to execute.
                $this->outer = $outer;
                $this->tarifa = $mockTarifa;
            }
            public function private_core(): void
            {
                // no-op: do NOT call parent::private_core(); the parent
                // would re-instantiate $this->tarifa and clobber our mock.
            }
            protected function configuredDivisaCodes(): array
            {
                return $this->catalog;
            }
            public function set_catalog(array $codes): void
            {
                $this->catalog = $codes;
            }
            public function call_guardar_tarifa(): void
            {
                // The production method is private; invoke it via
                // reflection on the parent class to preserve its original
                // visibility contract. setAccessible(true) bypasses the
                // private check for the duration of this call.
                $method = new \ReflectionMethod(\tarif_tarifas::class, 'guardar_tarifa');
                $method->setAccessible(true);
                $method->invoke($this);
            }
        };

        // Wire up fs_app::$core_log (instance) and fs_controller::$class_name
        // on the controller so new_error_msg() pushes into the in-memory
        // error list (and get_errors() returns it). The class_name check
        // is what determines whether the error ends up in core_log->errors.
        $coreLogProp = new \ReflectionProperty(\fs_app::class, 'core_log');
        $coreLogProp->setAccessible(true);
        $coreLogProp->setValue($controller, new \fs_core_log(\tarif_tarifas::class));

        $classNameProp = new \ReflectionProperty(\fs_controller::class, 'class_name');
        $classNameProp->setAccessible(true);
        $classNameProp->setValue($controller, \tarif_tarifas::class);

        return $controller;
    }

    /**
     * A currency that the catalog does not configure must be rejected BEFORE
     * save() runs, with an explicit error pointing at the Divisas page.
     */
    public function test_guardar_tarifa_rejects_a_currency_missing_from_the_catalog(): void
    {
        $_POST = [
            'action' => 'guardar_tarifa',
            'codtarifa' => 'TEST',
            'nombre' => 'Tarifa Test',
            'coddivisa' => 'GBP',
        ];

        $this->controller->call_guardar_tarifa();

        $this->assertSame(
            0,
            $this->saveCount,
            'save() must not be called when the currency is not configured'
        );
        $errors = implode(' | ', $this->controller->get_errors());
        $this->assertStringContainsString(
            'Divisas',
            $errors,
            'The error must tell the operator where the currency is configured'
        );
    }

    /**
     * A currency configured in the catalog is accepted even when it was never
     * part of the removed ALLOWED_CURRENCIES list.
     */
    public function test_guardar_tarifa_accepts_a_catalog_currency_outside_the_old_whitelist(): void
    {
        $this->controller->set_catalog(['EUR', 'MXN', 'USD', 'ARS']);
        $_POST = [
            'action' => 'guardar_tarifa',
            'codtarifa' => 'TEST',
            'nombre' => 'Tarifa Test',
            'coddivisa' => 'ARS',
        ];

        $this->controller->call_guardar_tarifa();

        $this->assertSame(1, $this->saveCount, 'save() must be called for a configured currency');
        $this->assertSame('ARS', $this->lastSavedCoddivisa);
        $this->assertEmpty($this->controller->get_errors());
    }

    /**
     * Lowercase input must be normalized to uppercase on the model.
     */
    public function test_guardar_tarifa_normalizes_lowercase_coddivisa(): void
    {
        $_POST = [
            'action' => 'guardar_tarifa',
            'codtarifa' => 'TEST',
            'nombre' => 'Tarifa Test',
            'coddivisa' => 'usd',
        ];

        $this->controller->call_guardar_tarifa();

        $this->assertSame(
            1,
            $this->saveCount,
            'save() must be called exactly once when coddivisa is valid'
        );
        $this->assertSame(
            'USD',
            $this->lastSavedCoddivisa,
            'save() must see the normalized uppercase coddivisa'
        );
        $errors = $this->controller->get_errors();
        $this->assertEmpty(
            $errors,
            'No error should be emitted for a valid currency'
        );
    }

    /**
     * A tariff that already stores a currency which was later removed from the
     * catalog must stay editable: the stored value remains selectable.
     */
    public function test_guardar_tarifa_keeps_a_stored_currency_dropped_from_the_catalog(): void
    {
        $this->trackedModel->coddivisa = 'GBP';
        $_POST = [
            'action' => 'guardar_tarifa',
            'codtarifa' => 'TEST',
            'nombre' => 'Tarifa Renombrada',
            'coddivisa' => 'GBP',
        ];

        $this->controller->call_guardar_tarifa();

        $this->assertSame(
            1,
            $this->saveCount,
            'Renaming a tariff must not be blocked by a historic currency'
        );
        $this->assertSame('GBP', $this->lastSavedCoddivisa);
    }

    /**
     * With no currency configured at all, the model's ISO format check is the
     * only gate: creating tariffs must stay possible.
     */
    public function test_guardar_tarifa_degrades_to_format_validation_without_catalog(): void
    {
        $this->controller->set_catalog([]);
        $_POST = [
            'action' => 'guardar_tarifa',
            'codtarifa' => 'TEST',
            'nombre' => 'Tarifa Test',
            'coddivisa' => 'GBP',
        ];

        $this->controller->call_guardar_tarifa();

        $this->assertSame(1, $this->saveCount, 'A three-character currency must pass when nothing is configured');
        $this->assertSame('GBP', $this->lastSavedCoddivisa);
    }

    /**
     * The tariff modal renders the currency options from the catalog instead of
     * the hardcoded EUR/MXN/USD triple.
     */
    public function test_view_builds_the_currency_options_from_the_catalog(): void
    {
        $content = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/View/tarif_tarifas.html.twig');

        $this->assertStringContainsString('{% for divisa in fsc.divisas %}', $content);
        $this->assertStringNotContainsString(
            '<option value="MXN"',
            $content,
            'The hardcoded currency triple must be gone'
        );
    }
}
