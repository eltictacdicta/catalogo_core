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
 * Central access verdict for the article catalog Excel import/export
 * (change catalogo-excel-access-control, AD-1).
 *
 * Rule: admin users are always allowed (admin dominance); non-admin users are
 * allowed only when they hold at least one role granted via the
 * `catalogo_excel_roles` setting (comma-separated `fs_roles.codrol` list,
 * persisted through fs_settings). Fail-closed: an absent or unparsable setting
 * grants zero roles, so the default is admin-only.
 *
 * Instantiated final class with a no-arg constructor (no DI: the plugin has no
 * config/services.php). Per-instance memoization (AD-3): instance lifetime in
 * PHP-FPM equals the request lifetime, so verdicts and the granted role set
 * are evaluated once per request and can never go stale across requests.
 */
final class ArticleExcelAccessPolicy
{
    /** fs_settings key holding the comma-separated granted role list. */
    public const SETTING_KEY = 'catalogo_excel_roles';

    private ?\FSFramework\model\fs_user $testUser = null;

    /** @var mixed|null raw setting override (null = absent); non-null bypasses fs_settings */
    private mixed $testSettingRaw = null;
    private bool $settingOverridden = false;

    /** @var list<string>|null existing-roles override (null = read fs_roles) */
    private ?array $testExistingRoles = null;

    /** @var \fs_rol_user|null */
    private $rolUserModel = null;

    /** @var \fs_rol|null */
    private $rolModel = null;

    /** @var array<string,bool> nick => verdict memo */
    private array $verdictMemo = [];

    /** @var array<string,bool> nick => admin memo */
    private array $adminMemo = [];

    /** @var array<string,list<string>> nick => held roles memo */
    private array $userRolesMemo = [];

    /** @var list<string>|null granted roles memo (per instance) */
    private ?array $grantedRolesMemo = null;

    /**
     * True when $user (or the session user when null) may import/export the
     * article catalog Excel. Memoized per nick within this instance.
     */
    public function isAllowed(?\FSFramework\model\fs_user $user = null): bool
    {
        $resolved = $this->resolveUser($user);
        if ($resolved === null) {
            return false;
        }

        $nick = (string) $resolved->nick;
        if (!array_key_exists($nick, $this->verdictMemo)) {
            $this->verdictMemo[$nick] = $this->computeAllowed($resolved);
        }

        return $this->verdictMemo[$nick];
    }

    /**
     * True when the evaluated user is admin (admin dominance path).
     */
    public function isAdmin(?\FSFramework\model\fs_user $user = null): bool
    {
        $resolved = $this->resolveUser($user);
        if ($resolved === null) {
            return false;
        }

        $nick = (string) $resolved->nick;
        if (!array_key_exists($nick, $this->adminMemo)) {
            $this->adminMemo[$nick] = !empty($resolved->admin);
        }

        return $this->adminMemo[$nick];
    }

    /**
     * Granted role codes: the parsed `catalogo_excel_roles` comma list
     * intersected with existing `fs_roles` rows (AD-6 read-time validation:
     * a deleted role is not granted). Absent or unparsable setting yields [].
     *
     * @return list<string>
     */
    public function grantedRoles(): array
    {
        if ($this->grantedRolesMemo === null) {
            $this->grantedRolesMemo = $this->computeGrantedRoles();
        }

        return $this->grantedRolesMemo;
    }

    /**
     * User-facing denial message (plugin convention: Spanish).
     */
    public function denialMessage(): string
    {
        return 'No tienes permiso para importar o exportar el catálogo de artículos en Excel. '
            . 'Contacta con un administrador.';
    }

    // ---- Internal resolution ----

    private function computeAllowed(\FSFramework\model\fs_user $user): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        $held = $this->rolesOfUser((string) $user->nick);
        if ($held === []) {
            return false;
        }

        return count(array_intersect($held, $this->grantedRoles())) > 0;
    }

    private function resolveUser(?\FSFramework\model\fs_user $user): ?\FSFramework\model\fs_user
    {
        if ($user !== null) {
            return $user;
        }

        if ($this->testUser !== null) {
            return $this->testUser;
        }

        $nick = $this->sessionNick();
        if ($nick === null || $nick === '') {
            return null;
        }

        $found = $this->userModel()->get($nick);

        return $found === false ? null : $found;
    }

    private function sessionNick(): ?string
    {
        try {
            return \FSFramework\Security\SessionManager::getInstance()->getCurrentUserNick();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function rolesOfUser(string $nick): array
    {
        if (array_key_exists($nick, $this->userRolesMemo)) {
            return $this->userRolesMemo[$nick];
        }

        $held = [];
        foreach ($this->rolUserModel()->all_from_user($nick) as $rolUser) {
            $codrol = (string) ($rolUser->codrol ?? '');
            if ($codrol !== '' && !in_array($codrol, $held, true)) {
                $held[] = $codrol;
            }
        }

        $this->userRolesMemo[$nick] = $held;

        return $held;
    }

    /**
     * @return list<string>
     */
    private function computeGrantedRoles(): array
    {
        $raw = $this->settingRaw();
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $parsed = [];
        foreach (explode(',', $raw) as $piece) {
            $codrol = trim($piece);
            if ($codrol !== '') {
                $parsed[$codrol] = true;
            }
        }

        if ($parsed === []) {
            return [];
        }

        $granted = [];
        foreach ($this->existingRoles() as $codrol) {
            if (isset($parsed[$codrol])) {
                $granted[] = $codrol;
            }
        }

        return $granted;
    }

    /**
     * Raw setting value: test override first, then fs_settings (pure
     * $GLOBALS['config2'] array op). Null when absent.
     */
    private function settingRaw(): mixed
    {
        if ($this->settingOverridden) {
            return $this->testSettingRaw;
        }

        return (new \fs_settings())->get(self::SETTING_KEY);
    }

    /**
     * Existing role codes: test override first, then `fs_rol::all()`.
     *
     * @return list<string>
     */
    private function existingRoles(): array
    {
        if ($this->testExistingRoles !== null) {
            return $this->testExistingRoles;
        }

        $existing = [];
        foreach ($this->rolModel()->all() as $rol) {
            $codrol = (string) ($rol->codrol ?? '');
            if ($codrol !== '' && !in_array($codrol, $existing, true)) {
                $existing[] = $codrol;
            }
        }

        return $existing;
    }

    // ---- Lazy model wiring (ArticlePermissionListener pattern) ----

    private function userModel(): \FSFramework\model\fs_user
    {
        require_once FS_FOLDER . '/model/core/fs_user.php';

        return new \FSFramework\model\fs_user();
    }

    private function rolUserModel(): \fs_rol_user
    {
        if ($this->rolUserModel === null) {
            require_once FS_FOLDER . '/model/fs_rol_user.php';
            $this->rolUserModel = new \fs_rol_user();
        }

        return $this->rolUserModel;
    }

    private function rolModel(): \fs_rol
    {
        if ($this->rolModel === null) {
            require_once FS_FOLDER . '/model/fs_rol.php';
            $this->rolModel = new \fs_rol();
        }

        return $this->rolModel;
    }

    // ---- @internal test setters (NOT production surface) ----

    /** @internal testing-only: bypasses session resolution. */
    public function setUser(?\FSFramework\model\fs_user $user): void
    {
        $this->testUser = $user;
    }

    /** @internal testing-only: raw setting override (null = absent). */
    public function setSettingRaw(mixed $raw): void
    {
        $this->testSettingRaw = $raw;
        $this->settingOverridden = true;
        $this->grantedRolesMemo = null;
    }

    /** @internal testing-only: existing-roles override (null = read fs_roles). */
    public function setExistingRoles(?array $roles): void
    {
        $this->testExistingRoles = $roles;
        $this->grantedRolesMemo = null;
    }

    /** @internal testing-only: held-roles model override. */
    public function setRolUserModel(\fs_rol_user $model): void
    {
        $this->rolUserModel = $model;
    }

    /** @internal testing-only: existing-roles model override. */
    public function setRolModel(\fs_rol $model): void
    {
        $this->rolModel = $model;
    }
}
