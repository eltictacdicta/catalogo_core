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

namespace Tests\CatalogoCore\Support;

require_once FS_FOLDER . '/base/fs_db2.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_familia_estructura.php';

/**
 * In-memory `catalogo_familia_estructura` table shared by the fake db (reads)
 * and the fake model (writes), so both sides always agree without a real DB.
 */
final class FakeFamiliaEstructuraTable
{
    /** @var array<string, array<string, array<string, mixed>>> codlista => codfamilia => row */
    public array $rows = [];

    public int $writes = 0;

    public int $deletes = 0;

    /**
     * @param array<string, mixed> $row
     */
    public function put(array $row): void
    {
        $this->rows[(string) $row['codlista']][(string) $row['codfamilia']] = $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $codlista, string $codfamilia): ?array
    {
        return $this->rows[$codlista][$codfamilia] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function remove(string $codlista, string $codfamilia): ?array
    {
        $row = $this->get($codlista, $codfamilia);
        unset($this->rows[$codlista][$codfamilia]);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(string $codlista): array
    {
        return array_values($this->rows[$codlista] ?? []);
    }

    public function seed(string $codlista, string $codfamilia, ?string $madre = null, string $capitulo = '', bool $activa = true): void
    {
        $this->put([
            'codlista' => $codlista,
            'codfamilia' => $codfamilia,
            'madre' => $madre,
            'capitulo' => $capitulo,
            'nivel' => '',
            'en_catalogo' => true,
            'en_tarifa' => false,
            'activa' => $activa,
            'orden' => 0,
        ]);
    }
}

/**
 * Fake row/model double: skipping the parent constructor avoids the DB.
 */
final class FakeFamiliaEstructuraModel extends \FSFramework\model\catalogo_familia_estructura
{
    public function __construct(public FakeFamiliaEstructuraTable $table)
    {
        // Deliberately skip parent::__construct(): no DB, no table checks.
    }

    public function get($codlista, $codfamilia)
    {
        $row = $this->table->get((string) $codlista, (string) $codfamilia);
        if ($row === null) {
            return false;
        }

        $model = clone $this;
        foreach ($row as $key => $value) {
            $model->{$key} = $value;
        }

        return $model;
    }

    public function save()
    {
        $this->table->put([
            'codlista' => $this->codlista,
            'codfamilia' => $this->codfamilia,
            'madre' => $this->madre,
            'capitulo' => $this->capitulo,
            'nivel' => $this->nivel,
            'en_catalogo' => $this->en_catalogo,
            'en_tarifa' => $this->en_tarifa,
            'activa' => $this->activa,
            'orden' => $this->orden,
        ]);
        $this->table->writes++;

        return true;
    }

    public function delete()
    {
        $this->table->remove((string) $this->codlista, (string) $this->codfamilia);
        $this->table->deletes++;

        return true;
    }
}

/**
 * Fake `fs_db2`: serves the batch structure read plus the `familias` chain
 * used by the base cycle guard, and records every statement.
 */
final class FakeFamiliaEstructuraDb extends \fs_db2
{
    public ?FakeFamiliaEstructuraTable $table = null;

    /** @var array<string, string|null> */
    public array $madres;

    /** @var list<array{sql: string, params: array}> */
    public array $queries = [];

    /** @var list<string> */
    public array $execs = [];

    /**
     * @param array<string, string|null> $madres
     */
    public function __construct(?FakeFamiliaEstructuraTable $table = null, array $madres = [])
    {
        // Deliberately skip parent::__construct(): no engine, no connection.
        $this->table = $table;
        $this->madres = $madres;
    }

    public function var2str($val)
    {
        if ($val === null) {
            return 'NULL';
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function select($sql, $params = [])
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        if (str_contains($sql, 'catalogo_familia_estructura')) {
            $codlista = (string) ($params[0] ?? '');
            $rows = $this->table !== null ? $this->table->all($codlista) : [];
            foreach ($rows as $index => $row) {
                $rows[$index]['descripcion'] = 'Descripcion ' . $row['codfamilia'];
            }

            return $rows;
        }

        if (str_contains($sql, 'familias') && preg_match("/codfamilia\s*=\s*'([^']*)'/", $sql, $matches)) {
            $codfamilia = $matches[1];
            if (!array_key_exists($codfamilia, $this->madres)) {
                return [];
            }

            return [['madre' => $this->madres[$codfamilia]]];
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $this->execs[] = $sql;

        return true;
    }

    public function structureQueries(): int
    {
        return count(array_filter(
            $this->queries,
            static fn (array $query): bool => str_contains($query['sql'], 'catalogo_familia_estructura')
        ));
    }

    /**
     * @return list<string>
     */
    public function allSql(): array
    {
        return array_merge(array_column($this->queries, 'sql'), $this->execs);
    }
}
