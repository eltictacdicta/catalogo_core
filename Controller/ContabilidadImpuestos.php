<?php
declare(strict_types=1);
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FSFramework\Plugins\catalogo_core\Controller;

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/impuesto.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/base/fs_default_items.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use Symfony\Component\HttpFoundation\Request;

class ContabilidadImpuestos extends PageController
{
    public string $codsubcuentasop = '';
    public string $codsubcuentarep = '';
    public bool $allow_delete = false;
    public bool $has_subcuentas = false;

    /** @var \FSFramework\model\impuesto|null */
    public $impuesto = null;

    /** @var array<int, \FSFramework\model\impuesto> */
    public array $impuestos = [];

    public function __construct()
    {
        parent::__construct('ContabilidadImpuestos');
        $this->setTemplate('contabilidad_impuestos');
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on($this->getPageData()['name']);
        $this->loadExtensions();
    }

    public function getPageData(): array
    {
        return [
            'name' => 'contabilidad_impuestos',
            'title' => 'Impuestos',
            'menu' => 'contabilidad',
            'showonmenu' => true,
            'ordernum' => 100,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $this->impuesto = new \FSFramework\model\impuesto();
        $this->loadSubcuentasPredeterminadas();

        if ($this->request->query->has('delete')) {
            $this->eliminarImpuesto($this->request);
        } elseif ($this->request->request->has('codimpuesto')) {
            $this->editarImpuesto($this->request);
        } elseif ($this->request->query->has('set_default')) {
            $this->saveCodimpuesto((string) $this->request->query->get('set_default', ''));
        }

        $list = $this->impuesto->all();
        $this->impuestos = is_array($list) ? $list : [];
    }

    private function loadExtensions(): void
    {
        $pageName = $this->getPageData()['name'];
        $fsext = new \fs_extension();
        foreach ($fsext->all() as $ext) {
            if (!in_array($ext->to, [null, $pageName], true)) {
                continue;
            }

            if ($ext->type !== 'config' && !$this->user->have_access_to($ext->from)) {
                continue;
            }

            $this->extensions[] = $ext;
        }
    }

    private function loadSubcuentasPredeterminadas(): void
    {
        $this->codsubcuentasop = '';
        $this->codsubcuentarep = '';
        $this->has_subcuentas = false;

        if ($this->empresa === null || !is_object($this->empresa) || empty($this->empresa->codejercicio)) {
            return;
        }

        if (!class_exists(\FSFramework\model\subcuenta::class)) {
            return;
        }

        $this->has_subcuentas = true;
        $subcuenta = new \FSFramework\model\subcuenta();
        $codejercicio = (string) $this->empresa->codejercicio;

        $subcuentasop = $subcuenta->get_cuentaesp('IVASOP', $codejercicio);
        if ($subcuentasop) {
            $this->codsubcuentasop = (string) ($subcuentasop->codsubcuenta ?? '');
        }

        $subcuentarep = $subcuenta->get_cuentaesp('IVAREP', $codejercicio);
        if ($subcuentarep) {
            $this->codsubcuentarep = (string) ($subcuentarep->codsubcuenta ?? '');
            if ($this->codsubcuentasop === '') {
                $this->codsubcuentasop = $this->codsubcuentarep;
            }
        }
    }

    private function editarImpuesto(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $codimpuesto = trim((string) $request->request->get('codimpuesto', ''));
        if ($codimpuesto === '') {
            $this->new_error_msg('El código del impuesto es obligatorio.');
            return;
        }

        $impuesto = $this->impuesto->get($codimpuesto);
        if (!$impuesto) {
            $impuesto = new \FSFramework\model\impuesto();
            $impuesto->codimpuesto = $codimpuesto;
        }

        $impuesto->descripcion = (string) $request->request->get('descripcion', '');

        $codsubcuentarep = trim((string) $request->request->get('codsubcuentarep', ''));
        $impuesto->codsubcuentarep = $codsubcuentarep !== '' ? $codsubcuentarep : null;

        $codsubcuentasop = trim((string) $request->request->get('codsubcuentasop', ''));
        $impuesto->codsubcuentasop = $codsubcuentasop !== '' ? $codsubcuentasop : null;

        $impuesto->iva = (float) $request->request->get('iva', 0);
        $impuesto->recargo = (float) $request->request->get('recargo', 0);

        if ($impuesto->save()) {
            $this->new_message('Impuesto ' . $impuesto->codimpuesto . ' guardado correctamente.');
            return;
        }

        $this->new_error_msg('Error al guardar el impuesto.');
    }

    private function eliminarImpuesto(Request $request): void
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar impuestos.');
            return;
        }

        if (!$this->user->admin) {
            $this->new_error_msg('Sólo un administrador puede eliminar impuestos.');
            return;
        }

        $cod = trim((string) $request->query->get('delete', ''));
        $impuesto = $this->impuesto->get($cod);
        if (!$impuesto) {
            $this->new_error_msg('Impuesto no encontrado.');
            return;
        }

        if ($impuesto->delete()) {
            $this->new_message('Impuesto eliminado correctamente.');
            return;
        }

        $this->new_error_msg('Ha sido imposible eliminar el impuesto.');
    }

    private function saveCodimpuesto(string $cod): void
    {
        $cod = trim($cod);
        if ($cod === '') {
            return;
        }

        $expire = time() + (defined('FS_COOKIES_EXPIRE') ? (int) FS_COOKIES_EXPIRE : 31536000);
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        if (PHP_VERSION_ID >= 70300) {
            setcookie('default_impuesto', $cod, [
                'expires' => $expire,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie('default_impuesto', $cod, $expire, '/');
        }

        $defaults = new \fs_default_items();
        $defaults->set_codimpuesto($cod);
        $this->new_message('Impuesto predeterminado actualizado.');
    }
}
