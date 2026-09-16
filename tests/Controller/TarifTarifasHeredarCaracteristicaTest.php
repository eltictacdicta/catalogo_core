<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
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
 * CAR-10 wiring: `tarif_tarifas::heredar_estructura()` must clone the three
 * feature-value scopes as an ordered step after the eleven existing copy
 * steps (design §11).
 *
 * DB-free, following the TarifTarifasHeredarOpcionalMasterTest precedent: an
 * anonymous subclass skips the heavy constructor and overrides the
 * `caracteristica_value_model()` seam with spies, so the copy helper can be
 * exercised without a live database. The full `heredar_estructura()`
 * ordering is asserted as a source contract because its eleven legacy steps
 * reach the DB.
 */
final class TarifTarifasHeredarCaracteristicaTest extends TestCase
{
    private const CONTROLLER = 'plugins/catalogo_core/controller/tarif_tarifas.php';

    /** The ordered scopes the clone must walk. */
    private const SCOPES = ['articulo', 'familia', 'global'];

    /**
     * The legacy copy steps that must stay intact and before the new one.
     */
    private const LEGACY_STEPS = [
        '$this->tarifa_familia->copy_from_tarifa(',
        '$this->tarifa_articulo->copy_from_tarifa(',
        'copy_precios_articulos(',
        'copy_precios_opcionales(',
        'copy_grupos(',
        'copy_etiquetas_articulos(',
        'copy_etiquetas_familias(',
        'copy_opcional_familias(',
        'copy_articulo_opcionales(',
        'copy_opcional_etiquetas(',
        'copy_tarifa_opcionales(',
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Legacy base classes and the models are not PSR-4 autoloaded; load
        // the same chain the controller file does.
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

    /**
     * Overridable scope-model double: records every `copy_from_tarifa()` call.
     */
    private function scopeStub(): object
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

    /**
     * @param array<string, object> $stubs
     */
    private function buildController(array $stubs, array &$requested): object
    {
        return new class($stubs, $requested) extends \tarif_tarifas {
            /** @var array<string, object> */
            private array $stubs;

            /** @var list<string> */
            private array $requested = [];

            public function __construct(array $stubs, array &$requested)
            {
                // Skip parent::__construct — heavy FS init would connect to DB.
                $this->stubs = $stubs;
                $this->requested = &$requested;
            }

            protected function caracteristica_value_model(string $scope)
            {
                $this->requested[] = $scope;

                return $this->stubs[$scope];
            }
        };
    }

    // =====================================================================
    // Behaviour: the clone helper walks the three scopes through the seam
    // =====================================================================

    public function test_copy_caracteristicas_clones_the_three_scopes_in_order(): void
    {
        $stubs = [
            'articulo' => $this->scopeStub(),
            'familia' => $this->scopeStub(),
            'global' => $this->scopeStub(),
        ];
        $requested = [];
        $controller = $this->buildController($stubs, $requested);

        $method = new \ReflectionMethod(\tarif_tarifas::class, 'copy_caracteristicas');
        $method->setAccessible(true);
        $method->invoke($controller, 'T-ORIGEN', 'T-DESTINO');

        self::assertSame(self::SCOPES, $requested, 'the three scopes must be walked in order');
        foreach (self::SCOPES as $scope) {
            self::assertSame(
                [['T-ORIGEN', 'T-DESTINO']],
                $stubs[$scope]->copyCalls,
                'the ' . $scope . ' scope must be cloned from origen into destino'
            );
        }
    }

    // =====================================================================
    // Source contracts: step 12 in heredar_estructura() and the seam
    // =====================================================================

    public function test_heredar_estructura_clones_features_after_the_eleven_steps(): void
    {
        $src = $this->source();
        $body = $this->methodBody($src, 'heredar_estructura');
        self::assertNotSame('', $body, 'heredar_estructura() body must be found');

        self::assertStringContainsString(
            'copy_caracteristicas($origen, $destino)',
            $body,
            'heredar_estructura() must clone the feature values as its final step'
        );

        $clonePos = strpos($body, 'copy_caracteristicas(');
        self::assertNotFalse($clonePos);

        foreach (self::LEGACY_STEPS as $step) {
            $stepPos = strpos($body, $step);
            self::assertNotFalse($stepPos, 'the legacy step ' . $step . ' must stay in heredar_estructura()');
            self::assertLessThan(
                $clonePos,
                $stepPos,
                'the feature clone must run after the legacy step ' . $step
            );
        }
    }

    public function test_copy_helper_uses_the_scope_seam_and_copy_from_tarifa(): void
    {
        $src = $this->source();
        $body = $this->methodBody($src, 'copy_caracteristicas');
        self::assertNotSame('', $body, 'copy_caracteristicas() body must be found');
        self::assertStringContainsString('caracteristica_value_model(', $body, 'the helper must use the overridable seam');
        self::assertStringContainsString('copy_from_tarifa(', $body, 'the helper must call the model clone');
    }

    public function test_controller_requires_and_imports_the_three_scope_models(): void
    {
        $src = $this->source();

        foreach ([
            'model/core/caracteristica_scope_value.php',
            'model/core/catalogo_caracteristica_articulo.php',
            'model/core/catalogo_caracteristica_familia.php',
            'model/core/catalogo_caracteristica_global.php',
        ] as $path) {
            self::assertStringContainsString($path, $src, 'tarif_tarifas must require ' . $path);
        }

        foreach ([
            'use FSFramework\\model\\catalogo_caracteristica_articulo;',
            'use FSFramework\\model\\catalogo_caracteristica_familia;',
            'use FSFramework\\model\\catalogo_caracteristica_global;',
        ] as $import) {
            self::assertStringContainsString($import, $src, 'tarif_tarifas must import ' . $import);
        }
    }

    public function test_scope_seam_returns_the_matching_model_per_scope(): void
    {
        $src = $this->source();
        $body = $this->methodBody($src, 'caracteristica_value_model');
        self::assertNotSame('', $body, 'caracteristica_value_model() body must be found');

        self::assertStringContainsString('catalogo_caracteristica_articulo', $body);
        self::assertStringContainsString('catalogo_caracteristica_familia', $body);
        self::assertStringContainsString('catalogo_caracteristica_global', $body);
    }
}
