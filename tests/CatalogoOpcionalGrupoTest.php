<?php
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\model\catalogo_opcional;
use FSFramework\model\catalogo_opcional_grupo;
use PHPUnit\Framework\TestCase;

final class CatalogoOpcionalGrupoTest extends TestCase
{
    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
        require_once FS_FOLDER . '/plugins/tpvmod/lib/tpvmod_opcionales.php';
    }

    public function testGrupoModelGeneratesCodigoPrefix(): void
    {
        $grupo = new catalogo_opcional_grupo();
        $this->assertStringStartsWith('GRP', $grupo->get_new_codigo());
    }

    public function testOpcionalStoresIdGrupo(): void
    {
        $opcional = new catalogo_opcional([
            'id' => 1,
            'codigo' => 'OPC0001',
            'nombre' => 'Blanco',
            'descripcion' => 'Blanco',
            'precio' => 10,
            'tipo_precio' => catalogo_opcional::TIPO_PRECIO_FIJO,
            'porcentaje' => null,
            'activo' => true,
            'id_grupo' => 5,
        ]);

        $this->assertSame(5, $opcional->id_grupo);
    }

    public function testTpvmodBuildOpcionalItemIncludesGroupMetadata(): void
    {
        $opcional = new catalogo_opcional([
            'id' => 10,
            'codigo' => 'OPC0010',
            'nombre' => 'Rojo',
            'descripcion' => 'Rojo',
            'precio' => 5,
            'tipo_precio' => catalogo_opcional::TIPO_PRECIO_FIJO,
            'porcentaje' => null,
            'activo' => true,
            'id_grupo' => 2,
        ]);

        $grupo = new catalogo_opcional_grupo([
            'id' => 2,
            'codigo' => 'GRP0002',
            'nombre' => 'Color',
            'exclusivo' => true,
            'activo' => true,
            'orden' => 1,
        ]);

        $item = tpvmod_build_opcional_item($opcional, 100.0, 'DEF', $grupo);

        $this->assertSame(2, $item['grupo_id']);
        $this->assertSame('Color', $item['grupo_nombre']);
        $this->assertTrue($item['grupo_exclusivo']);
    }

    public function testTpvmodOpcionalesForArticuloReturnsGroupedPayloadWhenEmpty(): void
    {
        $this->assertSame(
            ['grupos' => [], 'sueltos' => []],
            tpvmod_opcionales_for_articulo('', 100.0)
        );
    }

    public function testGroupedOptionalHasIdGrupo(): void
    {
        $opcional = new catalogo_opcional([
            'id' => 10,
            'codigo' => 'OPC0010',
            'nombre' => 'Blanco',
            'descripcion' => 'Blanco',
            'precio' => 0,
            'tipo_precio' => catalogo_opcional::TIPO_PRECIO_FIJO,
            'porcentaje' => null,
            'activo' => true,
            'id_grupo' => 2,
        ]);

        $this->assertSame(2, $opcional->id_grupo);
    }

    public function testUrlNuevoEnGrupoIncludesQueryParam(): void
    {
        $opcional = new catalogo_opcional();
        $this->assertSame(
            'index.php?page=ventas_opcional&id_grupo=3',
            $opcional->url_nuevo_en_grupo(3)
        );
    }
}
