<?php

declare(strict_types=1);

namespace FSFramework\model;

require_once __DIR__ . '/../../../base/fs_model.php';

/**
 * Stubs loaded before Init::upgrade() in InitUpgradeTest.
 *
 * @internal
 */
abstract class CatalogoCoreSeedStub extends \fs_model
{
    public static int $seedCalls = 0;

    public function __construct()
    {
        $this->table_name = 'stub';
        $this->db = new class {
            public function table_exists(string $name): bool
            {
                return true;
            }

            public function select(string $sql): array
            {
                return [];
            }

            public function exec(string $sql, $transaction = null, array $params = [], bool $isBatch = false): bool
            {
                return true;
            }
        };
    }

    public function delete() { return false; }
    public function exists() { return false; }
    public function save() { return false; }

    public function seed_if_empty(): bool
    {
        self::$seedCalls++;

        return parent::seed_if_empty();
    }

    protected function install()
    {
        return 'INSERT INTO stub (id) VALUES (1);';
    }
}

class impuesto extends CatalogoCoreSeedStub
{
    public function __construct()
    {
        parent::__construct();
        $this->table_name = 'impuestos';
    }
}

class familia extends CatalogoCoreSeedStub
{
    public function __construct()
    {
        parent::__construct();
        $this->table_name = 'familias';
    }
}

class fabricante extends CatalogoCoreSeedStub
{
    public function __construct()
    {
        parent::__construct();
        $this->table_name = 'fabricantes';
    }
}

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Init;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InitUpgradeTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../Init.php';
        \FSFramework\model\CatalogoCoreSeedStub::$seedCalls = 0;
    }

    #[Test]
    public function upgradeSeedsDefaultCatalogModels(): void
    {
        Init::upgrade();

        $this->assertSame(
            3,
            \FSFramework\model\CatalogoCoreSeedStub::$seedCalls,
            'Init::upgrade() must seed impuesto, familia and fabricante'
        );
    }

    /**
     * Multitarifa task 2.4 (D5): FK install ordering inside
     * ensureCatalogTables — the definition table precedes each dependent
     * (listas → articulo_precios → grupo → group members).
     */
    #[Test]
    public function ensureCatalogTablesOrdersListsBeforeDependents(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/Init.php');

        $expectedOrder = [
            'catalogo_lista_precio',
            'catalogo_articulo_precio',
            'catalogo_grupo',
            'catalogo_grupo_roles',
            'catalogo_grupo_usuarios',
            'catalogo_grupo_tarifas',
            'catalogo_grupo_articulos',
        ];

        $positions = [];
        foreach ($expectedOrder as $name) {
            $positions[$name] = mb_strpos($source, "'" . $name . "'");
            $this->assertNotFalse($positions[$name], "Init.php must ensure the $name table");
        }

        for ($i = 1; $i < count($expectedOrder); $i++) {
            $this->assertLessThan(
                $positions[$expectedOrder[$i]],
                $positions[$expectedOrder[$i - 1]],
                $expectedOrder[$i - 1] . ' must be ensured before ' . $expectedOrder[$i]
                . ' (FK definitions first, dependents after)'
            );
        }
    }

    /**
     * Familias-jerarquia task 1.4 (D4, R-MT-006): FK ordering must place
     * catalogo_listas_precio before catalogo_familia_estructura, and that in
     * turn before catalogo_articulo_familia_estructura (both depend on the
     * lists table; the structure tables depend on each other's parents).
     */
    #[Test]
    public function ensureCatalogTablesOrdersStructureTablesAfterLists(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/Init.php');

        $expectedOrder = [
            'catalogo_lista_precio',
            'catalogo_familia_estructura',
            'catalogo_articulo_familia_estructura',
        ];

        $positions = [];
        foreach ($expectedOrder as $name) {
            $positions[$name] = mb_strpos($source, "'" . $name . "'");
            $this->assertNotFalse($positions[$name], "Init.php must ensure the $name table");
        }

        for ($i = 1; $i < count($expectedOrder); $i++) {
            $this->assertLessThan(
                $positions[$expectedOrder[$i]],
                $positions[$expectedOrder[$i - 1]],
                $expectedOrder[$i - 1] . ' must be ensured before ' . $expectedOrder[$i]
                . ' (structure install depends on lists)'
            );
        }
    }

    /**
     * Multitarifa task 2.4 (D4): Init::init() must register the first-party
     * GroupPermissionListener unconditionally on the permission filter event.
     */
    #[Test]
    public function initRegistersGroupPermissionListenerOnFilterEvent(): void
    {
        require_once FS_FOLDER . '/base/fs_functions.php';
        \FSFramework\Event\FSEventDispatcher::reset();

        try {
            (new Init())->init();

            $listeners = \FSFramework\Event\FSEventDispatcher::getInstance()
                ->getListeners(\FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent::NAME);

            $found = false;
            foreach ($listeners as $listener) {
                if ($listener instanceof \FSFramework\Plugins\catalogo_core\Services\GroupPermissionListener) {
                    $found = true;
                    break;
                }
            }

            $this->assertTrue(
                $found,
                'Init::init() must register GroupPermissionListener on ArticlePermissionFilterEvent::NAME'
            );
        } finally {
            \FSFramework\Event\FSEventDispatcher::reset();
        }
    }
}
