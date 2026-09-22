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

/**
 * DB-free test double for the language registry: it skips the fs_model
 * constructor, injects the in-memory fake and collects the reported errors.
 */
final class FakeCatalogoIdioma extends \FSFramework\model\catalogo_idioma
{
    /** @var list<string> */
    public array $errors = [];

    public function __construct($data = false)
    {
        $this->table_name = \FSFramework\model\catalogo_idioma::TABLE;

        if ($data) {
            $this->codidioma = $data['codidioma'];
            $this->nombre = $data['nombre'];
            $this->activo = $this->str2bool($data['activo']);
            $this->por_defecto = $this->str2bool($data['por_defecto']);
        } else {
            $this->codidioma = null;
            $this->nombre = '';
            $this->activo = true;
            $this->por_defecto = false;
        }
    }

    public function useFakeDb(IdiomaRegistryFake $db): self
    {
        $this->db = $db;

        return $this;
    }

    protected function new_error_msg($msg)
    {
        $this->errors[] = (string) $msg;
    }
}
