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

namespace FSFramework\Plugins\catalogo_core\Services;

use Symfony\Component\HttpFoundation\Request;

/**
 * Neutral action-handler contract for the canonical articles list
 * (spec ALC-05/ALC-07, AD-W3-6).
 *
 * catalogo_core owns the seam; consumers (tarifario) register handlers at boot
 * so every pre-existing `page=ventas_articulos&action=...` entry point keeps
 * resolving without any catalogo_core file referencing a consumer plugin path.
 */
interface ArticuloListActionHandlerInterface
{
    /**
     * True when this handler owns the action.
     */
    public function supports(string $action): bool;

    /**
     * Executes the action.
     *
     * @param array<string, mixed> $state resolved list state
     * @return bool TRUE when the action was handled
     */
    public function handle(string $action, Request $request, array $state): bool;
}

/**
 * Neutral registry for the canonical articles list actions (AD-W3-6).
 *
 * `VentasArticulos::processExcelAction()` runs its own canonical cases first
 * and then falls through to {@see dispatch()}; an unknown action returns FALSE
 * so the basic catalogo_core Excel stack keeps serving a standalone install.
 */
final class ArticuloListActionRegistry
{
    /** @var list<ArticuloListActionHandlerInterface> */
    private static array $handlers = [];

    public static function register(ArticuloListActionHandlerInterface $handler): void
    {
        self::$handlers[] = $handler;
    }

    /**
     * Test seam: drops every registered handler.
     */
    public static function reset(): void
    {
        self::$handlers = [];
    }

    /**
     * Dispatches to the first handler that supports the action.
     *
     * @param array<string, mixed> $state
     * @return bool TRUE when a handler handled the action
     */
    public static function dispatch(string $action, Request $request, array $state): bool
    {
        foreach (self::$handlers as $handler) {
            if ($handler->supports($action)) {
                return $handler->handle($action, $request, $state);
            }
        }

        return false;
    }
}
