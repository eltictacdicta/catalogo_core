<?php
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
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Dependency-fork contract for the moved hierarchical opcional configurator
 * (design D4, spec "Hierarchical configurator").
 *
 * The configurator lives in catalogo_core and MUST NOT load any tarifario PHP
 * class: the per-tarifa relations it consumed (`tarif_tarifa_articulo`,
 * `tarif_tarifa_articulo_etiqueta`, `tarif_articulo_precio`,
 * `tarif_articulos_ext`) are replaced by raw parameterized SQL against the
 * stable tarifario table names via declared consts plus private helpers
 * guarded by `db->table_exists()`.
 *
 * The additive relocation methods on the catalogo base classes hydrate the
 * moved `tarif_opcional` so the configurator/tarifario consumers keep their
 * pre-move behavior without a catalogo_core -> tarifario code dependency.
 */
final class ConfiguradorQuerySpyDb
{
    public string $lastSelect = '';

    /** @var list<mixed> */
    public array $lastParams = [];

    /** @var list<string> */
    public array $askedTables = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public bool $tableExists = true;

    public function var2str($val)
    {
        if ($val === null) {
            return 'NULL';
        }
        if (is_bool($val)) {
            return $val ? '1' : '0';
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function escape_string(string $str): string
    {
        return addslashes($str);
    }

    public function table_exists($name, $list = false)
    {
        $this->askedTables[] = (string) $name;

        return $this->tableExists;
    }

    public function select($sql, $params = [])
    {
        $this->lastSelect = trim((string) $sql);
        $this->lastParams = is_array($params) ? $params : [];

        return $this->rows;
    }

    public function select_limit($sql, $limit = 50, $offset = 0)
    {
        return $this->select($sql);
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        return true;
    }
}

final class TarifConfiguradorOpcionalesTest extends TestCase
{
    private const CONTROLLER_RELATIVE = 'plugins/catalogo_core/controller/tarif_configurador_opcionales.php';
    private const RESOLVER_RELATIVE = 'plugins/catalogo_core/model/tarif_tarifa_opcional_resolver.php';
    private const ARTICULO_OPCIONAL_RELATIVE = 'plugins/catalogo_core/model/core/catalogo_articulo_opcional.php';
    private const OPCIONAL_FAMILIA_RELATIVE = 'plugins/catalogo_core/model/core/catalogo_opcional_familia.php';

    private static bool $baseLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
        self::$baseLoaded = true;
    }

    private function controllerSource(): string
    {
        $path = FS_FOLDER . '/' . self::CONTROLLER_RELATIVE;
        if (!is_file($path)) {
            self::fail('missing catalogo_core path: ' . self::CONTROLLER_RELATIVE);
        }

        return (string) file_get_contents($path);
    }

    private function resolverSource(): string
    {
        $path = FS_FOLDER . '/' . self::RESOLVER_RELATIVE;
        if (!is_file($path)) {
            self::fail('missing catalogo_core path: ' . self::RESOLVER_RELATIVE);
        }

        return (string) file_get_contents($path);
    }

    private function loadControllerClass(): void
    {
        if (class_exists('tarif_configurador_opcionales', false)) {
            return;
        }

        if (!is_file(FS_FOLDER . '/' . self::CONTROLLER_RELATIVE)) {
            self::fail('missing catalogo_core path: ' . self::CONTROLLER_RELATIVE);
        }

        require_once FS_FOLDER . '/' . self::CONTROLLER_RELATIVE;
    }

    private function buildController(object $db): object
    {
        return new class($db) extends \tarif_configurador_opcionales {
            public function __construct($db)
            {
                // Skip the heavy fs_controller constructor: only the D4 helper
                // seams and the action dispatch are exercised.
                $this->db = $db;
            }
        };
    }

    private function invokePrivate(object $subject, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($subject, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($subject, $args);
    }

    // =====================================================================
    // D4 fork: zero tarifario PHP class dependency
    // =====================================================================

    public function test_configurator_has_zero_tarifario_requires_and_uses(): void
    {
        $src = $this->controllerSource();

        $this->assertStringNotContainsString(
            'plugins/tarifario/',
            $src,
            'the moved configurator must not require any plugin/tarifario/ file (design D4)'
        );
        $this->assertStringNotContainsString(
            'use FSFramework\\model\\tarif_tarifa_articulo;',
            $src,
            'tarif_tarifa_articulo must be forked off the moved configurator'
        );
        $this->assertStringNotContainsString(
            'use FSFramework\\model\\tarif_tarifa_articulo_etiqueta;',
            $src,
            'tarif_tarifa_articulo_etiqueta must be forked off the moved configurator'
        );
        $this->assertStringNotContainsString(
            'new tarif_tarifa_articulo(',
            $src,
            'no tarif_tarifa_articulo instantiation may remain'
        );
        $this->assertStringNotContainsString(
            'new tarif_tarifa_articulo_etiqueta(',
            $src,
            'no tarif_tarifa_articulo_etiqueta instantiation may remain'
        );
        $this->assertStringNotContainsString(
            'new tarif_articulo(',
            $src,
            'the article model must be catalogo_core articulo'
        );
        $this->assertStringNotContainsString(
            'new tarif_articulos_ext(',
            $src,
            'tarif_articulos_ext must be dropped from the moved configurator'
        );
    }

    public function test_configurator_declares_the_fork_consts(): void
    {
        $src = $this->controllerSource();

        foreach ([
            'TARIFA_ARTICULO_TABLE' => 'tarif_tarifa_articulo',
            'TARIFA_ARTICULO_ETIQUETA_TABLE' => 'tarif_tarifa_articulo_etiqueta',
            'TARIFA_ARTICULO_PRECIO_TABLE' => 'tarif_articulo_precios',
        ] as $const => $value) {
            $this->assertMatchesRegularExpression(
                '/const\s+' . preg_quote($const, '/') . "\s*=\s*'" . preg_quote($value, '/') . "'/",
                $src,
                'the moved configurator must declare const ' . $const . " = '" . $value . "'"
            );
        }
    }

    public function test_configurator_declares_the_private_sql_helpers(): void
    {
        $src = $this->controllerSource();

        foreach (['articulos_from_familia', 'etiquetas_articulo', 'precio_articulo_tarifa'] as $helper) {
            $this->assertMatchesRegularExpression(
                '/private function ' . $helper . '\s*\(/',
                $src,
                'the moved configurator must declare private helper ' . $helper . '()'
            );
        }

        $this->assertStringContainsString(
            'table_exists(',
            $src,
            'the D4 helpers must be guarded by db->table_exists() so standalone catalogo_core never fatals'
        );
        $this->assertStringContainsString(
            'precio_articulo_tarifa(',
            $src,
            'precio_en_tarifa() call sites must be repointed to precio_articulo_tarifa()'
        );
    }

    public function test_configurator_all_from_familia_call_sites_use_the_helper(): void
    {
        $src = $this->controllerSource();

        $this->assertStringNotContainsString(
            'all_from_familia',
            $src,
            'no tarif_tarifa_articulo::all_from_familia() call may remain'
        );
        $this->assertStringNotContainsString(
            'get_etiquetas_articulo',
            $src,
            'no tarif_tarifa_articulo_etiqueta::get_etiquetas_articulo() call may remain'
        );
        $this->assertStringContainsString(
            'get_opcionales_directos_from_articulo(',
            $src,
            'the configurator direct-only read must use the additive relocation method'
        );
    }

    // =====================================================================
    // Hierarchical configurator: roots + htmx_tree fragment + push actions
    // =====================================================================

    public function test_configurator_exposes_the_hierarchy_actions(): void
    {
        $src = $this->controllerSource();

        foreach ([
            'htmx_tree',
            'push_to_products',
            'push_to_tags',
            'push_to_children',
            'push_all_down',
            'search_opcionales',
            'create_opcional',
            'edit_opcional',
        ] as $action) {
            $this->assertStringContainsString(
                "case '" . $action . "':",
                $src,
                'action ' . $action . ' must stay exposed by the configurator dispatch'
            );
        }
    }

    public function test_htmx_tree_renders_the_tree_fragment(): void
    {
        $src = $this->controllerSource();

        $this->assertMatchesRegularExpression(
            '/private function htmx_tree\s*\(/',
            $src,
            'htmx_tree must stay a private endpoint'
        );
        $this->assertStringContainsString(
            "\$this->template = 'tarif_configurador_tree';",
            $src,
            'htmx_tree must render the moved tree fragment'
        );
        $this->assertStringContainsString(
            'load_familias_raiz',
            $src,
            'the configurator must still load the root familias for the full page'
        );
    }

    public function test_push_actions_return_json_payloads(): void
    {
        $src = $this->controllerSource();

        foreach ([
            'ajax_push_to_products',
            'ajax_push_to_tags',
            'ajax_push_to_children',
            'ajax_push_all_down',
        ] as $method) {
            $this->assertMatchesRegularExpression(
                '/private function ' . $method . '\s*\(/',
                $src,
                $method . '() must exist'
            );
        }

        $this->assertGreaterThanOrEqual(
            3,
            substr_count($src, "json_encode(['success' => true, 'count' => \$count])"),
            'push_to_products/push_to_tags/push_to_children must return the {success,count} JSON payload'
        );
        $this->assertStringContainsString(
            "json_encode(['success' => true, 'count' => \$total])",
            $src,
            'push_all_down must return the {success,count} JSON payload'
        );
    }

    /**
     * Extracts a method body from source so assertions cannot be satisfied by
     * unrelated code elsewhere in the file.
     */
    private function methodBody(string $src, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{/';
        if (!preg_match($pattern, $src, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $matches[0][1] + strlen($matches[0][0]);
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

    public function test_mutating_actions_require_post_and_csrf(): void
    {
        $src = $this->controllerSource();

        $this->assertStringContainsString('MUTATING_ACTIONS', $src, 'the mutating action list must be declared');
        $this->assertStringContainsString('guard_mutating_action()', $src, 'mutating actions must be guarded');
        $this->assertStringContainsString('requireCsrf()', $src, 'the guard must enforce the framework CSRF token');
        $this->assertStringContainsString("!== 'POST'", $src, 'the guard must reject non-POST requests');

        $view = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/tarif_configurador_opcionales.html.twig'
        );
        $this->assertStringContainsString(
            "'X-CSRF-TOKEN': _csrfToken",
            $view,
            'the AJAX helper must send the CSRF token header'
        );
        $this->assertStringContainsString(
            '_mutatingActions[action]',
            $view,
            'the AJAX helper must branch on the mutating action list'
        );
        $this->assertStringContainsString("method: 'POST'", $view, 'the AJAX helper must use POST for mutations');
    }

    public function test_batch_push_to_products_resolves_article_tags_once(): void
    {
        $body = $this->methodBody($this->controllerSource(), 'batch_push_to_products');

        $this->assertNotSame('', $body, 'batch_push_to_products() body must be found');
        $this->assertSame(
            1,
            substr_count($body, 'etiquetas_articulo('),
            'article tags must be resolved once, outside the visible_ids loop'
        );
        $this->assertStringContainsString(
            '$matching_refs',
            $body,
            'the matching references must be collected once before the opcional loop'
        );
    }

    // =====================================================================
    // D4 helper behavior (fake DB)
    // =====================================================================

    public function test_articulos_from_familia_reads_parameterized_from_the_stable_table(): void
    {
        $this->loadControllerClass();
        self::assertTrue(
            method_exists(\tarif_configurador_opcionales::class, 'articulos_from_familia'),
            'missing configurator helper: articulos_from_familia'
        );

        $db = new ConfiguradorQuerySpyDb();
        $db->rows = [['referencia' => 'ART1'], ['referencia' => 'ART2']];
        $controller = $this->buildController($db);

        $result = $this->invokePrivate($controller, 'articulos_from_familia', ['T1', 'F1']);

        $this->assertCount(2, $result);
        $this->assertSame('ART1', $result[0]->referencia);
        $this->assertSame('ART2', $result[1]->referencia);
        $this->assertStringContainsString('tarif_tarifa_articulo', $db->lastSelect);
        $this->assertStringContainsString('?', $db->lastSelect);
        $this->assertSame(['T1', 'F1'], $db->lastParams);
    }

    public function test_articulos_from_familia_returns_empty_without_the_table(): void
    {
        $this->loadControllerClass();
        self::assertTrue(
            method_exists(\tarif_configurador_opcionales::class, 'articulos_from_familia'),
            'missing configurator helper: articulos_from_familia'
        );

        $db = new ConfiguradorQuerySpyDb();
        $db->tableExists = false;
        $controller = $this->buildController($db);

        $this->assertSame([], $this->invokePrivate($controller, 'articulos_from_familia', ['T1', 'F1']));
        $this->assertSame('', $db->lastSelect, 'no SQL must run when the table is absent');
    }

    public function test_etiquetas_articulo_reads_parameterized_from_the_stable_table(): void
    {
        $this->loadControllerClass();
        self::assertTrue(
            method_exists(\tarif_configurador_opcionales::class, 'etiquetas_articulo'),
            'missing configurator helper: etiquetas_articulo'
        );

        $db = new ConfiguradorQuerySpyDb();
        $db->rows = [['etiqueta' => 'ROJO'], ['etiqueta' => 'GRANDE']];
        $controller = $this->buildController($db);

        $result = $this->invokePrivate($controller, 'etiquetas_articulo', ['T1', 'ART1']);

        $this->assertSame(['ROJO', 'GRANDE'], $result);
        $this->assertStringContainsString('tarif_tarifa_articulo_etiqueta', $db->lastSelect);
        $this->assertStringContainsString('?', $db->lastSelect);
        $this->assertSame(['T1', 'ART1'], $db->lastParams);
    }

    public function test_precio_articulo_tarifa_reads_parameterized_price(): void
    {
        $this->loadControllerClass();
        self::assertTrue(
            method_exists(\tarif_configurador_opcionales::class, 'precio_articulo_tarifa'),
            'missing configurator helper: precio_articulo_tarifa'
        );

        $db = new ConfiguradorQuerySpyDb();
        $db->rows = [['precio' => '12.50']];
        $controller = $this->buildController($db);

        $result = $this->invokePrivate($controller, 'precio_articulo_tarifa', ['ART1', 'T1']);

        $this->assertSame(12.5, $result);
        $this->assertStringContainsString('tarif_articulo_precios', $db->lastSelect);
        $this->assertStringContainsString('?', $db->lastSelect);
        $this->assertSame(['ART1', 'T1'], $db->lastParams);
    }

    // =====================================================================
    // Additive relocation methods (design D4)
    // =====================================================================

    public function test_catalogo_articulo_opcional_exposes_the_direct_relocation_method(): void
    {
        require_once FS_FOLDER . '/' . self::ARTICULO_OPCIONAL_RELATIVE;
        self::assertTrue(
            method_exists(
                \FSFramework\model\catalogo_articulo_opcional::class,
                'get_opcionales_directos_from_articulo'
            ),
            'catalogo_articulo_opcional must expose get_opcionales_directos_from_articulo()'
        );

        $src = (string) file_get_contents(FS_FOLDER . '/' . self::ARTICULO_OPCIONAL_RELATIVE);
        $this->assertStringContainsString(
            'new tarif_opcional(',
            $src,
            'the additive relocation read must hydrate tarif_opcional'
        );
        $this->assertMatchesRegularExpression(
            '/get_opcionales_directos_from_articulo\s*\([^)]*\)\s*\n?\s*\{[^}]*\?/s',
            $src,
            'the additive relocation read must use parameterized SQL'
        );

        $db = new ConfiguradorQuerySpyDb();
        $db->rows = [];
        $model = new class($db) extends \FSFramework\model\catalogo_articulo_opcional {
            public function __construct($db)
            {
                $this->table_name = self::TABLE;
                $this->db = $db;
            }
        };

        $this->assertSame([], $model->get_opcionales_directos_from_articulo('ART1'));
        $this->assertStringContainsString(\FSFramework\model\catalogo_articulo_opcional::TABLE, $db->lastSelect);
        $this->assertStringContainsString('?', $db->lastSelect);
        $this->assertSame(['ART1'], $db->lastParams);
    }

    public function test_catalogo_opcional_familia_exposes_the_tarifario_relocation_method(): void
    {
        require_once FS_FOLDER . '/' . self::OPCIONAL_FAMILIA_RELATIVE;
        self::assertTrue(
            method_exists(
                \FSFramework\model\catalogo_opcional_familia::class,
                'get_opcionales_tarifario_from_familia'
            ),
            'catalogo_opcional_familia must expose get_opcionales_tarifario_from_familia()'
        );

        $src = (string) file_get_contents(FS_FOLDER . '/' . self::OPCIONAL_FAMILIA_RELATIVE);
        $this->assertStringContainsString(
            'new tarif_opcional(',
            $src,
            'the additive relocation read must hydrate tarif_opcional'
        );

        $db = new ConfiguradorQuerySpyDb();
        $db->rows = [];
        $model = new class($db) extends \FSFramework\model\catalogo_opcional_familia {
            public function __construct($db)
            {
                $this->table_name = self::TABLE;
                $this->db = $db;
            }
        };

        $this->assertSame([], $model->get_opcionales_tarifario_from_familia('F1'));
        $this->assertStringContainsString(\FSFramework\model\catalogo_opcional_familia::TABLE, $db->lastSelect);
        $this->assertStringContainsString('?', $db->lastSelect);
        $this->assertSame(['F1'], $db->lastParams);
    }

    // =====================================================================
    // Resolver fork (task 4.4)
    // =====================================================================

    public function test_resolver_forgoes_tarifario_classes(): void
    {
        $src = $this->resolverSource();

        $this->assertStringNotContainsString('plugins/tarifario/', $src);
        $this->assertStringNotContainsString(
            'new tarif_tarifa_articulo_etiqueta',
            $src,
            'the resolver install() must not instantiate the tarifario etiqueta class'
        );
        $this->assertMatchesRegularExpression(
            "/const\s+TARIFA_ARTICULO_ETIQUETA_TABLE\s*=\s*'tarif_tarifa_articulo_etiqueta'/",
            $src,
            'the resolver must declare const TARIFA_ARTICULO_ETIQUETA_TABLE'
        );
        $this->assertMatchesRegularExpression(
            '/private function etiquetas_articulo\s*\(/',
            $src,
            'the resolver must declare a private etiquetas_articulo() helper'
        );
        $this->assertStringContainsString(
            'table_exists(',
            $src,
            'the resolver helper must be guarded by db->table_exists()'
        );
    }

    public function test_resolver_etiquetas_helper_reads_parameterized(): void
    {
        require_once FS_FOLDER . '/' . self::RESOLVER_RELATIVE;
        self::assertTrue(
            method_exists(
                \FSFramework\model\tarif_tarifa_opcional_resolver::class,
                'etiquetas_articulo'
            ),
            'missing resolver helper: etiquetas_articulo'
        );

        $db = new ConfiguradorQuerySpyDb();
        $db->rows = [['etiqueta' => 'A'], ['etiqueta' => 'B']];
        $resolver = new class($db) extends \FSFramework\model\tarif_tarifa_opcional_resolver {
            public function __construct($db)
            {
                $this->db = $db;
            }
        };

        $result = $this->invokePrivate($resolver, 'etiquetas_articulo', ['T1', 'ART1']);

        $this->assertSame(['A', 'B'], $result);
        $this->assertStringContainsString('tarif_tarifa_articulo_etiqueta', $db->lastSelect);
        $this->assertStringContainsString('?', $db->lastSelect);
        $this->assertSame(['T1', 'ART1'], $db->lastParams);
    }
}
