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
 *
 * Every base-column reference is qualified with the `a.` alias because the
 * description predicate LEFT JOINs `articulo_descripciones` (alias `d`); an
 * unqualified base column would be ambiguous once the join is present.
 */
final class ArticuloSearchQueryBuilder
{
    public static function escapeForLike(string $value): string
    {
        return str_replace(['|', '%', '_'], ['||', '|%', '|_'], $value);
    }

    /**
     * The single language-agnostic description predicate.
     *
     * DELIBERATE DEVIATION (GDI-09 / D11) — article search is language-agnostic.
     * Every retrieved system scopes search to a language or store view
     * (PrestaShop `id_lang` S6; Akeneo working locale S20; Odoo
     * `COALESCE(lang, en_US)` S1; Magento store view S28/S29). This fork
     * intentionally matches across all languages because ~25 legacy readers and
     * the frozen `articulos.descripcion` column keep text outside the
     * translation table; a locale-scoped search would silently hide legacy
     * articles. Do NOT "fix" this into a locale-scoped search (GDI-09).
     *
     * The single-word, multi-word and numeric paths all route through this
     * helper, so exactly one language-agnostic predicate exists.
     *
     * @param string $like Already-quoted LIKE literal (see the callers).
     * @param callable(string): string $quote SQL literal quoter, accepted for
     *        symmetry with the builder's other condition callbacks.
     */
    public static function languageDescriptionPredicate(string $like, callable $quote): string
    {
        return '(lower(a.descripcion) LIKE ' . $like . " ESCAPE '|'"
            . ' OR lower(d.descripcion) LIKE ' . $like . " ESCAPE '|')";
    }

    /**
     * The join the language-agnostic predicate depends on.
     */
    public static function languageDescriptionJoin(): string
    {
        return ' LEFT JOIN articulo_descripciones d ON d.referencia = a.referencia';
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
            . 'a.referencia = ' . $quote($query)
            . ' OR a.referencia LIKE ' . $like . " ESCAPE '|'"
            . ' OR a.partnumber LIKE ' . $like . " ESCAPE '|'"
            . ' OR a.equivalencia LIKE ' . $like . " ESCAPE '|'"
            . ' OR ' . self::languageDescriptionPredicate($like, $quote)
            . ' OR a.codbarras = ' . $quote($query)
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
            . 'lower(a.referencia) = ' . $quote($query)
            . ' OR lower(a.referencia) LIKE ' . $like . " ESCAPE '|'"
            . ' OR lower(a.partnumber) LIKE ' . $like . " ESCAPE '|'"
            . ' OR lower(a.equivalencia) LIKE ' . $like . " ESCAPE '|'"
            . ' OR lower(a.codbarras) = ' . $quote($query)
            . ' OR ' . self::languageDescriptionPredicate($like, $quote)
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
            . 'lower(a.referencia) LIKE ' . $fuzzyLike . " ESCAPE '|'"
            . ' OR lower(a.partnumber) LIKE ' . $fuzzyLike . " ESCAPE '|'"
            . ' OR lower(a.equivalencia) LIKE ' . $fuzzyLike . " ESCAPE '|'"
            . ')';

        $descriptionParts = [];
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $descriptionParts[] = self::languageDescriptionPredicate(
                $quote('%' . self::escapeForLike($word) . '%'),
                $quote
            );
        }

        if ($descriptionParts === []) {
            return $referenceMatch;
        }

        return '(' . $referenceMatch . ' OR (' . implode(' AND ', $descriptionParts) . '))';
    }
}
