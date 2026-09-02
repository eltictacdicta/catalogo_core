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

use FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the neutral article permission filter event (R-TAR-HOOK-004).
 *
 * Contract (design AD-1/AD-3): default-allow event carrying the gating context,
 * with deny(reason) mutation support. Zero plugin coupling.
 */
#[CoversClass(ArticlePermissionFilterEvent::class)]
class ArticlePermissionFilterEventTest extends TestCase
{
    public function testDefaultsToAllowWithEmptyReason(): void
    {
        $event = new ArticlePermissionFilterEvent(
            'REF-001',
            ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            'admin'
        );

        $this->assertTrue($event->isAllowed(), 'Event must default to allow with zero listeners');
        $this->assertSame('', $event->getDenialReason(), 'Denial reason must be empty by default');
    }

    public function testDenyFlipsFlagAndCarriesReason(): void
    {
        $event = new ArticlePermissionFilterEvent(
            'REF-001',
            ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            'admin'
        );
        $event->deny('sin permisos de edición');

        $this->assertFalse($event->isAllowed(), 'deny() must flip the resolution to deny');
        $this->assertSame('sin permisos de edición', $event->getDenialReason());
    }

    public function testExposesReferentialActionAndNick(): void
    {
        $event = new ArticlePermissionFilterEvent(
            'REF-042',
            ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            'gestor1'
        );

        $this->assertSame('REF-042', $event->getReferencia());
        $this->assertSame('edit_article', $event->getAction());
        $this->assertSame('gestor1', $event->getNick());
    }

    public function testCodtarifaDefaultsToEmptyString(): void
    {
        $event = new ArticlePermissionFilterEvent(
            'REF-001',
            ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            'admin'
        );

        $this->assertSame('', $event->getCodtarifa(), 'codtarifa is optional and defaults to empty string');

        $eventWithTarifa = new ArticlePermissionFilterEvent(
            'REF-001',
            ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            'admin',
            'T001'
        );

        $this->assertSame('T001', $eventWithTarifa->getCodtarifa());
    }

    public function testFrozenNameAndActionConstants(): void
    {
        $this->assertSame(
            'catalogo_core.article_permission_filter',
            ArticlePermissionFilterEvent::NAME,
            'Event NAME constant is frozen by spec'
        );
        $this->assertSame(
            'edit_article',
            ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            'ACTION_EDIT_ARTICLE constant is frozen by design AD-3'
        );
    }

    public function testExtendsSymfonyContractEvent(): void
    {
        $event = new ArticlePermissionFilterEvent(
            'REF-001',
            ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            'admin'
        );

        $this->assertInstanceOf(
            \Symfony\Contracts\EventDispatcher\Event::class,
            $event,
            'Event must be dispatchable through the Symfony EventDispatcher'
        );
    }
}
