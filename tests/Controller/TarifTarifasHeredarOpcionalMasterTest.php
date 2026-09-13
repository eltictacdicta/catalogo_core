<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2025 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Unit 5 hybrid contract: `tarif_tarifas::heredar_estructura()` must copy the
 * per-`(tarifa, opcional)` master state as an additional step (spec "Master
 * lifecycle: seed, lazy inherit, copy"; design "step 11 master copy").
 *
 * Relocated from tarifario when the tarifas CRUD page moved to catalogo_core.
 *
 * DB-free, following the TarifTarifasGuardarTest precedent: an anonymous
 * subclass skips the heavy constructor and overrides the
 * `opcional_master_state()` seam with a spy, so the copy helper can be
 * exercised without a live database. The full `heredar_estructura()` ordering
 * is asserted as a source contract because its ten legacy steps reach the DB.
 */
final class TarifTarifasHeredarOpcionalMasterTest extends TestCase
{
    private const CONTROLLER = 'plugins/catalogo_core/controller/tarif_tarifas.php';

    /**
     * The legacy copy steps that must stay intact and before the new one.
     */
    private const LEGACY_STEPS = [
        'copy_precios_articulos(',
        'copy_precios_opcionales(',
        'copy_grupos(',
        'copy_etiquetas_articulos(',
        'copy_etiquetas_familias(',
        'copy_opcional_familias(',
        'copy_articulo_opcionales(',
        'copy_opcional_etiquetas(',
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Legacy base classes and the modelo are not PSR-4 autoloaded; load the
        // same chain the controller file does.
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/base/fs_app.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';
        require_once FS_FOLDER . '/' . self::CONTROLLER;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function source(): string
    {
        $path = FS_FOLDER . '/' . self::CONTROLLER;
        if (!is_file($path)) {
            self::fail('missing catalogo_core path: ' . self::CONTROLLER);
        }

        return (string) file_get_contents($path);
    }

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

    private function masterStub(): object
    {
        return new class() {
            /** @var list<array{0: string, 1: string}> */
            public array $copyCalls = [];

            public function copy_from_tarifa($origen, $destino): bool
            {
                $this->copyCalls[] = [(string) $origen, (string) $destino];

                return true;
            }
        };
    }

    private function buildController(object $master): object
    {
        return new class($master) extends \tarif_tarifas {
            private $masterStub;

            public function __construct($master)
            {
                // Skip parent::__construct — heavy FS init would connect to DB.
                $this->masterStub = $master;
            }

            protected function opcional_master_state()
            {
                return $this->masterStub;
            }
        };
    }

    // =====================================================================
    // Behaviour: the new copy helper delegates to the master
    // =====================================================================

    public function test_copy_tarifa_opcionales_delegates_to_the_master(): void
    {
        $master = $this->masterStub();
        $controller = $this->buildController($master);

        $method = new \ReflectionMethod(\tarif_tarifas::class, 'copy_tarifa_opcionales');
        $method->setAccessible(true);
        $method->invoke($controller, 'T-ORIGEN', 'T-DESTINO');

        self::assertSame(
            [['T-ORIGEN', 'T-DESTINO']],
            $master->copyCalls,
            'copy_tarifa_opcionales() must call copy_from_tarifa(origen, destino) on the master'
        );
    }

    // =====================================================================
    // Source contracts: step 11 in heredar_estructura() and master wiring
    // =====================================================================

    public function test_heredar_estructura_copies_the_master_after_the_ten_steps(): void
    {
        $src = $this->source();
        $body = $this->methodBody($src, 'heredar_estructura');
        self::assertNotSame('', $body, 'heredar_estructura() body must be found');

        self::assertStringContainsString(
            'copy_tarifa_opcionales($origen, $destino)',
            $body,
            'heredar_estructura() must copy the opcional master state'
        );

        $masterPos = strpos($body, 'copy_tarifa_opcionales(');
        self::assertNotFalse($masterPos);

        foreach (self::LEGACY_STEPS as $step) {
            $stepPos = strpos($body, $step);
            self::assertNotFalse($stepPos, 'the legacy step ' . $step . ' must stay in heredar_estructura()');
            self::assertLessThan(
                $masterPos,
                $stepPos,
                'the master copy must run after the legacy step ' . $step
            );
        }

        // The familia/articulo copies are direct model calls; ensure they too
        // precede the new step.
        $familiaPos = strpos($body, '$this->tarifa_familia->copy_from_tarifa(');
        $articuloPos = strpos($body, '$this->tarifa_articulo->copy_from_tarifa(');
        self::assertNotFalse($familiaPos, 'the familia copy step must stay');
        self::assertNotFalse($articuloPos, 'the articulo copy step must stay');
        self::assertLessThan($masterPos, $familiaPos, 'the master copy must run after the familia copy');
        self::assertLessThan($masterPos, $articuloPos, 'the master copy must run after the articulo copy');
    }

    public function test_controller_requires_and_imports_the_master_model(): void
    {
        $src = $this->source();

        self::assertStringContainsString(
            'model/tarif_tarifa_opcional.php',
            $src,
            'tarif_tarifas must require the master model'
        );
        self::assertStringContainsString(
            'use FSFramework\\model\\tarif_tarifa_opcional;',
            $src,
            'tarif_tarifas must import the master model'
        );
    }

    public function test_copy_helper_uses_the_master_seam_and_copy_from_tarifa(): void
    {
        $src = $this->source();
        $body = $this->methodBody($src, 'copy_tarifa_opcionales');
        self::assertNotSame('', $body, 'copy_tarifa_opcionales() body must be found');
        self::assertStringContainsString('opcional_master_state()', $body, 'the helper must use the overridable seam');
        self::assertStringContainsString('copy_from_tarifa(', $body, 'the helper must call the master copy method');
    }

    /**
     * A tarifa copy must not drop the per-lista opcional price data: the
     * legacy copy only carried `precio`, losing `porcentaje` and `en_catalogo`.
     */
    public function test_copy_precios_opcionales_preserves_the_per_lista_columns(): void
    {
        $src = $this->source();
        $body = $this->methodBody($src, 'copy_precios_opcionales');
        self::assertNotSame('', $body, 'copy_precios_opcionales() body must be found');

        self::assertStringContainsString(
            'precio',
            $body,
            'the price value must still be copied'
        );
        self::assertStringContainsString(
            'porcentaje',
            $body,
            'the per-lista percentage must survive a tarifa copy'
        );
        self::assertStringContainsString(
            'en_catalogo',
            $body,
            'the per-lista catalog flag must survive a tarifa copy'
        );
    }
}
