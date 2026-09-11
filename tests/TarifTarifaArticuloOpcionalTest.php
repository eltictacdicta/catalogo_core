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
 * Override-sync contract for the moved `tarif_tarifa_opcional_resolver`
 * (spec "Override sync persists the effective set", design D6).
 *
 * `sync_articulo_overrides` writes one per-tarifa override row per base
 * article relation with activation counts; `sync_familia_overrides` fans the
 * per-article sync out over the tarifa's article list and aggregates the
 * counts. The resolver factory seam and a spy DB keep the tests off the live
 * database.
 */
final class ArticuloOpcionalSpyDb
{
    /** @var list<string> */
    public array $execStatements = [];

    /** @var list<string> */
    public array $selectStatements = [];

    /** @var list<array<int, mixed>> */
    public array $selectParams = [];

    /** @var list<array<int, array<string, mixed>>> */
    public array $selectQueue = [];

    public bool $tableExists = true;

    public function table_exists($name, $list = false)
    {
        return $this->tableExists;
    }

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

    public function select($sql, $params = [])
    {
        $this->selectStatements[] = trim((string) $sql);
        $this->selectParams[] = is_array($params) ? $params : [];

        if (empty($this->selectQueue)) {
            return [];
        }

        return array_shift($this->selectQueue);
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $this->execStatements[] = trim((string) $sql);

        return true;
    }
}

final class TarifTarifaArticuloOpcionalTest extends TestCase
{
    private const RESOLVER_RELATIVE = 'plugins/catalogo_core/model/tarif_tarifa_opcional_resolver.php';
    private const ARTICULO_RELATIVE = 'plugins/catalogo_core/model/tarif_tarifa_articulo_opcional.php';

    private static bool $baseLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/' . self::ARTICULO_RELATIVE;
        require_once FS_FOLDER . '/' . self::RESOLVER_RELATIVE;
        self::$baseLoaded = true;
    }

    private function buildFakeModel(ArticuloOpcionalSpyDb $db): object
    {
        return new class($db) extends \FSFramework\model\tarif_tarifa_articulo_opcional {
            public function __construct($db)
            {
                $this->db = $db;
                $this->table_name = 'tarif_tarifa_articulo_opcional';
            }
        };
    }

    /**
     * @param array<int, object> $efectivos
     */
    private function buildSyncResolver(
        ArticuloOpcionalSpyDb $db,
        object $fakeModel,
        array $efectivos
    ): object {
        return new class($db, $fakeModel, $efectivos) extends \FSFramework\model\tarif_tarifa_opcional_resolver {
            /** @var array<int, object> */
            private array $efectivos;

            private object $fakeModel;

            public function __construct($db, object $fakeModel, array $efectivos)
            {
                $this->db = $db;
                $this->fakeModel = $fakeModel;
                $this->efectivos = $efectivos;
            }

            public function get_opcionales_activos_articulo($codtarifa, $codfamilia, $referencia)
            {
                return $this->efectivos;
            }

            protected function tarifa_articulo_opcional_model(): \FSFramework\model\tarif_tarifa_articulo_opcional
            {
                return $this->fakeModel;
            }
        };
    }

    /**
     * @param list<string> $statements
     * @return list<string>
     */
    private function statementsStartingWith(array $statements, string $prefix): array
    {
        return array_values(array_filter(
            $statements,
            static fn(string $sql): bool => str_starts_with(ltrim($sql), $prefix)
        ));
    }

    private function assertFactorySeamExists(): void
    {
        if (!method_exists(\FSFramework\model\tarif_tarifa_opcional_resolver::class, 'tarifa_articulo_opcional_model')) {
            self::fail('missing factory seam: tarif_tarifa_opcional_resolver::tarifa_articulo_opcional_model()');
        }
    }

    // =====================================================================
    // sync_articulo_overrides
    // =====================================================================

    public function test_sync_articulo_overrides_persists_the_effective_set_with_counts(): void
    {
        $this->assertFactorySeamExists();

        $db = new ArticuloOpcionalSpyDb();
        $db->selectQueue = [
            [['id_opcional' => 10], ['id_opcional' => 11]],
            [],
            [],
        ];

        $effective = (object) ['id' => 10];
        $resolver = $this->buildSyncResolver($db, $this->buildFakeModel($db), [$effective]);

        $stats = $resolver->sync_articulo_overrides('T1', 'F1', 'ART1');

        $this->assertSame(['procesados' => 2, 'activados' => 1, 'desactivados' => 1], $stats);

        $inserts = $this->statementsStartingWith($db->execStatements, 'INSERT');
        $this->assertCount(2, $inserts, 'one override row per base article relation');

        $activeRow = array_values(array_filter(
            $inserts,
            static fn(string $sql): bool => str_contains($sql, '10')
        ));
        $disabledRow = array_values(array_filter(
            $inserts,
            static fn(string $sql): bool => str_contains($sql, '11')
        ));

        $this->assertCount(1, $activeRow);
        $this->assertCount(1, $disabledRow);
        $this->assertMatchesRegularExpression(
            "/VALUES\\s*\\(\\s*'T1',\\s*'ART1',\\s*10,\\s*1,\\s*0\\s*\\)/",
            $activeRow[0]
        );
        $this->assertMatchesRegularExpression(
            "/VALUES\\s*\\(\\s*'T1',\\s*'ART1',\\s*11,\\s*0,\\s*0\\s*\\)/",
            $disabledRow[0]
        );
    }

    public function test_sync_articulo_overrides_short_circuits_without_base_relations(): void
    {
        $this->assertFactorySeamExists();

        $db = new ArticuloOpcionalSpyDb();
        $db->selectQueue = [[]];

        $resolver = $this->buildSyncResolver($db, $this->buildFakeModel($db), []);

        $stats = $resolver->sync_articulo_overrides('T1', 'F1', 'ART1');

        $this->assertSame(['procesados' => 0, 'activados' => 0, 'desactivados' => 0], $stats);
        $this->assertSame([], $db->execStatements, 'no override row is written without base relations');
    }

    // =====================================================================
    // sync_familia_overrides
    // =====================================================================

    public function test_sync_familia_overrides_aggregates_article_counts(): void
    {
        $db = new ArticuloOpcionalSpyDb();
        $db->selectQueue = [
            [['referencia' => 'ART1'], ['referencia' => 'ART2']],
        ];

        $resolver = new class($db) extends \FSFramework\model\tarif_tarifa_opcional_resolver {
            /** @var list<string> */
            public array $syncedRefs = [];

            public function __construct($db)
            {
                $this->db = $db;
            }

            public function sync_articulo_overrides($codtarifa, $codfamilia, $referencia)
            {
                $this->syncedRefs[] = $referencia;

                return ['procesados' => 1, 'activados' => 1, 'desactivados' => 0];
            }
        };

        $stats = $resolver->sync_familia_overrides('T1', 'F1');

        $this->assertSame(
            ['articulos' => 2, 'procesados' => 2, 'activados' => 2, 'desactivados' => 0],
            $stats
        );
        $this->assertSame(['ART1', 'ART2'], $resolver->syncedRefs);

        $this->assertCount(1, $db->selectStatements);
        $this->assertStringContainsString('tarif_tarifa_articulo', $db->selectStatements[0]);
        $this->assertStringContainsString('?', $db->selectStatements[0]);
        $this->assertSame(['T1', 'F1'], $db->selectParams[0]);
    }

    public function test_sync_familia_overrides_short_circuits_without_the_article_table(): void
    {
        $db = new ArticuloOpcionalSpyDb();
        $db->tableExists = false;

        $resolver = new class($db) extends \FSFramework\model\tarif_tarifa_opcional_resolver {
            public function __construct($db)
            {
                $this->db = $db;
            }
        };

        $stats = $resolver->sync_familia_overrides('T1', 'F1');

        $this->assertSame(
            ['articulos' => 0, 'procesados' => 0, 'activados' => 0, 'desactivados' => 0],
            $stats,
            'a missing tarif_tarifa_articulo table must return the empty stats'
        );
        $this->assertSame([], $db->selectStatements, 'no query may run when the table is absent');
    }
}
