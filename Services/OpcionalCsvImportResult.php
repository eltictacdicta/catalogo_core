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
 * Resultado de una importación del CSV de opcionales.
 */
final class OpcionalCsvImportResult
{
    public bool $dryRun = false;
    /** Error global (cabecera inválida, fichero ilegible...). La importación no se ejecutó. */
    public ?string $fatal = null;

    public int $filas = 0;
    public int $filasOk = 0;
    public int $opcionalesCreados = 0;
    public int $opcionalesActualizados = 0;
    public int $asignacionesCreadas = 0;
    public int $asignacionesActualizadas = 0;
    public int $imagenesDescargadas = 0;

    /** @var list<array{linea: int, sku: string, codigo: string, mensaje: string}> */
    public array $errores = [];

    /** @var list<array{linea: int, sku: string, codigo: string, mensaje: string}> */
    public array $avisos = [];

    public function addError(int $linea, string $sku, string $codigo, string $mensaje): void
    {
        $this->errores[] = ['linea' => $linea, 'sku' => $sku, 'codigo' => $codigo, 'mensaje' => $mensaje];
    }

    public function addAviso(int $linea, string $sku, string $codigo, string $mensaje): void
    {
        $this->avisos[] = ['linea' => $linea, 'sku' => $sku, 'codigo' => $codigo, 'mensaje' => $mensaje];
    }

    public function hasErrors(): bool
    {
        return $this->fatal !== null || $this->errores !== [];
    }
}
