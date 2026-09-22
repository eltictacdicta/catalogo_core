<?php
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\model\catalogo_idioma;
use FSFramework\model\catalogo_lista_precio;
use PHPUnit\Framework\TestCase;
use Tests\CatalogoCore\Support\FakeCatalogoIdioma;
use Tests\CatalogoCore\Support\IdiomaRegistryFake;

require_once __DIR__ . '/Support/IdiomaRegistryFake.php';
require_once __DIR__ . '/Support/FakeCatalogoIdioma.php';

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

    public function test_get_default_returns_only_an_active_default(): void
    {
        $active = (new FakeCatalogoIdioma())->useFakeDb(new IdiomaRegistryFake([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
        ]));
        $this->assertSame('es', $active->get_default()->codidioma);

        $inactive = (new FakeCatalogoIdioma())->useFakeDb(new IdiomaRegistryFake([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => false, 'por_defecto' => true],
        ]));
        $this->assertFalse($inactive->get_default(), 'an inactive default is not a usable default');
    }

    public function test_all_activos_excludes_inactive_languages(): void
    {
        $model = (new FakeCatalogoIdioma())->useFakeDb(new IdiomaRegistryFake([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => false, 'por_defecto' => false],
        ]));

        $names = array_map(static fn ($idioma): string => $idioma->nombre, $model->all_activos());

        $this->assertSame(['Español'], $names);
    }

    public function test_get_effective_default_code_prefers_configured_default(): void
    {
        $model = (new FakeCatalogoIdioma())->useFakeDb(new IdiomaRegistryFake([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => false],
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => true],
        ]));

        $this->assertSame('en', $model->get_effective_default_code());
    }

    public function test_set_default_switches_the_pointer(): void
    {
        $db = new IdiomaRegistryFake([
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
        ]);
        $model = (new FakeCatalogoIdioma())->useFakeDb($db);

        $this->assertTrue($model->set_default('en'));
        $this->assertSame('en', $model->get_effective_default_code());
    }

    public function test_registry_validation_bounds(): void
    {
        $model = new FakeCatalogoIdioma();

        $model->codidioma = 'x';
        $model->nombre = 'Valido';
        $this->assertFalse($model->test(), 'a codidioma shorter than 2 chars must be rejected');

        $model->codidioma = 'es';
        $model->nombre = str_repeat('a', 51);
        $this->assertFalse($model->test(), 'a nombre longer than 50 chars must be rejected');

        $model->codidioma = 'es';
        $model->nombre = 'Español';
        $this->assertTrue($model->test());
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
