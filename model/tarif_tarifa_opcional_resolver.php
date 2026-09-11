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

    /** Stable per-tarifa article tag table (no tarifario PHP class dependency). */
    private const TARIFA_ARTICULO_ETIQUETA_TABLE = 'tarif_tarifa_articulo_etiqueta';

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_tarifa_opcional_familia');
    }

    protected function install()
    {
        new tarif_tarifa_opcional_familia();
        new tarif_tarifa_etiqueta_familia();
        new tarif_tarifa_opcional_etiqueta();
        return '';
    }

    /**
     * Etiquetas de un artículo en una tarifa, leídas con SQL parametrizado
     * para no depender de la clase PHP de tarifario (design D4).
     *
     * @return array<int, string>
     */
    private function etiquetas_articulo($codtarifa, $referencia)
    {
        if (!$this->db->table_exists(self::TARIFA_ARTICULO_ETIQUETA_TABLE)) {
            return [];
        }

        $data = $this->db->select(
            'SELECT etiqueta FROM ' . self::TARIFA_ARTICULO_ETIQUETA_TABLE
            . ' WHERE codtarifa = ? AND referencia = ? ORDER BY etiqueta ASC;',
            [$codtarifa, $referencia]
        );

        $list = [];
        if ($data) {
            foreach ($data as $row) {
                $list[] = $row['etiqueta'];
            }
        }

        return $list;
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

        $etiquetas_articulo = $this->etiquetas_articulo($codtarifa, $referencia);

        $opcional_etiqueta = new tarif_tarifa_opcional_etiqueta();
        $etiquetas_por_opcional = [];
        foreach ($opcionales as $opcional) {
            $etiquetas_por_opcional[intval($opcional->id)] = $opcional_etiqueta->get_etiquetas_opcional(
                $codtarifa,
                intval($opcional->id),
                $codfamilia
            );
        }

        return $this->filtrar_por_etiquetas($opcionales, $etiquetas_articulo, $etiquetas_por_opcional);
    }

    /**
     * Filtro puro de intersección de etiquetas: los opcionales sin etiqueta
     * se incluyen siempre, y los etiquetados solo cuando alguna de sus
     * etiquetas está presente en el conjunto de etiquetas del artículo.
     *
     * @param array<int, object> $opcionales
     * @param array<int, string> $etiquetas_articulo
     * @param array<int, array<int, string>> $etiquetas_por_opcional indexado por id de opcional
     * @return array<int, object>
     */
    private function filtrar_por_etiquetas(array $opcionales, array $etiquetas_articulo, array $etiquetas_por_opcional)
    {
        $etiquetas_articulo_idx = [];
        foreach ($etiquetas_articulo as $etiqueta) {
            $etiquetas_articulo_idx[$etiqueta] = true;
        }

        $resultado = [];
        foreach ($opcionales as $opcional) {
            $etiquetas_opcional = $etiquetas_por_opcional[intval($opcional->id)] ?? [];

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
     * Seam de factoría para la tabla de overrides por artículo (verificable
     * sin base de datos viva).
     *
     * @return tarif_tarifa_articulo_opcional
     */
    protected function tarifa_articulo_opcional_model(): tarif_tarifa_articulo_opcional
    {
        return new tarif_tarifa_articulo_opcional();
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

        $tarifa_articulo_opcional = $this->tarifa_articulo_opcional_model();
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
