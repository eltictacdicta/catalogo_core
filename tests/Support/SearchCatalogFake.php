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

/**
 * DB-free fake of the read path used by `articulo::search()`.
 *
 * `select_limit()` records the emitted SQL and evaluates the
 * language-agnostic description predicate against the seeded rows, so the
 * model's search can be exercised without a database. Only the description leg
 * of the predicate is evaluated (the tests seed descriptions exclusively); the
 * reference, barcode and partnumber legs are asserted on the SQL string by the
 * test. A query whose description predicate is not the `a.`/`d.` shape the
 * model must emit yields no rows, which keeps a pre-change run failing cleanly
 * instead of instantiating a real `articulo` against a live database.
 */
final class SearchCatalogFake
{
    /** @var list<array<string, mixed>> */
    public array $articulos = [];

    /** @var list<array{referencia: string, codidioma: string, descripcion: string, descripcion_corta: ?string}> */
    public array $descripciones = [];

    /** @var list<string> */
    public array $queries = [];

    public function addArticulo(
        string $referencia,
        string $descripcion = '',
        bool $bloqueado = false,
        string $codfamilia = ''
    ): void {
        $this->articulos[] = [
            'referencia' => $referencia,
            'descripcion' => $descripcion,
            'codfamilia' => $codfamilia,
            'stockfis' => 0.0,
            'bloqueado' => $bloqueado,
            'codbarras' => '',
            'partnumber' => '',
            'equivalencia' => '',
        ];
    }

    public function addDescripcion(
        string $referencia,
        string $codidioma,
        string $descripcion,
        ?string $descripcionCorta = null
    ): void {
        $this->descripciones[] = [
            'referencia' => $referencia,
            'codidioma' => $codidioma,
            'descripcion' => $descripcion,
            'descripcion_corta' => $descripcionCorta,
        ];
    }

    /**
     * Simulates the clearing path: the language's description row stops existing.
     */
    public function clearDescripcion(string $referencia, string $codidioma): void
    {
        $this->descripciones = array_values(array_filter(
            $this->descripciones,
            static fn (array $row): bool => !(
                (string) $row['referencia'] === $referencia
                && (string) $row['codidioma'] === $codidioma
            )
        ));
    }

    public function var2str($val)
    {
        if ($val === null) {
            return 'NULL';
        }
        if (is_bool($val)) {
            return $val ? '1' : '0';
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function escape_string(string $str): string
    {
        return addslashes($str);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function select_limit($sql, $limit = 0, $offset = 0): array
    {
        $sql = (string) $sql;
        $this->queries[] = $sql;

        $matches = [];
        foreach ($this->articulos as $articulo) {
            if (!$this->passesBlockedFilter($sql, $articulo)) {
                continue;
            }
            if ($this->matchesTextPredicate($sql, $articulo)) {
                $matches[] = $articulo;
            }
        }

        usort(
            $matches,
            static fn (array $a, array $b): int => strcmp((string) $a['referencia'], (string) $b['referencia'])
        );

        return array_slice($matches, (int) $offset, $limit > 0 ? (int) $limit : null);
    }

    /**
     * @param array<string, mixed> $articulo
     */
    private function passesBlockedFilter(string $sql, array $articulo): bool
    {
        if (strpos($sql, 'a.bloqueado = FALSE') !== false && $articulo['bloqueado']) {
            return false;
        }
        if (strpos($sql, 'a.bloqueado = TRUE') !== false && !$articulo['bloqueado']) {
            return false;
        }

        return true;
    }

    /**
     * Evaluates the emitted description predicate. Every token found on the
     * `a.descripcion` leg must match the base column or the joined translation
     * row; the translation leg is only considered when the join is present.
     *
     * @param array<string, mixed> $articulo
     */
    private function matchesTextPredicate(string $sql, array $articulo): bool
    {
        $baseTerms = $this->extractTerms($sql, 'a');
        $languageTerms = $this->extractTerms($sql, 'd');

        if ($baseTerms === [] && $languageTerms === []) {
            // No recognizable description predicate: either there is no text
            // query at all, or the emitted shape is not the language-agnostic
            // one this harness models.
            return strpos($sql, 'LIKE') === false;
        }

        $joinPresent = strpos($sql, 'LEFT JOIN articulo_descripciones') !== false;
        $baseDescription = $this->lower((string) $articulo['descripcion']);

        foreach ($baseTerms as $index => $term) {
            if ($term === '') {
                continue;
            }

            $matched = strpos($baseDescription, $term) !== false;

            if (!$matched && $joinPresent && isset($languageTerms[$index])) {
                foreach ($this->descripciones as $row) {
                    if ((string) $row['referencia'] !== (string) $articulo['referencia']) {
                        continue;
                    }
                    if (strpos($this->lower((string) $row['descripcion']), $languageTerms[$index]) !== false) {
                        $matched = true;
                        break;
                    }
                }
            }

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function extractTerms(string $sql, string $alias): array
    {
        $pattern = '/lower\(' . preg_quote($alias, '/') . '\.descripcion\) LIKE \'([^\']*)\'/';
        preg_match_all($pattern, $sql, $matches);

        return array_map(fn (string $pattern): string => $this->unlike($pattern), $matches[1]);
    }

    private function unlike(string $pattern): string
    {
        $value = str_replace(['||', '|%', '|_'], ["\x00", "\x01", "\x02"], $pattern);
        $value = trim($value, '%');

        return str_replace(["\x00", "\x01", "\x02"], ['|', '%', '_'], $value);
    }

    private function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF8');
    }
}
