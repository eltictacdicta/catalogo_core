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

namespace Tests\CatalogoCore\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * GDI-13 — the canonical editor exposes an opcional language block and persists
 * per-language `nombre`/`descripcion` through the existing CSRF-guarded save.
 *
 * Legacy classes are required inside the tests (never at file scope): a
 * file-scope require would load the real `familia` model during test discovery
 * and collide with the `InitUpgradeTest` stub in the same process.
 */
final class VentasOpcionalIdiomaTest extends TestCase
{
    private const VIEW = 'plugins/catalogo_core/View/ventas_opcional.html.twig';

    private const CONTROLLER = 'plugins/catalogo_core/Controller/VentasOpcional.php';

    protected function setUp(): void
    {
        parent::setUp();
        global $plugins;
        $plugins = [];
    }

    public function test_view_exposes_the_language_selector_and_per_language_fields(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/' . self::VIEW);

        $this->assertStringContainsString('codidioma_selector', $view, 'the language selector must be present');
        $this->assertStringContainsString('name="idioma_nombre[', $view, 'a per-language name field must be present');
        $this->assertStringContainsString('name="idioma_descripcion[', $view, 'a per-language description field must be present');
        $this->assertStringContainsString('{{ csrf_field() }}', $view, 'the language fields must live in the CSRF-guarded form');
        $this->assertStringNotContainsString('|raw', $view);
    }

    public function test_view_removes_the_duplicated_base_name_and_description_inputs(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/' . self::VIEW);

        $this->assertStringNotContainsString('name="snombre"', $view, 'the base name input must be removed (GDI-11 mirror)');
        $this->assertStringNotContainsString('name="sdescripcion"', $view, 'the base description input must be removed (GDI-11 mirror)');
        $this->assertStringContainsString(
            '{% if idioma.codidioma == fsc.codidioma_edit %} required{% endif %}',
            $view,
            'the configured-default language name input must be required'
        );
    }

    public function test_guardar_opcional_validates_csrf_before_persisting_languages(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/' . self::CONTROLLER);
        $guardar = $this->methodBody($source, 'guardarOpcional');
        $idiomas = $this->methodBody($source, 'guardarIdiomas');

        $this->assertNotSame('', $guardar, 'guardarOpcional() must exist');
        $this->assertNotSame('', $idiomas, 'guardarIdiomas() must exist');

        $csrf = strpos($guardar, 'validateFormToken()');
        $this->assertNotFalse($csrf, 'guardarOpcional() must validate CSRF');
        $idiomasCall = strpos($guardar, 'guardarIdiomas(');
        $this->assertNotFalse($idiomasCall, 'guardarOpcional() must persist the language block');
        $this->assertLessThan($idiomasCall, $csrf, 'CSRF must be validated before the language block is persisted');
        $this->assertStringContainsString('set_idioma(', $idiomas);
    }

    public function test_valid_csrf_save_mirrors_the_default_language_into_the_base(): void
    {
        $controller = $this->makeController(true);
        $controller->exposeGuardar($this->request());

        $spy = $controller->spy();
        $this->assertSame(1, $spy->saveCalls, 'the base opcional must be saved once');
        $this->assertSame('Hola', $spy->nombre, 'the default language mirrors into the base name');
        $this->assertSame('Desc ES', $spy->descripcion, 'the default language mirrors into the base description');

        $cods = array_map(static fn (array $call): string => $call[0], $spy->idiomaCalls);
        $this->assertSame(['en'], $cods, 'only non-default languages are written through set_idioma()');
        $this->assertContains(['en', 'Hello', 'Desc EN'], $spy->idiomaCalls);
    }

    public function test_invalid_csrf_persists_nothing(): void
    {
        $controller = $this->makeController(false);
        $controller->exposeGuardar($this->request());

        $spy = $controller->spy();
        $this->assertSame(0, $spy->saveCalls, 'a CSRF-invalid POST must not save the opcional');
        $this->assertSame([], $spy->idiomaCalls, 'a CSRF-invalid POST must not persist any language row');
        $this->assertNotSame('', $controller->lastError);
    }

    private function request(): Request
    {
        return Request::create('/index.php?page=ventas_opcional&id=5', 'POST', [
            'save_opcional' => '1',
            'scodigo' => 'OPC0005',
            'stipo_precio' => 'fijo',
            'sprecio' => '0',
            'idioma_nombre' => ['en' => 'Hello', 'es' => 'Hola'],
            'idioma_descripcion' => ['en' => 'Desc EN', 'es' => 'Desc ES'],
        ]);
    }

    private function makeController(bool $csrfValid): object
    {
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_idioma.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcional.php';

        $spy = new class() extends \FSFramework\model\catalogo_opcional {
            public int $saveCalls = 0;

            /** @var list<array{0: string, 1: string, 2: string}> */
            public array $idiomaCalls = [];

            public function __construct()
            {
                $this->table_name = \FSFramework\model\catalogo_opcional::TABLE;
                $this->id = 5;
                $this->codigo = 'OPC0005';
                $this->nombre = 'Base';
                $this->descripcion = 'Base desc';
                $this->precio = 0.0;
                $this->tipo_precio = \FSFramework\model\catalogo_opcional::TIPO_PRECIO_FIJO;
                $this->porcentaje = null;
                $this->activo = true;
                $this->cantidad_min = 1;
                $this->cantidad_max = 1;
                $this->imagen = null;
            }

            public function save(): bool
            {
                $this->saveCalls++;

                return true;
            }

            public function set_grupos(array $idGrupos): bool
            {
                return true;
            }

            public function set_idioma($codidioma, $nombre, $descripcion): bool
            {
                $this->idiomaCalls[] = [(string) $codidioma, (string) $nombre, (string) $descripcion];

                return true;
            }

            public function es_precio_porcentaje(): bool
            {
                return false;
            }

            public function set_precio_lista($codlista, $precio): bool
            {
                return true;
            }

            public function set_porcentaje_lista($codlista, $porcentaje): bool
            {
                return true;
            }
        };

        return new class($spy, $csrfValid) extends \FSFramework\Plugins\catalogo_core\Controller\VentasOpcional {
            private object $spyModel;

            private bool $csrfValid;

            public string $lastError = '';

            public function __construct(object $spyModel, bool $csrfValid)
            {
                // Skip parent::__construct: no FS boot nor DB is needed.
                $this->spyModel = $spyModel;
                $this->csrfValid = $csrfValid;
                $this->opcional = $spyModel;
                $this->lista_precio_defecto = 'DEFAULT';
                // Mirrors loadIdiomas(): the editor also primes the effective
                // default so guardarOpcional() knows which language is the base.
                $this->codidioma_edit = 'es';
            }

            public function spy(): object
            {
                return $this->spyModel;
            }

            public function exposeGuardar(Request $request): void
            {
                // Production sets $this->request in the boot; mirror it so the
                // trailing `query->getInt('id')` redirect decision is reachable.
                $this->request = $request;

                $method = new \ReflectionMethod(
                    \FSFramework\Plugins\catalogo_core\Controller\VentasOpcional::class,
                    'guardarOpcional'
                );
                $method->setAccessible(true);
                $method->invoke($this, $request);
            }

            protected function validateFormToken(): bool
            {
                return $this->csrfValid;
            }

            public function new_message(string $msg): void
            {
            }

            public function new_error_msg(string $msg): void
            {
                $this->lastError = $msg;
            }

            public function redirect(string $url): void
            {
            }
        };
    }

    private function methodBody(string $source, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{/';
        if (!preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $matches[0][1] + strlen($matches[0][0]);
        $depth = 1;
        $length = strlen($source);
        for ($i = $start; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }

        return '';
    }
}
