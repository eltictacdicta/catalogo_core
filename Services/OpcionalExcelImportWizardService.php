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
 * Transport-and-persistence Excel import for opcionales and flujos
 * (OVE-06 / FLC-08 / AD-10).
 *
 * It follows the article wizard pattern: a base field catalog, header
 * suggestion, and row application. Persistence is idempotent by `codigo`
 * (existing → update, new → create) and routes through the domain models so
 * closed vocabularies and quantity rules are enforced at the model boundary.
 *
 * Every referenced opcional/grupo/idioma/artículo/familia/subject that does not
 * exist is appended to the rejected-rows buffer — never silently dropped. The
 * buffer is the in-memory twin of the `catalogo_import_*_descartadas.csv`
 * convention and can be flushed through `write_rejected_csv()`.
 *
 * The model and existence factories are protected seams so unit tests can run
 * DB-free while production resolves the real `FSFramework\model\*` classes.
 */
class OpcionalExcelImportWizardService
{
    public const IGNORE_SENTINEL = '__ignorar__';

    public const DEFAULT_IMAGE_FIELD = 'imagen_base64';

    /**
     * Base opcional field catalog. Locale pairs are appended dynamically by
     * `languageFieldCatalog()`.
     *
     * @var array<string, array<string, mixed>>
     */
    public const FIELD_CATALOG = [
        'codigo' => [
            'label' => 'Código',
            'column' => 'codigo',
            'type' => 'string(20)',
            'aliases' => ['código', 'codigo', 'código (no editar)', 'codigo (no editar)', 'cod'],
        ],
        'nombre' => [
            'label' => 'Nombre',
            'column' => 'nombre',
            'type' => 'string(100)',
            'aliases' => ['nombre', 'name'],
        ],
        'descripcion' => [
            'label' => 'Descripción',
            'column' => 'descripcion',
            'type' => 'text',
            'aliases' => ['descripción', 'descripcion', 'desc', 'description'],
        ],
        'precio' => [
            'label' => 'Precio',
            'column' => 'precio',
            'type' => 'double',
            'aliases' => ['precio', 'price'],
        ],
        'tipo_precio' => [
            'label' => 'Tipo Precio',
            'column' => 'tipo_precio',
            'type' => 'string(20)',
            'aliases' => ['tipo precio', 'tipo_precio'],
        ],
        'porcentaje' => [
            'label' => 'Porcentaje',
            'column' => 'porcentaje',
            'type' => 'double',
            'aliases' => ['porcentaje', 'percent', '%'],
        ],
        'cantidad_min' => [
            'label' => 'Cantidad Mín',
            'column' => 'cantidad_min',
            'type' => 'int',
            'aliases' => ['cantidad mín', 'cantidad min', 'cantidad_min', 'cantidad minima', 'mínimo'],
        ],
        'cantidad_max' => [
            'label' => 'Cantidad Máx',
            'column' => 'cantidad_max',
            'type' => 'int',
            'aliases' => ['cantidad máx', 'cantidad max', 'cantidad_max', 'cantidad maxima', 'máximo'],
        ],
        'imagen' => [
            'label' => 'Imagen',
            'column' => 'imagen',
            'type' => 'string(255)',
            'aliases' => ['imagen', 'image'],
        ],
        'grupos' => [
            'label' => 'Grupos',
            'column' => 'grupos',
            'type' => 'csv-codes',
            'aliases' => ['grupos', 'grupo', 'groups'],
        ],
        'familias' => [
            'label' => 'Familias',
            'column' => 'familias',
            'type' => 'csv-codes',
            'aliases' => ['familias', 'familia'],
        ],
    ];

    /** @var list<array<string, string>> rejected-rows buffer. */
    private array $rejected = [];

    /** @var object|null opcional model seam. */
    private $opcionalModel = null;

    /** @var object|null flow model seam. */
    private $flujoModel = null;

    /** @var object|null image-service seam. */
    private $imageService = null;

    /** @var callable|null new-opcional factory seam. */
    private $newOpcional = null;

    /** @var callable|null new-flujo factory seam. */
    private $newFlujo = null;

    /** @var callable|null flow-child persistence seam (conditions/actions/assignments). */
    private $flowChildrenWriter = null;

    /** @var callable|null language-existence seam. */
    private $idiomaExists = null;

    /** @var callable|null group-existence seam. */
    private $grupoExists = null;

    /** @var callable|null family-existence seam. */
    private $familiaExists = null;

    /** @var callable|null flow-subject-existence seam. */
    private $flowSubjectExists = null;

    /**
     * Injects the persistence and existence seams (unit tests use DB-free
     * doubles; production leaves them unset for lazy model construction).
     *
     * @param callable(string):bool $idiomaExists
     * @param callable(string):bool $grupoExists
     * @param callable(string):bool $familiaExists
     */
    public function setSeams(
        $opcionalModel,
        $imageService,
        callable $idiomaExists,
        callable $grupoExists,
        callable $familiaExists,
        ?callable $newOpcional = null
    ): void {
        $this->opcionalModel = $opcionalModel;
        $this->imageService = $imageService;
        $this->idiomaExists = $idiomaExists;
        $this->grupoExists = $grupoExists;
        $this->familiaExists = $familiaExists;
        $this->newOpcional = $newOpcional;
    }

    /**
     * Injects the flow seams (unit tests; production constructs the real model).
     *
     * @param callable(string,string):bool $subjectExists tipo => código
     */
    public function setFlowSeams($flujoModel, callable $subjectExists, ?callable $newFlujo = null, ?callable $childrenWriter = null): void
    {
        $this->flujoModel = $flujoModel;
        $this->flowSubjectExists = $subjectExists;
        $this->newFlujo = $newFlujo;
        $this->flowChildrenWriter = $childrenWriter;
    }

    /**
     * Rejected rows collected so far (mirror of `*_descartadas.csv`).
     *
     * @return list<array<string, string>>
     */
    public function rejectedRows(): array
    {
        return $this->rejected;
    }

    /**
     * The base field catalog plus one entry per active language pair, so the
     * wizard maps `nombre_<codidioma>` / `descripcion_<codidioma>` automatically.
     *
     * @param array<int, object|array<string, mixed>> $idiomas active languages
     * @return array<string, array<string, mixed>>
     */
    public static function languageFieldCatalog(array $idiomas): array
    {
        $catalog = [];
        foreach (self::orderedLanguages($idiomas, '') as $language) {
            $codigo = $language['codidioma'];
            $nombre = $language['nombre'];

            $catalog['nombre_' . $codigo] = [
                'label' => 'Nombre (' . $nombre . ')',
                'column' => 'nombre_' . $codigo,
                'type' => 'text',
                'aliases' => ['nombre_' . $codigo],
            ];
            $catalog['descripcion_' . $codigo] = [
                'label' => 'Descripción (' . $nombre . ')',
                'column' => 'descripcion_' . $codigo,
                'type' => 'text',
                'aliases' => ['descripcion_' . $codigo],
            ];
        }

        return $catalog;
    }

    /**
     * Base field catalog plus the additive locale fields.
     *
     * @param array<int, object|array<string, mixed>> $idiomas
     * @return array<string, array<string, mixed>>
     */
    public function fieldCatalog(array $idiomas = []): array
    {
        return array_merge(self::FIELD_CATALOG, self::languageFieldCatalog($idiomas));
    }

    /**
     * @param string[] $headers
     * @param array<int, object|array<string, mixed>> $idiomas
     * @return array<int, string>
     */
    public static function suggestMapping(array $headers, array $idiomas = []): array
    {
        $catalog = array_merge(self::FIELD_CATALOG, self::languageFieldCatalog($idiomas));
        $result = [];
        foreach ($headers as $colIdx => $header) {
            $normalized = mb_strtolower(trim((string) $header));
            $matched = self::IGNORE_SENTINEL;
            foreach ($catalog as $fieldName => $info) {
                $aliases = array_map(
                    static fn ($alias): string => mb_strtolower(trim((string) $alias)),
                    (array) ($info['aliases'] ?? [])
                );
                if (in_array($normalized, $aliases, true)) {
                    $matched = (string) $fieldName;
                    break;
                }
            }
            $result[$colIdx] = $matched;
        }

        return $result;
    }

    /**
     * Persists one mapped opcional row. Idempotent by `codigo`.
     *
     * @param array<string, string> $mappedRow
     * @return array{status: string, codigo: string, motivo: string}
     */
    public function apply_row(array $mappedRow): array
    {
        $codigo = trim((string) ($mappedRow['codigo'] ?? ''));
        if ($codigo === '') {
            return $this->reject('codigo_ausente', '', 'El opcional no tiene código.');
        }

        $gruposError = $this->validateCsvCodes(
            $this->split_csv((string) ($mappedRow['grupos'] ?? '')),
            fn (string $code): bool => $this->grupo_exists($code),
            'grupo_inexistente',
            $codigo
        );
        if ($gruposError !== null) {
            return $gruposError;
        }

        $idiomas = $this->mapped_language_fields($mappedRow);
        foreach (array_keys($idiomas) as $codidioma) {
            if (!$this->idioma_exists($codidioma)) {
                return $this->reject('idioma_inexistente', $codigo, 'Idioma inexistente: ' . $codidioma);
            }
        }

        $base64 = '';
        foreach ([self::DEFAULT_IMAGE_FIELD, 'imagen'] as $imageField) {
            $candidate = trim((string) ($mappedRow[$imageField] ?? ''));
            if (str_contains($candidate, 'base64')) {
                $base64 = $candidate;
                break;
            }
        }

        $existing = $this->opcional_model()->get_by_codigo($codigo);
        $opcional = $existing ?: $this->new_opcional();

        $this->hydrate_base_fields($opcional, $mappedRow, $codigo);
        $this->hydrate_imagen($opcional, $mappedRow, $codigo, $base64);

        if (!$opcional->save()) {
            return $this->reject('save_failed', $codigo, 'No se pudo guardar el opcional.');
        }

        $warnings = [];
        foreach ($idiomas as $codidioma => $pair) {
            if (!$opcional->set_idioma($codidioma, $pair['nombre'], $pair['descripcion'])) {
                $warnings[] = $codidioma;
            }
        }

        $this->persist_grupos($opcional, $this->split_csv((string) ($mappedRow['grupos'] ?? '')));
        $this->persist_familias($opcional, $this->split_csv((string) ($mappedRow['familias'] ?? '')));

        $status = $existing ? 'updated' : 'created';
        if ($warnings !== []) {
            $status = 'partial';
        }

        return ['status' => $status, 'codigo' => $codigo, 'motivo' => ''];
    }

    /**
     * Persists one mapped flow row, including conditions, actions and article /
     * family assignments. Idempotent by flow `codigo`; any referenced subject
     * that does not exist rejects the whole row.
     *
     * @param array<string, mixed> $mappedRow
     * @return array{status: string, codigo: string, motivo: string}
     */
    public function apply_flujo_row(array $mappedRow): array
    {
        $codigo = trim((string) ($mappedRow['codigo'] ?? ''));
        if ($codigo === '') {
            return $this->reject('codigo_ausente', '', 'El flujo no tiene código.');
        }

        $condiciones = (array) ($mappedRow['condiciones'] ?? []);
        $acciones = (array) ($mappedRow['acciones'] ?? []);
        $articulos = $this->to_code_list($mappedRow['articulos'] ?? []);
        $familias = $this->to_code_list($mappedRow['familias'] ?? []);

        foreach ($condiciones as $condicion) {
            $error = $this->validate_subject($codigo, $condicion);
            if ($error !== null) {
                return $error;
            }
        }
        foreach ($acciones as $accion) {
            $error = $this->validate_subject($codigo, $accion);
            if ($error !== null) {
                return $error;
            }
        }
        foreach ($articulos as $referencia) {
            if (!$this->flow_subject_exists('articulo', $referencia)) {
                return $this->reject('sujeto_inexistente', $codigo, 'Artículo inexistente: ' . $referencia);
            }
        }
        foreach ($familias as $codfamilia) {
            if (!$this->flow_subject_exists('familia', $codfamilia)) {
                return $this->reject('sujeto_inexistente', $codigo, 'Familia inexistente: ' . $codfamilia);
            }
        }

        $existing = $this->flujo_model()->get_by_codigo($codigo);
        $flujo = $existing ?: $this->new_flujo();

        $flujo->codigo = $codigo;
        $flujo->nombre = trim((string) ($mappedRow['nombre'] ?? $flujo->nombre ?? ''));
        $flujo->descripcion = trim((string) ($mappedRow['descripcion'] ?? ($flujo->descripcion ?? '')));
        if (($mappedRow['activo'] ?? '') !== '') {
            $flujo->activo = in_array(mb_strtolower(trim((string) $mappedRow['activo'])), ['1', 'sí', 'si', 'true', 'yes'], true);
        }
        if (($mappedRow['prioridad'] ?? '') !== '') {
            $flujo->prioridad = (int) $mappedRow['prioridad'];
        }

        if (!$flujo->save()) {
            return $this->reject('save_failed', $codigo, 'No se pudo guardar el flujo.');
        }

        $this->flujo_conditions = [];
        foreach ($condiciones as $condicion) {
            $this->flujo_conditions[] = $this->normalize_condition_row($condicion);
        }
        $this->flujo_actions = [];
        foreach ($acciones as $accion) {
            $this->flujo_actions[] = $this->normalize_action_row($accion);
        }
        $this->flujo_articulos = $articulos;
        $this->flujo_familias = $familias;

        $this->persist_flow_children($flujo, $this->flujo_conditions, $this->flujo_actions, $articulos, $familias);

        return [
            'status' => $existing ? 'updated' : 'created',
            'codigo' => $codigo,
            'motivo' => '',
        ];
    }

    /** @var list<array<string, mixed>> */
    public array $flujo_conditions = [];

    /** @var list<array<string, mixed>> */
    public array $flujo_actions = [];

    /** @var list<string> */
    public array $flujo_articulos = [];

    /** @var list<string> */
    public array $flujo_familias = [];

    /**
     * Flushes the rejected rows to a CSV, mirroring
     * `catalogo_import_*_descartadas.csv` (UTF-8 BOM, `;` delimiter, `motivo`).
     *
     * @return string the written path
     */
    public function write_rejected_csv(string $path): string
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el CSV de descartes: ' . $path);
        }

        @chmod($path, 0600);
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['motivo_descarte', 'codigo', 'detalle'], ';');
        foreach ($this->rejected as $row) {
            fputcsv($handle, [$row['motivo'], $row['codigo'], $row['detalle']], ';');
        }
        fclose($handle);

        return $path;
    }

    /**
     * Persists a flow's conditions, actions and article/family assignments
     * through the domain models (FLC-02/03/04). Existing children of the flow
     * are replaced so a re-import stays idempotent. The injected seam keeps
     * unit tests DB-free.
     *
     * @param object $flujo
     * @param list<array<string, mixed>> $conditions
     * @param list<array<string, mixed>> $actions
     * @param list<string> $articulos
     * @param list<string> $familias
     */
    private function persist_flow_children($flujo, array $conditions, array $actions, array $articulos, array $familias): void
    {
        if ($this->flowChildrenWriter !== null) {
            ($this->flowChildrenWriter)($flujo, $conditions, $actions, $articulos, $familias);

            return;
        }

        $idFlujo = (int) ($flujo->id ?? 0);
        if ($idFlujo <= 0) {
            return;
        }

        $condicionModel = new \FSFramework\model\catalogo_flujo_condicion();
        foreach ($condicionModel->all_from_flujo($idFlujo) as $row) {
            $row->delete();
        }
        foreach ($conditions as $condition) {
            $model = new \FSFramework\model\catalogo_flujo_condicion();
            $model->id_flujo = $idFlujo;
            $model->grupo_and_or = (string) $condition['grupo_and_or'];
            $model->sujeto_tipo = (string) $condition['sujeto_tipo'];
            $model->sujeto_codigo = (string) $condition['sujeto_codigo'];
            $model->operador = (string) $condition['operador'];
            $model->valor = $condition['valor'] === null ? null : (string) $condition['valor'];
            $model->save();
        }

        $accionModel = new \FSFramework\model\catalogo_flujo_accion();
        foreach ($accionModel->all_from_flujo($idFlujo) as $row) {
            $row->delete();
        }
        foreach ($actions as $action) {
            $model = new \FSFramework\model\catalogo_flujo_accion();
            $model->id_flujo = $idFlujo;
            $model->accion = (string) $action['accion'];
            $model->sujeto_tipo = (string) $action['sujeto_tipo'];
            $model->sujeto_codigo = (string) $action['sujeto_codigo'];
            $model->save();
        }

        $articuloModel = new \FSFramework\model\catalogo_flujo_articulo();
        $articuloModel->delete_all_from_flujo($idFlujo);
        foreach ($articulos as $referencia) {
            $articuloModel->add($idFlujo, $referencia);
        }

        $familiaModel = new \FSFramework\model\catalogo_flujo_familia();
        $familiaModel->delete_all_from_flujo($idFlujo);
        foreach ($familias as $codfamilia) {
            $familiaModel->add($idFlujo, $codfamilia);
        }
    }

    // =====================================================================
    // Persistence helpers
    // =====================================================================

    /**
     * @param array<string, string> $mappedRow
     */
    private function hydrate_base_fields($opcional, array $mappedRow, string $codigo): void
    {
        $opcional->codigo = $codigo;
        if (($mappedRow['nombre'] ?? '') !== '') {
            $opcional->nombre = trim((string) $mappedRow['nombre']);
        } elseif (($opcional->nombre ?? '') === '') {
            $opcional->nombre = $codigo;
        }
        if (array_key_exists('descripcion', $mappedRow)) {
            $opcional->descripcion = trim((string) $mappedRow['descripcion']);
        }
        if (($mappedRow['precio'] ?? '') !== '') {
            $opcional->precio = (float) str_replace(',', '.', trim((string) $mappedRow['precio']));
        }
        if (($mappedRow['tipo_precio'] ?? '') !== '') {
            $opcional->tipo_precio = mb_strtolower(trim((string) $mappedRow['tipo_precio']));
        }
        if (($mappedRow['porcentaje'] ?? '') !== '') {
            $opcional->porcentaje = (float) str_replace(',', '.', trim((string) $mappedRow['porcentaje']));
        }
        if (($mappedRow['cantidad_min'] ?? '') !== '') {
            $opcional->cantidad_min = (int) $mappedRow['cantidad_min'];
        }
        if (($mappedRow['cantidad_max'] ?? '') !== '') {
            $opcional->cantidad_max = (int) $mappedRow['cantidad_max'];
        }
    }

    /**
     * @param array<string, string> $mappedRow
     */
    private function hydrate_imagen($opcional, array $mappedRow, string $codigo, string $base64): void
    {
        if ($base64 !== '') {
            $filename = $this->image_service()->upload_from_base64($codigo, $base64);
            if ($filename !== null && $filename !== '') {
                $opcional->imagen = $filename;
            }

            return;
        }

        if (($mappedRow['imagen'] ?? '') !== '') {
            // A bare filename cell is honoured as-is; the model sanitizes it.
            $opcional->imagen = trim((string) $mappedRow['imagen']);
        }
    }

    /**
     * @param list<string> $codigos
     */
    private function persist_grupos($opcional, array $codigos): void
    {
        foreach ($codigos as $codigo) {
            $grupo = $this->grupo_model()->get_by_codigo($codigo);
            if ($grupo) {
                $opcional->add_to_grupo((int) $grupo->id);
            }
        }
    }

    /**
     * @param list<string> $codigos
     */
    private function persist_familias($opcional, array $codigos): void
    {
        foreach ($codigos as $codfamilia) {
            $opcional->add_familia_only($codfamilia);
        }
    }

    /**
     * @param array<string, string> $mappedRow
     * @return array<string, array{nombre: string, descripcion: string}>
     */
    private function mapped_language_fields(array $mappedRow): array
    {
        $pairs = [];
        foreach ($mappedRow as $field => $value) {
            if (preg_match('/^nombre_(.+)$/', (string) $field, $m)) {
                $codidioma = $m[1];
                $pairs[$codidioma]['nombre'] = (string) $value;
                $pairs[$codidioma]['descripcion'] ??= '';
            } elseif (preg_match('/^descripcion_(.+)$/', (string) $field, $m)) {
                $codidioma = $m[1];
                $pairs[$codidioma]['nombre'] ??= '';
                $pairs[$codidioma]['descripcion'] = (string) $value;
            }
        }

        return $pairs;
    }

    // =====================================================================
    // Validation helpers
    // =====================================================================

    /**
     * @param list<string> $codes
     * @param callable(string):bool $exists
     * @return array{status: string, codigo: string, motivo: string}|null
     */
    private function validateCsvCodes(array $codes, callable $exists, string $motivo, string $codigo): ?array
    {
        foreach ($codes as $code) {
            if (!$exists($code)) {
                return $this->reject($motivo, $codigo, 'Referencia inexistente: ' . $code);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{status: string, codigo: string, motivo: string}|null
     */
    private function validate_subject(string $codigo, array $row): ?array
    {
        $tipo = mb_strtolower(trim((string) ($row['sujeto_tipo'] ?? '')));
        $sujeto = trim((string) ($row['sujeto_codigo'] ?? ''));
        if ($tipo === '' || $sujeto === '') {
            return $this->reject('sujeto_inexistente', $codigo, 'Sujeto incompleto.');
        }
        if (!$this->flow_subject_exists($tipo, $sujeto)) {
            return $this->reject('sujeto_inexistente', $codigo, $tipo . ' inexistente: ' . $sujeto);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize_condition_row(array $row): array
    {
        return [
            'grupo_and_or' => strtoupper(trim((string) ($row['grupo_and_or'] ?? 'AND'))),
            'sujeto_tipo' => mb_strtolower(trim((string) ($row['sujeto_tipo'] ?? ''))),
            'sujeto_codigo' => trim((string) ($row['sujeto_codigo'] ?? '')),
            'operador' => mb_strtolower(trim((string) ($row['operador'] ?? ''))),
            'valor' => isset($row['valor']) && $row['valor'] !== '' ? (string) $row['valor'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize_action_row(array $row): array
    {
        return [
            'accion' => mb_strtolower(trim((string) ($row['accion'] ?? ''))),
            'sujeto_tipo' => mb_strtolower(trim((string) ($row['sujeto_tipo'] ?? ''))),
            'sujeto_codigo' => trim((string) ($row['sujeto_codigo'] ?? '')),
        ];
    }

    /**
     * @return array{status: string, codigo: string, motivo: string}
     */
    private function reject(string $motivo, string $codigo, string $detalle): array
    {
        $this->rejected[] = [
            'motivo' => $motivo,
            'codigo' => $codigo,
            'detalle' => $detalle,
        ];

        return ['status' => 'rejected', 'codigo' => $codigo, 'motivo' => $motivo];
    }

    // =====================================================================
    // Small utilities
    // =====================================================================

    /**
     * @return list<string>
     */
    private function split_csv(string $value): array
    {
        $list = [];
        foreach (preg_split('/[,;]/', $value) ?: [] as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $list[] = $item;
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function to_code_list($value): array
    {
        if (is_string($value)) {
            return $this->split_csv($value);
        }

        $list = [];
        foreach ((array) $value as $item) {
            if (is_array($item)) {
                $item = $item['codigo'] ?? $item['referencia'] ?? $item['codfamilia'] ?? '';
            } elseif (is_object($item)) {
                $item = $item->codigo ?? $item->referencia ?? $item->codfamilia ?? '';
            }
            $item = trim((string) $item);
            if ($item !== '') {
                $list[] = $item;
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * @param array<int, object|array<string, mixed>> $idiomas
     * @return list<array{codidioma: string, nombre: string}>
     */
    private static function orderedLanguages(array $idiomas, string $firstCodidioma): array
    {
        $byCode = [];
        foreach ($idiomas as $idioma) {
            $codigo = is_array($idioma)
                ? (string) ($idioma['codidioma'] ?? '')
                : (string) ($idioma->codidioma ?? '');
            if ($codigo === '') {
                continue;
            }
            $nombre = is_array($idioma)
                ? (string) ($idioma['nombre'] ?? $codigo)
                : (string) ($idioma->nombre ?? $codigo);
            $byCode[$codigo] = ['codidioma' => $codigo, 'nombre' => $nombre];
        }

        ksort($byCode, SORT_STRING);
        $ordered = array_values($byCode);

        if ($firstCodidioma !== '' && isset($byCode[$firstCodidioma])) {
            $ordered = array_values(array_filter(
                $ordered,
                static fn (array $language): bool => $language['codidioma'] !== $firstCodidioma
            ));
            array_unshift($ordered, $byCode[$firstCodidioma]);
        }

        return $ordered;
    }

    // =====================================================================
    // Model seams (production resolves the real FSFramework\model\* classes)
    // =====================================================================

    /**
     * @return object
     */
    protected function opcional_model()
    {
        if ($this->opcionalModel === null) {
            $this->opcionalModel = $this->create_opcional_model();
        }

        return $this->opcionalModel;
    }

    /**
     * @return object
     */
    protected function create_opcional_model()
    {
        return new \FSFramework\model\catalogo_opcional();
    }

    /**
     * Fresh opcional for a create (the injected test record or the real model).
     *
     * @return object
     */
    protected function new_opcional()
    {
        if ($this->newOpcional !== null) {
            return ($this->newOpcional)();
        }

        return $this->create_opcional_model();
    }

    /**
     * Fresh flow for a create.
     *
     * @return object
     */
    protected function new_flujo()
    {
        if ($this->newFlujo !== null) {
            return ($this->newFlujo)();
        }

        return new \FSFramework\model\catalogo_flujo();
    }

    /**
     * @return object
     */
    protected function grupo_model()
    {
        return new \FSFramework\model\catalogo_opcional_grupo();
    }

    /**
     * @return object
     */
    protected function flujo_model()
    {
        if ($this->flujoModel === null) {
            $this->flujoModel = new \FSFramework\model\catalogo_flujo();
        }

        return $this->flujoModel;
    }

    /**
     * @return object
     */
    protected function image_service()
    {
        if ($this->imageService === null) {
            $this->imageService = new OpcionalImagenService();
        }

        return $this->imageService;
    }

    private function idioma_exists(string $codidioma): bool
    {
        if ($this->idiomaExists !== null) {
            return (bool) ($this->idiomaExists)($codidioma);
        }

        return (bool) (new \FSFramework\model\catalogo_idioma())->get($codidioma);
    }

    private function grupo_exists(string $codigo): bool
    {
        if ($this->grupoExists !== null) {
            return (bool) ($this->grupoExists)($codigo);
        }

        return (bool) $this->grupo_model()->get_by_codigo($codigo);
    }

    private function familia_exists(string $codfamilia): bool
    {
        if ($this->familiaExists !== null) {
            return (bool) ($this->familiaExists)($codfamilia);
        }

        return (bool) (new \FSFramework\model\familia())->get($codfamilia);
    }

    private function flow_subject_exists(string $tipo, string $codigo): bool
    {
        if ($this->flowSubjectExists !== null) {
            return (bool) ($this->flowSubjectExists)($tipo, $codigo);
        }

        switch (mb_strtolower($tipo)) {
            case 'opcional':
                return (bool) $this->opcional_model()->get_by_codigo($codigo);
            case 'grupo':
                return $this->grupo_exists($codigo);
            case 'articulo':
                return (bool) (new \FSFramework\model\articulo())->get($codigo);
            case 'familia':
                return $this->familia_exists($codigo);
            default:
                return false;
        }
    }
}
