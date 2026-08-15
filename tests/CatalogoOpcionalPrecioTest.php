<?php
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\model\catalogo_opcional;
use PHPUnit\Framework\TestCase;

final class CatalogoOpcionalPrecioTest extends TestCase
{
    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
    }

    public function test_precio_para_articulo_uses_percentage_of_article_pvp(): void
    {
        $opcional = new catalogo_opcional([
            'id' => 1,
            'codigo' => 'OPC0001',
            'nombre' => 'Extra',
            'descripcion' => '',
            'precio' => 0,
            'tipo_precio' => catalogo_opcional::TIPO_PRECIO_PORCENTAJE,
            'porcentaje' => 10,
            'activo' => true,
        ]);

        $articulo = (object) ['pvp' => 200.0];

        $this->assertSame(20.0, $opcional->precio_para_articulo($articulo));
    }

    public function test_precio_para_articulo_uses_fixed_price_when_not_percentage(): void
    {
        $opcional = new catalogo_opcional([
            'codigo' => 'OPC0002',
            'nombre' => 'Extra fijo',
            'descripcion' => '',
            'precio' => 35.5,
            'tipo_precio' => catalogo_opcional::TIPO_PRECIO_FIJO,
            'porcentaje' => null,
            'activo' => true,
        ]);

        $articulo = (object) ['pvp' => 200.0];

        $this->assertSame(35.5, $opcional->precio_para_articulo($articulo));
    }

    public function test_etiqueta_precio_base_shows_percentage_symbol(): void
    {
        $opcional = new catalogo_opcional([
            'id' => 3,
            'codigo' => 'OPC0003',
            'nombre' => 'Extra pct',
            'descripcion' => '',
            'precio' => 0,
            'tipo_precio' => catalogo_opcional::TIPO_PRECIO_PORCENTAJE,
            'porcentaje' => 12.5,
            'activo' => true,
        ]);

        $this->assertSame('12,5%', $opcional->etiqueta_precio_base());
    }
}
