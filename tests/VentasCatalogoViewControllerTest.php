<?php
declare(strict_types=1);
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
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

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Services\CatalogoOptions;
use FSFramework\Plugins\catalogo_core\Services\CatalogoViewProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fake `fs_db2` for the catalog view: hands back canned table rows and records
 * every statement, so list scoping, visibility filtering and the flag-off
 * short-circuit can be asserted without a live database.
 *
 * The rows deliberately ignore the WHERE clause (a permissive stand-in for the
 * engine): a provider that only trusted SQL would leak the rows this fake
 * returns, so the behavioural assertions below prove the PHP-side filter too.
 */
final class CatalogoViewFakeDb extends \fs_db2
{
    /** @var list<string> */
    public array $queries = [];

    /** @var list<array<string, mixed>> */
    public array $lists;

    /** @var list<array<string, mixed>> */
    public array $structures;

    /** @var list<array<string, mixed>> */
    public array $articles;

    /**
     * @param list<array<string, mixed>> $lists
     * @param list<array<string, mixed>> $structures
     * @param list<array<string, mixed>> $articles
     */
    public function __construct(array $lists = [], array $structures = [], array $articles = [])
    {
        // Deliberately skip parent::__construct(): no engine, no connection.
        $this->lists = $lists;
        $this->structures = $structures;
        $this->articles = $articles;
    }

    public function var2str($val)
    {
        if ($val === null) {
            return 'NULL';
        }
        if (is_bool($val)) {
            return $val ? "'1'" : "'0'";
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function select($sql, $params = [])
    {
        $this->queries[] = trim((string) $sql);

        if (str_contains((string) $sql, 'FROM catalogo_articulo_familia_estructura')) {
            return $this->articles;
        }
        if (str_contains((string) $sql, 'FROM catalogo_familia_estructura')) {
            return $this->structures;
        }
        if (str_contains((string) $sql, 'FROM catalogo_listas_precio')) {
            return $this->lists;
        }

        return [];
    }
}

/**
 * Catalog view page `ventas_catalogo_view` (familias-jerarquia task 4.1,
 * R-FJ-004 / R-FJ-006).
 *
 * Two layers: the page contract (files, menu, admin-only menu visibility,
 * no dependency on the frozen tarifario hooks) and the behavioural contract of
 * CatalogoViewProvider (list scoping, hidden structures, empty state, inert
 * flag-off, list identity taken only from catalogo_listas_precio).
 */
final class VentasCatalogoViewControllerTest extends TestCase
{
    private const CONTROLLER = '/plugins/catalogo_core/Controller/VentasCatalogoView.php';

    private const WRAPPER = '/plugins/catalogo_core/controller/ventas_catalogo_view.php';

    private const VIEW = '/plugins/catalogo_core/View/ventas_catalogo_view.html.twig';

    private const CONTROLLER_CLASS = 'FSFramework\\Plugins\\catalogo_core\\Controller\\VentasCatalogoView';

    protected function setUp(): void
    {
        parent::setUp();
        global $plugins;
        $plugins = [];
    }

    // =====================================================================
    // Page contract (R-FJ-004 / R-FJ-006)
    // =====================================================================

    #[Test]
    public function pageFilesExistAndTheWrapperExtendsTheModernController(): void
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . self::CONTROLLER;
        require_once FS_FOLDER . self::WRAPPER;

        $this->assertFileExists(FS_FOLDER . self::VIEW, 'ventas_catalogo_view.html.twig must exist');

        $modern = new \ReflectionClass(self::CONTROLLER_CLASS);
        $this->assertTrue($modern->isSubclassOf(\FSFramework\Controller\PageController::class));
        $this->assertTrue($modern->hasMethod('privateCore'));

        $wrapper = new \ReflectionClass('ventas_catalogo_view');
        $this->assertTrue($wrapper->isSubclassOf(self::CONTROLLER_CLASS));
    }

    #[Test]
    public function pageDataIsRegisteredUnderTheVentasMenu(): void
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . self::CONTROLLER;

        $data = $this->controllerWithUser(true)->getPageData();

        $this->assertSame('ventas_catalogo_view', $data['name']);
        $this->assertSame('ventas', $data['menu']);
    }

    #[Test]
    public function menuVisibilityIsAdminOnly(): void
    {
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . self::CONTROLLER;

        $this->assertTrue(
            $this->controllerWithUser(true)->getPageData()['showonmenu'],
            'The catalog view entry must be visible in the menu for administrators'
        );
        $this->assertFalse(
            $this->controllerWithUser(false)->getPageData()['showonmenu'],
            'The catalog view entry must stay out of the menu for non-administrators'
        );
    }

    #[Test]
    public function controllerResolvesThePageThroughTheProviderAndTheListQueryParam(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER);

        $this->assertStringContainsString('CatalogoViewProvider', $source, 'Data resolution must live in the provider');
        $this->assertStringContainsString("query->get('codlista')", $source, 'The list must come from ?codlista=');
    }

    #[Test]
    public function newViewDoesNotDependOnTheFrozenTarifarioHooks(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . self::VIEW);

        $this->assertSame(
            0,
            preg_match_all('/render_hook\(/', $source),
            'The absorbed catalog UI must render from catalogo_core views, not from the frozen hooks (R-FJ-007)'
        );
        $this->assertStringNotContainsString('@tarifario', $source);
    }

    #[Test]
    public function newViewUsesOnlyPrefixedTranslationKeys(): void
    {
        $keys = $this->translationKeysUsedBy(FS_FOLDER . self::VIEW);

        $this->assertNotSame([], $keys, 'The catalog view must not ship hardcoded UI copy');
        foreach ($keys as $key) {
            $this->assertStringStartsWith(
                'catalogo-core-',
                $key,
                sprintf('Catalog view key "%s" must carry the plugin prefix (R-FJ-008)', $key)
            );
        }
    }

    // =====================================================================
    // List scoping (R-FJ-004 scenario 1, R-MT-002)
    // =====================================================================

    #[Test]
    public function onlyTheRequestedListStructuresAndArticlesAreRendered(): void
    {
        $provider = $this->provider($this->standardDb());

        $result = $provider->build('L1');

        $this->assertTrue($result['enabled']);
        $this->assertSame('L1', $result['codlista']);
        $this->assertSame(['F1', 'F2'], $this->flattenTree($result['tree']), 'Only L1 structures may render');
        $this->assertSame(
            ['A1'],
            $this->flattenArticles($result['tree']),
            'Only L1 assignments of visible families may render'
        );
        $this->assertFalse($result['empty']);
    }

    #[Test]
    public function listIdentityComesOnlyFromCatalogoListasPrecio(): void
    {
        $db = $this->standardDb();
        $provider = $this->provider($db);

        $provider->build('L1');

        $listQuery = implode("\n", array_filter(
            $db->queries,
            static fn (string $sql): bool => str_contains($sql, 'FROM catalogo_listas_precio')
        ));
        $this->assertStringContainsString('catalogo_listas_precio', $listQuery);
        foreach (['tarif_tarifas', 'tarif_tarifa_familia', 'tarif_tarifa_articulo'] as $legacy) {
            $this->assertStringNotContainsString(
                $legacy,
                implode("\n", $db->queries),
                'The catalog view must never consult legacy tarifario tables (R-MT-002)'
            );
        }
    }

    #[Test]
    public function structuresAreScopedByListInSqlAndInPhp(): void
    {
        $db = $this->standardDb();
        $provider = $this->provider($db);

        $result = $provider->build('L2');

        $structureSql = implode("\n", $db->queries);
        $this->assertStringContainsString("'L2'", $structureSql, 'The structure read must be bound to the selected list');
        $this->assertStringContainsString('activa', $structureSql);
        $this->assertStringContainsString('en_catalogo', $structureSql);

        $this->assertSame(['F9'], $this->flattenTree($result['tree']));
        $this->assertSame(['A9'], $this->flattenArticles($result['tree']));
    }

    #[Test]
    public function firstActiveListIsUsedWhenNoListIsRequested(): void
    {
        $result = $this->provider($this->standardDb())->build(null);

        $this->assertSame('L1', $result['codlista']);
        $this->assertSame('Lista uno', $result['list_name']);
    }

    #[Test]
    public function unknownListFallsBackToTheFirstActiveList(): void
    {
        $result = $this->provider($this->standardDb())->build('NOPE');

        $this->assertSame('L1', $result['codlista']);
    }

    #[Test]
    public function inactivePriceListsAreNeverOffered(): void
    {
        $result = $this->provider($this->standardDb())->build('L1');

        $this->assertSame(['L1', 'L2'], array_column($result['lists'], 'codlista'));
    }

    // =====================================================================
    // Visibility (R-FJ-004 scenario 2)
    // =====================================================================

    #[Test]
    public function inactiveOrOutOfCatalogStructuresAreHidden(): void
    {
        $db = new CatalogoViewFakeDb(
            [['codlista' => 'L1', 'nombre' => 'Lista uno', 'activa' => '1']],
            [
                ['codlista' => 'L1', 'codfamilia' => 'F-OFF', 'descripcion' => 'Inactiva', 'madre' => null,
                    'familia_madre' => null, 'capitulo' => '', 'orden' => 0, 'activa' => '0', 'en_catalogo' => '1'],
                ['codlista' => 'L1', 'codfamilia' => 'F-NOCAT', 'descripcion' => 'Fuera de catalogo', 'madre' => null,
                    'familia_madre' => null, 'capitulo' => '', 'orden' => 0, 'activa' => '1', 'en_catalogo' => '0'],
                ['codlista' => 'L1', 'codfamilia' => 'F-VISIBLE', 'descripcion' => 'Visible', 'madre' => null,
                    'familia_madre' => null, 'capitulo' => 'CAP', 'orden' => 3, 'activa' => '1', 'en_catalogo' => '1'],
            ],
            [
                ['codlista' => 'L1', 'referencia' => 'A-HIDDEN', 'descripcion' => 'Art de familia oculta',
                    'codfamilia' => 'F-OFF', 'orden' => 0, 'en_catalogo' => '1'],
                ['codlista' => 'L1', 'referencia' => 'A-VISIBLE', 'descripcion' => 'Art visible',
                    'codfamilia' => 'F-VISIBLE', 'orden' => 0, 'en_catalogo' => '1'],
            ]
        );

        $result = $this->provider($db)->build('L1');

        $this->assertSame(['F-VISIBLE'], $this->flattenTree($result['tree']));
        $this->assertSame(['A-VISIBLE'], $this->flattenArticles($result['tree']));
        $this->assertSame('CAP', $result['tree'][0]['capitulo']);
        $this->assertSame(3, $result['tree'][0]['orden']);
    }

    #[Test]
    public function nestingFollowsTheMotherChainAndSurvivesAHiddenAncestor(): void
    {
        $db = new CatalogoViewFakeDb(
            [['codlista' => 'L1', 'nombre' => 'Lista uno', 'activa' => '1']],
            [
                ['codlista' => 'L1', 'codfamilia' => 'MADRE', 'descripcion' => 'Madre', 'madre' => null,
                    'familia_madre' => null, 'capitulo' => '', 'orden' => 0, 'activa' => '1', 'en_catalogo' => '1'],
                ['codlista' => 'L1', 'codfamilia' => 'HIJA', 'descripcion' => 'Hija', 'madre' => 'MADRE',
                    'familia_madre' => 'MADRE', 'capitulo' => '', 'orden' => 0, 'activa' => '1', 'en_catalogo' => '1'],
                ['codlista' => 'L1', 'codfamilia' => 'NIETA', 'descripcion' => 'Nieta', 'madre' => 'HIJA',
                    'familia_madre' => 'HIJA', 'capitulo' => '', 'orden' => 0, 'activa' => '1', 'en_catalogo' => '1'],
                ['codlista' => 'L1', 'codfamilia' => 'OCULTA', 'descripcion' => 'Oculta', 'madre' => null,
                    'familia_madre' => null, 'capitulo' => '', 'orden' => 0, 'activa' => '0', 'en_catalogo' => '1'],
                ['codlista' => 'L1', 'codfamilia' => 'HIJA-OCULTA', 'descripcion' => 'Hija de oculta', 'madre' => 'OCULTA',
                    'familia_madre' => 'OCULTA', 'capitulo' => '', 'orden' => 0, 'activa' => '1', 'en_catalogo' => '1'],
            ],
            []
        );

        $tree = $this->provider($db)->build('L1')['tree'];

        $this->assertSame(['MADRE', 'HIJA-OCULTA'], array_column($tree, 'codfamilia'));
        $this->assertSame('HIJA', $tree[0]['children'][0]['codfamilia']);
        $this->assertSame('NIETA', $tree[0]['children'][0]['children'][0]['codfamilia']);
        $this->assertSame(1, $tree[0]['depth']);
        $this->assertSame(3, $tree[0]['children'][0]['children'][0]['depth']);
        $this->assertSame(
            1,
            $tree[1]['depth'],
            'A visible child of a hidden mother is promoted to a root node instead of disappearing'
        );
    }

    // =====================================================================
    // Empty state
    // =====================================================================

    #[Test]
    public function aListWithoutVisibleStructureRendersTheEmptyState(): void
    {
        $db = new CatalogoViewFakeDb(
            [['codlista' => 'L1', 'nombre' => 'Lista uno', 'activa' => '1']],
            [],
            [['codlista' => 'L1', 'referencia' => 'A1', 'descripcion' => 'Suelto', 'codfamilia' => null,
                'orden' => 0, 'en_catalogo' => '1']]
        );

        $result = $this->provider($db)->build('L1');

        $this->assertTrue($result['empty'], 'No visible family node means the page shows its empty state');
        $this->assertSame([], $result['tree']);
    }

    #[Test]
    public function aCatalogWithoutActiveListsIsEmpty(): void
    {
        $result = $this->provider(new CatalogoViewFakeDb([], [], []))->build('L1');

        $this->assertTrue($result['empty']);
        $this->assertNull($result['codlista']);
        $this->assertSame([], $result['lists']);
    }

    // =====================================================================
    // Inert when disabled (D3 lifecycle gate)
    // =====================================================================

    #[Test]
    public function disabledCapabilityReportsItselfAndQueriesNothing(): void
    {
        $db = $this->standardDb();
        $provider = new CatalogoViewProvider($db, new CatalogoOptions([]));

        $result = $provider->build('L1');

        $this->assertFalse($result['enabled'], 'The page must report the capability as disabled');
        $this->assertTrue($result['empty']);
        $this->assertSame([], $result['tree']);
        $this->assertSame([], $result['lists']);
        $this->assertSame([], $db->queries, 'With the flag off the provider must not touch the structure tables');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function controllerWithUser(bool $admin): object
    {
        $controller = new class() extends \FSFramework\Plugins\catalogo_core\Controller\VentasCatalogoView {
            public function __construct()
            {
            }
        };
        $controller->user = (object) ['admin' => $admin];

        return $controller;
    }

    private function provider(CatalogoViewFakeDb $db): CatalogoViewProvider
    {
        return new CatalogoViewProvider(
            $db,
            new CatalogoOptions([CatalogoOptions::KEY_FAMILY_STRUCTURE => 'true'])
        );
    }

    private function standardDb(): CatalogoViewFakeDb
    {
        return new CatalogoViewFakeDb(
            [
                ['codlista' => 'L1', 'nombre' => 'Lista uno', 'activa' => '1'],
                ['codlista' => 'L2', 'nombre' => 'Lista dos', 'activa' => '1'],
                ['codlista' => 'L3', 'nombre' => 'Lista retirada', 'activa' => '0'],
            ],
            [
                ['codlista' => 'L1', 'codfamilia' => 'F1', 'descripcion' => 'Familia 1', 'madre' => null,
                    'familia_madre' => null, 'capitulo' => 'C1', 'orden' => 1, 'activa' => '1', 'en_catalogo' => '1'],
                ['codlista' => 'L1', 'codfamilia' => 'F2', 'descripcion' => 'Familia 2', 'madre' => 'F1',
                    'familia_madre' => 'F1', 'capitulo' => 'C1', 'orden' => 2, 'activa' => '1', 'en_catalogo' => '1'],
                ['codlista' => 'L2', 'codfamilia' => 'F9', 'descripcion' => 'Familia 9', 'madre' => null,
                    'familia_madre' => null, 'capitulo' => 'C9', 'orden' => 1, 'activa' => '1', 'en_catalogo' => '1'],
            ],
            [
                ['codlista' => 'L1', 'referencia' => 'A1', 'descripcion' => 'Articulo 1', 'codfamilia' => 'F1',
                    'orden' => 1, 'en_catalogo' => '1'],
                ['codlista' => 'L2', 'referencia' => 'A9', 'descripcion' => 'Articulo 9', 'codfamilia' => 'F9',
                    'orden' => 1, 'en_catalogo' => '1'],
            ]
        );
    }

    /**
     * @param list<array<string, mixed>> $tree
     * @return list<string> family codes in render order
     */
    private function flattenTree(array $tree): array
    {
        $codes = [];
        foreach ($tree as $node) {
            $codes[] = $node['codfamilia'];
            $codes = array_merge($codes, $this->flattenTree($node['children']));
        }

        return $codes;
    }

    /**
     * @param list<array<string, mixed>> $tree
     * @return list<string> article references attached to visible nodes, in render order
     */
    private function flattenArticles(array $tree): array
    {
        $refs = [];
        foreach ($tree as $node) {
            foreach ($node['articles'] as $article) {
                $refs[] = $article['referencia'];
            }
            $refs = array_merge($refs, $this->flattenArticles($node['children']));
        }

        return $refs;
    }

    /**
     * @return list<string> translation keys referenced from a template
     */
    private function translationKeysUsedBy(string $path): array
    {
        $source = (string) file_get_contents($path);
        preg_match_all("/'([a-z0-9\-]+)'\|trans/", $source, $piped);
        preg_match_all("/trans\('([^']+)'/", $source, $called);

        return array_values(array_unique(array_merge($piped[1], $called[1])));
    }
}
