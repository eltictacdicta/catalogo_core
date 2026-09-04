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

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\CatalogoOptions;
use PHPUnit\Framework\TestCase;

/**
 * Namespaced options store (multitarifa task 1.4, R-CO-001..003).
 * Raw override injection mirrors the setSettingRaw() test pattern of
 * ArticleExcelAccessPolicy so no fs_settings/global state is touched.
 */
final class CatalogoOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['config2'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['config2']);
    }

    public function test_flag_round_trip(): void
    {
        $options = new CatalogoOptions();
        $this->assertFalse($options->multiTariffEnabled());

        $options->setMultiTariff(true);

        $fresh = new CatalogoOptions();
        $this->assertTrue($fresh->multiTariffEnabled());
    }

    public function test_groups_flag_round_trip(): void
    {
        $options = new CatalogoOptions();
        $this->assertFalse($options->groupsEnabled());

        $options->setGroupsEnabled(true);

        $fresh = new CatalogoOptions();
        $this->assertTrue($fresh->groupsEnabled());
    }

    public function test_absent_keys_yield_safe_defaults(): void
    {
        $options = new CatalogoOptions();

        $this->assertFalse($options->multiTariffEnabled());
        $this->assertFalse($options->groupsEnabled());
        $this->assertNull($options->excelRoles());
    }

    public function test_excel_roles_round_trip(): void
    {
        $options = new CatalogoOptions();
        $options->setExcelRoles('compras,almacen');

        $fresh = new CatalogoOptions();
        $this->assertSame('compras,almacen', $fresh->excelRoles());
    }

    public function test_excel_roles_falls_back_to_legacy_key(): void
    {
        $GLOBALS['config2']['catalogo_excel_roles'] = 'A,B';

        $options = new CatalogoOptions();

        $this->assertSame('A,B', $options->excelRoles());
    }

    public function test_new_store_wins_over_legacy(): void
    {
        $GLOBALS['config2']['catalogo_excel_roles'] = 'A';
        $GLOBALS['config2']['catalogo_core.excel_roles'] = 'B';

        $options = new CatalogoOptions();

        $this->assertSame('B', $options->excelRoles());
    }

    public function test_uses_namespaced_keys(): void
    {
        $options = new CatalogoOptions();
        $options->setMultiTariff(true);
        $options->setGroupsEnabled(true);
        $options->setExcelRoles('a');

        $this->assertArrayHasKey('catalogo_core.multi_tariff', $GLOBALS['config2']);
        $this->assertArrayHasKey('catalogo_core.groups_enabled', $GLOBALS['config2']);
        $this->assertArrayHasKey('catalogo_core.excel_roles', $GLOBALS['config2']);
    }

    public function test_raw_override_short_circuits_fs_settings(): void
    {
        $override = new CatalogoOptions(['catalogo_core.multi_tariff' => 'true']);

        $this->assertTrue($override->multiTariffEnabled());
        $this->assertArrayNotHasKey('catalogo_core.multi_tariff', $GLOBALS['config2']);
    }
}
