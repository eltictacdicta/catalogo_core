<?php
declare(strict_types=1);

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\ArticuloExcelRowUpdater;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArticuloExcelRowUpdater::class)]
final class ArticuloExcelRowUpdaterTest extends TestCase
{
    private function makeArticulo(): \FSFramework\model\articulo
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';

        return new \FSFramework\model\articulo();
    }

    public function testParsePriceHandlesCommaDecimal(): void
    {
        $this->assertSame(10.5, ArticuloExcelRowUpdater::parsePrice('10,50'));
    }

    public function testParseBoolRecognizesSi(): void
    {
        $this->assertTrue(ArticuloExcelRowUpdater::parseBool('Sí'));
        $this->assertFalse(ArticuloExcelRowUpdater::parseBool('No'));
    }

    public function testApplyPvpUpdatesAndSetsFactualizado(): void
    {
        $art = $this->makeArticulo();
        $art->pvp = 0.0;
        $changed = ArticuloExcelRowUpdater::applyPvp('12,34', $art);
        $this->assertTrue($changed);
        $this->assertSame(12.34, $art->pvp);
        $this->assertNotEmpty($art->factualizado);
    }

    public function testNormalizePriceWithRoundingUsesBround(): void
    {
        if (!function_exists('bround')) {
            require_once FS_FOLDER . '/base/fs_functions.php';
        }

        $rounded = ArticuloExcelRowUpdater::normalizePrice('10,555', true);
        $this->assertSame(bround(10.555, ArticuloExcelRowUpdater::getPriceDecimals()), $rounded);
    }

    public function testApplyPvpWithRoundingUsesSetPvp(): void
    {
        if (!defined('FS_NF0_ART')) {
            define('FS_NF0_ART', 2);
        }
        if (!function_exists('bround')) {
            require_once FS_FOLDER . '/base/fs_functions.php';
        }

        $art = $this->makeArticulo();
        $art->pvp = 0.0;
        $changed = ArticuloExcelRowUpdater::applyPvp('10,556', $art, true);
        $this->assertTrue($changed);
        $this->assertSame(bround(10.556, 2), $art->pvp);
    }

    public function testApplyMappedFieldsSkipsUnchanged(): void
    {
        $art = $this->makeArticulo();
        $art->descripcion = 'Test';
        $changed = ArticuloExcelRowUpdater::applyMappedFields(['descripcion' => 'Test'], $art);
        $this->assertFalse($changed);
    }
}
