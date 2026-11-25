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

/**
 * Acceso a datos que necesita la importación del CSV de opcionales.
 *
 * Separa la lógica de importación (validar, decidir qué escribir, contar) de la
 * persistencia, para probar la primera con un doble en memoria.
 *
 * Forma de un opcional ("info"):
 *   id: int, codigo: string, nombre: string (texto real, no html-escapado),
 *   idiomas: array<codidioma, nombre>, es_porcentaje: bool,
 *   valor: float (precio fijo o porcentaje EN LA TARIFA indicada),
 *   imagen: ?string, agrupado: bool
 */
interface OpcionalCsvGateway
{
    /**
     * Referencia interna del artículo para un `sku_producto` (tarif_articulos.ref
     * o, en su defecto, la propia referencia), o null.
     */
    public function findArticulo(string $sku): ?string;

    /**
     * @return array<string, mixed>|null
     */
    public function findOpcionalPorCodigo(string $codigo, string $codtarifa): ?array;

    /**
     * Opcionales cuyo nombre acaba en "(CODIGO)".
     *
     * @return list<array<string, mixed>>
     */
    public function buscarOpcionalesPorSufijo(string $codigo, string $codtarifa): array;

    /** Cambia el código del opcional (las filas de idioma lo siguen por FK). */
    public function renombrarCodigo(int $id, string $nuevoCodigo): bool;

    /**
     * Crea (`$id` null) o actualiza un opcional y devuelve su id.
     *
     * En una creación `$valor` inicializa también el precio/porcentaje base.
     */
    public function guardarOpcional(?int $id, string $codigo, string $nombre, bool $esPorcentaje, float $valor): ?int;

    public function guardarIdioma(int $id, string $codidioma, string $nombre): bool;

    public function guardarPrecioTarifa(int $id, string $codtarifa, bool $esPorcentaje, float $valor): bool;

    public function existeAsignacion(string $referencia, int $id): bool;

    /**
     * Asigna el opcional al artículo (o actualiza su máximo si ya estaba).
     */
    public function asignar(string $referencia, int $id, int $maximo, ?string &$error = null): bool;

    /**
     * Descarga el URL y lo guarda como imagen del opcional.
     */
    public function guardarImagenDesdeUrl(int $id, string $codigo, string $url, ?string &$error = null): bool;
}
