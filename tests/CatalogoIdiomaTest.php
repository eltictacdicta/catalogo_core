<?php
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\model\catalogo_idioma;
use FSFramework\model\catalogo_lista_precio;
use PHPUnit\Framework\TestCase;

final class CatalogoIdiomaTest extends TestCase
{
    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
    }

    public function test_install_seeds_es_and_en(): void
    {
        $model = $this->makeBareModel();

        $sql = $model->install();

        $this->assertStringContainsString("'es', 'Español'", $sql);
        $this->assertStringContainsString("'en', 'English'", $sql);
    }

    public function test_ensure_defaults_skips_when_table_has_rows(): void
    {
        $model = $this->makeBareModel();
        $model->db = new class {
            public int $execCalls = 0;

            public function select(string $sql): array
            {
                return [['total' => '2']];
            }

            public function exec(string $sql): bool
            {
                $this->execCalls++;
                return true;
            }
        };

        $model->ensure_defaults();

        $this->assertSame(0, $model->db->execCalls);
    }

    private function makeBareModel(): catalogo_idioma
    {
        return new class() extends catalogo_idioma {
            public $db;

            public function __construct()
            {
                $this->table_name = catalogo_idioma::TABLE;
            }

            public function delete() { return false; }
            public function exists() { return false; }
            public function save() { return false; }

            public function install(): string
            {
                return parent::install();
            }
        };
    }
}

final class CatalogoListaPrecioTest extends TestCase
{
    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
    }

    public function test_install_seeds_default_list(): void
    {
        $model = new class() extends catalogo_lista_precio {
            public $db;

            public function __construct()
            {
                $this->table_name = catalogo_lista_precio::TABLE;
            }

            public function delete() { return false; }
            public function exists() { return false; }
            public function save() { return false; }
        };

        $sql = $model->install();

        $this->assertStringContainsString("'DEF'", $sql);
        $this->assertStringContainsString('por_defecto', $sql);
    }
}
