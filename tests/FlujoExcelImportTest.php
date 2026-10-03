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
 * FLC-08 — flow export/import by código.
 *
 * DB-free: the flow store fake answers `get_by_codigo` and records creates and
 * updates; the subject-existence seam decides whether a referenced opcional,
 * grupo, artículo or familia exists. Orphan references must surface in the
 * rejected-rows output, idempotency keyed by the flow `codigo`.
 */
final class FlujoExcelImportTest extends TestCase
{
    public function test_flow_import_is_idempotent_by_codigo(): void
    {
        $flujo = new FakeFlujoModel(['F1']);
        $wizard = $this->wizard($flujo);

        $result = $wizard->apply_flujo_row(['codigo' => 'F1', 'nombre' => 'Actualizado']);

        $this->assertSame('updated', $result['status']);
        $this->assertSame([], $flujo->created, 'an existing flow código must never be created again');
        $this->assertSame(1, $flujo->updateCalls);
        $this->assertSame('Actualizado', $flujo->lastUpdated['nombre'] ?? null);
    }

    public function test_unknown_flow_is_created(): void
    {
        $flujo = new FakeFlujoModel([]);
        $wizard = $this->wizard($flujo);

        $result = $wizard->apply_flujo_row(['codigo' => 'F9', 'nombre' => 'Nuevo']);

        $this->assertSame('created', $result['status']);
        $this->assertSame(['F9'], $flujo->created);
        $this->assertSame(0, $flujo->updateCalls);
    }

    public function test_orphan_subject_is_reported(): void
    {
        $flujo = new FakeFlujoModel([]);
        $wizard = $this->wizard($flujo, ['opcional' => ['OPC0001' => false]]);

        $result = $wizard->apply_flujo_row([
            'codigo' => 'F1',
            'nombre' => 'Con condición',
            'condiciones' => [
                ['grupo_and_or' => 'AND', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'OPC0001', 'operador' => 'is', 'valor' => '1'],
            ],
        ]);

        $this->assertSame('rejected', $result['status']);
        $this->assertCount(1, $wizard->rejectedRows());
        $this->assertSame('sujeto_inexistente', $wizard->rejectedRows()[0]['motivo']);
        $this->assertSame([], $flujo->created, 'a rejected flow row must not be created');
    }

    public function test_valid_flow_with_conditions_actions_and_assignments_is_imported(): void
    {
        $flujo = new FakeFlujoModel([]);
        $wizard = $this->wizard($flujo, [
            'opcional' => ['OPC0001' => true, 'OPC0002' => true],
            'articulo' => ['ART1' => true],
            'familia' => ['ROPA' => true],
        ]);

        $result = $wizard->apply_flujo_row([
            'codigo' => 'F1',
            'nombre' => 'Flujo válido',
            'activo' => '1',
            'prioridad' => '5',
            'condiciones' => [
                ['grupo_and_or' => 'AND', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'OPC0001', 'operador' => 'is', 'valor' => '1'],
            ],
            'acciones' => [
                ['accion' => 'mostrar', 'sujeto_tipo' => 'opcional', 'sujeto_codigo' => 'OPC0002'],
            ],
            'articulos' => ['ART1'],
            'familias' => ['ROPA'],
        ]);

        $this->assertSame('created', $result['status']);
        $this->assertCount(1, $wizard->flujo_conditions);
        $this->assertCount(1, $wizard->flujo_actions);
        $this->assertSame(['ART1'], $wizard->flujo_articulos);
        $this->assertSame(['ROPA'], $wizard->flujo_familias);
        $this->assertCount(1, $flujo->persistedConditions);
        $this->assertCount(1, $flujo->persistedActions);
        $this->assertSame(['ART1'], $flujo->persistedArticulos);
        $this->assertSame(['ROPA'], $flujo->persistedFamilias);
    }

    /**
     * @param array<string, array<string, bool>> $subjects tipo => código => exists
     */
    private function wizard(FakeFlujoModel $flujo, array $subjects = []): OpcionalExcelImportWizardService
    {
        $wizard = new OpcionalExcelImportWizardService();
        $wizard->setFlowSeams(
            $flujo,
            static fn (string $tipo, string $codigo): bool => $subjects[$tipo][$codigo] ?? false,
            fn (): FakeFlujoRecord => $flujo->new_record(),
            static function ($flow, array $conditions, array $actions, array $articulos, array $familias) use ($flujo): void {
                $flujo->persistedConditions = $conditions;
                $flujo->persistedActions = $actions;
                $flujo->persistedArticulos = $articulos;
                $flujo->persistedFamilias = $familias;
            }
        );

        return $wizard;
    }
}

/**
 * In-memory flow store: answers `get_by_codigo`, records create/update, and
 * captures the child rows the wizard persists.
 */
final class FakeFlujoModel
{
    /** @var list<string> */
    public array $created = [];

    public int $updateCalls = 0;

    /** @var array<string, mixed> */
    public array $lastUpdated = [];

    /** @var list<array<string, mixed>> */
    public array $conditions = [];

    /** @var list<array<string, mixed>> */
    public array $actions = [];

    /** @var list<string> */
    public array $articulos = [];

    /** @var list<string> */
    public array $familias = [];

    /** @var list<array<string, mixed>> */
    public array $persistedConditions = [];

    /** @var list<array<string, mixed>> */
    public array $persistedActions = [];

    /** @var list<string> */
    public array $persistedArticulos = [];

    /** @var list<string> */
    public array $persistedFamilias = [];

    /** @var array<string, bool> */
    private array $existing = [];

    /** @param list<string> $existingCodigos */
    public function __construct(array $existingCodigos)
    {
        foreach ($existingCodigos as $codigo) {
            $this->existing[$codigo] = true;
        }
    }

    public function get_by_codigo($codigo)
    {
        if (!isset($this->existing[(string) $codigo])) {
            return false;
        }

        return FakeFlujoRecord::fromRow([
            'id' => 1,
            'codigo' => (string) $codigo,
            'nombre' => 'Existente',
            'descripcion' => '',
            'activo' => true,
            'prioridad' => 0,
        ])->withStore($this);
    }

    public function new_record(): FakeFlujoRecord
    {
        return (new FakeFlujoRecord())->withStore($this);
    }

    /** @param array<string, mixed> $data */
    public function recordCreated(array $data): void
    {
        $this->created[] = (string) $data['codigo'];
    }

    /** @param array<string, mixed> $data */
    public function recordUpdated(array $data): void
    {
        $this->updateCalls++;
        $this->lastUpdated = $data;
    }
}

/**
 * Mutable flow record with the real model's public property surface.
 */
final class FakeFlujoRecord
{
    public ?int $id = null;
    public string $codigo = '';
    public string $nombre = '';
    public string $descripcion = '';
    public bool $activo = true;
    public int $prioridad = 0;

    /** @var FakeFlujoModel|null */
    private $store = null;

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $record = new self();
        $record->id = (int) $row['id'];
        $record->codigo = (string) $row['codigo'];
        $record->nombre = (string) $row['nombre'];
        $record->descripcion = (string) $row['descripcion'];
        $record->activo = (bool) $row['activo'];
        $record->prioridad = (int) $row['prioridad'];

        return $record;
    }

    public function withStore(FakeFlujoModel $store): self
    {
        $this->store = $store;

        return $this;
    }

    public function save()
    {
        if ($this->store === null) {
            return true;
        }

        $data = [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'activo' => $this->activo,
            'prioridad' => $this->prioridad,
        ];

        if ($this->id === null) {
            $this->store->recordCreated($data);
        } else {
            $this->store->recordUpdated($data);
        }

        return true;
    }
}
