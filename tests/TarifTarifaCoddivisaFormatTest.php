<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Currency contract of `tarif_tarifa` after the `admin_divisas` catalog took
 * over the selectable set (change `tarifas-divisa-catalogo`).
 *
 * The model keeps the ISO 4217 shape (exactly 3 alphanumeric characters) and
 * stops owning a hardcoded whitelist: which codes are actually choosable is a
 * catalog question resolved by the controller.
 */
final class TarifTarifaCoddivisaFormatTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new \fs_core_log())->clear();
    }

    public function test_any_iso_shaped_currency_passes_the_model(): void
    {
        $tarifa = $this->buildModel();
        $tarifa->coddivisa = 'ars';

        $this->assertTrue(
            $tarifa->test(),
            'The model must not own a hardcoded currency whitelist anymore'
        );
        $this->assertSame('ARS', $tarifa->coddivisa, 'The model still normalizes case and spaces');
    }

    public function test_codes_that_are_not_exactly_three_alphanumeric_chars_are_rejected(): void
    {
        foreach (['EU', 'EURO', '', 'U$D', '1'] as $code) {
            $tarifa = $this->buildModel();
            $tarifa->coddivisa = $code;

            $this->assertFalse(
                $tarifa->test(),
                "'" . $code . "' is not an ISO 4217 shaped code and must be rejected"
            );
        }
    }

    public function test_closed_currency_whitelist_constant_is_gone(): void
    {
        $this->assertFalse(
            defined('FSFramework\\model\\tarif_tarifa::ALLOWED_CURRENCIES'),
            'ALLOWED_CURRENCIES must be replaced by the admin_divisas catalog'
        );
    }

    private function buildModel(): \FSFramework\model\tarif_tarifa
    {
        $model = new \FSFramework\model\tarif_tarifa();
        $model->codtarifa = 'TEST';
        $model->nombre = 'Tarifa Test';

        return $model;
    }
}
