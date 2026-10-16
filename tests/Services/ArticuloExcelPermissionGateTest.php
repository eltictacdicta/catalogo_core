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

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent;
use FSFramework\Plugins\catalogo_core\Services\ArticuloExcelPermissionGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Per-row permission gate tests (R-CEXC-003; spec scenarios 20-22).
 *
 * The gate is the per-row enforcement point of the article Excel import:
 * - Scenario 20 (allowed row applies pvp): pvp-mapped row with no denying
 *   listener must pass (pre-change behavior preserved).
 * - Scenario 21 (admin rows pass by admin path): admin must pass WITHOUT
 *   dispatching at all (AD-5 skip — the outcome never depends on listeners).
 * - Scenario 22 (listener exception fails closed per row): a throwing
 *   listener denies the row with the fail-closed reason, import continues.
 *
 * Dispatch-count assertions are behavioral: a spy listener counts invocations,
 * so a zero-count case proves the gate took the no-dispatch path.
 */
#[CoversClass(ArticuloExcelPermissionGate::class)]
final class ArticuloExcelPermissionGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FSEventDispatcher::reset();
    }

    protected function tearDown(): void
    {
        FSEventDispatcher::reset();
        parent::tearDown();
    }

    public function testNoPvpInMappingAllowsWithoutDispatch(): void
    {
        $spy = $this->spy();
        $this->registerDenyingListener($spy);

        $reason = ArticuloExcelPermissionGate::check(
            ['referencia' => 'REF-001', 'descripcion' => 'Artículo sin precio'],
            'REF-001',
            'editor1',
            false
        );

        $this->assertNull($reason, 'A row that does not write pvp must pass the gate');
        $this->assertSame(0, $spy->calls, 'No pvp in mapping ⇒ zero dispatch (isset condition)');
    }

    public function testPvpMappedDenyingListenerReturnsReason(): void
    {
        $spy = $this->spy();
        $this->registerDenyingListener($spy);

        $reason = ArticuloExcelPermissionGate::check(
            ['referencia' => 'REF-001', 'pvp' => '12.50'],
            'REF-001',
            'editor1',
            false
        );

        $this->assertSame('editor sin asignación', $reason, 'Denial must surface the listener-provided reason');
        $this->assertSame(1, $spy->calls, 'A pvp-mapped non-admin row must dispatch exactly once');
    }

    public function testPvpMappedZeroListenersAllows(): void
    {
        $reason = ArticuloExcelPermissionGate::check(
            ['referencia' => 'REF-001', 'pvp' => '12.50'],
            'REF-001',
            'editor1',
            false
        );

        $this->assertNull($reason, 'Zero listeners must resolve allow (host-neutral pre-change behavior)');
    }

    public function testListenerExceptionFailsClosedPerRow(): void
    {
        $this->registerThrowingListener();

        $reason = ArticuloExcelPermissionGate::check(
            ['referencia' => 'REF-001', 'pvp' => '12.50'],
            'REF-001',
            'editor1',
            false
        );

        $this->assertSame(
            'permission filter error',
            $reason,
            'A throwing listener must deny the row fail-closed with the generic reason'
        );
    }

    public function testAdminAllowsWithoutDispatch(): void
    {
        $spy = $this->spy();
        $this->registerDenyingListener($spy);

        $reason = ArticuloExcelPermissionGate::check(
            ['referencia' => 'REF-001', 'pvp' => '12.50'],
            'REF-001',
            'admin1',
            true
        );

        $this->assertNull($reason, 'Admin rows must pass by the admin path, never denied by this filter (AD-5)');
        $this->assertSame(0, $spy->calls, 'Admin ⇒ skip the dispatch entirely (AD-5)');
    }

    // ---- Fakes (ArticlePermissionFilterDispatchTest pattern) ----

    private function spy(): object
    {
        return new class {
            public int $calls = 0;
        };
    }

    private function registerDenyingListener(object $spy): void
    {
        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            static function (ArticlePermissionFilterEvent $event) use ($spy): void {
                $spy->calls++;
                $event->deny('editor sin asignación');
            }
        );
    }

    private function registerThrowingListener(): void
    {
        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            static function (): never {
                throw new \RuntimeException('listener bug');
            }
        );
    }
}