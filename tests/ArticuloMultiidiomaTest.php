<?php
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\model\articulo;
use PHPUnit\Framework\TestCase;

final class ArticuloMultiidiomaTest extends TestCase
{
    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo_descripcion.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_familia.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
    }

    public function test_get_descripcion_idioma_falls_back_to_base_description(): void
    {
        $model = new articulo([
            'referencia' => 'ART001',
            'descripcion' => 'Descripcion base',
        ]);

        $this->assertSame('Descripcion base', $model->get_descripcion_idioma('es'));
    }

    public function test_descripcion_idioma_truncates_long_text(): void
    {
        $model = new articulo([
            'referencia' => 'ART002',
            'descripcion' => str_repeat('a', 150),
        ]);

        $result = $model->descripcion_idioma('es', 10);

        $this->assertSame('aaaaaaaaaa...', $result);
    }
}
