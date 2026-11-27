<?php
declare(strict_types=1);
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\CatalogoCore;

use FSFramework\model\catalogo_opcional;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * "Eliminar todos los opcionales": solo administradores, POST + CSRF y la
 * confirmación escrita; el modelo borra en una transacción todo o nada.
 */
final class VentasOpcionalesDeleteAllTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcionales.php';
    }

    public function test_admin_with_confirmation_deletes_everything(): void
    {
        $c = $this->controller(admin: true, post: ['delete_all_opcionales' => '1', 'confirmacion' => 'ELIMINAR']);
        $c->run();

        $this->assertSame(1, $c->store->calls);
        $this->assertSame(['3 opcionales eliminados, con sus relaciones y precios. Los grupos de opcionales se conservan.'], $c->messages);
        $this->assertSame([], $c->errors);
    }

    public function test_non_admin_is_rejected_even_with_delete_permission(): void
    {
        $c = $this->controller(admin: false, post: ['delete_all_opcionales' => '1', 'confirmacion' => 'ELIMINAR']);
        $c->allow_delete = true;
        $c->run();

        $this->assertSame(0, $c->store->calls);
        $this->assertSame(['Solo un administrador puede eliminar todos los opcionales.'], $c->errors);
    }

    public function test_wrong_confirmation_deletes_nothing(): void
    {
        $c = $this->controller(admin: true, post: ['delete_all_opcionales' => '1', 'confirmacion' => 'eliminar']);
        $c->run();

        $this->assertSame(0, $c->store->calls);
        $this->assertCount(1, $c->errors);
    }

    public function test_get_or_invalid_token_deletes_nothing(): void
    {
        $c = $this->controller(admin: true, post: ['delete_all_opcionales' => '1', 'confirmacion' => 'ELIMINAR'], token: false);
        $c->run();
        $this->assertSame(0, $c->store->calls);

        $c = $this->controller(admin: true, post: [], method: 'GET');
        $c->run();
        $this->assertSame(0, $c->store->calls);
    }

    public function test_failed_delete_reports_that_nothing_was_deleted(): void
    {
        $c = $this->controller(admin: true, post: ['delete_all_opcionales' => '1', 'confirmacion' => 'ELIMINAR']);
        $c->store->result = false;
        $c->run();

        $this->assertSame(['No se pudieron eliminar los opcionales. No se ha borrado nada.'], $c->errors);
    }

    public function test_model_deletes_dependents_then_opcionales_in_one_transaction(): void
    {
        $db = new DeleteAllDbDouble();
        $model = $this->model($db);

        $this->assertSame(7, $model->delete_all());
        $this->assertSame('begin', $db->log[0]);
        $this->assertSame('commit', end($db->log));
        $deletes = array_values(array_filter($db->log, static fn ($l) => str_starts_with($l, 'DELETE')));
        $this->assertSame('DELETE FROM catalogo_opcionales;', end($deletes), 'opcionales last');
        foreach (catalogo_opcional::DEPENDENT_TABLES as $table) {
            $this->assertContains('DELETE FROM ' . $table . ';', $deletes);
        }
        $this->assertContains("DELETE FROM catalogo_flujo_condiciones WHERE sujeto_tipo = 'opcional';", $deletes);
        $this->assertNotContains('DELETE FROM catalogo_opcional_grupos;', $deletes, 'groups are kept');
    }

    public function test_model_rolls_back_when_a_statement_fails(): void
    {
        $db = new DeleteAllDbDouble();
        $db->failOn = 'DELETE FROM catalogo_articulo_opcional;';

        $this->assertFalse($this->model($db)->delete_all());
        $this->assertSame('rollback', end($db->log));
        $this->assertNotContains('DELETE FROM catalogo_opcionales;', $db->log);
    }

    public function test_view_shows_the_button_only_to_admins_and_posts_with_csrf(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/View/ventas_opcionales.html.twig');
        $start = strpos($view, '{% if fsc.allow_delete_all %}');
        $this->assertNotFalse($start);
        $block = substr($view, $start, strpos($view, '{% endif %}', $start) - $start);

        $this->assertStringContainsString('name="delete_all_opcionales"', $block);
        $this->assertStringContainsString('{{ csrf_field() }}', $block);
        $this->assertStringContainsString('method="post"', $block);
        $this->assertStringContainsString('name="confirmacion"', $block);
    }

    public function test_list_is_searched_again_after_a_mutation(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcionales.php');
        $start = strpos($src, 'public function privateCore(');
        $body = substr($src, $start, strpos($src, 'private function loadExtensions(') - $start);

        $init = strpos($body, 'init_opcionales_list()');
        $last = max(strpos($body, 'delete_all_opcionales()'), strpos($body, 'delete_opcional()'), strpos($body, 'new_opcional()'));
        $research = strrpos($body, 'search_opcionales()');

        $this->assertNotFalse($init);
        $this->assertNotFalse($research);
        $this->assertGreaterThan($last, $research, 'after deleting or creating, the list is searched again: otherwise the page shows the rows that were just deleted');
        $this->assertGreaterThan($research, strrpos($body, 'load_grupos_cache()'));
    }

    private function model(DeleteAllDbDouble $db): catalogo_opcional
    {
        $model = (new \ReflectionClass(catalogo_opcional::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionProperty(\fs_model::class, 'db');
        $ref->setValue($model, $db);
        $ref = new \ReflectionProperty(\fs_model::class, 'table_name');
        $ref->setValue($model, 'catalogo_opcionales');

        return $model;
    }

    /** @param array<string,string> $post */
    private function controller(bool $admin, array $post, bool $token = true, string $method = 'POST'): object
    {
        $c = new class extends \FSFramework\Plugins\catalogo_core\Controller\VentasOpcionales {
    /** @var mixed */
    public $user;
    public bool $token = true;
    public DeleteAllStoreDouble $store;
    /** @var list<string> */
    public array $messages = [];
    /** @var list<string> */
    public array $errors = [];

    public function __construct()
    {
        $this->store = new DeleteAllStoreDouble();
    }

    public function run(): void
    {
        if ($this->request->request->has('delete_all_opcionales')) {
            $this->delete_all_opcionales();
        }
    }

    protected function opcional_model()
    {
        return $this->store;
    }

    protected function validateFormToken(): bool
    {
        return $this->token;
    }

    public function new_message(string $msg): void
    {
        $this->messages[] = $msg;
    }

    public function new_error_msg(string $msg): void
    {
        $this->errors[] = $msg;
    }
};
        $c->user = (object) ['admin' => $admin];
        $c->token = $token;
        $c->request = Request::create('/index.php?page=ventas_opcionales', $method, $post);

        return $c;
    }
}

final class DeleteAllStoreDouble
{
    public int $calls = 0;
    /** @var int|false */
    public $result = 3;

    /** @return int|false */
    public function delete_all()
    {
        $this->calls++;

        return $this->result;
    }
}

final class DeleteAllDbDouble
{
    /** @var list<string> */
    public array $log = [];
    public ?string $failOn = null;

    public function table_exists(string $table): bool
    {
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function select(string $sql): array
    {
        return [['total' => 7]];
    }

    public function exec(string $sql): bool
    {
        $this->log[] = $sql;

        return $sql !== $this->failOn;
    }

    public function begin_transaction(): void
    {
        $this->log[] = 'begin';
    }

    public function commit(): void
    {
        $this->log[] = 'commit';
    }

    public function rollback(): void
    {
        $this->log[] = 'rollback';
    }
}
