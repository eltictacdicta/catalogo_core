<?php
declare(strict_types=1);
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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Dependency-free audit of the family/catalog capability (familias-jerarquia
 * task 4.4, D6 / R-MT-002 modified).
 *
 * WHAT IS AUDITED
 * 1. `plugins/tarifario/` — the sibling plugin — must not be required, used or
 *    path-referenced from catalogo_core production code, and `fsframework.ini`
 *    must not declare it as a dependency. This is the hard rule: the coupling
 *    is by event, one-way.
 * 2. The family-structure READ PATH introduced by this change (provider,
 *    controller, wrapper, view) must read only canonical `catalogo_*` tables —
 *    no legacy `codtarifa` surface.
 *
 * WHAT IS DELIBERATELY NOT A FAILURE
 * catalogo_core OWNS the legacy models and tables it absorbed earlier
 * (`model/tarif_tarifa_familia.php`, `model/tarif_tarifa_articulo.php`, their
 * XML, the legacy `controller/tarif_*` pages, the caracteristicas backfill and
 * the `Init` surface ensure). Those are its own pre-existing files, not a
 * `plugins/tarifario/` dependency, and the caracteristicas services read legacy
 * columns by design (their own SDDs), so they are pinned as an explicit
 * BASELINE: a NEW file touching the legacy names fails, pre-existing debt stays
 * visible instead of being silently ignored.
 */
final class TarifarioCouplingAuditTest extends TestCase
{
    private const LEGACY_TABLES = ['tarif_tarifa_familia', 'tarif_tarifa_articulo'];

    /**
     * Files of the read path this capability introduces: they must stay on the
     * canonical side only.
     */
    private const NEW_READ_PATH = [
        '/Services/CatalogoViewProvider.php',
        '/Controller/VentasCatalogoView.php',
        '/controller/ventas_catalogo_view.php',
        '/View/ventas_catalogo_view.html.twig',
    ];

    /**
     * Pre-existing production files that mention the legacy tables. Each entry
     * is a legacy surface catalogo_core already owned before this change (or the
     * migration service this change adds).
     */
    private const LEGACY_REFERENCE_BASELINE = [
        // The one-way copy added by this change: the only place allowed to READ them.
        '/Services/FamiliaEstructuraMigration.php',
        // Legacy models and their schemas, absorbed in earlier changes.
        '/model/tarif_tarifa_familia.php',
        '/model/tarif_tarifa_articulo.php',
        '/model/tarif_tarifa_articulo_etiqueta.php',
        '/model/tarif_tarifa_articulo_opcional.php',
        '/model/tarif_tarifa_opcional.php',
        '/model/tarif_tarifa_opcional_resolver.php',
        '/model/tarif_familia.php',
        '/model/table/tarif_tarifa_familia.xml',
        '/model/table/tarif_tarifa_articulo.xml',
        '/model/table/tarif_tarifa_articulo_etiqueta.xml',
        '/model/table/tarif_tarifa_articulo_opcional.xml',
        '/model/table/tarif_tarifa_opcional.xml',
        // Legacy UI and its partial kept alive until the tarifario follow-up.
        '/controller/tarif_familias.php',
        '/controller/tarif_tarifas.php',
        '/Controller/VentasArticulo.php',
        '/View/partials/familias/familia_row.html.twig',
        // Install/bootstrap surface ensure and the caracteristicas read-through
        // of legacy columns (own SDDs, not part of the family-structure flow).
        '/Init.php',
        '/Services/CaracteristicaBackfillMigration.php',
        '/Services/CaracteristicaColumnDropMigration.php',
        '/Services/CaracteristicaValorStore.php',
    ];

    #[Test]
    public function productionCodeNeverReferencesTheTarifarioPlugin(): void
    {
        $offenders = [];
        foreach ($this->productionFiles() as $relative => $contents) {
            if (str_contains($contents, 'plugins/tarifario')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'catalogo_core must not require, use or path-reference plugins/tarifario (D6 hard rule)'
        );
    }

    #[Test]
    public function manifestDeclaresNoDependencyOnTheTarifarioPlugin(): void
    {
        $ini = (string) file_get_contents($this->pluginRoot() . '/fsframework.ini');

        preg_match('/^require\s*=\s*"?(.*?)"?\s*$/mi', $ini, $matches);
        $requires = $matches[1] ?? '';

        $this->assertStringNotContainsStringIgnoringCase(
            'tarifario',
            $requires,
            'fsframework.ini must not require the tarifario plugin (D6)'
        );
    }

    #[Test]
    public function theNewReadPathTouchesNoLegacyTable(): void
    {
        foreach (self::NEW_READ_PATH as $relative) {
            $path = $this->pluginRoot() . $relative;
            $this->assertFileExists($path, sprintf('%s is part of the audited read path', $relative));

            $contents = (string) file_get_contents($path);
            foreach (self::LEGACY_TABLES as $table) {
                $this->assertStringNotContainsString(
                    $table,
                    $contents,
                    sprintf(
                        '%s must read canonical catalogo_* tables only: the catalog view never consults legacy codtarifa (R-MT-002)',
                        $relative
                    )
                );
            }
        }
    }

    #[Test]
    public function legacyTableReadsStayInsideThePinnedBaseline(): void
    {
        $unexpected = [];
        foreach ($this->productionFiles() as $relative => $contents) {
            if (in_array($relative, self::LEGACY_REFERENCE_BASELINE, true)) {
                continue;
            }
            foreach (self::LEGACY_TABLES as $table) {
                if (str_contains($contents, $table)) {
                    $unexpected[] = $relative . ' (' . $table . ')';
                }
            }
        }

        $this->assertSame(
            [],
            $unexpected,
            'A new file touching tarif_tarifa_familia/tarif_tarifa_articulo must be either migrated to the canonical '
            . 'tables or added to LEGACY_REFERENCE_BASELINE with a rationale — the family-structure flow may only read '
            . 'them from Services/FamiliaEstructuraMigration.php'
        );
    }

    #[Test]
    public function theMigrationServiceIsTheDesignatedLegacyReader(): void
    {
        $contents = (string) file_get_contents($this->pluginRoot() . '/Services/FamiliaEstructuraMigration.php');

        foreach (self::LEGACY_TABLES as $table) {
            $this->assertStringContainsString(
                $table,
                $contents,
                sprintf('The copy service is the designated one-way reader of %s', $table)
            );
        }
    }

    private function pluginRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Production files only: tests, vendor, SDD docs and VCS metadata are out of
     * scope (they legitimately name paths and legacy tables).
     *
     * @return array<string, string> relative path => contents
     */
    private function productionFiles(): array
    {
        $root = $this->pluginRoot();
        $excludedDirs = ['vendor', 'tests', 'openspec', '.git'];
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current) use ($excludedDirs): bool {
                    if ($current->isDir()) {
                        return !in_array($current->getFilename(), $excludedDirs, true);
                    }

                    return true;
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            if (!in_array($file->getExtension(), ['php', 'twig', 'xml', 'ini'], true)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
            $files[$relative] = (string) file_get_contents($file->getPathname());
        }

        return $files;
    }
}
