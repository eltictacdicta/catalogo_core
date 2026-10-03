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
 * Flujo de lógica condicional reutilizable.
 *
 * El vocabulario de sujetos, operadores y acciones es CERRADO: agregar uno es
 * un cambio de especificación, no una decisión del consumidor. Las referencias
 * a opcionales y grupos se hacen por `codigo`.
 */
class catalogo_flujo extends \fs_model
{
    public const TABLE = 'catalogo_flujos';

    public const SUBJECT_OPCIONAL = 'opcional';
    public const SUBJECT_GRUPO = 'grupo';
    public const SUBJECT_TYPES = [self::SUBJECT_OPCIONAL, self::SUBJECT_GRUPO];

    public const GROUP_AND = 'AND';
    public const GROUP_OR = 'OR';

    public const OPERATORS = [
        'is',
        'isnot',
        'isempty',
        'isnotempty',
        'startswith',
        'endswith',
        'greaterthan',
        'lessthan',
        'greaterthanequal',
        'lessthanequal',
    ];

    public const ACTIONS = ['mostrar', 'ocultar', 'requerir', 'deshabilitar'];

    public $id;
    public $codigo;
    public $nombre;
    public $descripcion;
    public $activo;
    public $prioridad;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id = intval($data['id']);
            $this->codigo = $data['codigo'];
            $this->nombre = $data['nombre'];
            $this->descripcion = $data['descripcion'] ?? '';
            $this->activo = $this->str2bool($data['activo'] ?? true);
            $this->prioridad = isset($data['prioridad']) ? intval($data['prioridad']) : 0;
        } else {
            $this->id = null;
            $this->codigo = null;
            $this->nombre = '';
            $this->descripcion = '';
            $this->activo = true;
            $this->prioridad = 0;
        }
    }

    protected function install()
    {
        return '';
    }

    public static function is_valid_subject_type($tipo): bool
    {
        return in_array((string) $tipo, self::SUBJECT_TYPES, true);
    }

    public static function is_valid_operator($operador): bool
    {
        return in_array((string) $operador, self::OPERATORS, true);
    }

    public static function is_valid_action($accion): bool
    {
        return in_array((string) $accion, self::ACTIONS, true);
    }

    public function get($id)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($id) . ';');
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function get_by_codigo($codigo)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE codigo = ' . $this->var2str($codigo) . ';');
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function exists()
    {
        if (is_null($this->id)) {
            return false;
        }

        return $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';');
    }

    public function test()
    {
        $this->codigo = $this->no_html(trim((string) $this->codigo));
        $this->nombre = $this->no_html($this->nombre);
        $this->descripcion = $this->no_html($this->descripcion);
        $this->prioridad = intval($this->prioridad);

        if (mb_strlen($this->codigo) < 1 || mb_strlen($this->codigo) > 20) {
            $this->new_error_msg('Código de flujo no válido. Debe tener entre 1 y 20 caracteres.');
            return false;
        }

        if (mb_strlen($this->nombre) < 1 || mb_strlen($this->nombre) > 100) {
            $this->new_error_msg('Nombre de flujo no válido.');
            return false;
        }

        $existente = $this->get_by_codigo($this->codigo);
        if ($existente && $existente->id != $this->id) {
            $this->new_error_msg('Ya existe un flujo con el código: ' . $this->codigo);
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
            $sql = 'UPDATE ' . $this->table_name . ' SET '
                . 'codigo = ' . $this->var2str($this->codigo)
                . ', nombre = ' . $this->var2str($this->nombre)
                . ', descripcion = ' . $this->var2str($this->descripcion)
                . ', activo = ' . $this->var2str($this->activo)
                . ', prioridad = ' . $this->var2str($this->prioridad)
                . ' WHERE id = ' . $this->intval($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (codigo, nombre, descripcion, activo, prioridad) VALUES ('
                . $this->var2str($this->codigo) . ','
                . $this->var2str($this->nombre) . ','
                . $this->var2str($this->descripcion) . ','
                . $this->var2str($this->activo) . ','
                . $this->var2str($this->prioridad) . ');';
        }

        if ($this->db->exec($sql)) {
            if (is_null($this->id)) {
                $this->id = $this->db->lastval();
            }

            return true;
        }

        return false;
    }

    public function delete()
    {
        if ($this->id) {
            $id = $this->intval($this->id);
            $this->db->exec('DELETE FROM ' . catalogo_flujo_condicion::TABLE . ' WHERE id_flujo = ' . $id . ';');
            $this->db->exec('DELETE FROM ' . catalogo_flujo_accion::TABLE . ' WHERE id_flujo = ' . $id . ';');
            $this->db->exec('DELETE FROM ' . catalogo_flujo_articulo::TABLE . ' WHERE id_flujo = ' . $id . ';');
            $this->db->exec('DELETE FROM ' . catalogo_flujo_familia::TABLE . ' WHERE id_flujo = ' . $id . ';');
        }

        return (bool) $this->db->exec('DELETE FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';');
    }

    public function all()
    {
        $list = [];
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' ORDER BY prioridad ASC, id ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    /**
     * Replaces the row with the same id inside $rows, or appends the pending row.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $pending
     * @return list<array<string, mixed>>
     */
    public static function merge_pending_row(array $rows, array $pending): array
    {
        $id = $pending['id'] ?? null;
        if ($id !== null && $id !== '') {
            foreach ($rows as $index => $row) {
                if ((string) ($row['id'] ?? '') === (string) $id) {
                    $rows[$index] = $pending;

                    return array_values($rows);
                }
            }
        }

        $rows[] = $pending;

        return array_values($rows);
    }

    /**
     * FLC-07 / AD-6: an edge S1 -> S2 exists when a flow acts on S2 and is
     * conditioned by S1. Returns the cycle path (subject nodes) or null.
     *
     * @param array<int, array<string, mixed>> $conditions
     * @param array<int, array<string, mixed>> $actions
     * @return list<string>|null
     */
    public static function detect_cycle(array $conditions, array $actions): ?array
    {
        $byFlow = [];
        foreach ($conditions as $row) {
            $flow = (int) ($row['id_flujo'] ?? 0);
            $node = self::subject_node($row);
            if ($flow > 0 && $node !== null) {
                $byFlow[$flow]['cond'][] = $node;
            }
        }
        foreach ($actions as $row) {
            $flow = (int) ($row['id_flujo'] ?? 0);
            $node = self::subject_node($row);
            if ($flow > 0 && $node !== null) {
                $byFlow[$flow]['act'][] = $node;
            }
        }

        $edges = [];
        foreach ($byFlow as $parts) {
            foreach ($parts['cond'] ?? [] as $from) {
                foreach ($parts['act'] ?? [] as $to) {
                    $edges[$from][$to] = true;
                }
            }
        }

        return self::find_cycle($edges);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function subject_node(array $row): ?string
    {
        $codigo = trim((string) ($row['sujeto_codigo'] ?? ''));
        if ($codigo === '') {
            return null;
        }

        $tipo = strtolower(trim((string) ($row['sujeto_tipo'] ?? '')));

        return $tipo . ':' . $codigo;
    }

    /**
     * @param array<string, array<string, bool>> $edges
     * @return list<string>|null
     */
    private static function find_cycle(array $edges): ?array
    {
        $state = [];
        $stack = [];
        foreach (array_keys($edges) as $node) {
            $cycle = self::dfs_cycle((string) $node, $edges, $state, $stack);
            if ($cycle !== null) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, bool>> $edges
     * @param array<string, int> $state
     * @param list<string> $stack
     * @return list<string>|null
     */
    private static function dfs_cycle(string $node, array $edges, array &$state, array &$stack): ?array
    {
        $state[$node] = 1;
        $stack[] = $node;

        foreach (array_keys($edges[$node] ?? []) as $next) {
            $nextState = $state[$next] ?? 0;
            if ($nextState === 1) {
                $index = array_search($next, $stack, true);
                if ($index !== false) {
                    return array_slice($stack, (int) $index);
                }
            } elseif ($nextState === 0) {
                $cycle = self::dfs_cycle((string) $next, $edges, $state, $stack);
                if ($cycle !== null) {
                    return $cycle;
                }
            }
        }

        array_pop($stack);
        $state[$node] = 2;

        return null;
    }
}
