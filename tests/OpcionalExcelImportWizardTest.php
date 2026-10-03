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

use FSFramework\Plugins\catalogo_core\Services\OpcionalExcelImportWizardService;
use PHPUnit\Framework\TestCase;

/**
 * OVE-06 — bulk import wizard parity and idempotency.
 *
 * DB-free: the wizard's model factory seams are replaced with in-memory fakes
 * that answer the exact lookup the service issues and record every create. The
 * rejected-rows sink is an in-memory buffer, so the tests prove orphan
 * references are reported and never silently dropped.
 */
final class OpcionalExcelImportWizardTest extends TestCase
{
    public function test_reimport_updates_instead_of_duplicating(): void
    {
        $opcional = new FakeOpcionalModel(['OPC0001']);
        $wizard = $this->wizard($opcional, [], []);

        $result = $wizard->apply_row(['codigo' => 'OPC0001', 'nombre' => 'Nuevo', 'cantidad_max' => '3']);

        $this->assertSame('updated', $result['status']);
        $this->assertSame([], $opcional->created, 'an existing código must never be created again');
        $this->assertSame(1, $opcional->updateCalls);
        $this->assertSame('Nuevo', $opcional->lastUpdated['nombre'] ?? null);
        $this->assertSame(3, $opcional->lastUpdated['cantidad_max'] ?? null);
    }

    public function test_orphan_group_is_reported_and_not_persisted(): void
    {
        $opcional = new FakeOpcionalModel(['OPC0001']);
        $wizard = $this->wizard($opcional, [], ['GRP9' => false]);

        $result = $wizard->apply_row(['codigo' => 'OPC0001', 'nombre' => 'X', 'grupos' => 'GRP9']);

        $this->assertSame('rejected', $result['status']);
        $this->assertCount(1, $wizard->rejectedRows());
        $this->assertSame('grupo_inexistente', $wizard->rejectedRows()[0]['motivo']);
        $this->assertSame(0, $opcional->updateCalls, 'a rejected row must not persist the opcional');
        $this->assertSame([], $opcional->created);
    }

    public function test_unknown_language_is_reported(): void
    {
        $opcional = new FakeOpcionalModel([]);
        $wizard = $this->wizard($opcional, ['es' => true, 'en' => true], []);

        $result = $wizard->apply_row([
            'codigo' => 'OPC0001',
            'nombre' => 'X',
            'nombre_zz' => 'Hello',
            'descripcion_zz' => 'World',
        ]);

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('idioma_inexistente', $wizard->rejectedRows()[0]['motivo']);
        $this->assertSame([], $opcional->created);
    }

    public function test_base64_image_is_persisted_through_the_image_service(): void
    {
        $opcional = new FakeOpcionalModel([]);
        $image = new FakeOpcionalImageService('OPC0001_123_abcd.png');
        $wizard = $this->wizard($opcional, [], [], $image);

        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

        $result = $wizard->apply_row([
            'codigo' => 'OPC0001',
            'nombre' => 'Con imagen',
            'imagen_base64' => 'data:image/png;base64,' . $png,
        ]);

        $this->assertSame('created', $result['status']);
        $this->assertCount(1, $image->uploads);
        $this->assertSame('OPC0001', $image->uploads[0]['codigo']);
        $this->assertSame('OPC0001_123_abcd.png', $opcional->lastSaved['imagen'] ?? null);
    }

    public function test_new_opcional_is_created(): void
    {
        $opcional = new FakeOpcionalModel([]);
        $wizard = $this->wizard($opcional, [], []);

        $result = $wizard->apply_row(['codigo' => 'OPC0009', 'nombre' => 'Nueva']);

        $this->assertSame('created', $result['status']);
        $this->assertSame(['OPC0009'], $opcional->created);
        $this->assertSame(0, $opcional->updateCalls);
    }

    /**
     * @param array<string, bool> $idiomas code => exists
     * @param array<string, bool> $grupos code => exists
     */
    private function wizard(
        FakeOpcionalModel $opcional,
        array $idiomas,
        array $grupos,
        ?FakeOpcionalImageService $image = null
    ): OpcionalExcelImportWizardService {
        $wizard = new OpcionalExcelImportWizardService();
        $wizard->setSeams(
            $opcional,
            $image ?? new FakeOpcionalImageService('unused.png'),
            static fn (string $codigo): bool => $idiomas[$codigo] ?? false,
            static fn (string $codigo): bool => $grupos[$codigo] ?? false,
            static fn (string $codfamilia): bool => true,
            fn (): FakeOpcionalRecord => $opcional->new_record()
        );

        return $wizard;
    }
}

/**
 * In-memory opcional store: answers `get_by_codigo`, records create/update and
 * the last persisted payload, so idempotency and orphan handling are provable
 * without a database.
 */
final class FakeOpcionalModel
{
    /** @var list<string> */
    public array $created = [];

    public int $updateCalls = 0;

    /** @var array<string, mixed> */
    public array $lastUpdated = [];

    /** @var array<string, mixed> */
    public array $lastSaved = [];

    /** @var array<string, array<string, mixed>> */
    private array $existing;

    /** @param list<string> $existingCodigos */
    public function __construct(array $existingCodigos)
    {
        $this->existing = [];
        foreach ($existingCodigos as $codigo) {
            $this->existing[$codigo] = [
                'id' => count($this->existing) + 1,
                'codigo' => $codigo,
            ];
        }
    }

    public function get_by_codigo($codigo)
    {
        if (!isset($this->existing[(string) $codigo])) {
            return false;
        }

        return FakeOpcionalRecord::fromRow($this->existing[(string) $codigo])->withStore($this);
    }

    public function new_record(): FakeOpcionalRecord
    {
        return (new FakeOpcionalRecord())->withStore($this);
    }

    /** @param array<string, mixed> $data */
    public function recordCreated(array $data): void
    {
        $this->created[] = (string) $data['codigo'];
        $this->lastSaved = $data;
    }

    /** @param array<string, mixed> $data */
    public function recordUpdated(array $data): void
    {
        $this->updateCalls++;
        $this->lastUpdated = $data;
        $this->lastSaved = $data;
    }
}

/**
 * Mutable opcional record with the real model's public property surface.
 */
final class FakeOpcionalRecord
{
    public ?int $id = null;
    public string $codigo = '';
    public string $nombre = '';
    public string $descripcion = '';
    public float $precio = 0.0;
    public string $tipo_precio = 'fijo';
    public ?float $porcentaje = null;
    public bool $activo = true;
    public int $cantidad_min = 1;
    public int $cantidad_max = 1;
    public ?string $imagen = null;

    /** @var array<string, array{nombre: string, descripcion: string}> */
    public array $idiomas = [];

    /** @var list<int> */
    public array $grupos = [];

    /** @var list<string> */
    public array $familias = [];

    /** @var FakeOpcionalModel|null */
    private $store = null;

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $record = new self();
        $record->id = (int) $row['id'];

        return $record;
    }

    public function withStore(FakeOpcionalModel $store): self
    {
        $this->store = $store;

        return $this;
    }

    public function save()
    {
        $data = [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'precio' => $this->precio,
            'tipo_precio' => $this->tipo_precio,
            'porcentaje' => $this->porcentaje,
            'activo' => $this->activo,
            'cantidad_min' => $this->cantidad_min,
            'cantidad_max' => $this->cantidad_max,
            'imagen' => $this->imagen,
        ];

        if ($this->store === null) {
            return true;
        }

        if ($this->id === null) {
            $this->store->recordCreated($data);
        } else {
            $this->store->recordUpdated($data);
        }

        return true;
    }

    public function set_idioma($codidioma, $nombre, $descripcion): bool
    {
        $this->idiomas[(string) $codidioma] = [
            'nombre' => (string) $nombre,
            'descripcion' => (string) $descripcion,
        ];

        return true;
    }

    public function add_to_grupo(int $idGrupo): bool
    {
        $this->grupos[] = $idGrupo;

        return true;
    }

    public function add_familia_only($codfamilia): bool
    {
        $this->familias[] = (string) $codfamilia;

        return true;
    }
}

/**
 * Image-service seam double: records every base64 upload.
 */
final class FakeOpcionalImageService
{
    /** @var list<array{codigo: string, base64: string}> */
    public array $uploads = [];

    public function __construct(private ?string $filename = 'stored.png')
    {
    }

    public function upload_from_base64(string $codigo, string $base64, ?string &$error = null): ?string
    {
        $error = null;
        $this->uploads[] = ['codigo' => $codigo, 'base64' => $base64];

        return $this->filename;
    }
}
