<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
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
 */
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core\Services;

use FSFramework\model\catalogo_opcional;
use FSFramework\model\tarif_opcional;

/**
 * Exportación al CSV `opcionales_import_v1` (una fila por producto + opcional)
 * de los productos de una tarifa y de los opcionales asignados a cada uno.
 *
 * Solo lee. La lectura de base de datos vive en los métodos `load*` (seams) y
 * la transformación a filas es pura ({@see buildRows()}), de modo que el
 * formato se prueba sin base de datos.
 */
class OpcionalCsvExportService
{
    /** @var object|null fs_db2 (o doble en tests) */
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db;
    }

    /**
     * Escribe el CSV completo en `$stream` y devuelve las filas de datos.
     *
     * @param resource $stream
     * @param string $baseUrl URL absoluto del sitio con barra final (para las imágenes)
     */
    public function export(string $codtarifa, $stream, string $baseUrl = ''): int
    {
        $assignments = $this->loadAssignments($codtarifa);
        $opcionales = $this->loadOpcionales($codtarifa, array_column($assignments, 'id_opcional'));
        $rows = $this->buildRows($assignments, $opcionales, $baseUrl);

        $this->writeCsv($stream, $rows);

        return count($rows);
    }

    /**
     * @param resource $stream
     * @param list<array<string, string>> $rows
     */
    public function writeCsv($stream, array $rows): void
    {
        fputcsv($stream, OpcionalCsvFormat::COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            $line = [];
            foreach (OpcionalCsvFormat::COLUMNS as $column) {
                $line[] = OpcionalCsvFormat::escapeCell((string) ($row[$column] ?? ''));
            }
            fputcsv($stream, $line, ',', '"', '');
        }
    }

    /**
     * Transformación pura asignaciones + datos de opcional => filas del CSV.
     *
     * @param list<array{sku: string, id_opcional: int, cantidad_max: int}> $assignments
     * @param array<int, array{codigo: string, nombre: string, idiomas: array<string, string>, es_porcentaje: bool, valor: float, imagen: ?string}> $opcionales
     * @return list<array<string, string>>
     */
    public function buildRows(array $assignments, array $opcionales, string $baseUrl = ''): array
    {
        $codigos = $this->resolveCodigos($opcionales);

        $rows = [];
        foreach ($assignments as $assignment) {
            $id = (int) $assignment['id_opcional'];
            if (!isset($opcionales[$id])) {
                continue;
            }

            $o = $opcionales[$id];
            $nombres = OpcionalCsvFormat::nombresParaExportar($o['nombre'], $o['idiomas'])['nombres'];
            $tipo = OpcionalCsvFormat::tipoParaExportar($o['es_porcentaje'], $o['valor']);

            $rows[] = [
                'sku_producto' => (string) $assignment['sku'],
                'codigo_opcional' => $codigos[$id],
                'nombre_es' => $nombres['es'],
                'nombre_en' => $nombres['en'],
                'nombre_fr' => $nombres['fr'],
                'tipo_precio' => $tipo['tipo'],
                'precio' => OpcionalCsvFormat::formatDecimal(
                    $tipo['precio'],
                    $tipo['tipo'] === OpcionalCsvFormat::TIPO_FREE
                ),
                'maximo_por_mueble' => (string) max(1, (int) $assignment['cantidad_max']),
                'imagen' => $this->imagenUrl($o['imagen'], $baseUrl),
            ];
        }

        return $rows;
    }

    /**
     * Código de CSV de cada opcional: el "(CODIGO)" del final del nombre o, si
     * no lo hay, su código actual. Si dos opcionales extraen el mismo código, el
     * de menor id lo conserva y el resto usa su código real para que el CSV
     * identifique siempre a un único opcional por código.
     *
     * @param array<int, array{codigo: string, nombre: string}> $opcionales
     * @return array<int, string>
     */
    public function resolveCodigos(array $opcionales): array
    {
        ksort($opcionales);

        $resolved = [];
        $taken = [];
        foreach ($opcionales as $id => $o) {
            $extraido = OpcionalCsvFormat::extractCodigo($o['nombre'])['codigo'];
            $codigo = ($extraido !== null && !isset($taken[$extraido])) ? $extraido : (string) $o['codigo'];
            $taken[$codigo] = true;
            $resolved[(int) $id] = $codigo;
        }

        return $resolved;
    }

    private function imagenUrl(?string $imagen, string $baseUrl): string
    {
        $imagen = trim((string) $imagen);
        if ($imagen === '') {
            return '';
        }

        return $baseUrl . OpcionalImagenService::OPCIONAL_IMAGES_DIR . rawurlencode(basename($imagen));
    }

    /**
     * Productos con opcionales sueltos asignados y su SKU.
     *
     * Implementación base solo con tablas del catálogo (SKU = referencia y sin
     * filtrar por tarifa). Un plugin consumidor la sustituye por la suya, que acota
     * los productos (p. ej. a una tarifa) y aporta su propio SKU: este plugin no
     * conoce tablas ajenas.
     *
     * @return list<array{sku: string, id_opcional: int, cantidad_max: int}>
     */
    protected function loadAssignments(string $codtarifa): array
    {
        $data = $this->database()->select(
            'SELECT ao.referencia AS sku, ao.id_opcional, ao.cantidad_max'
            . ' FROM catalogo_articulo_opcional ao'
            . ' WHERE NOT EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r.id_opcional = ao.id_opcional)'
            . ' ORDER BY ao.referencia ASC, ao.id_opcional ASC;'
        );

        $list = [];
        foreach ($data ?: [] as $d) {
            $list[] = [
                'sku' => (string) $d['sku'],
                'id_opcional' => (int) $d['id_opcional'],
                'cantidad_max' => max(1, (int) $d['cantidad_max']),
            ];
        }

        return $list;
    }

    /**
     * Datos de cada opcional implicado, con su precio efectivo en la tarifa.
     *
     * @param array<int, int> $ids
     * @return array<int, array{codigo: string, nombre: string, idiomas: array<string, string>, es_porcentaje: bool, valor: float, imagen: ?string}>
     */
    protected function loadOpcionales(string $codtarifa, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $db = $this->database();
        $result = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', $chunk);
            $data = $db->select('SELECT * FROM ' . catalogo_opcional::TABLE . ' WHERE id IN (' . $in . ');');
            foreach ($data ?: [] as $d) {
                $opcional = new tarif_opcional($d);
                $result[(int) $opcional->id] = [
                    'codigo' => (string) $opcional->codigo,
                    'nombre' => self::decode((string) $opcional->nombre),
                    'idiomas' => [],
                    'es_porcentaje' => $opcional->es_precio_porcentaje(),
                    'valor' => (float) $opcional->precio_en_tarifa($codtarifa),
                    'imagen' => $opcional->imagen !== null && $opcional->imagen !== '' ? (string) $opcional->imagen : null,
                ];
            }
        }

        $byCodigo = [];
        foreach ($result as $id => $o) {
            $byCodigo[$o['codigo']] = $id;
        }

        $idiomas = $db->select('SELECT codigo, codidioma, nombre FROM catalogo_opcional_idiomas;');
        foreach ($idiomas ?: [] as $row) {
            $id = $byCodigo[(string) $row['codigo']] ?? null;
            if ($id !== null) {
                $result[$id]['idiomas'][(string) $row['codidioma']] = self::decode((string) $row['nombre']);
            }
        }

        return $result;
    }

    /**
     * El modelo guarda los textos con `no_html()`; el CSV lleva el texto real.
     */
    public static function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return object fs_db2
     */
    protected function database()
    {
        if ($this->db === null) {
            $this->db = new \fs_db2();
        }

        return $this->db;
    }
}
