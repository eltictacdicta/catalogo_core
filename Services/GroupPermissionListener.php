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

use FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent;
use FSFramework\model\fs_user;

/**
 * First-party listener for the neutral ArticlePermissionFilterEvent
 * (multitarifa R-RG-002..005, design D4).
 *
 * Always registered from Init::init(); the master setting short-circuit on the
 * very first statement makes the feature provably inert when the role-groups
 * master setting is off (no DB reads, no deny reachable).
 *
 * Resolution mirrors tarifario's ArticlePermissionListener semantics:
 * - master off ⇒ silent return (allow) before any model access
 * - admin dominance ⇒ silent return (allow)
 * - gestor in any group ⇒ allow on every scoped article
 * - editor ⇒ allow only when the article is explicitly assigned to one of
 *   his groups (catalogo_grupo_articulos)
 * - revisor / visualizador ⇒ read-only, denied on edit
 * - fail-closed: silent return never happens on error; any Throwable denies
 *   with a user-facing reason. Silent return = allow is the ONLY allow path
 *   after gating activates.
 */
final class GroupPermissionListener
{
    public const ROL_GESTOR = 'gestor';
    public const ROL_EDITOR = 'editor';

    private ?CatalogoOptions $options = null;

    /** @var fs_user|null */
    private $userModel = null;

    /** @var \FSFramework\model\catalogo_grupo_usuario|null */
    private $grupoUsuarioModel = null;

    /** @var \FSFramework\model\catalogo_grupo_articulo|null */
    private $grupoArticuloModel = null;

    public function __construct(?CatalogoOptions $options = null)
    {
        $this->options = $options;
    }

    /** Silent return = allow (event resolves allow by default). */
    public function __invoke(ArticlePermissionFilterEvent $event): void
    {
        try {
            $this->resolve($event);
        } catch (\Throwable $e) {
            error_log('[catalogo_core] GroupPermissionListener: ' . $e->getMessage());
            $event->deny('permission filter error');
        }
    }

    /** Resolves the R-RG-004 matrix. Returning without denying = allow. */
    private function resolve(ArticlePermissionFilterEvent $event): void
    {
        // Master short-circuit FIRST: zero model reads when the feature is off.
        if (!$this->options()->groupsEnabled()) {
            return;
        }

        if ($this->isAdmin((string) $event->getNick())) {
            return;
        }

        $sawEditor = false;
        foreach ($this->grupoUsuarioModel()->all_from_nick($event->getNick()) as $membership) {
            $rol = (string) $membership->rol;

            if ($rol === self::ROL_GESTOR) {
                return;
            }

            if ($rol === self::ROL_EDITOR) {
                $sawEditor = true;
                $assigned = $this->grupoArticuloModel()->get($membership->id_grupo, $event->getReferencia());
                if ($assigned !== false) {
                    return;
                }
            }
        }

        $event->deny($sawEditor
            ? 'editor: el artículo no está asignado a tus grupos'
            : 'sin permisos de edición');
    }

    private function isAdmin(string $nick): bool
    {
        $user = $this->userModel()->get($nick);

        return $user !== false && !empty($user->admin);
    }

    private function options(): CatalogoOptions
    {
        if ($this->options === null) {
            $this->options = new CatalogoOptions();
        }

        return $this->options;
    }

    // ---- lazy wiring (ArticlePermissionListener pattern) ----

    private function userModel(): fs_user
    {
        if ($this->userModel === null) {
            require_once FS_FOLDER . '/model/core/fs_user.php';
            $this->userModel = new fs_user();
        }

        return $this->userModel;
    }

    private function grupoUsuarioModel(): \FSFramework\model\catalogo_grupo_usuario
    {
        if ($this->grupoUsuarioModel === null) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_grupo_usuario.php';
            $this->grupoUsuarioModel = new \FSFramework\model\catalogo_grupo_usuario();
        }

        return $this->grupoUsuarioModel;
    }

    private function grupoArticuloModel(): \FSFramework\model\catalogo_grupo_articulo
    {
        if ($this->grupoArticuloModel === null) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_grupo_articulo.php';
            $this->grupoArticuloModel = new \FSFramework\model\catalogo_grupo_articulo();
        }

        return $this->grupoArticuloModel;
    }

    // ---- @internal test setters (NOT production surface) ----

    /** @internal testing-only */
    public function setUserModel(fs_user $model): void
    {
        $this->userModel = $model;
    }

    /** @internal testing-only */
    public function setGrupoUsuarioModel(\FSFramework\model\catalogo_grupo_usuario $model): void
    {
        $this->grupoUsuarioModel = $model;
    }

    /** @internal testing-only */
    public function setGrupoArticuloModel(\FSFramework\model\catalogo_grupo_articulo $model): void
    {
        $this->grupoArticuloModel = $model;
    }
}
