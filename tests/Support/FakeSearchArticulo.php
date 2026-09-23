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
require_once __DIR__ . '/SearchCatalogFake.php';
require_once __DIR__ . '/SearchCacheFake.php';

/**
 * DB-free test double for the article search path: it skips the `fs_model`
 * constructor, injects the in-memory catalog/cache doubles and routes the
 * article instantiation in `all_from()` through the `make_articulo()` seam.
 */
final class FakeSearchArticulo extends \FSFramework\model\articulo
{
    public function __construct(SearchCatalogFake $db, SearchCacheFake $cache, ?string $referencia = null)
    {
        $this->table_name = 'articulos';
        $this->db = $db;
        $this->cache = $cache;
        $this->referencia = $referencia;
    }

    protected function make_articulo(array $data)
    {
        return new self($this->db, $this->cache, (string) ($data['referencia'] ?? ''));
    }
}
