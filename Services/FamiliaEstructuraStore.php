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

namespace FSFramework\Plugins\catalogo_core\Services;

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent;

/**
 * Per-list family structure store (familias-jerarquia tasks 3.1/3.3, D5/D6).
 *
 * The canonical side of the dual-source family page: every operation is scoped
 * to ONE `codlista` and every write is an upsert of
 * `catalogo_familia_estructura` through its model — never a `tarif_*` table.
 *
 * Contracts pinned here:
 *
 *  - reads are BATCH: `rows()` issues a single query per list and stays cached
 *    for the request, so the tree render is never N+1;
 *  - writes dispatch the existing `ArticlePermissionFilterEvent` BEFORE
 *    persisting (fail-closed: a denied listener or a throwing listener blocks
 *    the write and reports the reason through `lastError()`);
 *  - `assertAcyclicInList()` rejects a re-parent that would close a cycle in
 *    the per-list `madre` chain. It complements `FamiliaTreeGuard`, which walks
 *    the authoritative `familias.madre` chain only.
 */
final class FamiliaEstructuraStore
{
    public const TABLE = 'catalogo_familia_estructura';

    /** Defensive ceiling for a corrupt chain that never reaches a root. */
    private const MAX_DEPTH = 1000;

    /** Columns a caller is allowed to upsert. */
    private const WRITABLE = ['madre', 'capitulo', 'nivel', 'en_catalogo', 'en_tarifa', 'activa', 'orden'];

    private \fs_db2 $db;

    private string $nick;

    /** @var object|null `catalogo_familia_estructura` prototype (or a test double). */
    private ?object $model;

    /** @var array<string, list<object>> codlista => rows */
    private array $cache = [];

    /** @var array<string, bool> */
    private array $loaded = [];

    private string $lastError = '';

    public function __construct(\fs_db2 $db, string $nick = '', ?object $model = null)
    {
        $this->db = $db;
        $this->nick = $nick;
        $this->model = $model;
    }

    /**
     * Structure rows of one list, ordered like the legacy tree.
     *
     * @return list<object>
     */
    public function rows(string $codlista, bool $onlyActive = false): array
    {
        $this->load($codlista);

        $rows = $this->cache[$codlista] ?? [];
        if (!$onlyActive) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (object $row): bool => (bool) $row->activa
        ));
    }

    /**
     * The row of (codlista, codfamilia), or null when the family is not in the
     * list. Served from the cached batch.
     */
    public function find(string $codlista, string $codfamilia): ?object
    {
        foreach ($this->rows($codlista) as $row) {
            if ((string) $row->codfamilia === $codfamilia) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Direct children of a family inside the list.
     *
     * @return list<object>
     */
    public function children(string $codlista, string $codfamilia): array
    {
        $children = [];
        foreach ($this->rows($codlista) as $row) {
            if ($row->madre !== null && (string) $row->madre === $codfamilia) {
                $children[] = $row;
            }
        }

        return $children;
    }

    /**
     * Next chapter for a new node under $madre (mirrors the legacy ordering).
     */
    public function suggestCapitulo(string $codlista, ?string $madre): string
    {
        $madre = $madre === null || trim($madre) === '' ? null : trim($madre);

        $siblings = [];
        foreach ($this->rows($codlista) as $row) {
            $rowMadre = $row->madre === null || (string) $row->madre === '' ? null : (string) $row->madre;
            if ($rowMadre === $madre && (string) $row->capitulo !== '') {
                $siblings[] = (string) $row->capitulo;
            }
        }

        if ($siblings !== []) {
            usort($siblings, 'version_compare');
            $last = (string) end($siblings);
            $parts = explode('.', $last);
            $lastNumber = (int) array_pop($parts);
            $next = (string) ($lastNumber + 1);

            return $parts === [] ? $next : implode('.', $parts) . '.' . $next;
        }

        if ($madre !== null) {
            $mother = $this->find($codlista, $madre);
            if ($mother !== null && (string) $mother->capitulo !== '') {
                return (string) $mother->capitulo . '.1';
            }
        }

        return '1';
    }

    /**
     * Upserts the structure row of (codlista, codfamilia) with the supplied
     * values. Only the keys present in $values are written.
     *
     * @param array<string, mixed> $values
     */
    public function save(string $codlista, string $codfamilia, array $values): bool
    {
        if (!$this->dispatch($codlista, $codfamilia)) {
            return false;
        }

        return $this->write($codlista, $codfamilia, $values);
    }

    /**
     * Applies a full chapter map, writing only the rows that actually change.
     * Every changed row is permission-checked BEFORE the first write, so a
     * denial can never leave a half-applied order.
     *
     * @param array<string, string> $chapters codfamilia => capitulo
     */
    public function saveChapterMap(string $codlista, array $chapters): bool
    {
        $changed = [];
        foreach ($chapters as $codfamilia => $capitulo) {
            $row = $this->find($codlista, (string) $codfamilia);
            if ($row === null || (string) $row->capitulo === (string) $capitulo) {
                continue;
            }
            $changed[(string) $codfamilia] = (string) $capitulo;
        }

        foreach (array_keys($changed) as $codfamilia) {
            if (!$this->dispatch($codlista, (string) $codfamilia)) {
                return false;
            }
        }

        foreach ($changed as $codfamilia => $capitulo) {
            if (!$this->write($codlista, (string) $codfamilia, ['capitulo' => $capitulo])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Adds a family to the list with a suggested chapter.
     *
     * @param array<string, mixed> $flags en_catalogo / en_tarifa / activa / orden
     */
    public function add(string $codlista, string $codfamilia, ?string $madre, array $flags = []): bool
    {
        if ($this->find($codlista, $codfamilia) !== null) {
            $this->lastError = 'La familia ya existe en la lista.';

            return false;
        }

        if (!$this->dispatch($codlista, $codfamilia)) {
            return false;
        }

        $values = array_merge(
            [
                'madre' => $madre === null || trim($madre) === '' ? null : trim($madre),
                'capitulo' => $this->suggestCapitulo($codlista, $madre),
                'nivel' => '',
            ],
            $flags
        );

        return $this->write($codlista, $codfamilia, $values);
    }

    /**
     * Removes the family from the list: its children are re-rooted first, and
     * the base `familias` row is never touched.
     */
    public function delete(string $codlista, string $codfamilia): bool
    {
        if (!$this->dispatch($codlista, $codfamilia)) {
            return false;
        }

        foreach ($this->children($codlista, $codfamilia) as $child) {
            if (!$this->write($codlista, (string) $child->codfamilia, ['madre' => null])) {
                return false;
            }
        }

        $row = $this->find($codlista, $codfamilia);
        if ($row === null) {
            $this->lastError = 'Familia no encontrada en la lista.';

            return false;
        }

        if (!$row->delete()) {
            $this->lastError = 'No se pudo eliminar la familia de la lista.';
            $this->invalidate($codlista);

            return false;
        }

        $this->invalidate($codlista);

        return true;
    }

    /**
     * Rejects a re-parent that would close a cycle inside the per-list `madre`
     * chain.
     *
     * @throws \RuntimeException when the new mother is the family itself or one
     *                           of its descendants, or the chain is corrupt
     */
    public function assertAcyclicInList(string $codlista, string $codfamilia, ?string $madre): void
    {
        if ($madre === null || trim($madre) === '') {
            return;
        }

        $madre = trim($madre);

        if ($madre === $codfamilia) {
            throw new \RuntimeException(
                'Family cycle detected: "' . $codfamilia . '" cannot be its own mother.'
            );
        }

        $visited = [$codfamilia => true];
        $current = $madre;
        $depth = 0;

        while ($current !== null && $current !== '' && $depth < self::MAX_DEPTH) {
            if (isset($visited[$current])) {
                throw new \RuntimeException(
                    'Family cycle detected: "' . $codfamilia . '" cannot be nested under "' . $madre . '".'
                );
            }

            $visited[$current] = true;
            $row = $this->find($codlista, $current);
            $current = $row === null || $row->madre === null || (string) $row->madre === ''
                ? null
                : (string) $row->madre;
            $depth++;
        }

        if ($depth >= self::MAX_DEPTH) {
            throw new \RuntimeException(
                'Family cycle detected: the mother chain of "' . $madre . '" does not terminate.'
            );
        }
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    public function invalidate(string $codlista): void
    {
        unset($this->cache[$codlista], $this->loaded[$codlista]);
    }

    /**
     * Neutral permission dispatch, mirroring the article write path (D6).
     * Public static so the semantics are testable without a controller.
     */
    public static function dispatchStructureFilter(
        string $codfamilia,
        string $nick,
        string $codlista
    ): ArticlePermissionFilterEvent {
        $event = new ArticlePermissionFilterEvent(
            $codfamilia,
            ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            $nick,
            $codlista
        );

        try {
            FSEventDispatcher::getInstance()->dispatch($event, ArticlePermissionFilterEvent::NAME);
        } catch (\Throwable $e) {
            error_log('ArticlePermissionFilterEvent listener error: ' . $e->getMessage());
            $event->deny('permission filter error');
        }

        return $event;
    }

    // ---- internals ----

    private function dispatch(string $codlista, string $codfamilia): bool
    {
        $event = self::dispatchStructureFilter($codfamilia, $this->nick, $codlista);
        if ($event->isAllowed()) {
            return true;
        }

        $reason = $event->getDenialReason();
        $this->lastError = $reason !== '' ? $reason : 'permission filter error';

        return false;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function write(string $codlista, string $codfamilia, array $values): bool
    {
        $row = $this->find($codlista, $codfamilia);
        if ($row === null) {
            $row = clone $this->model();
            $row->codlista = $codlista;
            $row->codfamilia = $codfamilia;
            $row->madre = null;
            $row->capitulo = '';
            $row->nivel = '';
            $row->en_catalogo = true;
            $row->en_tarifa = false;
            $row->activa = true;
            $row->orden = 0;
        }

        foreach (self::WRITABLE as $key) {
            if (array_key_exists($key, $values)) {
                $row->{$key} = $values[$key];
            }
        }

        if (!$row->save()) {
            $this->lastError = 'No se pudo guardar la estructura de la familia.';
            $this->invalidate($codlista);

            return false;
        }

        $this->invalidate($codlista);

        return true;
    }

    private function load(string $codlista): void
    {
        if (isset($this->loaded[$codlista])) {
            return;
        }

        $this->loaded[$codlista] = true;
        $this->cache[$codlista] = [];

        $sql = 'SELECT e.codlista, e.codfamilia, e.madre, e.capitulo, e.nivel, e.en_catalogo,'
            . ' e.en_tarifa, e.activa, e.orden, f.descripcion'
            . ' FROM ' . self::TABLE . ' e'
            . ' LEFT JOIN familias f ON f.codfamilia = e.codfamilia'
            . ' WHERE e.codlista = ?'
            . ' ORDER BY e.capitulo ASC, e.orden ASC;';

        $data = $this->db->select($sql, [$codlista]);
        if (!is_array($data)) {
            return;
        }

        $rows = [];
        foreach ($data as $row) {
            $rows[] = $this->hydrate($row);
        }

        usort($rows, [self::class, 'compareRows']);
        $this->cache[$codlista] = $rows;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrate(array $data): object
    {
        $row = clone $this->model();
        $row->codlista = (string) ($data['codlista'] ?? '');
        $row->codfamilia = (string) ($data['codfamilia'] ?? '');
        $row->madre = isset($data['madre']) && (string) $data['madre'] !== '' ? (string) $data['madre'] : null;
        $row->capitulo = (string) ($data['capitulo'] ?? '');
        $row->nivel = (string) ($data['nivel'] ?? '');
        $row->en_catalogo = self::toBool($data['en_catalogo'] ?? true);
        $row->en_tarifa = self::toBool($data['en_tarifa'] ?? false);
        $row->activa = self::toBool($data['activa'] ?? true);
        $row->orden = (int) ($data['orden'] ?? 0);
        $row->descripcion = (string) ($data['descripcion'] ?? '');

        return $row;
    }

    private function model(): object
    {
        if ($this->model === null) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_familia_estructura.php';
            $this->model = new \FSFramework\model\catalogo_familia_estructura();
        }

        return $this->model;
    }

    private static function compareRows(object $a, object $b): int
    {
        $cmp = version_compare((string) $a->capitulo, (string) $b->capitulo);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) $a->descripcion, (string) $b->descripcion);
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 't', 'true', 'yes', 'on'], true);
    }
}
