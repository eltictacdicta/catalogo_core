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
namespace FSFramework\model;

/**
 * Idiomas disponibles para descripciones multiidioma de artículos.
 */
class catalogo_idioma extends \fs_model
{
    public const TABLE = 'catalogo_idiomas';
    public const DEFAULT_CODE = 'es';

    public $codidioma;
    public $nombre;
    public $activo;
    public $por_defecto;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

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

    protected function install()
    {
        return $this->default_idiomas_sql();
    }

    public function ensure_defaults(): void
    {
        $count = $this->db->select('SELECT COUNT(*) as total FROM ' . $this->table_name . ';');
        if ($count && intval($count[0]['total']) > 0) {
            return;
        }

        $this->db->exec($this->default_idiomas_sql());
    }

    private function default_idiomas_sql(): string
    {
        return 'INSERT INTO ' . $this->table_name . ' (codidioma, nombre, activo, por_defecto) VALUES '
            . "('es', 'Español', TRUE, TRUE),"
            . "('en', 'English', TRUE, FALSE);";
    }

    public function url()
    {
        return 'index.php?page=ventas_articulos#idiomas';
    }

    public function get($cod)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE codidioma = ' . $this->var2str($cod) . ';');
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function get_default()
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE por_defecto = TRUE AND activo = TRUE LIMIT 1;');
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    /**
     * Resolves the effective default language code, total and deterministic
     * (GDI-02 / D-02): the configured active default wins, otherwise the lowest
     * active `codidioma`, and `DEFAULT_CODE` is the terminal fallback (documented
     * as unreachable while the invariants hold — the last language is undeletable
     * and the default cannot be inactive, so an active language always exists).
     */
    public function get_effective_default_code(): string
    {
        $default = $this->get_default();
        if ($default) {
            return (string) $default->codidioma;
        }

        $activos = $this->db->select(
            'SELECT codidioma FROM ' . $this->table_name . ' WHERE activo = TRUE ORDER BY codidioma ASC LIMIT 1;'
        );
        if ($activos) {
            return (string) $activos[0]['codidioma'];
        }

        return self::DEFAULT_CODE;
    }

    /**
     * Makes the given language the only default (D-02). A flag flip: it issues
     * no `articulo_descripciones` statement.
     */
    public function set_default(string $codidioma): bool
    {
        $target = $this->get($codidioma);
        if (!$target || !$target->activo) {
            $this->new_error_msg('El idioma indicado no existe o está inactivo.');
            return false;
        }

        $this->db->begin_transaction();

        $ok = $this->db->exec('UPDATE ' . $this->table_name . ' SET por_defecto = FALSE WHERE por_defecto = TRUE;')
            && $this->db->exec(
                'UPDATE ' . $this->table_name . ' SET por_defecto = TRUE, activo = TRUE WHERE codidioma = '
                . $this->var2str($codidioma) . ';'
            );

        if (!$ok) {
            $this->db->rollback();
            return false;
        }

        $this->normalize_default();
        $this->db->commit();

        return true;
    }

    /**
     * Restores the "exactly one active default" invariant after a mutation.
     *
     * Split into two PHP statements because a single UPDATE ... WHERE (SELECT ...
     * FROM the same table) raises MySQL error 1093.
     */
    private function normalize_default(): void
    {
        $this->db->exec(
            'UPDATE ' . $this->table_name . ' SET por_defecto = FALSE WHERE por_defecto = TRUE AND activo = FALSE;'
        );

        $count = $this->db->select('SELECT COUNT(*) as total FROM ' . $this->table_name . ' WHERE por_defecto = TRUE;');
        if ($count && intval($count[0]['total']) > 0) {
            return;
        }

        $candidates = $this->db->select(
            'SELECT codidioma FROM ' . $this->table_name . ' WHERE activo = TRUE ORDER BY codidioma ASC LIMIT 1;'
        );
        if ($candidates) {
            $this->db->exec(
                'UPDATE ' . $this->table_name . ' SET por_defecto = TRUE WHERE codidioma = '
                . $this->var2str($candidates[0]['codidioma']) . ';'
            );
        }
    }

    public function exists()
    {
        if (is_null($this->codidioma)) {
            return false;
        }

        return $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE codidioma = ' . $this->var2str($this->codidioma) . ';');
    }

    public function test()
    {
        $this->codidioma = $this->no_html(strtolower(trim((string) $this->codidioma)));
        $this->nombre = $this->no_html($this->nombre);

        if (mb_strlen($this->codidioma) < 2 || mb_strlen($this->codidioma) > 5) {
            $this->new_error_msg('Código de idioma no válido. Deben ser entre 2 y 5 caracteres.');
            return false;
        }

        if (mb_strlen($this->nombre) < 1 || mb_strlen($this->nombre) > 50) {
            $this->new_error_msg('Nombre de idioma no válido.');
            return false;
        }

        return true;
    }

    public function save()
    {
        if (!$this->test()) {
            return false;
        }

        if ($this->exists()) {
            $current = $this->get($this->codidioma);
            if ($current && $current->por_defecto && !$this->activo) {
                $this->new_error_msg('No se puede desactivar el idioma por defecto.');
                return false;
            }
        }

        $this->db->begin_transaction();

        if ($this->por_defecto) {
            $this->db->exec('UPDATE ' . $this->table_name . ' SET por_defecto = FALSE WHERE codidioma != ' . $this->var2str($this->codidioma) . ';');
            $this->activo = true;
        }

        if ($this->exists()) {
            $sql = 'UPDATE ' . $this->table_name . ' SET '
                . 'nombre = ' . $this->var2str($this->nombre)
                . ', activo = ' . $this->var2str($this->activo)
                . ', por_defecto = ' . $this->var2str($this->por_defecto)
                . ' WHERE codidioma = ' . $this->var2str($this->codidioma) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (codidioma, nombre, activo, por_defecto) VALUES ('
                . $this->var2str($this->codidioma) . ','
                . $this->var2str($this->nombre) . ','
                . $this->var2str($this->activo) . ','
                . $this->var2str($this->por_defecto) . ');';
        }

        if (!$this->db->exec($sql)) {
            $this->db->rollback();
            return false;
        }

        $this->normalize_default();
        $this->db->commit();

        return true;
    }

    public function delete()
    {
        $count = $this->db->select('SELECT COUNT(*) as total FROM ' . $this->table_name . ';');
        if ($count && intval($count[0]['total']) <= 1) {
            $this->new_error_msg('No se puede eliminar el último idioma.');
            return false;
        }

        if ($this->por_defecto) {
            $this->new_error_msg('No se puede eliminar el idioma por defecto.');
            return false;
        }

        $this->db->begin_transaction();

        $ok = $this->db->exec(
            'DELETE FROM articulo_descripciones WHERE codidioma = ' . $this->var2str($this->codidioma) . ';'
        ) && $this->db->exec(
            'DELETE FROM ' . $this->table_name . ' WHERE codidioma = ' . $this->var2str($this->codidioma) . ';'
        );

        if (!$ok) {
            $this->db->rollback();
            return false;
        }

        $this->normalize_default();
        $this->db->commit();

        return true;
    }

    public function all()
    {
        $list = [];
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' ORDER BY nombre ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    public function all_activos()
    {
        $list = [];
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE activo = TRUE ORDER BY nombre ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }
}
