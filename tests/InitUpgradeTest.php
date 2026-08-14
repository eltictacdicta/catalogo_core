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
}
