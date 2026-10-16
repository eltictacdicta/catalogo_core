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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Dispatch-path tests for the article permission filter (R-TAR-HOOK-004/005).
 *
 * Scenarios covered:
 * - S1: default allow with zero listeners (host neutrality).
 * - S2: a denying listener flips the resolution and carries its reason.
 * - S3 (AD-3): a throwing listener propagates; the host catch pattern denies
 *   fail-closed with a generic reason.
 */
#[CoversClass(ArticlePermissionFilterEvent::class)]
class ArticlePermissionFilterDispatchTest extends TestCase
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

    private function newEvent(): ArticlePermissionFilterEvent
    {
        return new ArticlePermissionFilterEvent(
            'REF-001',
            ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            'admin'
        );
    }

    public function testNoListenersResolvesAllow(): void
    {
        $dispatcher = FSEventDispatcher::getInstance();
        $event = $this->newEvent();

        $dispatched = $dispatcher->dispatch($event, ArticlePermissionFilterEvent::NAME);

        $this->assertSame($event, $dispatched, 'Dispatch must return the same event instance');
        $this->assertTrue($dispatched->isAllowed(), 'S1: zero listeners must resolve allow');
        $this->assertSame('', $dispatched->getDenialReason());
    }

    public function testDenyingListenerFlipsResolutionAndCarriesReason(): void
    {
        $dispatcher = FSEventDispatcher::getInstance();
        $dispatcher->addListener(
            ArticlePermissionFilterEvent::NAME,
            function (ArticlePermissionFilterEvent $event): void {
                $event->deny('editor sin asignación');
            }
        );

        $event = $this->newEvent();
        $dispatched = $dispatcher->dispatch($event, ArticlePermissionFilterEvent::NAME);

        $this->assertFalse($dispatched->isAllowed(), 'S2: denying listener must flip resolution to deny');
        $this->assertSame('editor sin asignación', $dispatched->getDenialReason());
    }

    public function testThrowingListenerPropagatesException(): void
    {
        $dispatcher = FSEventDispatcher::getInstance();
        $dispatcher->addListener(
            ArticlePermissionFilterEvent::NAME,
            function (): never {
                throw new \RuntimeException('listener bug');
            }
        );

        $event = $this->newEvent();

        $this->expectException(\RuntimeException::class);
        $dispatcher->dispatch($event, ArticlePermissionFilterEvent::NAME);
    }

    public function testHostCatchPatternDeniesFailClosed(): void
    {
        $dispatcher = FSEventDispatcher::getInstance();
        $dispatcher->addListener(
            ArticlePermissionFilterEvent::NAME,
            function (): never {
                throw new \RuntimeException('listener bug');
            }
        );

        $event = $this->newEvent();

        try {
            $dispatcher->dispatch($event, ArticlePermissionFilterEvent::NAME);
            $this->fail('Dispatch must propagate listener exceptions; the host MUST catch them (AD-3).');
        } catch (\Throwable) {
            // Exact host pattern per AD-3: on Throwable, log and deny fail-closed.
            $event->deny('permission filter error');
        }

        $this->assertFalse($event->isAllowed(), 'Listener exception must deny (fail-closed, AD-3)');
        $this->assertSame('permission filter error', $event->getDenialReason());
    }

    // ---- Per-article prices tab: dispatch semantics on price save (R-MT-005, R-RG-002) ----

    /**
     * The per-list price save path MUST dispatch the existing event with
     * ACTION_EDIT_ARTICLE + referencia (D2: the event class is NOT modified).
     */
    public function testPriceSaveDispatchesEditArticleEventWithReferencia(): void
    {
        FSEventDispatcher::reset();
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php';

        $captured = null;
        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            function (ArticlePermissionFilterEvent $event) use (&$captured): void {
                $captured = $event;
            }
        );

        $dispatched = \FSFramework\Plugins\catalogo_core\Controller\VentasArticulo::dispatchPriceEditFilter('REF-9', 'juan');

        $this->assertNotNull($dispatched, 'The price save path must dispatch the filter event');
        $this->assertTrue($dispatched->isAllowed(), 'Zero denying listeners must resolve allow');
        $this->assertNotNull($captured);
        $this->assertSame(ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE, $captured->getAction());
        $this->assertSame('REF-9', $captured->getReferencia());
        $this->assertSame('juan', $captured->getNick());
    }

    /** A denying listener blocks the price save with its reason (R-RG-002). */
    public function testPriceSaveRespectsDenyingListener(): void
    {
        FSEventDispatcher::reset();
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php';

        FSEventDispatcher::getInstance()->addListener(
            ArticlePermissionFilterEvent::NAME,
            function (ArticlePermissionFilterEvent $event): void {
                $event->deny('editor: el artículo no está asignado a tus grupos');
            }
        );

        $dispatched = \FSFramework\Plugins\catalogo_core\Controller\VentasArticulo::dispatchPriceEditFilter('REF-9', 'juan');

        $this->assertNotNull($dispatched);
        $this->assertFalse($dispatched->isAllowed());
        $this->assertSame('editor: el artículo no está asignado a tus grupos', $dispatched->getDenialReason());
    }
}
