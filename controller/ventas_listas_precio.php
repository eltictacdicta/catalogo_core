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

require_once FS_FOLDER . '/base/fs_controller.php';

/**
 * Retired page (change `fusionar-paginas-tarifas-listas`, R-MT-009): only
 * tariffs exist as a concept; price lists are internal mirrors of the tariffs.
 * This shim only keeps old links alive by redirecting to `tarif_tarifas`; it
 * renders nothing, handles no request data and writes nothing.
 */
class ventas_listas_precio extends fs_controller
{
    public const TARGET = 'index.php?page=tarif_tarifas';

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Listas de precio', 'catalogo', FALSE, FALSE);
        $this->template = FALSE;
    }

    protected function private_core()
    {
        $this->template = FALSE;
        $this->send_location(self::TARGET);
    }

    protected function send_location(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}
