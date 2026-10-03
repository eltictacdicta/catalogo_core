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

use FSFramework\Plugins\catalogo_core\Services\OpcionalImagenService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * OVE-04 — upload, replace and remove in the canonical editor. The mutation
 * methods persist the bare filename, delete the previous physical file on
 * replace/remove, and are CSRF-guarded.
 *
 * Legacy classes are required inside the tests (never at file scope): a
 * file-scope require would load the real `familia` model during test discovery
 * and collide with other process-isolated stubs.
 */
final class VentasOpcionalImagenTest extends TestCase
{
    private const VIEW = 'plugins/catalogo_core/View/ventas_opcional.html.twig';

    private const CONTROLLER = 'plugins/catalogo_core/Controller/VentasOpcional.php';

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        global $plugins;
        $plugins = [];

        $this->dir = rtrim(sys_get_temp_dir(), '/') . '/opcional_editor_' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->dir, 0775, true);
        unset($_FILES['imagen']);
    }

    protected function tearDown(): void
    {
        unset($_FILES['imagen']);
        foreach (glob($this->dir . '*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_view_exposes_an_htmx_image_block_with_csrf(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/' . self::VIEW);

        $this->assertStringContainsString('id="opcional-imagen"', $view, 'the image block must be addressable by htmx');
        $this->assertStringContainsString('hx-encoding="multipart/form-data"', $view, 'the upload must be a multipart htmx request');
        $this->assertStringContainsString('name="upload_opcional_imagen"', $view);
        $this->assertStringContainsString('name="delete_opcional_imagen"', $view);
        $this->assertStringContainsString('imagen_url()', $view, 'the stored image must be served through imagen_url()');
        $this->assertStringContainsString('{{ csrf_field() }}', $view);
        $this->assertStringNotContainsString('|raw', $view);
    }

    public function test_controller_validates_csrf_before_persisting_the_image(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/' . self::CONTROLLER);
        $upload = $this->methodBody($source, 'subirImagenOpcional');
        $remove = $this->methodBody($source, 'eliminarImagenOpcional');

        $this->assertNotSame('', $upload, 'subirImagenOpcional() must exist');
        $this->assertNotSame('', $remove, 'eliminarImagenOpcional() must exist');
        $this->assertStringContainsString('validateFormToken()', $upload);
        $this->assertStringContainsString('validateFormToken()', $remove);
        $this->assertStringContainsString('delete_file(', $upload, 'replace must delete the previous file');
        $this->assertStringContainsString('delete_file(', $remove);
    }

    public function test_upload_persists_the_bare_filename(): void
    {
        $spy = $this->spyModel();
        $_FILES['imagen'] = ['tmp_name' => $this->pngTempFile(), 'error' => UPLOAD_ERR_OK, 'name' => '../../evil.php'];

        $controller = $this->makeController($spy, true);
        $controller->exposeSubir($this->request());

        $this->assertSame(1, $spy->saveCalls, 'the opcional must be saved once');
        $this->assertIsString($spy->imagen);
        $this->assertSame(basename($spy->imagen), $spy->imagen, 'the persisted value must be a bare filename');
        $this->assertStringNotContainsString('evil', $spy->imagen, 'the client filename must never be trusted');
        $this->assertFileExists($this->dir . $spy->imagen);
    }

    public function test_replace_deletes_the_previous_file(): void
    {
        $spy = $this->spyModel();
        $previous = 'previous.png';
        file_put_contents($this->dir . $previous, $this->pngBytes());
        $spy->imagen = $previous;

        $_FILES['imagen'] = ['tmp_name' => $this->pngTempFile(), 'error' => UPLOAD_ERR_OK];

        $controller = $this->makeController($spy, true);
        $controller->exposeSubir($this->request());

        $this->assertSame(1, $spy->saveCalls);
        $this->assertNotSame($previous, $spy->imagen);
        $this->assertFileDoesNotExist($this->dir . $previous, 'the replaced file must be deleted from disk');
        $this->assertFileExists($this->dir . $spy->imagen);
    }

    public function test_remove_clears_the_column_and_deletes_the_file(): void
    {
        $spy = $this->spyModel();
        $name = 'toremove.png';
        file_put_contents($this->dir . $name, $this->pngBytes());
        $spy->imagen = $name;

        $controller = $this->makeController($spy, true);
        $controller->exposeEliminar($this->request());

        $this->assertNull($spy->imagen, 'the column must be cleared');
        $this->assertSame(1, $spy->saveCalls);
        $this->assertFileDoesNotExist($this->dir . $name, 'the removed file must be deleted from disk');
    }

    public function test_upload_without_a_valid_csrf_token_persists_nothing(): void
    {
        $spy = $this->spyModel();
        $_FILES['imagen'] = ['tmp_name' => $this->pngTempFile(), 'error' => UPLOAD_ERR_OK];

        $controller = $this->makeController($spy, false);
        $controller->exposeSubir($this->request());

        $this->assertSame(0, $spy->saveCalls);
        $this->assertNull($spy->imagen);
        $this->assertSame([], $this->writtenFiles(), 'a CSRF-invalid upload must not write any file');
        $this->assertNotSame('', $controller->lastError);
    }

    public function test_remove_without_a_valid_csrf_token_deletes_nothing(): void
    {
        $spy = $this->spyModel();
        $name = 'keep.png';
        file_put_contents($this->dir . $name, $this->pngBytes());
        $spy->imagen = $name;

        $controller = $this->makeController($spy, false);
        $controller->exposeEliminar($this->request());

        $this->assertSame($name, $spy->imagen);
        $this->assertSame(0, $spy->saveCalls);
        $this->assertFileExists($this->dir . $name);
        $this->assertNotSame('', $controller->lastError);
    }

    private function request(): Request
    {
        return Request::create('/index.php?page=ventas_opcional&id=5', 'POST', [
            'form_token' => 'irrelevant',
        ]);
    }

    private function spyModel(): object
    {
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';

        return new class() extends \FSFramework\model\catalogo_opcional {
            public int $saveCalls = 0;

            public function __construct()
            {
                $this->table_name = \FSFramework\model\catalogo_opcional::TABLE;
                $this->id = 5;
                $this->codigo = 'OPC0005';
                $this->nombre = 'Test';
                $this->descripcion = '';
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
        };
    }

    private function makeController(object $spy, bool $csrfValid): object
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_idioma.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/OpcionalImagenService.php';
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcional.php';

        $service = new OpcionalImagenService($this->dir);

        return new class($spy, $service, $csrfValid) extends \FSFramework\Plugins\catalogo_core\Controller\VentasOpcional {
            private object $spyModel;

            private OpcionalImagenService $imageService;

            private bool $csrfValid;

            public string $lastError = '';

            public function __construct(object $spyModel, OpcionalImagenService $imageService, bool $csrfValid)
            {
                // Skip parent::__construct: no FS boot nor DB is needed.
                $this->spyModel = $spyModel;
                $this->imageService = $imageService;
                $this->csrfValid = $csrfValid;
                $this->opcional = $spyModel;
                $this->is_new = false;
                $this->lista_precio_defecto = 'DEFAULT';
            }

            public function exposeSubir(Request $request): void
            {
                $method = new \ReflectionMethod(
                    \FSFramework\Plugins\catalogo_core\Controller\VentasOpcional::class,
                    'subirImagenOpcional'
                );
                $method->setAccessible(true);
                $method->invoke($this, $request);
            }

            public function exposeEliminar(Request $request): void
            {
                $method = new \ReflectionMethod(
                    \FSFramework\Plugins\catalogo_core\Controller\VentasOpcional::class,
                    'eliminarImagenOpcional'
                );
                $method->setAccessible(true);
                $method->invoke($this, $request);
            }

            protected function opcional_imagen_service(): OpcionalImagenService
            {
                return $this->imageService;
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
        };
    }

    /**
     * @return list<string>
     */
    private function writtenFiles(): array
    {
        return array_values(array_filter(glob($this->dir . '*') ?: [], 'is_file'));
    }

    private function pngTempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'opcional_editor_src_');
        file_put_contents($path, $this->pngBytes());

        return $path;
    }

    private function pngBytes(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        );
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
