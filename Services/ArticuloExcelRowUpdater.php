<?php
/**
 * This file is part of catalogo_core
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
 * Per-field persistence helpers for article Excel import.
 */
final class ArticuloExcelRowUpdater
{
    public static function parseBool(string $value): bool
    {
        $v = mb_strtolower(trim($value));
        return in_array($v, ['1', 'true', 'yes', 'si', 'sí', 's', 'y'], true);
    }

    public static function parsePrice(string $value): float
    {
        $normalized = str_replace([' ', '€'], '', $value);
        $normalized = str_replace(',', '.', $normalized);

        return (float) $normalized;
    }

    public static function getPriceDecimals(): int
    {
        return defined('FS_NF0_ART') ? (int) FS_NF0_ART : 2;
    }

    public static function normalizePrice(string $value, bool $round = false): float
    {
        $price = self::parsePrice($value);
        if (!$round) {
            return $price;
        }

        if (!function_exists('bround')) {
            require_once FS_FOLDER . '/base/fs_functions.php';
        }

        return (float) bround($price, self::getPriceDecimals());
    }

    public static function formatPriceForDisplay(float $price): string
    {
        return number_format($price, self::getPriceDecimals(), ',', '');
    }

    public static function applyReferencia(string $value, \FSFramework\model\articulo $art): bool
    {
        $value = trim($value);
        if ($value === '' || $art->referencia === $value) {
            return false;
        }
        $art->referencia = $value;

        return true;
    }

    public static function applyDescripcion(string $value, \FSFramework\model\articulo $art): bool
    {
        $value = trim($value);
        if ($value === '' || $art->descripcion === $value) {
            return false;
        }
        $art->descripcion = $value;

        return true;
    }

    /**
     * Applies a mapped locale pair to the target language's description row
     * (D-07 / R5). The write goes through `articulo::set_descripcion_idioma()`,
     * so the clearing semantics (`articulo_descripcion::save()`) and the
     * search-cache invalidation stay owned by the model and the frozen base
     * column is never touched (GDI-06).
     *
     * A `null` component means "not mapped": the current value is preserved.
     * A mapped but empty pair clears the language's row.
     *
     * @return bool True when the target language's row actually changed.
     */
    public static function applyDescripcionIdioma(
        string $codidioma,
        ?string $descripcion,
        ?string $descripcionCorta,
        \FSFramework\model\articulo $art
    ): bool {
        $codidioma = trim($codidioma);
        if ($codidioma === '') {
            return false;
        }

        [$currentDescripcion, $currentCorta] = self::currentLanguagePair($art, $codidioma);
        $nextDescripcion = $descripcion === null ? $currentDescripcion : trim($descripcion);
        $nextCorta = $descripcionCorta === null ? $currentCorta : trim($descripcionCorta);

        if ($nextDescripcion === $currentDescripcion && $nextCorta === $currentCorta) {
            return false;
        }

        return (bool) $art->set_descripcion_idioma($codidioma, $nextDescripcion, $nextCorta);
    }

    /**
     * The target language's own pair, read from the article's cached description
     * list. Same-language only: no fallback chain is inherited, so "absent" is
     * observable as an empty pair (R2).
     *
     * @return array{0: string, 1: string}
     */
    private static function currentLanguagePair(\FSFramework\model\articulo $art, string $codidioma): array
    {
        foreach ($art->get_descripciones() as $description) {
            if ((string) $description->codidioma === $codidioma) {
                return [
                    (string) ($description->descripcion ?? ''),
                    (string) ($description->descripcion_corta ?? ''),
                ];
            }
        }

        return ['', ''];
    }

    public static function applyPvp(string $value, \FSFramework\model\articulo $art, bool $roundPrice = false): bool
    {
        if ($roundPrice) {
            $pvp = self::normalizePrice($value, true);
            if ($art->floatcmp($art->pvp, $pvp, self::getPriceDecimals() + 2)) {
                return false;
            }
            $art->set_pvp($pvp);

            return true;
        }

        $pvp = self::parsePrice($value);
        if (abs($art->pvp - $pvp) < 0.00001) {
            return false;
        }
        $art->pvp = $pvp;
        $art->factualizado = date('d-m-Y');

        return true;
    }

    public static function applyCodfamilia(string $value, \FSFramework\model\articulo $art): bool
    {
        $value = trim($value);
        $newVal = $value === '' ? null : $value;
        if ($art->codfamilia === $newVal) {
            return false;
        }
        $art->codfamilia = $newVal;

        return true;
    }

    public static function applyCodfabricante(string $value, \FSFramework\model\articulo $art): bool
    {
        $value = trim($value);
        $newVal = $value === '' ? null : $value;
        if ($art->codfabricante === $newVal) {
            return false;
        }
        $art->codfabricante = $newVal;

        return true;
    }

    public static function applyCodimpuesto(string $value, \FSFramework\model\articulo $art): bool
    {
        $value = trim($value);
        $newVal = $value === '' ? null : $value;
        if ($art->codimpuesto === $newVal) {
            return false;
        }
        $art->codimpuesto = $newVal;

        return true;
    }

    public static function applyBloqueado(string $value, \FSFramework\model\articulo $art): bool
    {
        $bloqueado = self::parseBool($value);
        if ($art->bloqueado === $bloqueado) {
            return false;
        }
        $art->bloqueado = $bloqueado;
        if ($bloqueado) {
            $art->publico = false;
        }

        return true;
    }

    /**
     * @param array<string,string> $mappedRow
     */
    public static function applyMappedFields(array $mappedRow, \FSFramework\model\articulo $art, bool $roundPrice = false): bool
    {
        $changed = false;
        foreach ($mappedRow as $fieldName => $value) {
            $value = (string) $value;
            switch ($fieldName) {
                case 'referencia':
                    if (self::applyReferencia($value, $art)) {
                        $changed = true;
                    }
                    break;
                case 'descripcion':
                    if (self::applyDescripcion($value, $art)) {
                        $changed = true;
                    }
                    break;
                case 'pvp':
                    if (self::applyPvp($value, $art, $roundPrice)) {
                        $changed = true;
                    }
                    break;
                case 'codfamilia':
                    if (self::applyCodfamilia($value, $art)) {
                        $changed = true;
                    }
                    break;
                case 'codfabricante':
                    if (self::applyCodfabricante($value, $art)) {
                        $changed = true;
                    }
                    break;
                case 'codimpuesto':
                    if (self::applyCodimpuesto($value, $art)) {
                        $changed = true;
                    }
                    break;
                case 'bloqueado':
                    if (self::applyBloqueado($value, $art)) {
                        $changed = true;
                    }
                    break;
            }
        }

        return $changed;
    }
}
