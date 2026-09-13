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
 * Soft-seam contract for the tarifas CRUD page after its move from tarifario
 * to catalogo_core.
 *
 * The groups/RBAC surface (`tarif_grupo_rol`, `tarif_grupo_tarifa`,
 * `tarif_grupo_usuario`, `tarif_tarifa_rol`) belongs to the optional tarifario
 * plugin. catalogo_core must own the page without a hard cross-plugin
 * dependency, and every group path must fail closed when tarifario is inactive.
 */
final class TarifTarifasSoftSeamTest extends TestCase
{
    public const CONTROLLER = 'plugins/catalogo_core/controller/tarif_tarifas.php';
    public const VIEW = 'plugins/catalogo_core/View/tarif_tarifas.html.twig';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/base/fs_app.php';
        if (!is_file(FS_FOLDER . '/' . self::CONTROLLER)) {
            self::fail('missing catalogo_core controller: ' . self::CONTROLLER);
        }
        require_once FS_FOLDER . '/' . self::CONTROLLER;
    }

    private function buildController(): object
    {
        return new class() extends \tarif_tarifas {
            public function __construct()
            {
                // Skip the heavy FS constructor (DB + menu boot).
            }

            public function resolve_tarifario_model(string $fqcn): ?object
            {
                return $this->loadTarifarioModel($fqcn);
            }
        };
    }

    /** The page is owned by catalogo_core and has no hard tarifario require. */
    public function test_controller_lives_in_catalogo_core_without_tarifario_require(): void
    {
        self::assertFileExists(
            FS_FOLDER . '/' . self::CONTROLLER,
            'the tarifas controller must live in catalogo_core'
        );
        self::assertFileDoesNotExist(
            FS_FOLDER . '/plugins/tarifario/controller/tarif_tarifas.php',
            'the tarifario controller copy must be removed'
        );

        $src = (string) file_get_contents(FS_FOLDER . '/' . self::CONTROLLER);
        self::assertStringNotContainsString(
            'plugins/tarifario/',
            $src,
            'catalogo_core production must not hard-require the tarifario plugin'
        );
        self::assertStringContainsString(
            'extends fbase_controller',
            $src,
            'the moved controller must reclass to catalogo_core fbase_controller'
        );
    }

    /** Triangulation: the seam resolves real classes and rejects absent ones. */
    public function test_load_tarifario_model_resolves_present_and_nulls_absent(): void
    {
        $controller = $this->buildController();

        self::assertNull(
            $controller->resolve_tarifario_model('FSFramework\\model\\clase_inexistente_xyz'),
            'an absent tarifario class must resolve to null (fail closed)'
        );

        $resolved = $controller->resolve_tarifario_model(TarifarioGroupStub::class);
        self::assertInstanceOf(TarifarioGroupStub::class, $resolved);
        self::assertSame(
            [['codgrupo' => 'G1']],
            $resolved->get_grupos_tarifa('T1'),
            'the resolved object must be the requested class instance'
        );
    }

    /** Group reads fail closed (empty/zero) when tarifario is inactive. */
    public function test_group_reads_fail_closed_when_tarifario_inactive(): void
    {
        $controller = $this->buildController();
        $controller->grupo_tarifa_model = null;
        $controller->grupo_rol_model = null;

        self::assertSame(
            [],
            $controller->get_grupos_tarifa('T1'),
            'get_grupos_tarifa() must return an empty list without a tarifario model'
        );
        self::assertSame(
            0,
            $controller->count_usuarios_tarifa('T1'),
            'count_usuarios_tarifa() must return zero without a tarifario model'
        );
    }

    /** The view hides the group/roles surface when tarifario is inactive. */
    public function test_view_gates_group_surface_behind_tarifario_activo(): void
    {
        self::assertFileExists(FS_FOLDER . '/' . self::VIEW);

        $src = (string) file_get_contents(FS_FOLDER . '/' . self::VIEW);
        self::assertStringContainsString(
            'fsc.tarifario_activo',
            $src,
            'the view must gate the group/roles UI behind fsc.tarifario_activo'
        );
        self::assertMatchesRegularExpression(
            '/\{%\s*if\s+fsc\.tarifario_activo\s*%\}.*page=tarif_roles.*\{%\s*endif\s*%\}/s',
            $src,
            'the tarif_roles link must only render when tarifario is active'
        );
    }
}

/**
 * Minimal stand-in used to prove the soft seam instantiates a real class.
 */
final class TarifarioGroupStub
{
    public function get_grupos_tarifa(string $codtarifa): array
    {
        return [['codgrupo' => 'G1']];
    }
}
