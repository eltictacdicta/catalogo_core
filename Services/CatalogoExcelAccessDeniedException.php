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

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Thrown by the SSE per-event access re-check (R-CEXC-005, AD-4) when the
 * policy verdict flips to denied mid-stream. The existing catch in
 * handleCatalogoStart() converts it into the SSE 'error' event, terminating
 * the stream with a clear denial and no further import processing.
 *
 * The message carries the user-facing denial text (policy denialMessage()).
 */
final class CatalogoExcelAccessDeniedException extends \RuntimeException
{
}