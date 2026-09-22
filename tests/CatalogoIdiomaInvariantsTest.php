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

namespace Tests\CatalogoCore;

use FSFramework\model\catalogo_idioma;
use PHPUnit\Framework\TestCase;
use Tests\CatalogoCore\Support\FakeCatalogoIdioma;
use Tests\CatalogoCore\Support\IdiomaRegistryFake;

require_once __DIR__ . '/Support/IdiomaRegistryFake.php';
require_once __DIR__ . '/Support/FakeCatalogoIdioma.php';

/**
 * GDI-02 — exactly one active default and total, deterministic resolution.
 *
 * The registry is exercised DB-free: an anonymous subclass injects the
 * in-memory fake and collects the reported errors.
 */
final class CatalogoIdiomaInvariantsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
    }

    public function test_setting_new_default_is_a_flag_flip(): void
    {
        $db = new IdiomaRegistryFake(
            [
                ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
                ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
            ],
            [
                ['referencia' => 'ART1', 'codidioma' => 'es', 'descripcion' => 'Hola'],
            ]
        );
        $descripcionesBefore = $db->descripciones;

        $model = $this->registry($db);

        $this->assertTrue($model->set_default('en'));
        $this->assertTrue($db->idiomas['en']['por_defecto'], 'the target must carry the flag');
        $this->assertFalse($db->idiomas['es']['por_defecto'], 'the previous default must be cleared');
        $this->assertSame(['en'], $db->activeDefaults());
        $this->assertSame(1, $db->transactions, 'the flag flip must be a single transaction');
        $this->assertTrue($db->committed);
        $this->assertSame($descripcionesBefore, $db->descripciones, 'setting a default must move no description row');
        foreach ($db->executed as $sql) {
            $this->assertStringNotContainsStringIgnoringCase(
                'articulo_descripciones',
                $sql,
                'setting a default must issue no description statement'
            );
        }
    }

    public function test_set_default_rejects_unknown_code(): void
    {
        $db = new IdiomaRegistryFake([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
        ]);
        $model = $this->registry($db);

        $this->assertFalse($model->set_default('zz'));
        $this->assertNotEmpty($model->errors);
        $this->assertSame([], $db->executed, 'an unknown target must emit no statement');
        $this->assertSame(['es'], $db->activeDefaults());
    }

    public function test_set_default_rejects_inactive_target(): void
    {
        $db = new IdiomaRegistryFake([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'fr', 'nombre' => 'Français', 'activo' => false, 'por_defecto' => false],
        ]);
        $model = $this->registry($db);

        $this->assertFalse($model->set_default('fr'));
        $this->assertNotEmpty($model->errors);
        $this->assertSame(['es'], $db->activeDefaults());
    }

    public function test_deactivating_the_default_is_rejected(): void
    {
        $db = new IdiomaRegistryFake([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
        ]);
        $model = $this->registry($db);
        $model->codidioma = 'es';
        $model->nombre = 'Español';
        $model->activo = false;
        $model->por_defecto = true;

        $this->assertFalse($model->save());
        $this->assertNotEmpty($model->errors);
        $this->assertTrue($db->idiomas['es']['activo'], 'the default must stay active');
        $this->assertSame([], $db->executed, 'a rejected deactivation must emit no statement');
    }

    public function test_default_resolution_is_total(): void
    {
        $db = new IdiomaRegistryFake([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => false, 'por_defecto' => true],
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
        ]);
        $model = $this->registry($db);

        $first = $model->get_effective_default_code();
        $second = $model->get_effective_default_code();

        $this->assertSame('en', $first, 'an inactive configured default must degrade to an active language');
        $this->assertSame($first, $second, 'resolution must be deterministic');
    }

    public function test_default_resolution_falls_back_to_lowest_active_code(): void
    {
        $db = new IdiomaRegistryFake([
            ['codidioma' => 'zz', 'nombre' => 'Zeta', 'activo' => true, 'por_defecto' => false],
            ['codidioma' => 'aa', 'nombre' => 'Alfa', 'activo' => true, 'por_defecto' => false],
        ]);
        $model = $this->registry($db);

        $this->assertSame('aa', $model->get_effective_default_code());
    }

    public function test_default_resolution_never_returns_false_on_empty_registry(): void
    {
        $model = $this->registry(new IdiomaRegistryFake());

        $this->assertSame(catalogo_idioma::DEFAULT_CODE, $model->get_effective_default_code());
    }

    public function test_invariant_holds_after_every_mutation(): void
    {
        $db = new IdiomaRegistryFake([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
        ]);

        // create a language
        $created = $this->registry($db);
        $created->codidioma = 'fr';
        $created->nombre = 'Français';
        $created->activo = true;
        $created->por_defecto = false;
        $this->assertTrue($created->save());
        $this->assertSame(['es'], $db->activeDefaults());

        // rename it
        $renamed = $this->registry($db);
        $renamed->codidioma = 'fr';
        $renamed->nombre = 'French';
        $renamed->activo = true;
        $renamed->por_defecto = false;
        $this->assertTrue($renamed->save());
        $this->assertSame(['es'], $db->activeDefaults());

        // set it as the default
        $this->assertTrue($created->set_default('fr'));
        $this->assertSame(['fr'], $db->activeDefaults());

        // deactivate a non-default language
        $deactivated = $this->registry($db);
        $deactivated->codidioma = 'es';
        $deactivated->nombre = 'Español';
        $deactivated->activo = false;
        $deactivated->por_defecto = false;
        $this->assertTrue($deactivated->save());
        $this->assertSame(['fr'], $db->activeDefaults());

        // reactivate it
        $reactivated = $this->registry($db);
        $reactivated->codidioma = 'es';
        $reactivated->nombre = 'Español';
        $reactivated->activo = true;
        $reactivated->por_defecto = false;
        $this->assertTrue($reactivated->save());
        $this->assertSame(['fr'], $db->activeDefaults());

        // delete a non-default language
        $deleted = $this->registry($db);
        $deleted->codidioma = 'en';
        $deleted->por_defecto = false;
        $this->assertTrue($deleted->delete());
        $this->assertSame(['fr'], $db->activeDefaults());
    }

    private function registry(IdiomaRegistryFake $db): catalogo_idioma
    {
        return (new FakeCatalogoIdioma())->useFakeDb($db);
    }
}
