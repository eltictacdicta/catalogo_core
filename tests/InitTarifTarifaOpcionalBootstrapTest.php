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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Bootstrap contract for the per-(tarifa, opcional) master table
 * (spec "Opcional master table bootstrap").
 *
 * `Init::ensureOpcionalesTarifaTables()` must ensure the master table after
 * the five moved `tarif_*` opcional tables (whose FK targets already exist),
 * keep the pinned 5-table `FK_SAFE_SEQUENCE` literal untouched, stay
 * idempotent, and be a safe no-op when the master class is absent.
 *
 * DB-free by design: instantiating the master reaches a live database, so
 * this pins the bootstrap wiring (ordering, guards, non-destructive ensure)
 * exactly like `InitOpcionalesTablesTest` pins the moved-table sequence.
 */
final class InitTarifTarifaOpcionalBootstrapTest extends TestCase
{
    private const MASTER_MODEL = 'plugins/catalogo_core/model/tarif_tarifa_opcional.php';

    private const MASTER_XML = 'plugins/catalogo_core/model/table/tarif_tarifa_opcional.xml';

    /** Last model of the pinned 5-table FK-safe loop. */
    private const LAST_MOVED_MODEL = 'tarif_tarifa_articulo_opcional';

    private function initSource(): string
    {
        $path = FS_FOLDER . '/plugins/catalogo_core/Init.php';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** Extracts a method body by brace matching from its signature. */
    private function methodSource(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            self::fail('missing catalogo_core method: ' . $signature);
        }

        $open = (int) strpos($src, '{', (int) $start);
        $depth = 0;
        $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, (int) $start, $i - $start + 1);
                }
            }
        }

        self::fail('method body is not terminated: ' . $signature);
    }

    private function ensureMethodSource(string $src): string
    {
        return $this->methodSource($src, 'public static function ensureOpcionalesTarifaTables()');
    }

    public function test_master_model_and_xml_exist_at_catalogo_core_paths(): void
    {
        $this->assertFileExists(FS_FOLDER . '/' . self::MASTER_MODEL);
        $this->assertFileExists(FS_FOLDER . '/' . self::MASTER_XML);
    }

    public function test_master_is_ensured_after_the_five_moved_tables(): void
    {
        $method = $this->ensureMethodSource($this->initSource());

        $masterPos = strpos($method, 'tarif_tarifa_opcional.php');
        $lastMovedPos = strpos($method, "'" . self::LAST_MOVED_MODEL . "'");

        $this->assertNotFalse(
            $masterPos,
            'the bootstrap must ensure the per-(tarifa, opcional) master table'
        );
        $this->assertNotFalse(
            $lastMovedPos,
            'the pinned 5-table loop must stay in the bootstrap'
        );
        $this->assertGreaterThan(
            $lastMovedPos,
            $masterPos,
            'the master must be ensured after the five moved tarif_* tables (FK targets already exist)'
        );
    }

    public function test_master_ensure_is_guarded_for_class_safety(): void
    {
        $method = $this->ensureMethodSource($this->initSource());

        $this->assertMatchesRegularExpression(
            '/is_file\s*\(\s*\$masterFile\s*\)\s*\)\s*\{\s*require_once\s+\$masterFile\s*;\s*\}/',
            $method,
            'the master require must be guarded by is_file (no-op when the file is absent)'
        );
        $this->assertMatchesRegularExpression('/class_exists\(\$masterFqcn,\s*false\)/', $method);
        $this->assertMatchesRegularExpression('/is_subclass_of\(\$masterFqcn,\s*\\\\fs_model::class\)/', $method);

        $posGuard = strpos($method, 'class_exists($masterFqcn, false)');
        $posNew = strpos($method, 'new $masterFqcn()');
        $this->assertNotFalse($posGuard);
        $this->assertNotFalse($posNew);
        $this->assertLessThan(
            $posNew,
            $posGuard,
            'an absent master class must be a safe no-op, not a fatal error'
        );
    }

    public function test_master_ensure_is_idempotent_and_non_destructive(): void
    {
        $method = $this->ensureMethodSource($this->initSource());

        $this->assertDoesNotMatchRegularExpression(
            '/\b(DROP|TRUNCATE|DELETE|ALTER|INSERT|UPDATE)\b/i',
            $method,
            'the bootstrap ensure path must only construct the model (fs_model is the idempotent ensure)'
        );
        $this->assertStringNotContainsString(
            'seed_if_empty',
            $method,
            'the bootstrap must not force a seed on every run'
        );
    }
}
