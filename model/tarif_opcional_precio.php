<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2025 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
namespace FSFramework\model;

require_once 'plugins/catalogo_core/model/core/catalogo_opcional.php';
require_once 'plugins/catalogo_core/model/core/catalogo_opcional_precio.php';
require_once 'plugins/catalogo_core/model/core/catalogo_opcional_familia.php';
require_once 'plugins/catalogo_core/model/tarif_opcional_precio_historial.php';

/**
 * Precio de un opcional en una tarifa específica.
 *
 * Thin adapter over the canonical {@see catalogo_opcional_precio}: the single
 * price source is `catalogo_opcional_precios`, keyed by
 * `(id_opcional, codlista)`. The legacy `codtarifa` member is aliased to
 * `codlista` (design D3), so every existing caller keeps working while no
 * physical `tarif_opcional_precios` table is ever created.
 */
class tarif_opcional_precio extends catalogo_opcional_precio
{
    /** @var array<string, mixed> Dynamic legacy extras (nombre, codigo, ...). */
    private $extra = [];

    public function __construct($data = FALSE)
    {
        if (is_array($data) && array_key_exists('codtarifa', $data) && !array_key_exists('codlista', $data)) {
            $data['codlista'] = $data['codtarifa'];
        }

        parent::__construct($data);
    }

    /**
     * Alias of the canonical `codlista` key.
     *
     * @param string $name
     */
    public function __get($name)
    {
        if ($name === 'codtarifa') {
            return $this->codlista;
        }

        return $this->extra[$name] ?? null;
    }

    /**
     * Alias of the canonical `codlista` key.
     *
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value)
    {
        if ($name === 'codtarifa') {
            $this->codlista = $value;
            return;
        }

        $this->extra[$name] = $value;
    }

    /**
     * @param string $name
     */
    public function __isset($name)
    {
        if ($name === 'codtarifa') {
            return $this->codlista !== null;
        }

        return isset($this->extra[$name]);
    }

    public function url()
    {
        if (is_null($this->id_opcional)) {
            return "index.php?page=tarif_opcionales";
        }

        return "index.php?page=tarif_opcional_edit&id=" . $this->id_opcional;
    }

    public function save()
    {
        if (!$this->test()) {
            return FALSE;
        }

        $actual = $this->get($this->id_opcional, $this->codlista);
        $precio_anterior = $actual ? floatval($actual->precio) : 0.0;

        // Read-modify-write: preserve the stored percentage unless this
        // instance explicitly carries one.
        if ($this->porcentaje === null && $actual) {
            $this->porcentaje = $actual->porcentaje;
        }

        $result = parent::save();

        // Round the delta to the epsilon's precision before comparing: the
        // binary representation of an exact 0.0001 change lands just below
        // 0.0001 (9.9999999999766942e-5) and would otherwise be silently lost.
        if ($result && round(abs($precio_anterior - $this->precio), 5) >= 0.0001) {
            $this->registrar_cambio_historial(
                $this->id_opcional,
                $this->codlista,
                $precio_anterior,
                $this->precio
            );
        }

        return $result;
    }

    /**
     * Appends one price-history row. Overridable seam so the history append
     * is unit-testable without a database.
     *
     * @param int $id_opcional
     * @param string $codlista
     * @param float $precio_anterior
     * @param float $precio_nuevo
     */
    protected function registrar_cambio_historial($id_opcional, $codlista, $precio_anterior, $precio_nuevo): bool
    {
        $usuario = null;
        try {
            $coreLog = new \fs_core_log();
            $usuario = $coreLog->user_nick() ?: null;
        } catch (\Throwable $e) {
            $usuario = null;
        }

        return tarif_opcional_precio_historial::registrar_cambio(
            $id_opcional,
            $codlista,
            $precio_anterior,
            $precio_nuevo,
            $usuario
        );
    }

    /**
     * Devuelve todos los precios de opcionales para una lista/tarifa.
     *
     * @param string $codtarifa
     * @return array
     */
    public function all_from_tarifa($codtarifa)
    {
        return $this->all_from_lista($codtarifa);
    }

    /**
     * Elimina todos los precios de una lista/tarifa.
     *
     * @param string $codtarifa
     * @return boolean
     */
    public function delete_from_tarifa($codtarifa)
    {
        return $this->delete_from_lista($codtarifa);
    }

    /**
     * Elimina el precio de un opcional en una lista/tarifa específica.
     *
     * @param int $id_opcional
     * @param string $codtarifa
     * @return boolean
     */
    public function delete_precio($id_opcional, $codtarifa)
    {
        $this->id_opcional = $this->intval($id_opcional);
        $this->codlista = $codtarifa;

        return $this->delete();
    }

    /**
     * Devuelve todos los registros ordenados por opcional y lista.
     *
     * @return array
     */
    public function all()
    {
        $list = [];
        $sql = 'SELECT * FROM ' . $this->table_name . ' ORDER BY id_opcional ASC, codlista ASC;';
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    /**
     * Aplica un porcentaje de cambio a múltiples opcionales en una tarifa.
     * Registra automáticamente los cambios en el historial.
     *
     * @param string $codtarifa
     * @param array $ids_opcionales
     * @param float $porcentaje
     * @return array
     */
    public function aplicar_porcentaje_masivo($codtarifa, $ids_opcionales, $porcentaje)
    {
        $resultado = [
            'actualizados' => 0,
            'errores' => 0,
            'detalles' => [],
        ];

        if (empty($ids_opcionales) || empty($codtarifa)) {
            return $resultado;
        }

        $factor = 1 + ($porcentaje / 100);

        foreach ($ids_opcionales as $id_opcional) {
            $precio = $this->get($id_opcional, $codtarifa);
            if ($precio) {
                $precio_anterior = $precio->precio;
                $precio_nuevo = round($precio_anterior * $factor, 2);
                $precio->precio = $precio_nuevo;

                if ($precio->save()) {
                    $resultado['actualizados']++;
                    $resultado['detalles'][] = [
                        'id_opcional' => $id_opcional,
                        'precio_anterior' => $precio_anterior,
                        'precio_nuevo' => $precio_nuevo,
                        'status' => 'ok',
                    ];
                } else {
                    $resultado['errores']++;
                    $resultado['detalles'][] = [
                        'id_opcional' => $id_opcional,
                        'precio_anterior' => $precio_anterior,
                        'precio_nuevo' => $precio_nuevo,
                        'status' => 'error',
                    ];
                }
            } else {
                $resultado['errores']++;
                $resultado['detalles'][] = [
                    'id_opcional' => $id_opcional,
                    'precio_anterior' => null,
                    'precio_nuevo' => null,
                    'status' => 'not_found',
                ];
            }
        }

        return $resultado;
    }

    /**
     * Obtiene opcionales con precios en una tarifa filtrados por familias.
     *
     * @param string $codtarifa
     * @param array $familias
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function all_by_familias($codtarifa, $familias = [], $offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $list = [];

        $sql = 'SELECT op.*, o.nombre, o.codigo FROM ' . $this->table_name . ' op '
            . 'INNER JOIN ' . catalogo_opcional::TABLE . ' o ON op.id_opcional = o.id '
            . 'WHERE op.codlista = ' . $this->var2str($codtarifa);

        if (!empty($familias)) {
            $familias_sql = implode(',', array_map(function ($f) {
                return $this->var2str($f);
            }, $familias));
            $sql .= ' AND EXISTS (SELECT 1 FROM ' . catalogo_opcional_familia::TABLE . ' tof WHERE tof.id_opcional = op.id_opcional AND tof.codfamilia IN (' . $familias_sql . '))';
        }

        $sql .= ' ORDER BY o.codigo ASC';

        $data = $this->db->select_limit($sql, $limit, $offset);
        if ($data) {
            foreach ($data as $d) {
                $item = new static($d);
                $item->nombre = $d['nombre'];
                $item->codigo = $d['codigo'];
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * Cuenta opcionales con precios en una tarifa filtrados por familias.
     *
     * @param string $codtarifa
     * @param array $familias
     * @return int
     */
    public function count_by_familias($codtarifa, $familias = [])
    {
        $sql = 'SELECT COUNT(*) as total FROM ' . $this->table_name . ' op '
            . 'INNER JOIN ' . catalogo_opcional::TABLE . ' o ON op.id_opcional = o.id '
            . 'WHERE op.codlista = ' . $this->var2str($codtarifa);

        if (!empty($familias)) {
            $familias_sql = implode(',', array_map(function ($f) {
                return $this->var2str($f);
            }, $familias));
            $sql .= ' AND EXISTS (SELECT 1 FROM ' . catalogo_opcional_familia::TABLE . ' tof WHERE tof.id_opcional = op.id_opcional AND tof.codfamilia IN (' . $familias_sql . '))';
        }

        $data = $this->db->select($sql);

        return $data ? intval($data[0]['total']) : 0;
    }
}
