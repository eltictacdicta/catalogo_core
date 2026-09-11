<?php
/**
 * This file is part of tarifario
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

/**
 * Resuelve los opcionales efectivos por artículo aplicando etiquetas por tarifa.
 */
class tarif_tarifa_opcional_resolver extends \fs_model
{
    /** Stable per-tarifa article table (no tarifario PHP class dependency). */
    private const TARIFA_ARTICULO_TABLE = 'tarif_tarifa_articulo';

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_tarifa_opcional_familia');
    }

    protected function install()
    {
        new tarif_tarifa_opcional_familia();
        new tarif_tarifa_etiqueta_familia();
        new tarif_tarifa_articulo_etiqueta();
        new tarif_tarifa_opcional_etiqueta();
        return '';
    }

    /**
     * Devuelve los opcionales activos y aplicables a un artículo.
     *
        * Regla aplicada:
     * - Si la familia no tiene etiquetas definidas: se aplican todos los opcionales activos de familia.
        * - Si la familia tiene etiquetas y el artículo no tiene: solo se aplican opcionales SIN etiqueta.
        * - Si ambos tienen etiquetas: se aplican opcionales sin etiqueta o con intersección de etiquetas.
     *
     * @param string $codtarifa
     * @param string $codfamilia
     * @param string $referencia
     * @return array
     */
    public function get_opcionales_activos_articulo($codtarifa, $codfamilia, $referencia)
    {
        $rel_familia = new tarif_tarifa_opcional_familia();
        $opcionales = $rel_familia->get_opcionales_activos($codtarifa, $codfamilia);
        if (empty($opcionales)) {
            return [];
        }

        $familia_etiqueta = new tarif_tarifa_etiqueta_familia();
        if (!$familia_etiqueta->familia_tiene_etiquetas($codtarifa, $codfamilia)) {
            return $opcionales;
        }

        $articulo_etiqueta = new tarif_tarifa_articulo_etiqueta();
        $etiquetas_articulo = $articulo_etiqueta->get_etiquetas_articulo($codtarifa, $referencia);
        $etiquetas_articulo_idx = [];
        foreach ($etiquetas_articulo as $etiqueta) {
            $etiquetas_articulo_idx[$etiqueta] = true;
        }

        $opcional_etiqueta = new tarif_tarifa_opcional_etiqueta();
        $resultado = [];
        foreach ($opcionales as $opcional) {
            $etiquetas_opcional = $opcional_etiqueta->get_etiquetas_opcional($codtarifa, intval($opcional->id), $codfamilia);

            if (empty($etiquetas_opcional)) {
                $resultado[] = $opcional;
                continue;
            }

            if (empty($etiquetas_articulo_idx)) {
                continue;
            }

            foreach ($etiquetas_opcional as $etiqueta_opcional) {
                if (isset($etiquetas_articulo_idx[$etiqueta_opcional])) {
                    $resultado[] = $opcional;
                    break;
                }
            }
        }

        return $resultado;
    }

    /**
     * Compila reglas de etiquetas para un artículo en la tabla de overrides por tarifa.
     *
     * @param string $codtarifa
     * @param string $codfamilia
     * @param string $referencia
     * @return array
     */
    public function sync_articulo_overrides($codtarifa, $codfamilia, $referencia)
    {
        $stats = ['procesados' => 0, 'activados' => 0, 'desactivados' => 0];

        if (empty($codtarifa) || empty($codfamilia) || empty($referencia)) {
            return $stats;
        }

        $efectivos = $this->get_opcionales_activos_articulo($codtarifa, $codfamilia, $referencia);
        $efectivos_idx = [];
        foreach ($efectivos as $opcional) {
            $efectivos_idx[intval($opcional->id)] = true;
        }

        $data = $this->db->select("SELECT id_opcional FROM catalogo_articulo_opcional"
            . " WHERE referencia = " . $this->var2str($referencia) . ";");
        if (empty($data)) {
            return $stats;
        }

        $tarifa_articulo_opcional = new tarif_tarifa_articulo_opcional();
        foreach ($data as $row) {
            $id_opcional = intval($row['id_opcional']);
            $activo = isset($efectivos_idx[$id_opcional]);

            if ($tarifa_articulo_opcional->set_activo($codtarifa, $referencia, $id_opcional, $activo)) {
                if ($activo) {
                    $stats['activados']++;
                } else {
                    $stats['desactivados']++;
                }
            }
            $stats['procesados']++;
        }

        return $stats;
    }

    /**
     * Compila reglas de etiquetas para todos los artículos de una familia en tarifa.
     *
     * @param string $codtarifa
     * @param string $codfamilia
     * @return array
     */
    public function sync_familia_overrides($codtarifa, $codfamilia)
    {
        $stats = ['articulos' => 0, 'procesados' => 0, 'activados' => 0, 'desactivados' => 0];

        if (empty($codtarifa) || empty($codfamilia)) {
            return $stats;
        }

        $data = $this->db->select(
            'SELECT referencia FROM ' . self::TARIFA_ARTICULO_TABLE
            . ' WHERE codtarifa = ? AND codfamilia = ?;',
            [$codtarifa, $codfamilia]
        );
        if (empty($data)) {
            return $stats;
        }

        foreach ($data as $row) {
            $art_stats = $this->sync_articulo_overrides($codtarifa, $codfamilia, $row['referencia']);
            $stats['articulos']++;
            $stats['procesados'] += $art_stats['procesados'];
            $stats['activados'] += $art_stats['activados'];
            $stats['desactivados'] += $art_stats['desactivados'];
        }

        return $stats;
    }

    public function exists()
    {
        return FALSE;
    }

    public function save()
    {
        return FALSE;
    }

    public function delete()
    {
        return FALSE;
    }
}
