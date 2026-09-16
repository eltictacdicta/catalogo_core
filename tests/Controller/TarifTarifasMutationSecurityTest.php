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

/**
 * Mutation-safety contract for tarif_tarifas after the CodeRabbit review of
 * the tarifario -> catalogo_core migration.
 *
 * Covers four findings:
 *  1. delete / set_default / copy_structure must never be dispatched from a
 *     query string; they are POST-only and CSRF-guarded.
 *  2. copy_structure must reject equal source and destination codes.
 *  3. inline onclick handlers must encode model data for the JS/HTML-attr
 *     context.
 *  4. the asignar_grupo AJAX error path must not open the modal with empty
 *     selections.
 */
final class TarifTarifasMutationSecurityTest extends TestCase
{
    private const CONTROLLER = 'plugins/catalogo_core/controller/tarif_tarifas.php';
    private const VIEW = 'plugins/catalogo_core/View/tarif_tarifas.html.twig';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/base/fs_app.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';
        require_once FS_FOLDER . '/' . self::CONTROLLER;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        $this->resetCoreLog();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Clear the fs_core_log state that every instance shares.
     *
     * fs_core_log accumulates its entries in a static array, so without
     * this a test that ran earlier in the same process leaks its errors
     * into get_errors() here. The static controller_name is reset as well:
     * fs_controller::new_error_msg() only writes to the 'errors' channel
     * when class_name matches controller_name(), and the constructor only
     * assigns that name when it is empty. Nulling it lets the fresh
     * instance claim it. fs_model::$core_log gets a new instance too.
     */
    private function resetCoreLog(): void
    {
        (new \fs_core_log())->clear();

        $ref = new \ReflectionClass(\fs_core_log::class);
        $nameProp = $ref->getProperty('controller_name');
        $nameProp->setAccessible(true);
        $nameProp->setValue(null, null);

        $prop = (new \ReflectionClass(\fs_model::class))->getProperty('core_log');
        $prop->setAccessible(true);
        $prop->setValue(null, new \fs_core_log());
    }

    private function controllerSource(): string
    {
        $path = FS_FOLDER . '/' . self::CONTROLLER;
        if (!is_file($path)) {
            self::fail('missing catalogo_core controller: ' . self::CONTROLLER);
        }

        return (string) file_get_contents($path);
    }

    private function viewSource(): string
    {
        $path = FS_FOLDER . '/' . self::VIEW;
        if (!is_file($path)) {
            self::fail('missing catalogo_core view: ' . self::VIEW);
        }

        return (string) file_get_contents($path);
    }

    /**
     * Extract a PHP method body by balancing braces.
     */
    private function phpMethodBody(string $src, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{/';
        if (!preg_match($pattern, $src, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        return $this->balancedBody($src, $matches[0][1] + strlen($matches[0][0]));
    }

    /**
     * Extract a JS function body by balancing braces.
     */
    private function jsFunctionBody(string $src, string $function): string
    {
        $pattern = '/function\s+' . preg_quote($function, '/') . '\s*\([^)]*\)\s*\{/';
        if (!preg_match($pattern, $src, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        return $this->balancedBody($src, $matches[0][1] + strlen($matches[0][0]));
    }

    private function balancedBody(string $src, int $start): string
    {
        $depth = 1;
        $length = strlen($src);
        for ($i = $start; $i < $length; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start);
                }
            }
        }

        return '';
    }

    private function wireCoreLog( object $controller ): void
    {
        $coreLogProp = new \ReflectionProperty(\fs_app::class, 'core_log');
        $coreLogProp->setAccessible(true);
        $coreLogProp->setValue($controller, new \fs_core_log(\tarif_tarifas::class));

        $classNameProp = new \ReflectionProperty(\fs_controller::class, 'class_name');
        $classNameProp->setAccessible(true);
        $classNameProp->setValue($controller, \tarif_tarifas::class);
    }

    // =====================================================================
    // Finding 1 — mutations are POST-only and CSRF-guarded
    // =====================================================================

    public function test_mutations_are_not_dispatched_from_the_query_string(): void
    {
        $src = $this->controllerSource();

        foreach (["\$_GET['delete']", "\$_GET['set_default']", "\$_GET['copy_from']", "\$_GET['copy_to']"] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $src,
                'mutations must not read parameters from the query string: ' . $needle
            );
        }
    }

    public function test_mutations_are_post_actions_guarded_by_csrf(): void
    {
        $src = $this->controllerSource();
        $body = $this->phpMethodBody($src, 'private_core');
        self::assertNotSame('', $body, 'private_core() body must be found');

        foreach (["case 'delete':", "case 'set_default':", "case 'copy_structure':"] as $case) {
            self::assertStringContainsString($case, $body, 'missing POST action: ' . $case);
        }

        self::assertGreaterThanOrEqual(
            3,
            substr_count($body, 'requireCsrf()'),
            'each mutating POST action must be CSRF-guarded via requireCsrf()'
        );
    }

    public function test_mutation_handlers_read_their_parameters_from_post(): void
    {
        $src = $this->controllerSource();

        self::assertStringContainsString(
            "\$_POST['delete']",
            $this->phpMethodBody($src, 'delete_tarifa'),
            'delete_tarifa() must read the code from POST'
        );
        self::assertStringContainsString(
            "\$_POST['set_default']",
            $this->phpMethodBody($src, 'set_default'),
            'set_default() must read the code from POST'
        );

        $copyBody = $this->phpMethodBody($src, 'copy_structure');
        self::assertStringContainsString("\$_POST['copy_from']", $copyBody, 'copy_structure() must read copy_from from POST');
        self::assertStringContainsString("\$_POST['copy_to']", $copyBody, 'copy_structure() must read copy_to from POST');
    }

    // =====================================================================
    // Finding 2 — equal source/destination must be rejected before copying
    // =====================================================================

    public function test_copy_structure_rejects_equal_source_and_destination(): void
    {
        $familiaSpy = new class() {
            public int $copies = 0;

            public function copy_from_tarifa($origen, $destino): bool
            {
                $this->copies++;

                return true;
            }
        };

        $tarifaMock = new class() {
            public function get($cod)
            {
                $obj = new \stdClass();
                $obj->nombre = 'Tarifa ' . $cod;

                return $obj;
            }
        };

        $controller = new class($tarifaMock, $familiaSpy) extends \tarif_tarifas {
            public function __construct($tarifa, $familia)
            {
                // Skip the heavy FS constructor (DB + menu boot).
                $this->tarifa = $tarifa;
                $this->tarifa_familia = $familia;
            }

            public function call_copy_structure(): void
            {
                $method = new \ReflectionMethod(\tarif_tarifas::class, 'copy_structure');
                $method->setAccessible(true);
                $method->invoke($this);
            }
        };
        $this->wireCoreLog($controller);

        $_POST['copy_from'] = 'T-IGUAL';
        $_POST['copy_to'] = 'T-IGUAL';

        $controller->call_copy_structure();

        $errors = $controller->get_errors();
        self::assertNotEmpty($errors, 'equal codes must emit an error');
        self::assertStringContainsStringIgnoringCase(
            'distinta',
            implode(' | ', $errors),
            'the error must explain that source and destination must differ'
        );
        self::assertSame(
            0,
            $familiaSpy->copies,
            'heredar_estructura() must not run when source and destination are equal'
        );
    }

    public function test_copy_structure_guards_before_inheriting(): void
    {
        $body = $this->phpMethodBody($this->controllerSource(), 'copy_structure');
        self::assertNotSame('', $body, 'copy_structure() body must be found');

        $guardPos = strpos($body, 'distinta');
        $inheritPos = strpos($body, 'heredar_estructura(');
        self::assertNotFalse($guardPos, 'copy_structure() must validate origin vs destination');
        self::assertNotFalse($inheritPos, 'copy_structure() must still inherit for distinct codes');
        self::assertLessThan(
            $inheritPos,
            $guardPos,
            'the equality guard must run before heredar_estructura()'
        );
    }

    // =====================================================================
    // Finding 3 — inline onclick handlers encode model data
    // =====================================================================

    public function test_inline_handlers_encode_model_values_for_the_js_context(): void
    {
        $view = $this->viewSource();
        $cd = "{{ tarifa.codtarifa|json_encode|e('html_attr') }}";
        $nm = "{{ tarifa.nombre|json_encode|e('html_attr') }}";

        $handlers = [
            'abrirAsignarGrupo(' . $cd . ', ' . $nm . ')',
            'copiar_estructura(' . $cd . ', ' . $nm . ')',
            'eliminar_tarifa(' . $cd . ', ' . $nm . ')',
        ];
        foreach ($handlers as $handler) {
            self::assertStringContainsString(
                $handler,
                $view,
                'inline handler must encode its arguments: ' . $handler
            );
        }

        self::assertStringContainsString(
            "editar_tarifa(" . $cd . ", " . $nm,
            $view,
            'editar_tarifa() must encode its string arguments'
        );
        self::assertStringContainsString(
            "{{ tarifa.coddivisa|default('EUR')|json_encode|e('html_attr') }}",
            $view,
            'editar_tarifa() must encode the currency argument'
        );

        foreach (['abrirAsignarGrupo', 'copiar_estructura', 'editar_tarifa', 'eliminar_tarifa'] as $fn) {
            self::assertStringNotContainsString(
                'onclick="' . $fn . "('{{ tarifa.codtarifa }}'",
                $view,
                $fn . ' must not interpolate the raw code into the onclick attribute'
            );
        }
    }

    // =====================================================================
    // Finding 4 — AJAX failure must not open the modal with empty selections
    // =====================================================================

    public function test_asignar_grupo_ajax_failure_keeps_the_modal_closed(): void
    {
        $view = $this->viewSource();
        $body = $this->jsFunctionBody($view, 'abrirAsignarGrupo');
        self::assertNotSame('', $body, 'abrirAsignarGrupo() body must be found');

        self::assertSame(
            1,
            substr_count($body, "$('#modal_asignar_grupo').modal('show')"),
            'the modal must only be shown on the AJAX success path'
        );

        $errorPos = strpos($body, 'error:');
        self::assertNotFalse($errorPos, 'the AJAX error handler must stay');
        $errorBranch = substr($body, $errorPos);
        self::assertStringNotContainsString(
            "$('#modal_asignar_grupo').modal('show')",
            $errorBranch,
            'the AJAX error path must not open the modal'
        );
        self::assertMatchesRegularExpression(
            '/bootbox\.alert|(^|[^\w.])alert\(/',
            $errorBranch,
            'the AJAX error path must surface an error to the user'
        );
    }

    // =====================================================================
    // Finding 1 (view) — row mutations submit POST, never the query string
    // =====================================================================

    public function test_view_mutations_use_post_forms_instead_of_query_string_links(): void
    {
        $view = $this->viewSource();

        self::assertStringNotContainsString('tarif_tarifas&delete=', $view);
        self::assertStringNotContainsString('tarif_tarifas&set_default=', $view);
        self::assertStringNotContainsString('tarif_tarifas&copy_from=', $view);

        self::assertStringContainsString('tarif_mutacion_form', $view, 'a shared POST mutation form is required');
        self::assertStringContainsString(
            'name="action" value="set_default"',
            $view,
            'set_default must be submitted as a POST action'
        );
        self::assertStringContainsString("ejecutar_mutacion('delete'", $view, 'delete must submit via the POST helper');
        self::assertStringContainsString(
            "ejecutar_mutacion('copy_structure'",
            $view,
            'copy_structure must submit via the POST helper'
        );
    }

    public function test_mutation_helper_does_not_clear_the_csrf_tokens(): void
    {
        $body = $this->jsFunctionBody($this->viewSource(), 'ejecutar_mutacion');
        self::assertNotSame('', $body, 'ejecutar_mutacion() body must be found');

        self::assertStringNotContainsString(
            'input[type=hidden]',
            $body,
            'the helper must not blank the CSRF hidden inputs of the shared form'
        );
        self::assertStringContainsString('#mutacion_delete', $body);
        self::assertStringContainsString('#mutacion_set_default', $body);
        self::assertStringContainsString('#mutacion_copy_from', $body);
        self::assertStringContainsString('#mutacion_copy_to', $body);
    }
}
