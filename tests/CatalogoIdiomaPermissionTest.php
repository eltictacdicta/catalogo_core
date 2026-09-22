<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
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
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Controller\VentasArticulos;
use FSFramework\Translation\FSTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\CatalogoCore\Support\FakeCatalogoIdioma;
use Tests\CatalogoCore\Support\IdiomaRegistryFake;

require_once __DIR__ . '/Support/IdiomaRegistryFake.php';
require_once __DIR__ . '/Support/FakeCatalogoIdioma.php';

/**
 * GDI-04 — every language mutation is administrator-only, POST-only and
 * CSRF-validated; the `ventas_articulos` page itself stays accessible and
 * carries no `#[AdminOnly]` (D-06/D-12).
 *
 * DB-free: an anonymous VentasArticulos subclass skips the controller
 * constructor, injects the in-memory registry double through the trait seam
 * and stubs the CSRF gate, so the real `gestionarIdioma()` body executes.
 */
final class CatalogoIdiomaPermissionTest extends TestCase
{
    private const CONTROLLER = '/plugins/catalogo_core/Controller/VentasArticulos.php';

    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/extras/VentasArticulosListTrait.php';
        if (!class_exists('articulo', false)) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
            require_once FS_FOLDER . '/base/fs_model_autoloader.php';
            \fs_model_autoloader::ensureGlobalAlias('articulo');
        }
        if (is_file(FS_FOLDER . self::CONTROLLER)) {
            require_once FS_FOLDER . self::CONTROLLER;
        }
    }

    /**
     * @return array<int, object>
     */
    private function registryRows(IdiomaRegistryFake $db): array
    {
        return (new FakeCatalogoIdioma())->useFakeDb($db)->all();
    }

    private function buildController(IdiomaRegistryFake $db, bool $csrfValid, bool $admin): object
    {
        $outer = $this;
        $controller = new class($outer, $db, $csrfValid, $admin) extends VentasArticulos {
            public object $outer;

            public function __construct(
                object $outer,
                private IdiomaRegistryFake $registry,
                private bool $csrfValid,
                private bool $admin
            ) {
                $this->outer = $outer;
                $this->className = 'ventas_articulos';
                $this->core_log = new \fs_core_log(VentasArticulos::class);
                $this->user = (object) ['admin' => $this->admin, 'nick' => 'tester'];
                $this->idiomas_todos = [];
            }

            protected function validateFormToken(): bool
            {
                return $this->csrfValid;
            }

            protected function idioma_model()
            {
                return (new FakeCatalogoIdioma())->useFakeDb($this->registry);
            }

            public function setRequestObj(Request $request): void
            {
                $this->request = $request;
            }

            /** @param array<int, object> $rows */
            public function setIdiomasTodos(array $rows): void
            {
                $this->idiomas_todos = $rows;
            }

            public function callGestionarIdioma(Request $request): void
            {
                $method = new \ReflectionMethod(VentasArticulos::class, 'gestionarIdioma');
                $method->setAccessible(true);
                $method->invoke($this, $request);
            }

            public function callShouldDispatch(Request $request): bool
            {
                $method = new \ReflectionMethod(VentasArticulos::class, 'shouldDispatchIdioma');
                $method->setAccessible(true);

                return (bool) $method->invoke($this, $request);
            }
        };

        return $controller;
    }

    /**
     * @param array<string, string> $fields
     */
    private function postRequest(object $controller, array $fields): Request
    {
        $request = Request::create('/index.php?page=ventas_articulos', 'POST', $fields);
        $controller->setRequestObj($request);

        return $request;
    }

    private function source(string $relative): string
    {
        $path = FS_FOLDER . $relative;
        if (!is_file($path)) {
            self::fail('missing path: ' . $relative);
        }

        return (string) file_get_contents($path);
    }

    private function methodSource(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            self::fail('missing method: ' . $signature);
        }

        $open = (int) strpos($src, '{', $start);
        $depth = 0;
        $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start + 1);
                }
            }
        }

        self::fail('method body is not terminated: ' . $signature);
    }

    private function seedTwoLanguages(): IdiomaRegistryFake
    {
        return new IdiomaRegistryFake([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
        ]);
    }

    public function test_admin_round_trip_mutates_the_registry(): void
    {
        $db = $this->seedTwoLanguages();
        $controller = $this->buildController($db, true, true);
        $controller->setIdiomasTodos($this->registryRows($db));

        // Create.
        $controller->callGestionarIdioma($this->postRequest($controller, [
            'idioma_action' => 'save',
            'codidioma' => 'fr',
            'nombre' => 'Français',
        ]));
        $this->assertArrayHasKey('fr', $db->idiomas, 'an administrator must be able to create a language');
        $this->assertContains(FSTranslator::trans('language-created'), $controller->get_messages());

        // Rename. Each mutation reloads the registry, exactly like the page
        // reload a POST triggers.
        $controller->setIdiomasTodos($this->registryRows($db));
        $controller->callGestionarIdioma($this->postRequest($controller, [
            'idioma_action' => 'save',
            'codidioma' => 'fr',
            'nombre' => 'French',
        ]));
        $this->assertSame('French', $db->idiomas['fr']['nombre'], 'a rename must persist');

        // Deactivate.
        $controller->setIdiomasTodos($this->registryRows($db));
        $controller->callGestionarIdioma($this->postRequest($controller, [
            'idioma_action' => 'toggle_active',
            'codidioma' => 'fr',
        ]));
        $this->assertFalse($db->idiomas['fr']['activo'], 'a non-default language must be deactivatable');

        // Reactivate.
        $controller->setIdiomasTodos($this->registryRows($db));
        $controller->callGestionarIdioma($this->postRequest($controller, [
            'idioma_action' => 'toggle_active',
            'codidioma' => 'fr',
        ]));
        $this->assertTrue($db->idiomas['fr']['activo'], 'a deactivated language must be reactivatable');

        // Set default.
        $controller->setIdiomasTodos($this->registryRows($db));
        $controller->callGestionarIdioma($this->postRequest($controller, [
            'idioma_action' => 'set_default',
            'codidioma' => 'fr',
        ]));
        $this->assertSame(['fr'], $db->activeDefaults(), 'setting a default must flip the pointer');
        $this->assertContains(FSTranslator::trans('language-default-changed'), $controller->get_messages());

        // Delete a non-default language.
        $controller->setIdiomasTodos($this->registryRows($db));
        $controller->callGestionarIdioma($this->postRequest($controller, [
            'idioma_action' => 'delete',
            'codidioma' => 'en',
        ]));
        $this->assertArrayNotHasKey('en', $db->idiomas, 'a non-default language must be deletable');
        $this->assertContains(FSTranslator::trans('language-deleted'), $controller->get_messages());
    }

    public function test_non_administrator_with_a_valid_token_persists_nothing(): void
    {
        $db = $this->seedTwoLanguages();
        $controller = $this->buildController($db, true, false);
        $controller->setIdiomasTodos($this->registryRows($db));

        $controller->callGestionarIdioma($this->postRequest($controller, [
            'idioma_action' => 'save',
            'codidioma' => 'fr',
            'nombre' => 'Français',
        ]));

        $this->assertArrayNotHasKey('fr', $db->idiomas, 'a non-administrator must not create a language');
        $this->assertSame([], $db->executed, 'a refused mutation must emit no statement');
        $this->assertNotEmpty($controller->get_errors(), 'the refusal must be explicit');
    }

    public function test_csrf_failure_persists_nothing_and_rejects_explicitly(): void
    {
        $db = $this->seedTwoLanguages();
        $controller = $this->buildController($db, false, true);
        $controller->setIdiomasTodos($this->registryRows($db));

        $controller->callGestionarIdioma($this->postRequest($controller, [
            'idioma_action' => 'save',
            'codidioma' => 'fr',
            'nombre' => 'Français',
        ]));

        $this->assertArrayNotHasKey('fr', $db->idiomas, 'an invalid CSRF token must persist nothing');
        $this->assertSame([], $db->executed, 'a CSRF failure must emit no statement');
        $this->assertContains(
            FSTranslator::trans('language-csrf-invalid'),
            $controller->get_errors(),
            'the CSRF rejection must be explicit'
        );
    }

    public function test_get_cannot_mutate_and_the_page_has_no_admin_only(): void
    {
        $db = $this->seedTwoLanguages();
        $controller = $this->buildController($db, true, true);

        $this->assertTrue(
            $controller->callShouldDispatch($this->postRequest($controller, ['idioma_action' => 'delete'])),
            'a POST carrying idioma_action must dispatch'
        );
        $this->assertFalse(
            $controller->callShouldDispatch(Request::create('/index.php?page=ventas_articulos')),
            'a POST without idioma_action must not dispatch'
        );
        $this->assertFalse(
            $controller->callShouldDispatch(Request::create(
                '/index.php?page=ventas_articulos&idioma_action=delete&codidioma=en',
                'GET'
            )),
            'a GET carrying idioma_action in the query string must never dispatch'
        );

        $src = $this->source(self::CONTROLLER);
        $this->assertStringNotContainsString(
            'AdminOnly',
            $src,
            'ventas_articulos must stay accessible: no page-level #[AdminOnly] attribute'
        );

        $dispatch = $this->methodSource($src, 'private function gestionarIdioma');
        $this->assertStringContainsString('validateFormToken()', $dispatch, 'the mutation must pass the CSRF gate');
        $this->assertStringContainsString('user->admin', $dispatch, 'the mutation must pass the administrator gate');
        $this->assertStringContainsString(
            "request->request->get('idioma_action'",
            $dispatch,
            'the action must be read from the POST body'
        );
        $this->assertStringNotContainsString(
            "query->get('idioma_action'",
            $src,
            'the action must never be read from the query string'
        );
    }

    public function test_default_and_last_language_deletions_are_refused_through_the_mutation(): void
    {
        // The default cannot be deleted.
        $db = $this->seedTwoLanguages();
        $controller = $this->buildController($db, true, true);
        $controller->setIdiomasTodos($this->registryRows($db));

        $controller->callGestionarIdioma($this->postRequest($controller, [
            'idioma_action' => 'delete',
            'codidioma' => 'es',
        ]));
        $this->assertArrayHasKey('es', $db->idiomas, 'the default language must not be deletable');

        // The last language cannot be deleted.
        $single = new IdiomaRegistryFake([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
        ]);
        $controller = $this->buildController($single, true, true);
        $controller->setIdiomasTodos($this->registryRows($single));

        $controller->callGestionarIdioma($this->postRequest($controller, [
            'idioma_action' => 'delete',
            'codidioma' => 'es',
        ]));
        $this->assertArrayHasKey('es', $single->idiomas, 'the last language must not be deletable');
    }
}
