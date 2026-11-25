<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Services\OpcionalCsvGateway;
use FSFramework\Plugins\catalogo_core\Services\OpcionalCsvImportService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Doble en memoria del gateway: registra cada escritura.
 */
final class FakeOpcionalCsvGateway implements OpcionalCsvGateway
{
    /** @var array<string, string> sku => referencia */
    public array $articulos = ['SKU1' => 'R1', 'SKU2' => 'R2'];
    /** @var array<string, array<string, mixed>> codigo => info */
    public array $opcionales = [];
    /** @var list<string> */
    public array $log = [];
    /** @var array<string, int> "ref|id" => max */
    public array $asignaciones = [];
    private int $nextId = 100;

    public function findArticulo(string $sku): ?string
    {
        return $this->articulos[$sku] ?? null;
    }

    public function findOpcionalPorCodigo(string $codigo, string $codtarifa): ?array
    {
        return $this->opcionales[$codigo] ?? null;
    }

    public function buscarOpcionalesPorSufijo(string $codigo, string $codtarifa): array
    {
        $out = [];
        foreach ($this->opcionales as $o) {
            if (str_ends_with((string) $o['nombre'], '(' . $codigo . ')')) {
                $out[] = $o;
            }
        }

        return $out;
    }

    public function renombrarCodigo(int $id, string $nuevoCodigo): bool
    {
        $this->log[] = "rename:$id:$nuevoCodigo";
        foreach ($this->opcionales as $k => $o) {
            if ($o['id'] === $id) {
                unset($this->opcionales[$k]);
                $o['codigo'] = $nuevoCodigo;
                $this->opcionales[$nuevoCodigo] = $o;
            }
        }

        return true;
    }

    public function guardarOpcional(?int $id, string $codigo, string $nombre, bool $esPorcentaje, float $valor): ?int
    {
        $this->log[] = 'save:' . ($id ?? 'new') . ":$codigo:" . ($esPorcentaje ? 'pct' : 'fix') . ":$valor";
        if ($id === null) {
            $id = $this->nextId++;
            $this->opcionales[$codigo] = ['id' => $id, 'codigo' => $codigo, 'nombre' => $nombre, 'idiomas' => [],
                'es_porcentaje' => $esPorcentaje, 'valor' => 0.0, 'imagen' => null, 'agrupado' => false];
        }

        return $id;
    }

    public function guardarIdioma(int $id, string $codidioma, string $nombre): bool
    {
        $this->log[] = "idioma:$id:$codidioma:$nombre";

        return true;
    }

    public function guardarPrecioTarifa(int $id, string $codtarifa, bool $esPorcentaje, float $valor): bool
    {
        $this->log[] = "precio:$id:$codtarifa:" . ($esPorcentaje ? 'pct' : 'fix') . ":$valor";

        return true;
    }

    public function existeAsignacion(string $referencia, int $id): bool
    {
        return isset($this->asignaciones["$referencia|$id"]);
    }

    public function asignar(string $referencia, int $id, int $maximo, ?string &$error = null): bool
    {
        $this->log[] = "asignar:$referencia:$id:$maximo";
        $this->asignaciones["$referencia|$id"] = $maximo;

        return true;
    }

    public function guardarImagenDesdeUrl(int $id, string $codigo, string $url, ?string &$error = null): bool
    {
        $this->log[] = "imagen:$id:$url";

        return true;
    }

    /** @return list<string> */
    public function writes(): array
    {
        return $this->log;
    }
}

#[CoversClass(OpcionalCsvImportService::class)]
final class OpcionalCsvImportServiceTest extends TestCase
{
    private const HEADER = "sku_producto,codigo_opcional,nombre_es,nombre_en,nombre_fr,tipo_precio,precio,maximo_por_mueble,imagen\n";

    private function importar(FakeOpcionalCsvGateway $gw, string $body, bool $dry = false, string $header = self::HEADER)
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $header . $body);
        rewind($stream);

        return (new OpcionalCsvImportService($gw))->importStream($stream, 'T1', $dry);
    }

    public function testCreatesOpcionalPriceAndAssignmentWithMax(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $r = $this->importar($gw, "SKU1,502X07,Parrilla,Wire shelf,Grille,fixed,41.0,6,\n");

        $this->assertFalse($r->hasErrors());
        $this->assertSame(1, $r->opcionalesCreados);
        $this->assertSame(1, $r->asignacionesCreadas);
        $this->assertSame([
            'save:new:502X07:fix:41',
            'idioma:100:en_GB:Wire shelf',
            'idioma:100:fr_FR:Grille',
            'precio:100:T1:fix:41',
            'asignar:R1:100:6',
        ], $gw->writes());
    }

    public function testEmptyMaxDefaultsToOne(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $this->importar($gw, "SKU1,X1,Uno,One,Un,free,0,,\n");

        $this->assertSame(1, $gw->asignaciones['R1|100']);
    }

    public function testPercentAndFreeMapping(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $this->importar($gw, "SKU1,P1,Volt,Volt,Volt,percent,3.0,1,\nSKU1,F1,Libre,Free,Libre,free,0,1,\n");

        $this->assertContains('precio:100:T1:pct:3', $gw->writes());
        $this->assertContains('precio:101:T1:fix:0', $gw->writes());
    }

    public function testDryRunWritesNothingButCounts(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $r = $this->importar($gw, "SKU1,X1,Uno,One,Un,fixed,5,2,https://x.test/a.jpg\nSKU2,X1,Uno,One,Un,fixed,5,3,\n", true);

        $this->assertSame([], $gw->writes());
        $this->assertTrue($r->dryRun);
        $this->assertSame(1, $r->opcionalesCreados, 'un código se cuenta una vez');
        $this->assertSame(2, $r->asignacionesCreadas);
        $this->assertSame(2, $r->filasOk);
    }

    public function testUnknownSkuIsRejectedAndNothingIsCreatedForIt(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $r = $this->importar($gw, "NOPE,X1,Uno,One,Un,fixed,5,1,\nSKU1,X2,Dos,Two,Deux,fixed,6,1,\n");

        $this->assertCount(1, $r->errores);
        $this->assertSame(2, $r->errores[0]['linea']);
        $this->assertSame('NOPE', $r->errores[0]['sku']);
        $this->assertSame(1, $r->filasOk);
        $this->assertArrayNotHasKey('X1', $gw->opcionales);
    }

    public function testInvalidRowsAreReportedWithLineNumbers(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $body = "SKU1,,Uno,One,Un,fixed,5,1,\n"                       // código vacío
            . "SKU1,X2,,One,Un,fixed,5,1,\n"                           // sin nombre_es
            . "SKU1,X3,Tres,Three,Trois,weird,5,1,\n"                  // tipo
            . "SKU1,X4,Cuatro,Four,Quatre,fixed,abc,1,\n"              // precio
            . "SKU1,X5,Cinco,Five,Cinq,free,2,1,\n"                    // free con precio
            . "SKU1,X6,Seis,Six,Six,fixed,5,0,\n"                      // máximo 0
            . "SKU1,X7,Siete,Seven,Sept,fixed,5,1.5,\n"                // máximo no entero
            . "SKU1," . str_repeat('A', 21) . ",N,N,N,fixed,5,1,\n";   // código largo

        $r = $this->importar($gw, $body);

        $this->assertSame(0, $r->filasOk);
        $this->assertSame(range(2, 9), array_column($r->errores, 'linea'));
        $this->assertSame([], $gw->writes());
    }

    public function testConflictingDataForTheSameCodeRejectsTheLaterRow(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $r = $this->importar($gw, "SKU1,X1,Uno,One,Un,fixed,5,1,\nSKU2,X1,Uno,One,Un,fixed,9,1,\n");

        $this->assertSame(1, $r->filasOk);
        $this->assertCount(1, $r->errores);
        $this->assertSame(3, $r->errores[0]['linea']);
        $this->assertStringContainsString('fila 2', $r->errores[0]['mensaje']);
    }

    public function testExistingOpcionalMatchingEverythingOnlyAssigns(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $gw->opcionales['X1'] = ['id' => 7, 'codigo' => 'X1', 'nombre' => 'Uno', 'idiomas' => ['en_GB' => 'One', 'fr_FR' => 'Un'],
            'es_porcentaje' => false, 'valor' => 5.0, 'imagen' => 'a.jpg', 'agrupado' => false];

        $r = $this->importar($gw, "SKU1,X1,Uno,One,Un,fixed,5.0,3,https://x.test/otra.jpg\n");

        $this->assertSame(['asignar:R1:7:3'], $gw->writes(), 'ni nombres, ni precio ni imagen cambian');
        $this->assertSame(0, $r->opcionalesActualizados);
    }

    public function testExistingOpcionalGetsPriceAndNamesUpdated(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $gw->opcionales['X1'] = ['id' => 7, 'codigo' => 'X1', 'nombre' => 'Uno', 'idiomas' => [],
            'es_porcentaje' => false, 'valor' => 5.0, 'imagen' => null, 'agrupado' => false];

        $r = $this->importar($gw, "SKU1,X1,Uno,One,Un,fixed,8,1,\n");

        $this->assertSame(1, $r->opcionalesActualizados);
        $this->assertContains('precio:7:T1:fix:8', $gw->writes());
        $this->assertContains('idioma:7:en_GB:One', $gw->writes());
        $this->assertContains('idioma:7:fr_FR:Un', $gw->writes());
    }

    public function testOpcionalMatchedByNameSuffixAdoptsTheCode(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $gw->opcionales['hash123'] = ['id' => 9, 'codigo' => 'hash123', 'nombre' => 'Separador / Divider / Séparateur (504X26)',
            'idiomas' => [], 'es_porcentaje' => false, 'valor' => 46.82, 'imagen' => null, 'agrupado' => false];

        $r = $this->importar($gw, "SKU1,504X26,Separador,Divider,Séparateur,fixed,46.82,1,\n");

        $this->assertSame(['rename:9:504X26', 'asignar:R1:9:1'], $gw->writes());
        $this->assertSame(1, $r->opcionalesActualizados);
        $this->assertSame(0, $r->opcionalesCreados);
    }

    private function duplicados(FakeOpcionalCsvGateway $gw): void
    {
        foreach ([1 => ['a', 'Dup uno'], 2 => ['b', 'Dup dos']] as $id => [$c, $nombre]) {
            $gw->opcionales[$c] = ['id' => $id, 'codigo' => $c, 'nombre' => "$nombre (Z9)", 'idiomas' => [],
                'es_porcentaje' => false, 'valor' => 0.0, 'imagen' => null, 'agrupado' => false];
        }
    }

    public function testSeveralSuffixMatchesPickTheOneWithTheSameNames(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $this->duplicados($gw);

        $r = $this->importar($gw, "SKU1,Z9,Dup dos,Dup dos,Dup dos,free,0,1,\n");

        $this->assertFalse($r->hasErrors());
        $this->assertSame(['rename:2:Z9', 'asignar:R1:2:1'], $gw->writes());
        $this->assertSame([], $r->avisos);
    }

    public function testSeveralSuffixMatchesWithoutNameMatchPickLowestIdAndWarn(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $this->duplicados($gw);

        $r = $this->importar($gw, "SKU1,Z9,Otro,Otro,Otro,free,0,1,\n");

        $this->assertFalse($r->hasErrors());
        $this->assertContains('rename:1:Z9', $gw->writes());
        $this->assertCount(1, $r->avisos);
        $this->assertStringContainsString('menor id (1)', $r->avisos[0]['mensaje']);
    }

    public function testGroupedOpcionalIsRejected(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $gw->opcionales['G1'] = ['id' => 3, 'codigo' => 'G1', 'nombre' => 'Grupo', 'idiomas' => [],
            'es_porcentaje' => false, 'valor' => 0.0, 'imagen' => null, 'agrupado' => true];

        $r = $this->importar($gw, "SKU1,G1,Grupo,Group,Groupe,free,0,1,\n");

        $this->assertCount(1, $r->errores);
        $this->assertSame([], $gw->writes());
    }

    public function testImageIsDownloadedOnlyOnceAndOnlyWhenMissing(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $this->importar($gw, "SKU1,X1,Uno,One,Un,fixed,5,1,https://x.test/a.jpg\nSKU2,X1,Uno,One,Un,fixed,5,1,https://x.test/a.jpg\n");

        $imagenes = array_values(array_filter($gw->writes(), static fn ($l) => str_starts_with($l, 'imagen:')));
        $this->assertSame(['imagen:100:https://x.test/a.jpg'], $imagenes);
    }

    public function testNonHttpsImageIsAWarningNotAnError(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $r = $this->importar($gw, "SKU1,X1,Uno,One,Un,fixed,5,1,http://x.test/a.jpg\n");

        $this->assertFalse($r->hasErrors());
        $this->assertCount(1, $r->avisos);
        $this->assertSame([], array_filter($gw->writes(), static fn ($l) => str_starts_with($l, 'imagen:')));
    }

    public function testRepeatedSkuCodeRowWarnsAndKeepsLastMax(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $r = $this->importar($gw, "SKU1,X1,Uno,One,Un,fixed,5,2,\nSKU1,X1,Uno,One,Un,fixed,5,4,\n");

        $this->assertSame(4, $gw->asignaciones['R1|100']);
        $this->assertSame(1, $r->asignacionesCreadas);
        $this->assertCount(1, $r->avisos);
    }

    public function testHeaderIsValidatedAndSemicolonAndBomAreAccepted(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $r = $this->importar($gw, "x\n", false, "a,b,c\n");
        $this->assertNotNull($r->fatal);
        $this->assertStringContainsString('Faltan columnas', (string) $r->fatal);

        $semi = "\xEF\xBB\xBF" . str_replace(',', ';', self::HEADER);
        $r = $this->importar($gw, "SKU1;X1;Uno;One;Un;fixed;5;2;\n", false, $semi);
        $this->assertNull($r->fatal);
        $this->assertSame(1, $r->filasOk);
    }

    public function testMissingTarifaIsFatal(): void
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, self::HEADER);
        rewind($stream);

        $r = (new OpcionalCsvImportService(new FakeOpcionalCsvGateway()))->importStream($stream, '');
        $this->assertNotNull($r->fatal);
    }

    public function testExportedFileRoundTripsWithoutChanges(): void
    {
        $gw = new FakeOpcionalCsvGateway();
        $csv = "SKU1,502X07,Parrilla,Wire shelf,Grille,fixed,41.0,6,\n";
        $this->importar($gw, $csv);

        // Segunda importación del mismo fichero sobre el estado resultante.
        $gw->opcionales['502X07']['idiomas'] = ['en_GB' => 'Wire shelf', 'fr_FR' => 'Grille'];
        $gw->opcionales['502X07']['valor'] = 41.0;
        $gw->log = [];
        $r = $this->importar($gw, $csv);

        $this->assertSame(0, $r->opcionalesActualizados);
        $this->assertSame(1, $r->asignacionesActualizadas);
        $this->assertSame(['asignar:R1:100:6'], $gw->writes());
    }
}
