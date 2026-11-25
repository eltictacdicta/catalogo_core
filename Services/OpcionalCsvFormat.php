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
 * Contrato del CSV `opcionales_import_v1` (una fila por producto + opcional).
 *
 * Cabecera:
 *   sku_producto,codigo_opcional,nombre_es,nombre_en,nombre_fr,
 *   tipo_precio,precio,maximo_por_mueble,imagen
 *
 * Esta clase es PURA (sin base de datos): reúne las reglas de traducción entre
 * el modelo interno y el CSV para poder probarlas de forma aislada.
 */
final class OpcionalCsvFormat
{
    public const COLUMNS = [
        'sku_producto',
        'codigo_opcional',
        'nombre_es',
        'nombre_en',
        'nombre_fr',
        'tipo_precio',
        'precio',
        'maximo_por_mueble',
        'imagen',
    ];

    public const TIPO_FIXED = 'fixed';
    public const TIPO_PERCENT = 'percent';
    public const TIPO_FREE = 'free';

    /** Máximo por mueble cuando el CSV lo deja vacío. */
    public const MAXIMO_DEFECTO = 1;

    /** Longitud máxima del código de opcional (catalogo_opcionales.codigo). */
    public const CODIGO_MAX_LEN = 20;

    /** Longitud máxima del nombre (catalogo_opcionales.nombre). */
    public const NOMBRE_MAX_LEN = 100;

    /** Columna CSV de nombre => código de idioma interno. */
    public const IDIOMAS = [
        'nombre_es' => 'es_ES',
        'nombre_en' => 'en_GB',
        'nombre_fr' => 'fr_FR',
    ];

    private const SEPARADOR_NOMBRE = ' / ';

    /** Un código al final del nombre: "Parrilla (502X07)". Solo mayúsculas/dígitos. */
    private const CODIGO_SUFIJO = '/\s*\(([A-Z0-9][A-Z0-9\/._-]*)\)\s*$/u';

    /**
     * Extrae el sufijo "(CODIGO)" del final de un nombre.
     *
     * @return array{nombre: string, codigo: ?string} nombre sin el sufijo y código (o null)
     */
    public static function extractCodigo(string $nombre): array
    {
        $nombre = trim($nombre);
        // Un sufijo más largo que el código máximo no puede ser un codigo_opcional
        // válido: se deja como parte del nombre.
        if (preg_match(self::CODIGO_SUFIJO, $nombre, $m, PREG_OFFSET_CAPTURE) === 1
            && strlen($m[1][0]) <= self::CODIGO_MAX_LEN) {
            return [
                'nombre' => trim(substr($nombre, 0, (int) $m[0][1])),
                'codigo' => $m[1][0],
            ];
        }

        return ['nombre' => $nombre, 'codigo' => null];
    }

    /**
     * Reparte "ES / EN / FR" en tres nombres. Solo se reparte cuando hay
     * EXACTAMENTE tres partes separadas por " / " (con espacios, para no partir
     * medidas como "BC36I/B"); si no, devuelve null.
     *
     * @return array{es: string, en: string, fr: string}|null
     */
    public static function splitTrilingue(string $nombre): ?array
    {
        $parts = explode(self::SEPARADOR_NOMBRE, $nombre);
        if (count($parts) !== 3) {
            return null;
        }

        $parts = array_map('trim', $parts);
        if (in_array('', $parts, true)) {
            return null;
        }

        return ['es' => $parts[0], 'en' => $parts[1], 'fr' => $parts[2]];
    }

    /**
     * Nombres es/en/fr de un opcional para exportar.
     *
     * Prioridad por idioma: fila explícita de `catalogo_opcional_idiomas`; si no
     * existe, parte correspondiente del nombre trilingüe; si no se puede partir,
     * el nombre completo en los tres idiomas.
     *
     * @param array<string, string> $idiomas codidioma => nombre (filas existentes)
     * @return array{nombres: array{es: string, en: string, fr: string}, codigo: ?string}
     */
    public static function nombresParaExportar(string $nombreBase, array $idiomas): array
    {
        $extraido = self::extractCodigo($nombreBase);
        $limpio = $extraido['nombre'];
        $split = self::splitTrilingue($limpio);

        $nombres = [];
        foreach (['es' => 'es_ES', 'en' => 'en_GB', 'fr' => 'fr_FR'] as $col => $codidioma) {
            $explicito = isset($idiomas[$codidioma]) ? trim($idiomas[$codidioma]) : '';
            if ($explicito !== '') {
                $nombres[$col] = $explicito;
            } else {
                $nombres[$col] = $split !== null ? $split[$col] : $limpio;
            }
        }

        return ['nombres' => $nombres, 'codigo' => $extraido['codigo']];
    }

    /**
     * Valor de `tipo_precio` del CSV y el número a volcar en `precio`.
     *
     * @return array{tipo: string, precio: float}
     */
    public static function tipoParaExportar(bool $esPorcentaje, float $valor): array
    {
        if ($esPorcentaje) {
            return ['tipo' => self::TIPO_PERCENT, 'precio' => $valor];
        }

        if (abs($valor) < 0.00001) {
            return ['tipo' => self::TIPO_FREE, 'precio' => 0.0];
        }

        return ['tipo' => self::TIPO_FIXED, 'precio' => $valor];
    }

    /**
     * Número en el formato del CSV de referencia: punto decimal y al menos un
     * decimal ("41.0", "46.82"); el cero de `free` se escribe "0".
     */
    public static function formatDecimal(float $valor, bool $free = false): string
    {
        if ($free) {
            return '0';
        }

        $s = rtrim(rtrim(sprintf('%.4F', $valor), '0'), '.');
        if (strpos($s, '.') === false) {
            $s .= '.0';
        }

        return $s;
    }

    /**
     * Interpreta un número del CSV ("41.0", "41,5"). Devuelve null si no es un
     * número finito.
     */
    public static function parseDecimal(string $valor): ?float
    {
        $valor = trim(str_replace(',', '.', $valor));
        if ($valor === '' || !preg_match('/^-?\d+(\.\d+)?$/', $valor)) {
            return null;
        }

        return (float) $valor;
    }

    /**
     * Neutraliza fórmulas de hoja de cálculo (CSV injection) al exportar.
     */
    public static function escapeCell(string $valor): string
    {
        if ($valor !== '' && strpos("=+-@\t\r", $valor[0]) !== false) {
            return "'" . $valor;
        }

        return $valor;
    }

    /**
     * Inversa de {@see escapeCell()} para que exportar → importar sea estable.
     */
    public static function unescapeCell(string $valor): string
    {
        if (strlen($valor) > 1 && $valor[0] === "'" && strpos("=+-@\t\r", $valor[1]) !== false) {
            return substr($valor, 1);
        }

        return $valor;
    }
}
