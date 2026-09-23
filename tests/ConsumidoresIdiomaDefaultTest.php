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
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Controller\VentasOpcional;
use FSFramework\Plugins\catalogo_core\Model\CatalogoApiService;
use PHPUnit\Framework\TestCase;
use Tests\CatalogoCore\Support\FakeArticulo;
use Tests\CatalogoCore\Support\IdiomaRegistryFake;

/**
 * GDI-10 (slice 4c) — every `catalogo_core` consumer with no language context
 * resolves the configured default language (D10). Each reader here has no
 * `codidioma`, so the read chain is requested -> configured default ->
 * `articulos.descripcion` -> ''.
 *
 * The configured default is deliberately `en` (never `es`) in every scenario, so
 * a hard-coded `es`, a base-column-only read, or a missing join fails the test.
 * The raw-SQL readers resolve the default code in PHP (a protected seam) and
 * interpolate it through `var2str`, the same quoting style the queries already
 * use for every other value.
 */
final class ConsumidoresIdiomaDefaultTest extends TestCase
{
    private const DESC_SELECT = 'COALESCE(d.descripcion, a.descripcion) AS descripcion';
    private const DESC_JOIN = 'LEFT JOIN articulo_descripciones d ON d.referencia = a.referencia';

    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Model/CatalogoApiService.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcional.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa_articulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_articulo_precio.php';
        require_once __DIR__ . '/Support/FakeArticulo.php';
    }

    /**
     * Configured default = `en`, plus an active `es` carrying different text. The
     * fake driver understands the language registry and the description table, so
     * the real read chain executes with no database.
     */
    private function registry(bool $withDefaultRow = true): IdiomaRegistryFake
    {
        $descripciones = [
            ['referencia' => 'A1', 'codidioma' => 'es', 'descripcion' => 'Camiseta ES'],
        ];
        if ($withDefaultRow) {
            array_unshift($descripciones, [
                'referencia' => 'A1',
                'codidioma' => 'en',
                'descripcion' => 'T-shirt EN',
            ]);
        }

        return new IdiomaRegistryFake(
            [
                ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => true],
                ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => false],
            ],
            $descripciones
        );
    }

    private function article(IdiomaRegistryFake $db, string $descripcion): FakeArticulo
    {
        $article = new FakeArticulo($db, 'A1', $descripcion);
        $article->publico = true;

        return $article;
    }

    /**
     * Article reader double: the public `search()` is replaced, so the consumer
     * never touches a database.
     *
     * @param list<object> $articles
     */
    private function articleModel(array $articles): object
    {
        return new class($articles) extends \FSFramework\model\articulo {
            /** @var list<object> */
            private array $articles;

            /** @param list<object> $articles */
            public function __construct(array $articles)
            {
                $this->articles = $articles;
            }

            public function search($query = '', $offset = 0, $codfamilia = '', $con_stock = FALSE, $codfabricante = '', $bloqueados = FALSE)
            {
                return $this->articles;
            }
        };
    }

    private function apiService(object $model): CatalogoApiService
    {
        return new class($model) extends CatalogoApiService {
            public function __construct(private object $articleModel)
            {
            }

            protected function articulo_model()
            {
                return $this->articleModel;
            }
        };
    }

    private function opcionalController(object $model): VentasOpcional
    {
        return new class($model) extends VentasOpcional {
            public function __construct(private object $articleModel)
            {
            }

            protected function articulo_model()
            {
                return $this->articleModel;
            }

            /** @return array<int, array<string, string>> */
            public function suggestions(string $query): array
            {
                return $this->buscarSugerencias($query);
            }
        };
    }

    /**
     * Recording engine for the raw-SQL readers: it keeps the last statement and
     * returns no row, so the model skips hydration while the emitted SQL stays
     * observable.
     */
    private function recordingDb(): object
    {
        return new class() {
            public string $lastSql = '';

            public function var2str($val): string
            {
                if ($val === null) {
                    return 'NULL';
                }
                if (is_bool($val)) {
                    return $val ? 'TRUE' : 'FALSE';
                }
                if (is_int($val) || is_float($val)) {
                    return (string) $val;
                }

                return "'" . str_replace("'", "''", (string) $val) . "'";
            }

            public function select($sql, $params = []): array
            {
                $this->lastSql = (string) $sql;

                return [];
            }

            public function select_limit($sql, $limit = 0, $offset = 0, $params = []): array
            {
                $this->lastSql = (string) $sql;

                return [];
            }
        };
    }

    /**
     * The model seam is overridden with the configured default `en`, so the
     * emitted join must carry `en` and never a hard-coded `es`.
     */
    private function tarifaModel(object $db): object
    {
        return new class($db) extends \FSFramework\model\tarif_tarifa_articulo {
            public function __construct($db)
            {
                $this->table_name = 'tarif_tarifa_articulo';
                $this->db = $db;
            }

            protected function default_codidioma(): string
            {
                return 'en';
            }
        };
    }

    private function precioModel(object $db): object
    {
        return new class($db) extends \FSFramework\model\tarif_articulo_precio {
            public function __construct($db)
            {
                $this->table_name = 'tarif_articulo_precios';
                $this->db = $db;
            }

            protected function default_codidioma(): string
            {
                return 'en';
            }
        };
    }

    public function test_api_serialises_the_configured_default_language(): void
    {
        $service = $this->apiService($this->articleModel([
            $this->article($this->registry(), 'Base A1'),
        ]));

        $rows = $service->getArticulos();

        $this->assertCount(1, $rows);
        $this->assertSame(
            'T-shirt EN',
            $rows[0]['descripcion'],
            'the public API has no language context and must resolve the configured default (en)'
        );
    }

    public function test_api_falls_back_to_the_base_column_when_the_default_row_is_absent(): void
    {
        $service = $this->apiService($this->articleModel([
            $this->article($this->registry(false), 'Base A1'),
        ]));

        $rows = $service->getArticulos();

        $this->assertSame(
            'Base A1',
            $rows[0]['descripcion'],
            'the frozen base column stays the last-resort fallback (never another language)'
        );
    }

    public function test_opcional_search_returns_the_configured_default_language(): void
    {
        $controller = $this->opcionalController($this->articleModel([
            $this->article($this->registry(), 'Base A1'),
        ]));

        $suggestions = $controller->suggestions('tshirt');

        $this->assertCount(1, $suggestions);
        $this->assertSame(
            'T-shirt EN',
            $suggestions[0]['descripcion'],
            'the opcional autocomplete must resolve the configured default (en)'
        );
        $this->assertSame(
            'A1 - T-shirt EN',
            $suggestions[0]['value'],
            'the suggestion label must be built from the resolved description'
        );
    }

    public function test_articulo_precio_reader_joins_the_configured_default_language(): void
    {
        $db = $this->recordingDb();
        $this->precioModel($db)->all_by_familias('T1');

        $sql = $db->lastSql;

        $this->assertStringContainsString(self::DESC_JOIN, $sql, 'all_by_familias() must join the description table');
        $this->assertStringContainsString(self::DESC_SELECT, $sql, 'the description must fall back to the base column');
        $this->assertStringContainsString("d.codidioma = 'en'", $sql, 'the join must use the configured default');
        $this->assertStringNotContainsString('SELECT ap.*, a.descripcion,', $sql, 'the bare base column must be gone');
        $this->assertStringNotContainsString("d.codidioma = 'es'", $sql, 'the default must never be hard-coded');
    }

    public function test_tarifa_articulo_readers_join_the_configured_default_language(): void
    {
        $db = $this->recordingDb();
        $model = $this->tarifaModel($db);

        $calls = [
            'get' => static function ($m): void {
                $m->get('T1', 'A1');
            },
            'all_from_tarifa' => static function ($m): void {
                $m->all_from_tarifa('T1');
            },
            'all_from_familia' => static function ($m): void {
                $m->all_from_familia('T1', 'F1');
            },
            'all_en_tarifa' => static function ($m): void {
                $m->all_en_tarifa('T1');
            },
            'all_en_catalogo' => static function ($m): void {
                $m->all_en_catalogo('T1');
            },
            'search' => static function ($m): void {
                $m->search('T1', 'mesa');
            },
        ];

        foreach ($calls as $name => $call) {
            $db->lastSql = '';
            $call($model);
            $sql = $db->lastSql;

            $this->assertStringContainsString(self::DESC_JOIN, $sql, $name . '() must join the description table');
            $this->assertStringContainsString(self::DESC_SELECT, $sql, $name . '() must fall back to the base column');
            $this->assertStringContainsString("d.codidioma = 'en'", $sql, $name . '() must use the configured default');
            $this->assertStringNotContainsString('a.descripcion,', $sql, $name . '() must not select the bare base column');
            $this->assertStringNotContainsString("d.codidioma = 'es'", $sql, $name . '() must never hard-code the locale');
        }
    }

    public function test_tarifa_articulo_search_matches_the_resolved_description(): void
    {
        $db = $this->recordingDb();
        $this->tarifaModel($db)->search('T1', 'mesa');

        $this->assertStringContainsString(
            'lower(COALESCE(d.descripcion, a.descripcion)) LIKE',
            $db->lastSql,
            'the search must match the resolved description, not only the frozen base column'
        );
    }

    public function test_quick_create_keeps_writing_only_the_base_column(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulos.php');

        $this->assertStringContainsString(
            '$art->descripcion = $descripcion;',
            $source,
            'quick-create deliberately seeds the frozen legacy base column (D-13)'
        );
        $this->assertStringNotContainsString(
            'set_descripcion_idioma',
            $source,
            'quick-create must not write a language row: no language exists at create time'
        );
        $this->assertStringNotContainsString(
            'articulo_descripcion',
            $source,
            'quick-create must not touch the multi-language description table'
        );
    }

    /**
     * The shared opcional trait was already correct before this slice; this gate
     * freezes that and fails if the accessors regress to the base-column
     * truncator.
     */
    public function test_opcional_state_trait_already_reads_through_the_language_api(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php');

        $this->assertStringContainsString(
            'descripcion_idioma($this->codidioma, 50)',
            $source,
            'the trait must keep resolving the truncated description through the language API'
        );
        $this->assertStringContainsString(
            'get_descripcion_idioma($this->codidioma)',
            $source,
            'the trait must keep resolving the full description through the language API'
        );
        $this->assertStringNotContainsString(
            '->descripcion(50)',
            $source,
            'the base-column truncator must not come back'
        );
    }
}
