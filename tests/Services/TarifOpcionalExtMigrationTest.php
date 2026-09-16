<?php
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
declare(strict_types=1);

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\TarifOpcionalExtMigration;
use PHPUnit\Framework\TestCase;

/**
 * Ownership + contract test for the opcional extension migration service,
 * moved from tarifario to catalogo_core (design D9 test disposition).
 */
final class TarifOpcionalExtMigrationTest extends TestCase
{
    private const SERVICE_RELATIVE = 'plugins/catalogo_core/Services/TarifOpcionalExtMigration.php';

    public function testMigrationServiceClassExists(): void
    {
        $this->assertFileExists(FS_FOLDER . '/' . self::SERVICE_RELATIVE);
        $this->assertTrue(
            class_exists(TarifOpcionalExtMigration::class),
            'TarifOpcionalExtMigration must exist in the catalogo_core plugin'
        );
    }

    public function testMigrationServiceNoLongerLivesInTarifario(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/tarifario/Services/TarifOpcionalExtMigration.php',
            'The migration service must be owned by catalogo_core'
        );
    }

    public function testExtTableXmlDefinesTarifOpcionalExt(): void
    {
        $path = FS_FOLDER . '/plugins/catalogo_core/model/table/tarif_opcional_ext.xml';
        $this->assertFileExists($path);

        $xml = (string) file_get_contents($path);
        $this->assertStringContainsString('<nombre>id_opcional</nombre>', $xml);
        $this->assertStringContainsString('<nombre>ref_sap</nombre>', $xml);
    }

    /**
     * D12 / CAR-15 clause 1: the opcional-owned visibility flags leave the
     * 1:1 `tarif_opcional_ext` table. The ext table keeps only `ref_sap`.
     */
    public function testOpcionalFlagsLeaveTarifOpcionalExt(): void
    {
        $xml = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/model/table/tarif_opcional_ext.xml');

        foreach (['en_catalogo', 'en_tarifa'] as $removed) {
            $this->assertStringNotContainsString(
                '<nombre>' . $removed . '</nombre>',
                $xml,
                $removed . ' must be absent from tarif_opcional_ext'
            );
        }
        $this->assertStringContainsString('<nombre>ref_sap</nombre>', $xml, 'ref_sap must remain');
    }

    public function testOpcionalFlagsLeaveTarifTarifaOpcional(): void
    {
        $xml = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/model/table/tarif_tarifa_opcional.xml');

        foreach (['en_catalogo', 'en_tarifa'] as $removed) {
            $this->assertStringNotContainsString(
                '<nombre>' . $removed . '</nombre>',
                $xml,
                $removed . ' must be absent from tarif_tarifa_opcional'
            );
        }
        $this->assertStringContainsString('<nombre>activa</nombre>', $xml, 'activa must remain');
        $this->assertStringContainsString('<nombre>orden</nombre>', $xml, 'orden must remain');
    }

    public function testMigrationServiceStopsCopyingTheRemovedFlags(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/' . self::SERVICE_RELATIVE);
        $copy = $this->methodBody($source, 'copyLegacyRows');

        $this->assertNotSame('', $copy, 'copyLegacyRows() body must be found');
        $this->assertStringNotContainsString(
            'en_catalogo',
            $copy,
            'the copy must not write the removed catalog flag'
        );
        $this->assertStringNotContainsString(
            'en_tarifa',
            $copy,
            'the copy must not write the removed tarifa flag'
        );
        $this->assertStringContainsString(
            'INSERT INTO tarif_opcional_ext (id_opcional, ref_sap)',
            $copy,
            'the copy must target only ref_sap'
        );
        $this->assertStringContainsString(
            'INSERT IGNORE INTO tarif_opcional_ext (id_opcional, ref_sap)',
            $copy,
            'the MySQL path must target only ref_sap'
        );
    }

    /**
     * Extracts a method body so an assertion cannot be satisfied by unrelated
     * code elsewhere in the file.
     */
    private function methodBody(string $src, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{/';
        if (!preg_match($pattern, $src, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $matches[0][1] + strlen($matches[0][0]);
        $depth = 1;
        $length = strlen($src);
        for ($i = $start; $i < $length; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start);
                }
            }
        }

        return '';
    }

    public function testCatalogoOpcionalesXmlDoesNotDefineTarifarioFields(): void
    {
        $path = FS_FOLDER . '/plugins/catalogo_core/model/table/catalogo_opcionales.xml';
        $this->assertFileExists($path);

        $xml = (string) file_get_contents($path);

        $this->assertStringNotContainsString('<nombre>ref_sap</nombre>', $xml);
        $this->assertStringNotContainsString('<nombre>codigo2</nombre>', $xml);
        $this->assertStringNotContainsString('<nombre>en_catalogo</nombre>', $xml);
        $this->assertStringNotContainsString('<nombre>en_tarifa</nombre>', $xml);
    }

    public function testTarifOpcionalModelUsesExtensionTable(): void
    {
        foreach ([
            'plugins/catalogo_core/model/core/catalogo_opcional.php',
            'plugins/catalogo_core/model/tarif_opcional_precio.php',
            'plugins/catalogo_core/model/tarif_opcional_ext.php',
            'plugins/catalogo_core/model/tarif_opcional.php',
        ] as $relative) {
            $this->assertFileExists(FS_FOLDER . '/' . $relative);
        }

        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional_ext.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional.php';

        $source = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional.php'
        );

        $this->assertStringContainsString('tarif_opcional_ext', $source);
        $this->assertStringContainsString('load_legacy_extension_columns', $source);
    }

    public function testMigrationServiceRequiresMovedModelPath(): void
    {
        $this->assertFileExists(FS_FOLDER . '/' . self::SERVICE_RELATIVE);

        $source = (string) file_get_contents(FS_FOLDER . '/' . self::SERVICE_RELATIVE);

        $this->assertStringContainsString(
            'namespace FSFramework\\Plugins\\catalogo_core\\Services;',
            $source,
            'The service must declare the catalogo_core namespace'
        );
        $this->assertStringContainsString(
            'plugins/catalogo_core/model/tarif_opcional_ext.php',
            $source,
            'The service must require the moved ext model from catalogo_core'
        );
        $this->assertStringNotContainsString(
            'plugins/tarifario/model/tarif_opcional_ext.php',
            $source,
            'The service must not require tarifario model paths'
        );
    }
}
