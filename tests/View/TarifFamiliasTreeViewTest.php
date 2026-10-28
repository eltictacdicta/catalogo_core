<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Family tree view contract (familias-jerarquia task 3.2, D2/D5, R-FJ-001).
 *
 * The page renders ONE template in two modes, selected by the capability flag:
 *
 *  - flag OFF: the legacy `tarif_tarifa_familia` view, byte-for-byte (the
 *    rollback guarantee) — tarifa selector, `codtarifa` in every action URL,
 *    no structure column;
 *  - flag ON: the per-list tree — list selector fed only by the active
 *    `catalogo_listas_precio` rows, `codlista` in every action URL, per-node
 *    `capitulo` / `orden` / `activa` / `en_catalogo` / `en_tarifa` columns and
 *    depth derived from the structure `madre` chain (never stored).
 *
 * The template is rendered for real here (Twig with the shipped macros and
 * partials) against a controller-shaped fixture, so the assertions read the
 * markup the browser receives instead of the template source.
 */
final class TarifFamiliasTreeViewTest extends TestCase
{
    private const CSRF_FIELD = 'name="_token" value="stub-csrf"';

    private const TEMPLATE = 'tarif_familias.html.twig';

    protected function setUp(): void
    {
        parent::setUp();
        global $plugins;
        $plugins = [];
    }

    // =====================================================================
    // Flag OFF — the legacy view is untouched (rollback guarantee)
    // =====================================================================

    #[Test]
    public function flagOffKeepsTheLegacyTarifaSelectorAndItsReloadFunction(): void
    {
        $html = $this->render($this->legacyController());

        $this->assertStringContainsString('id="select_tarifa"', $html);
        $this->assertStringContainsString('onchange="cambiarTarifa()"', $html);
        $this->assertStringContainsString('function cambiarTarifa()', $html);
        $this->assertStringNotContainsString('id="select_lista"', $html, 'Flag off exposes no list selector');
        $this->assertStringNotContainsString('cambiarLista', $html);
    }

    #[Test]
    public function flagOffKeepsEveryActionUrlOnTheLegacyTariff(): void
    {
        $html = $this->render($this->legacyController());

        foreach ([
            '&codtarifa=T1&action=promote',
            '&codtarifa=T1&action=demote',
            '&codtarifa=T1&action=toggle_catalogo',
            '&codtarifa=T1&action=toggle_en_tarifa',
            '&codtarifa=T1&action=toggle_activa',
            '&codtarifa=T1&action=delete',
            '&codtarifa=T1&action=reorder',
            '&codtarifa=T1&edit=F1',
            '&codtarifa=T1&b_solo_activas=TRUE',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, 'Legacy URL contract must survive 3.2: ' . $expected);
        }

        $this->assertStringNotContainsString('codlista=', $html, 'Flag off never mentions the canonical list key');
    }

    #[Test]
    public function flagOffRendersNoStructureColumn(): void
    {
        $html = $this->render($this->legacyController());

        $this->assertStringNotContainsString('familia-col-orden', $html, 'The orden column is structure-only');
        $this->assertStringNotContainsString('familia-orden', $html);
        $this->assertStringNotContainsString('data-familia-estructura', $html);
    }

    #[Test]
    public function flagOffKeepsTheLegacyEmptyStateAndCreateLink(): void
    {
        $controller = $this->legacyController();
        $controller->rows = [];

        $html = $this->render($controller);

        $this->assertStringContainsString('No hay familias en esta tarifa.', $html);
        $this->assertStringContainsString('<a href="index.php?page=ventas_familia">Crear familias primero</a>', $html);
        $this->assertStringNotContainsString('catalogo-core-estructura-empty', $html);
    }

    // =====================================================================
    // Flag ON — list selector (task 3.2, D5)
    // =====================================================================

    #[Test]
    public function flagOnRendersTheListSelectorFromTheActiveListsOnly(): void
    {
        $controller = $this->structureController();
        $controller->listas_estructura = [
            (object) ['codlista' => 'L1', 'nombre' => 'Tarifa base', 'activa' => true, 'por_defecto' => true],
            (object) ['codlista' => 'L2', 'nombre' => 'Tarifa cliente', 'activa' => true, 'por_defecto' => false],
        ];
        $controller->codlista_estructura = 'L2';

        $html = $this->render($controller);

        $this->assertStringContainsString('id="select_lista"', $html);
        $this->assertStringContainsString('onchange="cambiarLista()"', $html);
        $this->assertStringContainsString('<option value="L1"', $html);
        $this->assertStringContainsString('<option value="L2" selected', $html, 'codlista_estructura is preselected');
        $this->assertStringContainsString('Tarifa base', $html);
        $this->assertStringContainsString('Tarifa cliente', $html);
        $this->assertStringContainsString('&codlista=', $html, 'The selector reloads through ?codlista=');
        $this->assertStringContainsString('catalogo-core-lista-selector', $html, 'The selector label is translated');
    }

    #[Test]
    public function flagOnDropsTheLegacyTarifaSelectorEntirely(): void
    {
        $html = $this->render($this->structureController());

        $this->assertStringNotContainsString('id="select_tarifa"', $html);
        $this->assertStringNotContainsString('function cambiarTarifa()', $html);
        $this->assertStringNotContainsString('codtarifa=', $html, 'The canonical path never addresses a legacy tariff');
    }

    // =====================================================================
    // Flag ON — actions target the list in context (task 3.2)
    // =====================================================================

    #[Test]
    public function flagOnPointsEveryActionAtTheListInContext(): void
    {
        $html = $this->render($this->structureController());

        foreach ([
            '&codlista=L1&action=promote',
            '&codlista=L1&action=demote',
            '&codlista=L1&action=toggle_catalogo',
            '&codlista=L1&action=toggle_en_tarifa',
            '&codlista=L1&action=toggle_activa',
            '&codlista=L1&action=delete',
            '&codlista=L1&action=reorder',
            '&codlista=L1&action=get_next_capitulo',
            '&codlista=L1&action=edit_form',
            '&codlista=L1&edit=F1',
            '&codlista=L1&b_solo_activas=TRUE',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, 'Action URL must carry the list in context: ' . $expected);
        }

        $this->assertStringContainsString('name="add_familia"', $html);
    }

    #[Test]
    public function flagOnEditFormPostsToTheListInContext(): void
    {
        $controller = $this->structureController();
        $controller->editing_familia = $controller->rows[1];

        $html = $this->render($controller);

        $this->assertStringContainsString('name="save_familia"', $html);
        $this->assertStringContainsString(
            'action="index.php?page=tarif_familias&codlista=L1"',
            $html,
            'The save form lands on the list in context'
        );
        $this->assertStringContainsString('hx-post="index.php?page=tarif_familias&codlista=L1"', $html);
        $this->assertStringNotContainsString('codtarifa=', $html);
    }

    #[Test]
    public function flagOnLinksTheCatalogViewOfTheSameList(): void
    {
        $html = $this->render($this->structureController());

        $this->assertStringContainsString('index.php?page=ventas_catalogo_view&codlista=L1', $html);
        $this->assertStringNotContainsString('page=tarif_catalogo_view', $html, 'The frozen tarifario catalog view is not linked');
    }

    // =====================================================================
    // Flag ON — per-node structure columns and derived depth (R-FJ-001)
    // =====================================================================

    #[Test]
    public function flagOnRendersTheStructureColumnsOfEveryNode(): void
    {
        $html = $this->render($this->structureController());

        $this->assertMatchesRegularExpression(
            '/<th[^>]*class="[^"]*familia-col-orden[^"]*"/',
            $html,
            'The structure table declares the orden column'
        );
        $this->assertStringContainsString('catalogo-core-estructura-orden', $html);
        $this->assertMatchesRegularExpression(
            '/<td class="familia-orden[^"]*">\s*7\s*<\/td>/',
            $html,
            'Every node renders its structure orden'
        );
        $this->assertStringContainsString('data-familia-estructura="1"', $html, 'The structure markers reach the DOM');
    }

    #[Test]
    public function flagOnDerivesTheDepthFromTheMotherChain(): void
    {
        $html = $this->render($this->structureController());

        $root = $this->rowHtml($html, 'F1');
        $child = $this->rowHtml($html, 'F2');

        $this->assertStringContainsString('data-nivel="0"', $root, 'A root node is depth 0');
        $this->assertStringContainsString('data-nivel="1"', $child, 'A child node is depth 1, derived from madre');
        $this->assertSame(
            1,
            substr_count($child, 'class="familia-indent"'),
            'The child renders exactly one indentation marker per derived level'
        );
        $this->assertSame(0, substr_count($root, 'class="familia-indent"'));
    }

    #[Test]
    public function flagOnKeepsThePerNodeVisibilityToggles(): void
    {
        $html = $this->render($this->structureController());

        $this->assertStringContainsString('catalogo-core-estructura-en-catalogo', $html);
        $this->assertStringContainsString('catalogo-core-estructura-en-tarifa', $html);
        $this->assertStringContainsString('catalogo-core-estructura-activa', $html);
        $this->assertStringContainsString('catalogo-core-estructura-capitulo', $html);
    }

    // =====================================================================
    // Flag ON — empty states
    // =====================================================================

    #[Test]
    public function flagOnEmptyListExplainsHowToStart(): void
    {
        $controller = $this->structureController();
        $controller->rows = [];

        $html = $this->render($controller);

        $this->assertStringContainsString('catalogo-core-estructura-empty', $html);
        $this->assertStringNotContainsString('No hay familias en esta tarifa.', $html, 'The legacy copy is tarifa-scoped');
        $this->assertStringContainsString('familias-add-open', $html, 'The empty state offers the add flow');
        $this->assertStringContainsString('colspan="10"', $html, 'The structure table has one extra column');
    }

    #[Test]
    public function flagOnWithoutActiveListsReportsTheConfigurationState(): void
    {
        $controller = $this->structureController();
        $controller->listas_estructura = [];
        $controller->codlista_estructura = '';
        $controller->rows = [];

        $html = $this->render($controller);

        $this->assertStringContainsString('catalogo-core-lista-empty', $html);
        $this->assertStringNotContainsString('No hay tarifas definidas', $html, 'The legacy tariff warning is tarifa-scoped');
        $this->assertStringNotContainsString('id="select_lista"', $html, 'Without active lists there is nothing to select');
    }

    // =====================================================================
    // CSRF on every POST form, in both modes
    // =====================================================================

    /**
     * @return array<string, array{bool}>
     */
    public static function provideModes(): array
    {
        return [
            'flag off' => [false],
            'flag on' => [true],
        ];
    }

    #[Test]
    #[DataProvider('provideModes')]
    public function everyPostFormCarriesACsrfField(bool $structure): void
    {
        $html = $this->render($structure ? $this->structureController() : $this->legacyController());

        preg_match_all('/<form\b.*?<\/form>/s', $html, $matches);
        $this->assertNotSame([], $matches[0], 'The page must render forms to audit');

        $postForms = 0;
        foreach ($matches[0] as $form) {
            if (stripos($form, 'method="post"') === false) {
                continue;
            }
            $postForms++;
            $this->assertStringContainsString(
                self::CSRF_FIELD,
                $form,
                'Every POST form carries a CSRF field: ' . substr($form, 0, 120)
            );
        }

        $this->assertGreaterThanOrEqual(5, $postForms, 'The audited POST forms must actually be rendered');
    }

    // =====================================================================
    // Harness
    // =====================================================================

    private function legacyController(): TarifFamiliasViewFixture
    {
        $controller = new TarifFamiliasViewFixture();
        $controller->family_structure = false;
        $controller->codtarifa = 'T1';
        $controller->tarifas = [(object) ['codtarifa' => 'T1', 'nombre' => 'Tarifa 1', 'por_defecto' => true]];
        $controller->rows = [
            $this->row('F1', null, '1', 0, 0),
            $this->row('F2', 'F1', '1.1', 1, 7),
        ];

        return $controller;
    }

    private function structureController(): TarifFamiliasViewFixture
    {
        $controller = new TarifFamiliasViewFixture();
        $controller->family_structure = true;
        $controller->listas_estructura = [
            (object) ['codlista' => 'L1', 'nombre' => 'Lista 1', 'activa' => true, 'por_defecto' => true],
        ];
        $controller->codlista_estructura = 'L1';
        $controller->rows = [
            $this->row('F1', null, '1', 0, 1),
            $this->row('F2', 'F1', '1.1', 1, 7),
        ];

        return $controller;
    }

    private function row(string $codfamilia, ?string $madre, string $capitulo, int $depth, int $orden): object
    {
        return (object) [
            'codfamilia' => $codfamilia,
            'madre' => $madre,
            'capitulo' => $capitulo,
            'nivel' => '',
            'orden' => $orden,
            'descripcion' => 'Familia ' . $codfamilia,
            'activa' => true,
            'en_catalogo' => true,
            'en_tarifa' => false,
            'nivel_visual' => $depth,
            'nivel_tree' => $depth,
            'hijas_tree' => [],
        ];
    }

    private function rowHtml(string $html, string $codfamilia): string
    {
        if (preg_match('/<tr class="familia-row.*?data-codfamilia="' . preg_quote($codfamilia, '/') . '".*?<\/tr>/s', $html, $m) !== 1) {
            self::fail('row not rendered: ' . $codfamilia);
        }

        return $m[0];
    }

    /**
     * Rendered markup with the HTML entities decoded, so URL assertions read
     * `&codlista=...` instead of `&amp;codlista=...`.
     */
    private function render(TarifFamiliasViewFixture $fsc): string
    {
        $html = $this->twig()->render(self::TEMPLATE, [
            'fsc' => $fsc,
            'app' => (object) ['name' => 'test'],
            'user' => (object) ['nick' => 'juan', 'admin' => true],
            'menu' => [],
        ]);

        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function twig(): \Twig\Environment
    {
        $loader = new \Twig\Loader\ChainLoader([
            new \Twig\Loader\ArrayLoader([
                'header.html.twig' => '',
                'footer.html.twig' => '',
            ]),
            new \Twig\Loader\FilesystemLoader([FS_FOLDER . '/themes/AdminLTE/view']),
            new \Twig\Loader\FilesystemLoader([FS_FOLDER . '/plugins/catalogo_core/View']),
        ]);

        $twig = new \Twig\Environment($loader, ['strict_variables' => false, 'cache' => false]);

        $twig->addFunction(new \Twig\TwigFunction(
            'csrf_field',
            static fn (): string => '<input type="hidden" ' . self::CSRF_FIELD . '>',
            ['is_safe' => ['html']]
        ));
        $twig->addFunction(new \Twig\TwigFunction(
            'csrf_meta',
            static fn (): string => '<meta name="csrf-token" content="stub-csrf">',
            ['is_safe' => ['html']]
        ));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', static fn (): string => 'stub-csrf'));
        $twig->addFunction(new \Twig\TwigFunction(
            'csp_nonce_attr',
            static fn (): string => 'nonce="stub"',
            ['is_safe' => ['html']]
        ));
        $twig->addFunction(new \Twig\TwigFunction(
            'trans',
            static fn (string $key, array $params = []): string => $key,
            ['is_safe' => ['html']]
        ));
        $twig->addFilter(new \Twig\TwigFilter(
            'trans',
            static fn (string $key, array $params = []): string => $key
        ));
        $twig->addFunction(new \Twig\TwigFunction(
            'path',
            static fn (string $route, array $params = []): string => 'index.php?page=' . $route
        ));

        return $twig;
    }
}

/**
 * Controller-shaped double for the two render modes.
 *
 * It mirrors the public surface the template consumes; the real behaviour of
 * `source_query()` (the single place that decides codlista vs codtarifa) is
 * covered by `TarifFamiliasDualSourceTest`.
 */
final class TarifFamiliasViewFixture
{
    public bool $family_structure = false;

    /** @var list<object> */
    public array $listas_estructura = [];

    public string $codlista_estructura = '';

    public string $codtarifa = '';

    /** @var list<object> */
    public array $tarifas = [];

    /** @var list<object> */
    public array $familias_disponibles = [];

    /** @var list<object> */
    public array $rows = [];

    /** @var object|null */
    public $editing_familia = null;

    public bool $b_solo_activas = false;

    public bool $allow_delete = true;

    /** @var object|null */
    public $tarifa_actual = null;

    public function url(): string
    {
        return 'index.php?page=tarif_familias';
    }

    public function source_query(): string
    {
        return $this->family_structure
            ? 'codlista=' . rawurlencode($this->codlista_estructura)
            : 'codtarifa=' . rawurlencode($this->codtarifa);
    }

    /**
     * @return list<object>
     */
    public function get_familias_flat(): array
    {
        return $this->rows;
    }

    /**
     * @return object|null
     */
    public function get_tarifa_actual()
    {
        return $this->tarifa_actual;
    }

    /**
     * @return object|null
     */
    public function get_lista_actual()
    {
        if (!$this->family_structure) {
            return null;
        }

        foreach ($this->listas_estructura as $lista) {
            if ((string) $lista->codlista === $this->codlista_estructura) {
                return $lista;
            }
        }

        return null;
    }

    public function count_articulos($codfamilia): int
    {
        return 0;
    }

    /**
     * @return list<object>
     */
    public function get_posibles_madres($codfamilia = null): array
    {
        return $this->rows;
    }

    public function get_etiquetas_familia_text($codfamilia): string
    {
        return '';
    }

    /**
     * @return list<object>
     */
    public function get_etiquetas_familia_full($codfamilia): array
    {
        return [];
    }
}
