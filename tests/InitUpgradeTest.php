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
     * Mirrors `InitFamiliasTablesTest::test_upgrade_wires_ventas_familias_page_retirement`
     * for the retired `tarif_opcionales` page (OUM-09, AD-9).
     *
     * The retirement is idempotent by construction: `fs_page::get()` returns
     * FALSE once the row is gone, and `delete()` is gated by that result, so a
     * second `upgrade()` run is a no-op.
     */
    #[Test]
    public function upgradeRetiresTarifOpcionalesPageIdempotently(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../Init.php');

        $this->assertStringContainsString(
            'private static function retireTarifOpcionalesPage(): void',
            $src,
            'the idempotent tarif_opcionales page retirement must exist (OUM-09, AD-9)'
        );

        $upgrade = $this->methodBody($src, 'public static function upgrade(): void');
        $this->assertStringContainsString(
            'self::retireTarifOpcionalesPage();',
            $upgrade,
            'upgrade() must wire the retirement cleanup in its own try/catch (sibling style)'
        );

        $retire = $this->methodBody($src, 'private static function retireTarifOpcionalesPage(): void');
        $this->assertStringContainsString(
            "get('tarif_opcionales')",
            $retire,
            'the retirement must resolve the fs_page row by name'
        );
        $this->assertStringContainsString(
            '->delete()',
            $retire,
            'the retirement must delete via the fs_page model (model-managed DELETE + cache clear)'
        );
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$existing\s*!==\s*false\s*\)\s*\{\s*\$existing->delete\(\);\s*\}/s',
            $retire,
            'delete() must be gated by the fs_page::get() result so a second upgrade() run is a no-op'
        );
    }

    #[Test]
    public function upgradeRetiresTarifOpcionalPreciosPageIdempotently(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../Init.php');

        $this->assertStringContainsString(
            'private static function retireTarifOpcionalPreciosPage(): void',
            $src,
            'the idempotent tarif_opcional_precios page retirement must exist (OUM-11, AD-11)'
        );

        $upgrade = $this->methodBody($src, 'public static function upgrade(): void');
        $this->assertStringContainsString(
            'self::retireTarifOpcionalPreciosPage();',
            $upgrade,
            'upgrade() must wire the precios retirement in its own try/catch (sibling style)'
        );

        $retire = $this->methodBody($src, 'private static function retireTarifOpcionalPreciosPage(): void');
        $this->assertStringContainsString(
            "get('tarif_opcional_precios')",
            $retire,
            'the retirement must resolve the fs_page row by name'
        );
        $this->assertStringContainsString(
            '->delete()',
            $retire,
            'the retirement must delete via the fs_page model (model-managed DELETE + cache clear)'
        );
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$existing\s*!==\s*false\s*\)\s*\{\s*\$existing->delete\(\);\s*\}/s',
            $retire,
            'delete() must be gated by the fs_page::get() result so a second upgrade() run is a no-op'
        );
    }

    /**
     * Mirrors `upgradeRetiresTarifOpcionalesPageIdempotently` for the retired
     * `tarif_articulos` list page (ALC-06 scenario 3, AD-W3-7).
     */
    #[Test]
    public function upgradeRetiresTarifArticulosPageIdempotently(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../Init.php');

        $this->assertStringContainsString(
            'private static function retireTarifArticulosPage(): void',
            $src,
            'the idempotent tarif_articulos page retirement must exist (AD-W3-7)'
        );

        $upgrade = $this->methodBody($src, 'public static function upgrade(): void');
        $this->assertStringContainsString(
            'self::retireTarifArticulosPage();',
            $upgrade,
            'upgrade() must wire the retirement cleanup in its own try/catch (sibling style)'
        );

        $retire = $this->methodBody($src, 'private static function retireTarifArticulosPage(): void');
        $this->assertStringContainsString(
            "get('tarif_articulos')",
            $retire,
            'the retirement must resolve the fs_page row by name'
        );
        $this->assertStringContainsString(
            '->delete()',
            $retire,
            'the retirement must delete via the fs_page model (model-managed DELETE + cache clear)'
        );
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$existing\s*!==\s*false\s*\)\s*\{\s*\$existing->delete\(\);\s*\}/s',
            $retire,
            'delete() must be gated by the fs_page::get() result so a second upgrade() run is a no-op'
        );
    }

    /** Extracts a method body by brace matching from its signature. */
    private function methodBody(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            self::fail('missing catalogo_core method: ' . $signature);
        }

        $open = (int) strpos($src, '{', $start);
        $depth = 0;
        $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start + 1);
                }
            }
        }

        self::fail('method body is not terminated: ' . $signature);
    }
}
