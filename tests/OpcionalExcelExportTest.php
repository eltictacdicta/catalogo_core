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
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Services\OpcionalExcelExportService;
use PHPUnit\Framework\TestCase;

/**
 * OVE-05 — bulk export parity for opcionales.
 *
 * The export carries every new field (quantity, image, per-language name and
 * description) plus group memberships, family assignments and per-lista prices,
 * keeps the deterministic article-style column order with the default language
 * first, and is read-only: resolving a row never writes anything.
 *
 * DB-free: the renderer consumes plain rows and the read-only test drives a
 * probe whose write methods are counted so a stray write fails the assertion.
 */
final class OpcionalExcelExportTest extends TestCase
{
    public function test_export_includes_every_new_column(): void
    {
        $headers = (new OpcionalExcelExportService())->headers(
            [['codidioma' => 'es', 'nombre' => 'Español'], ['codidioma' => 'en', 'nombre' => 'English']],
            'es',
            ['DEF']
        );

        foreach ([
            'Cantidad Mín',
            'Cantidad Máx',
            'Imagen',
            'Grupos',
            'Familias',
            'nombre_es',
            'descripcion_es',
            'nombre_en',
            'descripcion_en',
            'Precio DEF',
        ] as $expected) {
            $this->assertContains($expected, $headers, 'missing export column: ' . $expected);
        }
    }

    public function test_default_language_comes_first(): void
    {
        $headers = (new OpcionalExcelExportService())->headers(
            [['codidioma' => 'en', 'nombre' => 'English'], ['codidioma' => 'es', 'nombre' => 'Español']],
            'es',
            []
        );

        $localeStart = count(OpcionalExcelExportService::EXPORT_HEADERS)
            + count(OpcionalExcelExportService::RELATION_HEADERS);
        $this->assertSame('nombre_es', $headers[$localeStart]);
        $this->assertSame('descripcion_es', $headers[$localeStart + 1]);
        $this->assertSame('nombre_en', $headers[$localeStart + 2]);
        $this->assertSame('descripcion_en', $headers[$localeStart + 3]);
    }

    public function test_spreadsheet_writes_the_new_values_in_their_columns(): void
    {
        $idiomas = [['codidioma' => 'es', 'nombre' => 'Español'], ['codidioma' => 'en', 'nombre' => 'English']];
        $rows = [[
            'codigo' => 'OPC0001',
            'nombre' => 'Color',
            'descripcion' => 'Color base',
            'precio' => 2.5,
            'tipo_precio' => 'fijo',
            'porcentaje' => '',
            'cantidad_min' => 1,
            'cantidad_max' => 3,
            'imagen' => 'pic.png',
            'nombre_es' => 'Color',
            'descripcion_es' => 'Color base',
            'nombre_en' => 'Colour',
            'descripcion_en' => 'Base colour',
            'grupos' => ['Tallas'],
            'familias' => ['ROPA'],
            'precio_DEF' => 2.5,
        ]];

        $sheet = (new OpcionalExcelExportService())
            ->buildSpreadsheet($rows, false, $idiomas, 'es', ['DEF'])
            ->getActiveSheet();

        $this->assertSame('OPC0001', $sheet->getCell('A2')->getValue());
        $this->assertSame(3, (int) $sheet->getCell('H2')->getValue());
        $this->assertSame('pic.png', $sheet->getCell('I2')->getValue());
        $this->assertSame('Tallas', $sheet->getCell('J2')->getValue());
        $this->assertSame('ROPA', $sheet->getCell('K2')->getValue());
        $this->assertSame(2.5, (float) $sheet->getCell('L2')->getValue());
        $this->assertSame('Colour', $sheet->getCell('O2')->getValue());
    }

    public function test_resolving_a_row_is_read_only(): void
    {
        $probe = new ReadOnlyOpcionalProbe();

        $row = (new OpcionalExcelExportService())->row_from_opcional($probe, ['DEF']);

        $this->assertSame(0, $probe->writes, 'building an export row must not write anything');
        $this->assertSame('OPC0001', $row['codigo']);
        $this->assertSame(3, $row['cantidad_max']);
        $this->assertSame('pic.png', $row['imagen']);
        $this->assertSame(['Tallas'], $row['grupos']);
        $this->assertSame(['ROPA'], $row['familias']);
        $this->assertSame(2.5, (float) $row['precio_DEF']);
    }
}

/**
 * Read probe for the read-only contract: every read returns fixture data and
 * every write method bumps a counter, so a single stray write fails the test.
 */
final class ReadOnlyOpcionalProbe
{
    public int $writes = 0;

    public function __construct(
        public string $codigo = 'OPC0001',
        public string $nombre = 'Color',
        public string $descripcion = 'Color base',
        public float $precio = 2.5,
        public string $tipo_precio = 'fijo',
        public ?float $porcentaje = null,
        public int $cantidad_min = 1,
        public int $cantidad_max = 3,
        public ?string $imagen = 'pic.png',
    ) {
    }

    /** @return list<string> */
    public function grupos_labels(): array
    {
        return ['Tallas'];
    }

    /** @return list<object> */
    public function get_familias(): array
    {
        return [(object) ['codfamilia' => 'ROPA']];
    }

    public function precio_en_lista($codlista = null): float
    {
        return 2.5;
    }

    public function get_nombre_idioma($codidioma = null): string
    {
        return $this->nombre;
    }

    public function get_descripcion_idioma($codidioma = null): string
    {
        return $this->descripcion;
    }

    public function save()
    {
        $this->writes++;

        return true;
    }

    public function delete()
    {
        $this->writes++;

        return true;
    }

    public function set_idioma($codidioma, $nombre, $descripcion)
    {
        $this->writes++;

        return true;
    }

    public function add_familia_only($codfamilia)
    {
        $this->writes++;

        return true;
    }

    public function add_to_grupo($idGrupo)
    {
        $this->writes++;

        return true;
    }

    public function set_precio_lista($codlista, $precio)
    {
        $this->writes++;

        return true;
    }
}
