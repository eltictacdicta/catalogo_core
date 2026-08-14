<?php
/**
 * Full-text-style SQL conditions for article search (tpvmod-compatible).
 *
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Builds LIKE-based text search SQL for articulos (reference, barcode, description…).
 *
 * Multi-word queries match tpvmod behaviour:
 * - referencia / partnumber / equivalencia: spaces become wildcards (foo bar → foo%bar)
 * - descripcion: every token must appear (AND)
 */
final class ArticuloSearchQueryBuilder
{
    public static function escapeForLike(string $value): string
    {
        return str_replace(['|', '%', '_'], ['||', '|%', '|_'], $value);
    }

    /**
     * @param callable(string): string $quote SQL literal quoter (e.g. fs_model::var2str)
     */
    public static function appendTextSearchConditions(
        string &$sql,
        string $separator,
        string $query,
        callable $quote
    ): void {
        $query = trim($query);
        if ($query === '') {
            return;
        }

        $condition = self::buildTextSearchCondition($query, $quote);
        if ($condition === '') {
            return;
        }

        $sql .= $separator . ' ' . $condition;
    }

    /**
     * @param callable(string): string $quote
     */
    public static function buildTextSearchCondition(string $query, callable $quote): string
    {
        $query = trim($query);
        if ($query === '') {
            return '';
        }

        if (is_numeric($query)) {
            return self::buildNumericCondition($query, $quote);
        }

        $words = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return '';
        }

        if (count($words) === 1) {
            return self::buildSingleWordCondition($query, $quote);
        }

        return self::buildMultiWordCondition($query, $words, $quote);
    }

    /**
     * @param callable(string): string $quote
     */
    private static function buildNumericCondition(string $query, callable $quote): string
    {
        $escaped = self::escapeForLike($query);
        $like = $quote('%' . $escaped . '%');

        return '('
            . 'referencia = ' . $quote($query)
            . ' OR referencia LIKE ' . $like . " ESCAPE '|'"
            . ' OR partnumber LIKE ' . $like . " ESCAPE '|'"
            . ' OR equivalencia LIKE ' . $like . " ESCAPE '|'"
            . ' OR descripcion LIKE ' . $like . " ESCAPE '|'"
            . ' OR codbarras = ' . $quote($query)
            . ')';
    }

    /**
     * @param callable(string): string $quote
     */
    private static function buildSingleWordCondition(string $query, callable $quote): string
    {
        $escaped = self::escapeForLike($query);
        $like = $quote('%' . $escaped . '%');

        return '('
            . 'lower(referencia) = ' . $quote($query)
            . ' OR lower(referencia) LIKE ' . $like . " ESCAPE '|'"
            . ' OR lower(partnumber) LIKE ' . $like . " ESCAPE '|'"
            . ' OR lower(equivalencia) LIKE ' . $like . " ESCAPE '|'"
            . ' OR lower(codbarras) = ' . $quote($query)
            . ' OR lower(descripcion) LIKE ' . $like . " ESCAPE '|'"
            . ')';
    }

    /**
     * @param list<string> $words
     * @param callable(string): string $quote
     */
    private static function buildMultiWordCondition(string $query, array $words, callable $quote): string
    {
        $escapedWords = array_map(
            static fn (string $word): string => self::escapeForLike($word),
            $words
        );
        $fuzzyLike = $quote('%' . implode('%', $escapedWords) . '%');

        $referenceMatch = '('
            . 'lower(referencia) LIKE ' . $fuzzyLike . " ESCAPE '|'"
            . ' OR lower(partnumber) LIKE ' . $fuzzyLike . " ESCAPE '|'"
            . ' OR lower(equivalencia) LIKE ' . $fuzzyLike . " ESCAPE '|'"
            . ')';

        $descriptionParts = [];
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $descriptionParts[] = 'lower(descripcion) LIKE '
                . $quote('%' . self::escapeForLike($word) . '%') . " ESCAPE '|'";
        }

        if ($descriptionParts === []) {
            return $referenceMatch;
        }

        return '(' . $referenceMatch . ' OR (' . implode(' AND ', $descriptionParts) . '))';
    }
}
