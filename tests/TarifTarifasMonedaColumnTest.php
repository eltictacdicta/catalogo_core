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

use PHPUnit\Framework\TestCase;

/**
 * The tariff list shows each tariff's currency (R-TAR-CUR-008): the operator
 * must not have to open the edit modal to know in which currency a tariff's
 * prices are expressed.
 */
class TarifTarifasMonedaColumnTest extends TestCase
{
    private const VIEW = '/View/tarif_tarifas.html.twig';

    private function listTable(): string
    {
        $source = (string) file_get_contents(dirname(__DIR__) . self::VIEW);
        $start = strpos($source, '<table class="table table-hover">');
        $end = strpos($source, '</table>', (int) $start);

        self::assertNotFalse($start, 'tariff list table not found');
        self::assertNotFalse($end, 'tariff list table is not closed');

        return substr($source, $start, $end - $start);
    }

    public function testListDeclaresMonedaHeaderAfterNombre(): void
    {
        $table = $this->listTable();

        self::assertMatchesRegularExpression(
            '~<th>Nombre</th>\s*<th[^>]*>Moneda</th>~',
            $table,
            'the tariff list must declare a "Moneda" column right after "Nombre"'
        );
    }

    public function testListRendersEachTariffCurrencyEscaped(): void
    {
        $table = $this->listTable();
        $body = substr($table, (int) strpos($table, '<tbody>'));
        $actions = strpos($body, 'btn-group');

        self::assertNotFalse($actions);
        self::assertMatchesRegularExpression(
            "~<td[^>]*>\s*\{\{\s*tarifa\.coddivisa\|default\('EUR'\)\s*\}\}\s*</td>~",
            substr($body, 0, $actions),
            'each row must print the tariff currency in its own cell, autoescaped'
        );
    }

    public function testHeaderAndRowHaveTheSameNumberOfUnconditionalCells(): void
    {
        $table = $this->listTable();
        $bodyAt = (int) strpos($table, '<tbody>');

        self::assertSame(
            preg_match_all('~<th\b~', substr($table, 0, $bodyAt)),
            preg_match_all('~<td\b~', substr($table, $bodyAt)),
            'header and row cell counts must match'
        );
    }
}
