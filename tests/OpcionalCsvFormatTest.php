<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Services\OpcionalCsvFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpcionalCsvFormat::class)]
final class OpcionalCsvFormatTest extends TestCase
{
    public function testHeaderMatchesTheReferenceFile(): void
    {
        $this->assertSame(
            'sku_producto,codigo_opcional,nombre_es,nombre_en,nombre_fr,tipo_precio,precio,maximo_por_mueble,imagen',
            implode(',', OpcionalCsvFormat::COLUMNS)
        );
    }

    /**
     * @return array<string, array{string, ?string, string}>
     */
    public static function codigoProvider(): array
    {
        return [
            'sufijo' => ['Parrilla (502X07)', '502X07', 'Parrilla'],
            'sufijo con guion y barra' => ['Rail BC36I/B (SBOTBC36)', 'SBOTBC36', 'Rail BC36I/B'],
            'trilingue' => ['Separador / Divider / Séparateur (504X26)', '504X26', 'Separador / Divider / Séparateur'],
            'sin sufijo' => ['115V/1ph/60Hz', null, '115V/1ph/60Hz'],
            'parentesis en minuscula no es codigo' => ['Cerradura (izquierda)', null, 'Cerradura (izquierda)'],
            'sufijo demasiado largo se queda en el nombre' => ['Guía (GUIA01UAGB/GUIA02UAGB)', null, 'Guía (GUIA01UAGB/GUIA02UAGB)'],
            'parentesis no final' => ['Kit (X1) completo', null, 'Kit (X1) completo'],
        ];
    }

    #[DataProvider('codigoProvider')]
    public function testExtractCodigo(string $input, ?string $codigo, string $nombre): void
    {
        $this->assertSame(['nombre' => $nombre, 'codigo' => $codigo], OpcionalCsvFormat::extractCodigo($input));
    }

    public function testSplitTrilingueNeedsExactlyThreeParts(): void
    {
        $this->assertSame(
            ['es' => 'Separador', 'en' => 'Divider', 'fr' => 'Séparateur'],
            OpcionalCsvFormat::splitTrilingue('Separador / Divider / Séparateur')
        );
        $this->assertNull(OpcionalCsvFormat::splitTrilingue('Separador / Divider'));
        $this->assertNull(OpcionalCsvFormat::splitTrilingue('A / B / C / D'));
        $this->assertNull(OpcionalCsvFormat::splitTrilingue('A /  / C'));
        $this->assertNull(OpcionalCsvFormat::splitTrilingue('BC36I/B'), 'una barra sin espacios no parte');
    }

    public function testNombresParaExportarPrefersLanguageRowsThenSplitThenFullName(): void
    {
        // Trilingüe sin filas de idioma: se reparte y se quita el "(codigo)".
        $r = OpcionalCsvFormat::nombresParaExportar('Separador / Divider / Séparateur (504X26)', []);
        $this->assertSame('504X26', $r['codigo']);
        $this->assertSame(['es' => 'Separador', 'en' => 'Divider', 'fr' => 'Séparateur'], $r['nombres']);

        // Sin partir: el nombre completo en los tres idiomas.
        $r = OpcionalCsvFormat::nombresParaExportar('115V/1ph/60Hz', []);
        $this->assertNull($r['codigo']);
        $this->assertSame(['es' => '115V/1ph/60Hz', 'en' => '115V/1ph/60Hz', 'fr' => '115V/1ph/60Hz'], $r['nombres']);

        // Una fila de idioma explícita gana sobre el reparto.
        $r = OpcionalCsvFormat::nombresParaExportar('Parrilla', ['en_GB' => 'Wire shelf', 'fr_FR' => 'Grille']);
        $this->assertSame(['es' => 'Parrilla', 'en' => 'Wire shelf', 'fr' => 'Grille'], $r['nombres']);
    }

    public function testTipoParaExportar(): void
    {
        $this->assertSame(['tipo' => 'percent', 'precio' => 3.0], OpcionalCsvFormat::tipoParaExportar(true, 3.0));
        $this->assertSame(['tipo' => 'fixed', 'precio' => 41.0], OpcionalCsvFormat::tipoParaExportar(false, 41.0));
        $this->assertSame(['tipo' => 'free', 'precio' => 0.0], OpcionalCsvFormat::tipoParaExportar(false, 0.0));
        // Un porcentaje 0 sigue siendo porcentaje.
        $this->assertSame('percent', OpcionalCsvFormat::tipoParaExportar(true, 0.0)['tipo']);
    }

    public function testFormatDecimalMatchesTheReferenceFile(): void
    {
        $this->assertSame('41.0', OpcionalCsvFormat::formatDecimal(41.0));
        $this->assertSame('46.82', OpcionalCsvFormat::formatDecimal(46.82));
        $this->assertSame('3.0', OpcionalCsvFormat::formatDecimal(3.0));
        $this->assertSame('0', OpcionalCsvFormat::formatDecimal(0.0, true));
    }

    public function testParseDecimal(): void
    {
        $this->assertSame(41.0, OpcionalCsvFormat::parseDecimal('41.0'));
        $this->assertSame(41.5, OpcionalCsvFormat::parseDecimal('41,5'));
        $this->assertNull(OpcionalCsvFormat::parseDecimal('abc'));
        $this->assertNull(OpcionalCsvFormat::parseDecimal(''));
        $this->assertNull(OpcionalCsvFormat::parseDecimal('1e5'));
    }

    public function testEscapeAndUnescapeAreInverseForFormulaChars(): void
    {
        foreach (['=SUMA(A1)', '+1', '-20ºC', '@x'] as $raw) {
            $escaped = OpcionalCsvFormat::escapeCell($raw);
            $this->assertSame("'" . $raw, $escaped);
            $this->assertSame($raw, OpcionalCsvFormat::unescapeCell($escaped));
        }

        $this->assertSame('Parrilla', OpcionalCsvFormat::escapeCell('Parrilla'));
        $this->assertSame("'Parrilla", OpcionalCsvFormat::unescapeCell("'Parrilla"), 'solo se quita ante fórmula');
    }
}
