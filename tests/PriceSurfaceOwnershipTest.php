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
 * Ownership guardrail for the price surfaces
 * (SDD: unificar-superficies-precio-catalogo-core, WU-0.1 — R-PSO-001,
 * R-PSO-003, R-PSO-004, R-CRH-002).
 *
 * `catalogo_core` owns every price surface; the consumer plugin only
 * registers into neutral seams. This test is test-only (no production code)
 * and lives under tests/, which the ownership scan excludes, so its own
 * fixtures may name consumer tables.
 */
class PriceSurfaceOwnershipTest extends TestCase
{
    /**
     * Consumer-owned storage tokens that a seam file MUST NOT name. Access to
     * these tables belongs to the target registered by the consumer.
     */
    private const CONSUMER_TABLE_TOKENS = [
        'tarif_articulo_precios',
        'tarif_opcional_precios',
        'tarif_grupo_articulos',
        'tarif_tarifas',
    ];

    /**
     * Seam files of this change, relative to the plugin root. Extended in
     * F1-F3 with each NEW file introduced by the change (never with
     * pre-existing allowlisted files).
     */
    private const SEAM_FILES = [
        '/Services/CatalogoPriceUpdateService.php',
    ];

    /** Frozen by R-TAR-HOOK-003 / R-PSO-004 — MUST NOT change. */
    private const FROZEN_HOOKS = [
        'ventas_articulo_tab_pane_after',
        'ventas_articulo_tabs_after',
        'ventas_opcional_tab_pane_after',
        'ventas_opcional_tabs_after',
    ];

    private const HOST_VIEWS = [
        '/View/ventas_articulo.html.twig',
        '/View/ventas_opcional.html.twig',
    ];

    /** Allowlist size at the start of the change (design C7: verified 52). */
    private const FROZEN_ALLOWLIST_SIZE = 52;

    private const ALLOWLIST_OWNER = '/tests/CatalogoCoreHookMarkersTest.php';

    // =====================================================================
    // R-PSO-001 — the seams never name consumer tables
    // =====================================================================

    public function testSeamFilesContainNoConsumerTableTokens(): void
    {
        $violations = [];

        foreach (self::SEAM_FILES as $relative) {
            $path = $this->pluginRoot() . $relative;
            $this->assertFileExists($path, "Seam file {$relative} must exist");

            $contents = (string) file_get_contents($path);
            foreach (self::CONSUMER_TABLE_TOKENS as $token) {
                if (stripos($contents, $token) !== false) {
                    $violations[] = $relative . ' -> ' . $token;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Seam files must stay neutral: consumer table access belongs to the registered target (R-PSO-001).'
        );
    }

    // =====================================================================
    // R-PSO-003 — the allowlist does not grow
    // =====================================================================

    public function testAllowlistIsFrozenAtFiftyTwoEntries(): void
    {
        $entries = $this->allowlistEntries();

        $this->assertCount(
            self::FROZEN_ALLOWLIST_SIZE,
            $entries,
            'TARIFARIO_ALLOWLIST is frozen: a new file naming the consumer must fail the guardrail, not join the list (R-PSO-003).'
        );
        $this->assertSame($entries, array_values(array_unique($entries)), 'Allowlist entries must be unique.');
    }

    public function testAllowlistNeverIncludesTheNewSeamFiles(): void
    {
        $entries = $this->allowlistEntries();

        foreach (self::SEAM_FILES as $relative) {
            $this->assertNotContains(
                $relative,
                $entries,
                "{$relative} is a seam file and must never be allowlisted for consumer references (R-PSO-003)."
            );
        }
    }

    // =====================================================================
    // R-PSO-004 / R-CRH-002 — only the four frozen hooks in the host views
    // =====================================================================

    public function testHostViewsKeepOnlyTheFourFrozenHooks(): void
    {
        $names = [];
        foreach (self::HOST_VIEWS as $relative) {
            $path = $this->pluginRoot() . $relative;
            $this->assertFileExists($path, "Host view {$relative} must exist");

            preg_match_all("/render_hook\(\s*'([^']+)'/", (string) file_get_contents($path), $matches);
            $names = array_merge($names, $matches[1]);
        }
        sort($names);

        $this->assertSame(
            self::FROZEN_HOOKS,
            $names,
            'Host views must declare exactly the four frozen hooks; any new hook lives only in the price update view (R-CRH-002).'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function pluginRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Reads the allowlist from the source of its owner test instead of loading
     * the class: the plugin suite runs with process isolation and the owner
     * keeps the list as a private constant.
     *
     * @return string[]
     */
    private function allowlistEntries(): array
    {
        $path = $this->pluginRoot() . self::ALLOWLIST_OWNER;
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);
        $matched = preg_match('/TARIFARIO_ALLOWLIST\s*=\s*\[(.*?)\n    \];/s', $source, $block);
        $this->assertSame(1, $matched, 'TARIFARIO_ALLOWLIST declaration not found in its owner test.');

        preg_match_all("/^\s*'(\/[^']+)',\s*$/m", $block[1], $entries);

        return $entries[1];
    }
}
