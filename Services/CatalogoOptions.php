<?php
declare(strict_types=1);
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
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
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Central options store for catalogo_core (change multitarifa, task 1.4).
 *
 * Single read/write path for all plugin options, backed by namespaced
 * fs_settings keys (every key prefixed with `catalogo_core.`) persisted over
 * `$GLOBALS['config2']`. Absorbs the legacy `catalogo_excel_roles` setting
 * with a read fallback when the new key is absent (R-CO-002), so a revert of
 * the options store can never orphan Excel access.
 *
 * This class is the SOLE writer of its keys (design D3): callers must not
 * write `catalogo_core.*` settings directly.
 */
final class CatalogoOptions
{
    public const KEY_MULTI_TARIFF = 'catalogo_core.multi_tariff';
    public const KEY_GROUPS_ENABLED = 'catalogo_core.groups_enabled';
    public const KEY_FAMILY_STRUCTURE = 'catalogo_core.family_structure';
    public const KEY_EXCEL_ROLES = 'catalogo_core.excel_roles';

    /** Legacy setting key preserved as read-only fallback (R-CO-002). */
    public const LEGACY_KEY_EXCEL_ROLES = 'catalogo_excel_roles';

    /** @var array<string, mixed>|null injectable raw key/value override for tests */
    private ?array $rawOverride;

    /** @var callable|null persistence seam for tests; null uses fs_settings::save() */
    private $persister;

    public function __construct(?array $rawOverride = null, ?callable $persister = null)
    {
        $this->rawOverride = $rawOverride;
        $this->persister = $persister;
    }

    /** Multi-tariff pricing flag. Safe default: false (R-CO-003). */
    public function multiTariffEnabled(): bool
    {
        return $this->readBool(self::KEY_MULTI_TARIFF);
    }

    public function setMultiTariff(bool $enabled): bool
    {
        return $this->write(self::KEY_MULTI_TARIFF, $enabled ? 'true' : 'false');
    }

    /** Role-groups master setting. Safe default: false ⇒ listener inert. */
    public function groupsEnabled(): bool
    {
        return $this->readBool(self::KEY_GROUPS_ENABLED);
    }

    public function setGroupsEnabled(bool $enabled): bool
    {
        return $this->write(self::KEY_GROUPS_ENABLED, $enabled ? 'true' : 'false');
    }

    /**
     * Family-structure capability flag (familias-jerarquia, D3 lifecycle
     * gate). Safe default: false — the migration and the tree UI stay inert
     * until the operator opts in.
     */
    public function familyStructureEnabled(): bool
    {
        return $this->readBool(self::KEY_FAMILY_STRUCTURE);
    }

    public function setFamilyStructure(bool $enabled): bool
    {
        return $this->write(self::KEY_FAMILY_STRUCTURE, $enabled ? 'true' : 'false');
    }

    /**
     * Comma-separated granted role list for Excel access, or null when absent
     * (null triggers the legacy `catalogo_excel_roles` fallback in readers).
     */
    public function excelRoles(): ?string
    {
        $raw = $this->read(self::KEY_EXCEL_ROLES);
        if (is_string($raw)) {
            return $raw;
        }

        // Legacy fallback: absorb the value persisted by the old page.
        $legacy = $this->read(self::LEGACY_KEY_EXCEL_ROLES);

        return is_string($legacy) ? $legacy : null;
    }

    public function setExcelRoles(string $commaList): bool
    {
        return $this->write(self::KEY_EXCEL_ROLES, $commaList);
    }

    /**
     * Persists the three general options of the options page in one save.
     *
     * All keys are set in memory first and the settings store is written once,
     * so a failure cannot leave the page half-persisted.
     */
    public function saveAll(bool $multiTariff, bool $groupsEnabled, string $excelRoles): bool
    {
        return $this->commit(static function (\fs_settings $settings) use ($multiTariff, $groupsEnabled, $excelRoles): void {
            $settings->set(self::KEY_MULTI_TARIFF, $multiTariff ? 'true' : 'false');
            $settings->set(self::KEY_GROUPS_ENABLED, $groupsEnabled ? 'true' : 'false');
            $settings->set(self::KEY_EXCEL_ROLES, $excelRoles);
        });
    }

    // ---- internals ----

    private function readBool(string $key): bool
    {
        $raw = $this->read($key);
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $normalized = strtolower(trim($raw));

            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function read(string $key): mixed
    {
        if ($this->rawOverride !== null) {
            return array_key_exists($key, $this->rawOverride) ? $this->rawOverride[$key] : null;
        }

        require_once FS_FOLDER . '/base/fs_settings.php';

        return (new \fs_settings())->get($key);
    }

    /**
     * Writes one key and persists the settings store.
     *
     * `fs_settings::set()` only mutates `$GLOBALS['config2']` in memory: the
     * value survives until the request ends and is lost unless `save()`
     * rewrites `tmp/<FS_TMP_NAME>config2.ini`. Returning the persistence
     * result lets callers report a real failure instead of a false success.
     */
    private function write(string $key, string $value): bool
    {
        return $this->commit(static function (\fs_settings $settings) use ($key, $value): void {
            $settings->set($key, $value);
        });
    }

    /**
     * Mutates the settings store and persists it, rolling back the in-memory
     * config when the write fails: `set()` alone would otherwise leave readers
     * in this request exposing values that never reached disk.
     *
     * @param callable(\fs_settings): void $mutate
     */
    private function commit(callable $mutate): bool
    {
        $hadConfig = array_key_exists('config2', $GLOBALS);
        $before = $GLOBALS['config2'] ?? [];

        $settings = $this->settings();
        $mutate($settings);

        if ($this->persist($settings)) {
            return true;
        }

        if ($hadConfig) {
            $GLOBALS['config2'] = $before;
        } else {
            unset($GLOBALS['config2']);
        }

        return false;
    }

    private function settings(): \fs_settings
    {
        require_once FS_FOLDER . '/base/fs_settings.php';

        return new \fs_settings();
    }

    private function persist(\fs_settings $settings): bool
    {
        if ($this->persister !== null) {
            return (bool) ($this->persister)($settings);
        }

        return $settings->save();
    }
}
