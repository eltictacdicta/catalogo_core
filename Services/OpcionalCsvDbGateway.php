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

use FSFramework\model\catalogo_articulo_opcional;
use FSFramework\model\catalogo_opcional;
use FSFramework\model\tarif_opcional;

/**
 * Implementación sobre los modelos reales de {@see OpcionalCsvGateway}.
 *
 * Los modelos guardan nombre/descripción/código con `no_html()`; al volver a
 * guardar un opcional leído de la base se decodifican antes para no
 * escapar dos veces ("&amp;amp;").
 */
class OpcionalCsvDbGateway implements OpcionalCsvGateway
{
    /** @var object|null fs_db2 */
    private $db;

    private OpcionalImagenService $imagenes;

    public function __construct($db = null, ?OpcionalImagenService $imagenes = null)
    {
        $this->db = $db;
        $this->imagenes = $imagenes ?? new OpcionalImagenService();
    }

    /**
     * Base: el SKU es la referencia del artículo. Un plugin consumidor lo
     * sobrescribe para resolver por su propio identificador (este plugin no
     * conoce tablas ajenas).
     */
    public function findArticulo(string $sku): ?string
    {
        $data = $this->db()->select('SELECT referencia FROM articulos WHERE referencia = ? LIMIT 1;', [$sku]);

        return $data ? (string) $data[0]['referencia'] : null;
    }

    public function findOpcionalPorCodigo(string $codigo, string $codtarifa): ?array
    {
        $data = $this->db()->select(
            'SELECT * FROM ' . catalogo_opcional::TABLE . ' WHERE codigo = ? LIMIT 1;',
            [$codigo]
        );

        return $data ? $this->info(new tarif_opcional($data[0]), $codtarifa) : null;
    }

    public function buscarOpcionalesPorSufijo(string $codigo, string $codtarifa): array
    {
        // El nombre se guarda html-escapado: el código solo lleva [A-Z0-9/._-]
        // y los paréntesis no se escapan, así que el sufijo es literal.
        $like = '%(' . addcslashes($codigo, '%_\\') . ')';
        $data = $this->db()->select(
            'SELECT * FROM ' . catalogo_opcional::TABLE . ' WHERE nombre LIKE ? ORDER BY id ASC LIMIT 5;',
            [$like]
        );

        $list = [];
        foreach ($data ?: [] as $d) {
            $list[] = $this->info(new tarif_opcional($d), $codtarifa);
        }

        return $list;
    }

    public function renombrarCodigo(int $id, string $nuevoCodigo): bool
    {
        $model = new catalogo_opcional();
        $existente = $model->get_by_codigo($nuevoCodigo);
        if ($existente && (int) $existente->id !== $id) {
            return false;
        }

        // Una sola sentencia: la FK ON UPDATE CASCADE arrastra catalogo_opcional_idiomas.
        return (bool) $this->db()->exec(
            'UPDATE ' . catalogo_opcional::TABLE
            . ' SET codigo = ' . $this->db()->var2str($nuevoCodigo)
            . ' WHERE id = ' . (int) $id . ';'
        );
    }

    public function guardarOpcional(?int $id, string $codigo, string $nombre, bool $esPorcentaje, float $valor): ?int
    {
        if ($id === null) {
            $opcional = new tarif_opcional();
            $opcional->codigo = $codigo;
            $opcional->nombre = $nombre;
            $opcional->descripcion = '';
            $opcional->activo = true;
            $opcional->cantidad_min = 1;
            $opcional->cantidad_max = 1;
            $this->aplicarTipo($opcional, $esPorcentaje, $valor, true);

            return $opcional->save() ? (int) $opcional->id : null;
        }

        $opcional = (new tarif_opcional())->get($id);
        if (!$opcional) {
            return null;
        }

        $this->decodificar($opcional);
        $opcional->nombre = $nombre !== '' ? $nombre : $opcional->nombre;
        $this->aplicarTipo($opcional, $esPorcentaje, $valor, false);

        return $opcional->save() ? (int) $opcional->id : null;
    }

    public function guardarIdioma(int $id, string $codidioma, string $nombre): bool
    {
        $opcional = (new tarif_opcional())->get($id);
        if (!$opcional) {
            return false;
        }

        $this->decodificar($opcional);

        $descripcion = $opcional->descripcion;
        foreach ($opcional->get_idiomas() as $fila) {
            if ($fila->codidioma === $codidioma) {
                $descripcion = OpcionalCsvExportService::decode((string) $fila->descripcion);
                break;
            }
        }

        return $opcional->set_idioma($codidioma, $nombre, (string) $descripcion);
    }

    public function guardarPrecioTarifa(int $id, string $codtarifa, bool $esPorcentaje, float $valor): bool
    {
        $opcional = (new tarif_opcional())->get($id);
        if (!$opcional) {
            return false;
        }

        return $esPorcentaje
            ? (bool) $opcional->set_porcentaje_tarifa($codtarifa, $valor)
            : (bool) $opcional->set_precio_tarifa($codtarifa, $valor);
    }

    public function existeAsignacion(string $referencia, int $id): bool
    {
        return (bool) (new catalogo_articulo_opcional())->exists_relation($referencia, $id);
    }

    public function asignar(string $referencia, int $id, int $maximo, ?string &$error = null): bool
    {
        $relacion = new catalogo_articulo_opcional();
        $valid = $relacion->validate_opcional_for_articulo($id);
        if ($valid !== true) {
            $error = (string) $valid;

            return false;
        }

        if ($relacion->exists_relation($referencia, $id)) {
            // Solo el máximo: no se toca `obligatorio` de una relación existente.
            $ok = $relacion->set_cantidad_max($referencia, $id, $maximo);
        } else {
            $ok = (bool) $relacion->add($referencia, $id, false, $maximo);
        }

        if (!$ok) {
            $error = 'No se pudo guardar la asignación.';
        }

        return $ok;
    }

    public function guardarImagenDesdeUrl(int $id, string $codigo, string $url, ?string &$error = null): bool
    {
        $opcional = (new tarif_opcional())->get($id);
        if (!$opcional) {
            $error = 'Opcional no encontrado';

            return false;
        }

        $filename = $this->imagenes->upload_from_url($codigo, $url, $error);
        if ($filename === null) {
            return false;
        }

        $this->decodificar($opcional);
        $opcional->imagen = $filename;
        if (!$opcional->save()) {
            $this->imagenes->delete_file($filename);
            $error = 'No se pudo asociar la imagen al opcional';

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function info(tarif_opcional $opcional, string $codtarifa): array
    {
        $idiomas = [];
        foreach ($opcional->get_idiomas() as $fila) {
            $idiomas[(string) $fila->codidioma] = OpcionalCsvExportService::decode((string) $fila->nombre);
        }

        return [
            'id' => (int) $opcional->id,
            'codigo' => (string) $opcional->codigo,
            'nombre' => OpcionalCsvExportService::decode((string) $opcional->nombre),
            'idiomas' => $idiomas,
            'es_porcentaje' => $opcional->es_precio_porcentaje(),
            'valor' => (float) $opcional->precio_en_tarifa($codtarifa),
            'imagen' => $opcional->imagen !== null && $opcional->imagen !== '' ? (string) $opcional->imagen : null,
            'agrupado' => $opcional->is_grouped(),
        ];
    }

    private function decodificar(catalogo_opcional $opcional): void
    {
        $opcional->codigo = OpcionalCsvExportService::decode((string) $opcional->codigo);
        $opcional->nombre = OpcionalCsvExportService::decode((string) $opcional->nombre);
        $opcional->descripcion = OpcionalCsvExportService::decode((string) $opcional->descripcion);
    }

    private function aplicarTipo(catalogo_opcional $opcional, bool $esPorcentaje, float $valor, bool $nuevo): void
    {
        if ($esPorcentaje) {
            $opcional->tipo_precio = catalogo_opcional::TIPO_PRECIO_PORCENTAJE;
            if ($nuevo || $opcional->porcentaje === null) {
                $opcional->porcentaje = $valor;
            }

            return;
        }

        $opcional->tipo_precio = catalogo_opcional::TIPO_PRECIO_FIJO;
        if ($nuevo) {
            $opcional->precio = $valor;
            $opcional->porcentaje = null;
        }
    }

    /**
     * @return object fs_db2
     */
    protected function db()
    {
        if ($this->db === null) {
            $this->db = new \fs_db2();
        }

        return $this->db;
    }
}
