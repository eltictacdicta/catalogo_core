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
 * Importación del CSV `opcionales_import_v1`.
 *
 * Reglas:
 *  - Alta/actualización (upsert): nunca borra opcionales ni asignaciones que no
 *    aparezcan en el fichero.
 *  - El opcional se identifica por `codigo_opcional`; si no existe se busca uno
 *    cuyo nombre acabe en "(CODIGO)" (y entonces adopta ese código); si tampoco,
 *    se crea con ese código.
 *  - El precio (fijo o porcentaje) se guarda para la tarifa indicada.
 *  - `maximo_por_mueble` vacío vale 1.
 *  - Una fila inválida se descarta y se informa; el resto se procesa.
 *  - Modo `dryRun`: valida y cuenta sin escribir nada ni descargar imágenes.
 */
class OpcionalCsvImportService
{
    public const MAX_ROWS = 20000;
    public const MAX_BYTES = 10485760; // 10 MB

    private const PRECIO_EPSILON = 0.00001;

    private OpcionalCsvGateway $gateway;

    /** @var array<string, string|null> sku => referencia */
    private array $articulos = [];

    /** @var array<string, array<string, mixed>> codigo => estado del opcional ya resuelto */
    private array $resueltos = [];

    /** @var array<string, array{linea: int, firma: string}> */
    private array $firmas = [];

    /** @var array<string, int> sku|codigo => línea */
    private array $vistos = [];

    /** @var array<string, bool> */
    private array $imagenIntentada = [];

    public function __construct(OpcionalCsvGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function importFile(string $path, string $codtarifa, bool $dryRun = false): OpcionalCsvImportResult
    {
        $result = new OpcionalCsvImportResult();
        $result->dryRun = $dryRun;

        if (!is_file($path) || !is_readable($path)) {
            $result->fatal = 'No se pudo leer el fichero subido.';

            return $result;
        }

        if ((int) filesize($path) > self::MAX_BYTES) {
            $result->fatal = 'El fichero supera el tamaño máximo (' . (int) (self::MAX_BYTES / 1048576) . ' MB).';

            return $result;
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            $result->fatal = 'No se pudo abrir el fichero subido.';

            return $result;
        }

        try {
            return $this->importStream($stream, $codtarifa, $dryRun);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param resource $stream
     */
    public function importStream($stream, string $codtarifa, bool $dryRun = false): OpcionalCsvImportResult
    {
        $this->articulos = [];
        $this->resueltos = [];
        $this->firmas = [];
        $this->vistos = [];
        $this->imagenIntentada = [];

        $result = new OpcionalCsvImportResult();
        $result->dryRun = $dryRun;

        if (trim($codtarifa) === '') {
            $result->fatal = 'No hay tarifa seleccionada.';

            return $result;
        }

        $firstLine = fgets($stream);
        if ($firstLine === false) {
            $result->fatal = 'El fichero está vacío.';

            return $result;
        }

        $firstLine = (string) preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $header = array_map(
            static fn ($h): string => strtolower(trim((string) $h)),
            str_getcsv(rtrim($firstLine, "\r\n"), $delimiter, '"', '')
        );

        $missing = array_diff(OpcionalCsvFormat::COLUMNS, $header);
        if ($missing !== []) {
            $result->fatal = 'Cabecera no válida. Faltan columnas: ' . implode(', ', $missing)
                . '. Se esperaba: ' . implode(',', OpcionalCsvFormat::COLUMNS) . '.';

            return $result;
        }

        $line = 1;
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || $cells === []) {
                continue; // línea en blanco
            }

            if ($result->filas >= self::MAX_ROWS) {
                $result->fatal = 'Demasiadas filas (máximo ' . self::MAX_ROWS . '). Divida el fichero.';

                return $result;
            }

            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = isset($cells[$i]) ? OpcionalCsvFormat::unescapeCell(trim((string) $cells[$i])) : '';
            }

            $result->filas++;
            $this->processRow($line, $row, $codtarifa, $dryRun, $result);
        }

        return $result;
    }

    /**
     * @param array<string, string> $row
     */
    private function processRow(int $line, array $row, string $codtarifa, bool $dryRun, OpcionalCsvImportResult $r): void
    {
        $sku = $row['sku_producto'];
        $codigo = $row['codigo_opcional'];
        $fail = static function (string $mensaje) use ($r, $line, $sku, $codigo): void {
            $r->addError($line, $sku, $codigo, $mensaje);
        };

        // ---- Validación de la fila (sin tocar nada) ------------------------
        if ($sku === '') {
            $fail('sku_producto vacío.');

            return;
        }

        $len = mb_strlen($codigo);
        if ($len < 1 || $len > OpcionalCsvFormat::CODIGO_MAX_LEN) {
            $fail('codigo_opcional debe tener entre 1 y ' . OpcionalCsvFormat::CODIGO_MAX_LEN . ' caracteres.');

            return;
        }

        $nombres = [];
        foreach (OpcionalCsvFormat::IDIOMAS as $column => $codidioma) {
            $nombres[$codidioma] = $row[$column] ?? '';
            if (mb_strlen($nombres[$codidioma]) > OpcionalCsvFormat::NOMBRE_MAX_LEN) {
                $fail($column . ' supera ' . OpcionalCsvFormat::NOMBRE_MAX_LEN . ' caracteres.');

                return;
            }
        }

        if ($nombres['es_ES'] === '') {
            $fail('nombre_es es obligatorio.');

            return;
        }

        $tipo = strtolower($row['tipo_precio']);
        if (!in_array($tipo, [OpcionalCsvFormat::TIPO_FIXED, OpcionalCsvFormat::TIPO_PERCENT, OpcionalCsvFormat::TIPO_FREE], true)) {
            $fail('tipo_precio debe ser fixed, percent o free.');

            return;
        }

        $valor = OpcionalCsvFormat::parseDecimal($row['precio'] === '' ? '0' : $row['precio']);
        // Only a fixed price may be negative (an opcional that discounts).
        if ($valor === null || ($valor < 0 && $tipo !== OpcionalCsvFormat::TIPO_FIXED)) {
            $fail('precio no es un número válido (>= 0 salvo en fixed).');

            return;
        }

        if ($tipo === OpcionalCsvFormat::TIPO_FREE && $valor > self::PRECIO_EPSILON) {
            $fail('tipo_precio free exige precio 0.');

            return;
        }

        $maximo = OpcionalCsvFormat::MAXIMO_DEFECTO;
        if ($row['maximo_por_mueble'] !== '') {
            if (!preg_match('/^\d+$/', $row['maximo_por_mueble']) || (int) $row['maximo_por_mueble'] < 1) {
                $fail('maximo_por_mueble debe ser un entero >= 1.');

                return;
            }
            $maximo = (int) $row['maximo_por_mueble'];
        }

        $esPorcentaje = $tipo === OpcionalCsvFormat::TIPO_PERCENT;
        if ($tipo === OpcionalCsvFormat::TIPO_FREE) {
            $valor = 0.0;
        }

        $firma = json_encode([$nombres, $esPorcentaje, round($valor, 4)]);
        if (isset($this->firmas[$codigo]) && $this->firmas[$codigo]['firma'] !== $firma) {
            $fail('Datos distintos a los de la fila ' . $this->firmas[$codigo]['linea']
                . ' para el mismo codigo_opcional.');

            return;
        }
        $this->firmas[$codigo] ??= ['linea' => $line, 'firma' => $firma];

        $imagen = $row['imagen'];
        if ($imagen !== '') {
            $urlError = null;
            if (RemoteImageFetcher::normalizeUrl($imagen, $urlError) === null) {
                $r->addAviso($line, $sku, $codigo, 'Imagen ignorada: ' . $urlError);
                $imagen = '';
            }
        }

        // ---- Producto ------------------------------------------------------
        if (!array_key_exists($sku, $this->articulos)) {
            $this->articulos[$sku] = $this->gateway->findArticulo($sku);
        }
        $referencia = $this->articulos[$sku];
        if ($referencia === null) {
            $fail('sku_producto no encontrado en el catálogo.');

            return;
        }

        // ---- Opcional ------------------------------------------------------
        $estado = $this->resolverOpcional(
            $line,
            $codigo,
            $nombres,
            $esPorcentaje,
            $valor,
            $codtarifa,
            $dryRun,
            $r
        );
        if (isset($estado['error'])) {
            $fail((string) $estado['error']);

            return;
        }

        // ---- Imagen (solo si el opcional aún no tiene) ----------------------
        if ($imagen !== '' && empty($estado['tiene_imagen']) && !isset($this->imagenIntentada[$codigo])) {
            $this->imagenIntentada[$codigo] = true;
            if (!$dryRun && $estado['id'] !== null) {
                $imgError = null;
                if ($this->gateway->guardarImagenDesdeUrl((int) $estado['id'], $codigo, $imagen, $imgError)) {
                    $r->imagenesDescargadas++;
                } else {
                    $r->addAviso($line, $sku, $codigo, 'Imagen no descargada: ' . ($imgError ?? 'error desconocido'));
                }
            }
        }

        // ---- Asignación producto ↔ opcional ----------------------------------
        $clave = $sku . '|' . $codigo;
        if (isset($this->vistos[$clave])) {
            $r->addAviso($line, $sku, $codigo, 'Fila repetida (ya aparecía en la fila ' . $this->vistos[$clave]
                . '); prevalece el último máximo.');
        }

        $id = $estado['id'];
        $existia = $id !== null && $this->gateway->existeAsignacion($referencia, (int) $id);

        if (!$dryRun && $id !== null) {
            $asignError = null;
            if (!$this->gateway->asignar($referencia, (int) $id, $maximo, $asignError)) {
                $fail($asignError ?? 'No se pudo asignar el opcional al producto.');

                return;
            }
        }

        if (!isset($this->vistos[$clave])) {
            if ($existia) {
                $r->asignacionesActualizadas++;
            } else {
                $r->asignacionesCreadas++;
            }
        }
        $this->vistos[$clave] ??= $line;
        $r->filasOk++;
    }

    /**
     * Resuelve (y crea/actualiza) el opcional una única vez por código.
     *
     * @param array<string, string> $nombres codidioma => nombre
     * @return array<string, mixed> `id` (null en dry-run para un alta), `tiene_imagen` o `error`
     */
    private function resolverOpcional(
        int $line,
        string $codigo,
        array $nombres,
        bool $esPorcentaje,
        float $valor,
        string $codtarifa,
        bool $dryRun,
        OpcionalCsvImportResult $r
    ): array {
        if (isset($this->resueltos[$codigo])) {
            return $this->resueltos[$codigo];
        }

        $estado = $this->resolverOpcionalNuevo($line, $codigo, $nombres, $esPorcentaje, $valor, $codtarifa, $dryRun, $r);
        $this->resueltos[$codigo] = $estado;

        return $estado;
    }

    /**
     * ¿Los nombres es/en/fr efectivos del opcional existente coinciden con los del CSV?
     *
     * @param array<string, mixed> $info
     * @param array<string, string> $nombres codidioma => nombre
     */
    private function mismosNombres(array $info, array $nombres): bool
    {
        $actuales = OpcionalCsvFormat::nombresParaExportar((string) $info['nombre'], (array) ($info['idiomas'] ?? []))['nombres'];

        return $actuales['es'] === $nombres['es_ES']
            && $actuales['en'] === $nombres['en_GB']
            && $actuales['fr'] === $nombres['fr_FR'];
    }

    /**
     * @param array<string, string> $nombres
     * @return array<string, mixed>
     */
    private function resolverOpcionalNuevo(
        int $line,
        string $codigo,
        array $nombres,
        bool $esPorcentaje,
        float $valor,
        string $codtarifa,
        bool $dryRun,
        OpcionalCsvImportResult $r
    ): array {
        $info = $this->gateway->findOpcionalPorCodigo($codigo, $codtarifa);
        $porSufijo = false;

        if ($info === null) {
            $candidatos = [];
            foreach ($this->gateway->buscarOpcionalesPorSufijo($codigo, $codtarifa) as $candidato) {
                if (OpcionalCsvFormat::extractCodigo((string) $candidato['nombre'])['codigo'] === $codigo) {
                    $candidatos[] = $candidato;
                }
            }

            if (count($candidatos) > 1) {
                // Varios opcionales comparten el mismo "(CODIGO)" en el nombre. Se
                // prefiere el que ya tiene exactamente estos nombres; si no, el de
                // menor id (la misma regla con la que la exportación reparte los
                // códigos repetidos), y se avisa.
                $mismosNombres = array_values(array_filter(
                    $candidatos,
                    fn (array $c): bool => $this->mismosNombres($c, $nombres)
                ));
                if (count($mismosNombres) === 1) {
                    $candidatos = $mismosNombres;
                } else {
                    usort($candidatos, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
                    $r->addAviso($line, '', $codigo, 'Varios opcionales existentes terminan en (' . $codigo
                        . '); se ha usado el de menor id (' . $candidatos[0]['id'] . ').');
                }
            }

            if ($candidatos !== []) {
                $info = $candidatos[0];
                $porSufijo = true;
            }
        }

        if ($info !== null && !empty($info['agrupado'])) {
            return ['id' => null, 'error' => 'El opcional pertenece a un grupo y no se asigna a productos sueltos.'];
        }

        // ---- Alta -----------------------------------------------------------
        if ($info === null) {
            if ($dryRun) {
                $r->opcionalesCreados++;

                return ['id' => null, 'tiene_imagen' => false];
            }

            $id = $this->gateway->guardarOpcional(null, $codigo, $nombres['es_ES'], $esPorcentaje, $valor);
            if ($id === null) {
                return ['id' => null, 'error' => 'No se pudo crear el opcional ' . $codigo . '.'];
            }

            foreach (['en_GB', 'fr_FR'] as $codidioma) {
                if ($nombres[$codidioma] !== '' && !$this->gateway->guardarIdioma($id, $codidioma, $nombres[$codidioma])) {
                    $r->addAviso($line, '', $codigo, 'No se pudo guardar el nombre ' . $codidioma . '.');
                }
            }

            if (!$this->gateway->guardarPrecioTarifa($id, $codtarifa, $esPorcentaje, $valor)) {
                return ['id' => $id, 'error' => 'Opcional creado pero no se pudo guardar su precio en la tarifa.'];
            }

            $r->opcionalesCreados++;

            return ['id' => $id, 'tiene_imagen' => false];
        }

        // ---- Actualización ----------------------------------------------------
        $id = (int) $info['id'];
        $cambiado = false;
        $tieneImagen = trim((string) ($info['imagen'] ?? '')) !== '';

        if ($porSufijo) {
            $cambiado = true;
            if (!$dryRun && !$this->gateway->renombrarCodigo($id, $codigo)) {
                return ['id' => null, 'error' => 'No se pudo adoptar el código ' . $codigo . ' en el opcional existente.'];
            }
        }

        $actuales = OpcionalCsvFormat::nombresParaExportar((string) $info['nombre'], (array) ($info['idiomas'] ?? []))['nombres'];
        $mapa = ['es_ES' => 'es', 'en_GB' => 'en', 'fr_FR' => 'fr'];

        $tipoCambia = (bool) $info['es_porcentaje'] !== $esPorcentaje;
        if ($tipoCambia) {
            $cambiado = true;
            if (!$dryRun && $this->gateway->guardarOpcional($id, $codigo, (string) $info['nombre'], $esPorcentaje, $valor) === null) {
                return ['id' => null, 'error' => 'No se pudo cambiar el tipo de precio del opcional.'];
            }
        }

        foreach ($mapa as $codidioma => $col) {
            $deseado = $nombres[$codidioma];
            if ($deseado === '' || $deseado === $actuales[$col]) {
                continue;
            }

            $cambiado = true;
            if (!$dryRun && !$this->gateway->guardarIdioma($id, $codidioma, $deseado)) {
                $r->addAviso($line, '', $codigo, 'No se pudo actualizar el nombre ' . $codidioma . '.');
            }
        }

        if ($tipoCambia || abs((float) $info['valor'] - $valor) > self::PRECIO_EPSILON) {
            $cambiado = true;
            if (!$dryRun && !$this->gateway->guardarPrecioTarifa($id, $codtarifa, $esPorcentaje, $valor)) {
                return ['id' => $id, 'error' => 'No se pudo guardar el precio en la tarifa.'];
            }
        }

        if ($cambiado) {
            $r->opcionalesActualizados++;
        }

        return ['id' => $id, 'tiene_imagen' => $tieneImagen];
    }
}
