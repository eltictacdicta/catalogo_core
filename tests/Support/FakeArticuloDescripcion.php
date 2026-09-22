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

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo_descripcion.php';
require_once __DIR__ . '/IdiomaRegistryFake.php';

/**
 * DB-free test double for a multi-language article description: it skips the
 * `fs_model` constructor, injects the in-memory fake and re-injects it into the
 * rows returned by the model's own lookups, so `save()` can be exercised
 * without a live database.
 */
final class FakeArticuloDescripcion extends \FSFramework\model\articulo_descripcion
{
    /** @var list<string> */
    public array $errors = [];

    public function __construct($data = false)
    {
        $this->table_name = \FSFramework\model\articulo_descripcion::TABLE;

        if ($data) {
            $this->id = isset($data['id']) ? (int) $data['id'] : null;
            $this->referencia = $data['referencia'] ?? null;
            $this->codidioma = $data['codidioma'] ?? \FSFramework\model\catalogo_idioma::DEFAULT_CODE;
            $this->descripcion = $data['descripcion'] ?? '';
            $this->descripcion_corta = $data['descripcion_corta'] ?? null;
        } else {
            $this->id = null;
            $this->referencia = null;
            $this->codidioma = \FSFramework\model\catalogo_idioma::DEFAULT_CODE;
            $this->descripcion = '';
            $this->descripcion_corta = null;
        }
    }

    public function useFakeDb(IdiomaRegistryFake $db): self
    {
        $this->db = $db;

        return $this;
    }

    public function get_by_articulo_idioma($referencia, $codidioma)
    {
        $row = parent::get_by_articulo_idioma($referencia, $codidioma);
        if ($row) {
            $row->useFakeDb($this->db);
        }

        return $row;
    }

    public function all_from_articulo($referencia)
    {
        $rows = parent::all_from_articulo($referencia);
        foreach ($rows as $row) {
            $row->useFakeDb($this->db);
        }

        return $rows;
    }

    protected function new_error_msg($msg)
    {
        $this->errors[] = (string) $msg;
    }
}
