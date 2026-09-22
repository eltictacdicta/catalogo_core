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

namespace Tests\CatalogoCore\Support;

require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo_descripcion.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
require_once __DIR__ . '/IdiomaRegistryFake.php';
require_once __DIR__ . '/FakeCatalogoIdioma.php';
require_once __DIR__ . '/FakeArticuloDescripcion.php';

/**
 * DB-free test double for an article: it skips the `fs_model` constructor and
 * routes the language API through the in-memory fakes via the two protected
 * seams (`language_registry()` and `description_model()`).
 */
final class FakeArticulo extends \FSFramework\model\articulo
{
    private IdiomaRegistryFake $fakeDb;

    public function __construct(IdiomaRegistryFake $db, string $referencia = 'ART1', string $descripcion = '')
    {
        $this->fakeDb = $db;
        $this->referencia = $referencia;
        $this->descripcion = $descripcion;
    }

    protected function language_registry()
    {
        return (new FakeCatalogoIdioma())->useFakeDb($this->fakeDb);
    }

    protected function description_model()
    {
        return (new FakeArticuloDescripcion())->useFakeDb($this->fakeDb);
    }
}
