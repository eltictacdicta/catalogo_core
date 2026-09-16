<?php
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
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Behavior/source contract for the controller-agnostic opcionales list trait
 * `VentasOpcionalesListTrait` absorbed from the legacy `tarif_opcionales` page
 * (spec opcionales-management OUM-01..OUM-07, OUM-09; design AD-1..AD-6, AD-11).
 *
 * The trait is exercised through an anonymous subclass that overrides the
 * preserved `opcional_master_state()` / `opcional_precio_model()` /
 * `opcional_model()` seams and the `PageController` surface (`request`,
 * `url()`, `redirect()`, `validateFormToken()`, `new_message()`), so no live
 * database row is ever read or written.
 */
final class CatalogoOpcionalesUnifiedControllerTest extends TestCase
{
    private const TRAIT_FILE = 'plugins/catalogo_core/extras/VentasOpcionalesListTrait.php';
    private const CONTROLLER_FILE = 'plugins/catalogo_core/Controller/VentasOpcionales.php';
    private const FORMATTER_FILE = 'plugins/catalogo_core/Services/CatalogoCurrencyFormatter.php';

    private static bool $baseLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        self::$baseLoaded = true;
    }

    /**
     * Loads the trait inside the isolated test process. Loading it in
     * setUpBeforeClass would re-require the plugin model chain in the parent
     * process after other suites already declared `familia`.
     */
    private function loadTrait(): void
    {
        $path = FS_FOLDER . '/' . self::TRAIT_FILE;
        if (!is_file($path)) {
            self::fail('missing unified list trait: ' . self::TRAIT_FILE);
        }

        require_once $path;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function traitSource(): string
    {
        $path = FS_FOLDER . '/' . self::TRAIT_FILE;
        if (!is_file($path)) {
            self::fail('missing unified list trait: ' . self::TRAIT_FILE);
        }

        return (string) file_get_contents($path);
    }

    private function methodBody(string $src, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{/';
        if (!preg_match($pattern, $src, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $matches[0][1] + strlen($matches[0][0]);
        $depth = 1;
        $length = strlen($src);
        for ($i = $start; $i < $length; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start);
                }
            }
        }

        return '';
    }

    private function invoke(object $subject, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($subject, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($subject, $args);
    }

    /**
     * Master-state double: records effective() reads and set_* writes.
     */
    private function masterStub(array $states = []): object
    {
        return new class($states) {
            /** @var list<array{0: string, 1: int}> */
            public array $effectiveCalls = [];

            /** @var list<array{0: string, 1: string, 2: int, 3: bool}> */
            public array $setCalls = [];

            /** @var array<int, array<string, mixed>> */
            private array $states;

            public function __construct(array $states)
            {
                $this->states = $states;
            }

            public function effective($codtarifa, $id_opcional): array
            {
                $id = (int) $id_opcional;
                $this->effectiveCalls[] = [(string) $codtarifa, $id];

                return $this->states[$id] ?? [
                    'activa' => true,
                    'en_catalogo' => true,
                    'en_tarifa' => false,
                    'orden' => 0,
                    'source' => 'inherit',
                ];
            }

            public function set_activa($codtarifa, $id, $value): bool
            {
                $this->setCalls[] = ['activa', (string) $codtarifa, (int) $id, (bool) $value];

                return true;
            }

            public function set_en_catalogo($codtarifa, $id, $value): bool
            {
                $this->setCalls[] = ['en_catalogo', (string) $codtarifa, (int) $id, (bool) $value];

                return true;
            }

            public function set_en_tarifa($codtarifa, $id, $value): bool
            {
                $this->setCalls[] = ['en_tarifa', (string) $codtarifa, (int) $id, (bool) $value];

                return true;
            }
        };
    }

    /**
     * Price-model double: records get() calls and returns canned prices.
     *
     * @param array<string, float> $prices keyed by "<id>|<codtarifa>"
     */
    private function priceStub(array $prices = []): object
    {
        return new class($prices) {
            /** @var list<array{0: int, 1: string}> */
            public array $calls = [];

            /** @var array<string, float> */
            private array $prices;

            public function __construct(array $prices)
            {
                $this->prices = $prices;
            }

            public function get($id_opcional, $codtarifa)
            {
                $this->calls[] = [(int) $id_opcional, (string) $codtarifa];
                $key = $id_opcional . '|' . $codtarifa;

                if (!isset($this->prices[$key])) {
                    return false;
                }

                return (object) ['precio' => $this->prices[$key], 'en_catalogo' => true];
            }
        };
    }

    /**
     * Opcional-model double for the filtered search + real total.
     */
    private function searchStub(array $rows, int $total): object
    {
        return new class($rows, $total) {
            /** @var list<array{0: string, 1: int, 2: string, 3: string, 4: bool, 5: string}> */
            public array $searchCalls = [];

            /** @var list<array{0: string, 1: string, 2: string, 3: bool, 4: string}> */
            public array $countCalls = [];

            /** @var list<object> */
            private array $rows;

            private int $total;

            public function __construct(array $rows, int $total)
            {
                $this->rows = $rows;
                $this->total = $total;
            }

            public function search($query, $offset, $codfamilia, $codtarifa, $solo_activos, $id_grupo = '')
            {
                $this->searchCalls[] = [(string) $query, (int) $offset, (string) $codfamilia, (string) $codtarifa, (bool) $solo_activos, (string) $id_grupo];

                return $this->rows;
            }

            public function count_filtered($query, $codfamilia, $codtarifa, $solo_activos, $id_grupo = '')
            {
                $this->countCalls[] = [(string) $query, (string) $codfamilia, (string) $codtarifa, (bool) $solo_activos, (string) $id_grupo];

                return $this->total;
            }
        };
    }

    private function request(string $uri, string $method = 'GET', array $post = []): Request
    {
        $request = Request::create($uri, $method);
        if ($post !== []) {
            $request->request->replace($post);
        }

        return $request;
    }

    /**
     * Anonymous subject using the trait with all external seams stubbed.
     */
    private function subject(
        object $master,
        object $opcional,
        Request $request,
        ?object $price = null,
        bool $csrfValid = true,
        bool $allowDelete = false,
        ?object $db = null,
        ?object $grupo = null
    ): object {
        $this->loadTrait();

        return new class($master, $opcional, $request, $price, $csrfValid, $allowDelete, $db, $grupo) {
            use \VentasOpcionalesListTrait;

            /** @var \Symfony\Component\HttpFoundation\Request */
            public $request;

            public bool $allow_delete = false;

            /** @var mixed */
            public $db;

            /** @var list<string> */
            public array $messages = [];

            /** @var list<string> */
            public array $errors = [];

            /** @var list<string> */
            public array $redirects = [];

            private bool $csrfValid;

            private $masterStub;

            private $opcionalStub;

            private $priceStub;

            private $grupoStub;

            /** @var object|null D12 visibility-resolver double (never a real read). */
            public $visibilityResolverStub = null;

            public function __construct($master, $opcional, $request, $price, $csrfValid, $allowDelete, $db, $grupo)
            {
                $this->masterStub = $master;
                $this->opcionalStub = $opcional;
                $this->priceStub = $price;
                $this->request = $request;
                $this->csrfValid = $csrfValid;
                $this->allow_delete = $allowDelete;
                $this->db = $db;
                $this->grupoStub = $grupo;
            }

            protected function opcional_master_state()
            {
                return $this->masterStub;
            }

            protected function opcional_visibility_resolver()
            {
                return $this->visibilityResolverStub
                    ?? new \FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver();
            }

            protected function opcional_precio_model()
            {
                return $this->priceStub;
            }

            protected function opcional_model()
            {
                return $this->opcionalStub;
            }

            protected function opcional_grupo_model()
            {
                return $this->grupoStub ?? new class() {
                    public function all_activos()
                    {
                        return [];
                    }
                };
            }

            protected function validateFormToken(): bool
            {
                return $this->csrfValid;
            }

            public function url(): string
            {
                return 'index.php?page=ventas_opcionales';
            }

            public function redirect(string $url): void
            {
                $this->redirects[] = $url;
            }

            public function new_message(string $msg): void
            {
                $this->messages[] = $msg;
            }

            public function new_error_msg(string $msg): void
            {
                $this->errors[] = $msg;
            }
        };
    }

    private function buildSubject(
        Request $request,
        ?object $master = null,
        ?object $opcional = null,
        ?object $price = null,
        bool $csrfValid = true,
        bool $allowDelete = false,
        ?object $grupo = null
    ): object {
        return $this->subject(
            $master ?? $this->masterStub(),
            $opcional ?? $this->searchStub([], 0),
            $request,
            $price ?? $this->priceStub(),
            $csrfValid,
            $allowDelete,
            new UnifiedListStateSpyDb(),
            $grupo
        );
    }

    // =====================================================================
    // OUM-01 / AD-1 — tarifa selector
    // =====================================================================

    public function test_tarifa_selector_prefers_requested_then_default_then_first_active(): void
    {
        $subject = $this->buildSubject($this->request('/index.php?page=ventas_opcionales'));
        $first = (object) ['codtarifa' => 'T1', 'nombre' => 'Base', 'coddivisa' => 'EUR'];
        $default = (object) ['codtarifa' => 'T2', 'nombre' => 'Default', 'coddivisa' => 'USD'];
        $subject->tarifas = [$first, $default];
        $subject->tarifa_defecto = $default;

        self::assertSame(
            $first,
            $this->invoke($subject, 'resolve_tarifa_seleccionada', ['T1']),
            'a requested active tarifa must win'
        );
        self::assertSame(
            $default,
            $this->invoke($subject, 'resolve_tarifa_seleccionada', ['UNKNOWN']),
            'an unknown tarifa must fall back to the default'
        );

        $subject->tarifa_defecto = false;
        self::assertSame(
            $first,
            $this->invoke($subject, 'resolve_tarifa_seleccionada', [null]),
            'with no default the first active tarifa is selected'
        );
    }

    public function test_no_active_tarifas_yields_null_selection(): void
    {
        $subject = $this->buildSubject($this->request('/index.php?page=ventas_opcionales'));
        $subject->tarifas = [];
        $subject->tarifa_defecto = false;

        self::assertNull(
            $this->invoke($subject, 'resolve_tarifa_seleccionada', ['T1']),
            'an empty active set must yield no selection'
        );
    }

    // =====================================================================
    // OUM-02 / AD-3, AD-6 — filters and real-total pagination
    // =====================================================================

    public function test_filters_query_primary_and_search_alias_are_carried_in_url(): void
    {
        $tarifa = (object) ['codtarifa' => 'T1', 'nombre' => 'Base', 'coddivisa' => 'EUR'];
        $subject = $this->buildSubject(
            $this->request('/index.php?page=ventas_opcionales&query=abc&b_codfamilia=F1&b_codtarifa=T1&b_id_grupo=0&b_solo_activos=TRUE&offset=50')
        );
        $subject->tarifas = [$tarifa];
        $subject->tarifa_defecto = $tarifa;

        $this->invoke($subject, 'ini_filters');

        self::assertSame(50, $subject->offset);
        self::assertSame('F1', $subject->b_codfamilia);
        self::assertSame('T1', $subject->b_codtarifa);
        self::assertSame('0', $subject->b_id_grupo, 'the "Sin grupo" sentinel must round-trip');
        self::assertTrue($subject->b_solo_activos);
        self::assertSame($tarifa, $subject->tarifa_seleccionada);
        self::assertStringContainsString('query=abc', $subject->b_url);
        self::assertStringContainsString('b_codfamilia=F1', $subject->b_url);
        self::assertStringContainsString('b_codtarifa=T1', $subject->b_url);
        self::assertStringContainsString('b_id_grupo=0', $subject->b_url);
        self::assertStringContainsString('b_solo_activos=TRUE', $subject->b_url);

        // `search` is accepted as an alias when the primary `query` is absent.
        $aliased = $this->buildSubject($this->request('/index.php?page=ventas_opcionales&search=xyz'));
        $aliased->tarifas = [$tarifa];
        $aliased->tarifa_defecto = $tarifa;
        $this->invoke($aliased, 'ini_filters');

        self::assertStringContainsString(
            'query=xyz',
            $aliased->b_url,
            'the search alias must be normalized into the primary query filter'
        );
    }

    public function test_pagination_uses_count_filtered_true_total_and_preserves_filters(): void
    {
        $tarifa = (object) ['codtarifa' => 'T1', 'nombre' => 'Base', 'coddivisa' => 'EUR'];
        $opcional = $this->searchStub([(object) ['id' => 1], (object) ['id' => 2]], 137);
        $subject = $this->buildSubject(
            $this->request('/index.php?page=ventas_opcionales&query=abc&b_codfamilia=F1&b_id_grupo=5&offset=50'),
            $opcional,
            $opcional
        );
        $subject->tarifas = [$tarifa];
        $subject->tarifa_defecto = $tarifa;

        $this->invoke($subject, 'ini_filters');
        $this->invoke($subject, 'search_opcionales');

        self::assertSame(137, $subject->total_resultados, 'the real filtered total must come from count_filtered()');
        self::assertSame([['abc', 'F1', 'T1', false, '5']], $opcional->countCalls, 'the group filter must reach count_filtered()');
        self::assertSame([['abc', 50, 'F1', 'T1', false, '5']], $opcional->searchCalls, 'search must use the request offset and the trailing group filter');

        $url = $subject->getPaginationUrl(100);
        self::assertStringContainsString('query=abc', $url);
        self::assertStringContainsString('b_codfamilia=F1', $url);
        self::assertStringContainsString('b_id_grupo=5', $url);
        self::assertStringContainsString('offset=100', $url);
        self::assertSame(1, substr_count($url, 'page='), 'page/action must not be duplicated as query params');

        self::assertFalse($subject->hasMoreResults(), 'two returned rows must not report more results');
    }

    // =====================================================================
    // OUM-03 — per-tarifa state and price display
    // =====================================================================

    public function test_state_cache_reads_effective_master_without_persistence(): void
    {
        $master = $this->masterStub([
            7 => ['activa' => false, 'en_catalogo' => true, 'en_tarifa' => true, 'orden' => 0, 'source' => 'master'],
        ]);
        $subject = $this->buildSubject($this->request('/index.php?page=ventas_opcionales'), $master);
        $subject->resultados = [(object) ['id' => 7]];
        $subject->tarifa_seleccionada = (object) ['codtarifa' => 'T1'];

        $this->invoke($subject, 'load_opcionales_state_cache');

        self::assertSame([['T1', 7]], $master->effectiveCalls, 'the master must be queried per (tarifa, opcional)');
        self::assertSame([], $master->setCalls, 'reading the state cache must never persist anything');
        self::assertFalse($subject->opcional_activo_en_tarifa(7), 'master activa=FALSE must win');
    }

    public function test_visibility_cache_is_derived_from_the_parent_product(): void
    {
        $subject = $this->buildSubject($this->request('/index.php?page=ventas_opcionales'));
        $subject->resultados = [(object) ['id' => 7]];
        $subject->tarifa_seleccionada = (object) ['codtarifa' => 'T1'];
        $subject->visibilityResolverStub = $this->visibilityResolverStub([
            'en_tarifa' => [7 => true],
            'en_catalogo' => [7 => false],
        ]);

        $this->invoke($subject, 'load_opcionales_visibility_cache');

        self::assertFalse(
            $subject->opcional_en_catalogo_tarifa(7),
            'the catalog indicator is derived from the parent product, never from the master row'
        );
        self::assertTrue($subject->opcional_en_tarifa_flag(7));
    }

    /**
     * Derived-visibility resolver double answering the two indicators.
     *
     * @param array<string, array<int, bool|null>> $map
     */
    private function visibilityResolverStub(array $map): object
    {
        return new class($map) {
            public function __construct(private array $map)
            {
            }

            public function resolve_opcionales_visibility(array $id_opcionales, string $codtarifa, string $codigo): array
            {
                $result = [];
                foreach ($id_opcionales as $id) {
                    $result[(int) $id] = $this->map[$codigo][(int) $id] ?? null;
                }

                return $result;
            }
        };
    }

    public function test_price_cache_maps_selected_tarifa_price(): void
    {
        $price = $this->priceStub(['7|T1' => 12.5]);
        $subject = $this->buildSubject($this->request('/index.php?page=ventas_opcionales'), null, null, $price);
        $subject->resultados = [(object) ['id' => 7]];
        $subject->tarifa_seleccionada = (object) ['codtarifa' => 'T1', 'coddivisa' => 'EUR'];

        $this->invoke($subject, 'load_precios_cache');

        self::assertSame([[7, 'T1']], $price->calls);
        self::assertSame(12.5, $subject->get_precio_opcional_tarifa(7));
        self::assertNull($subject->get_precio_opcional_tarifa(99), 'an unknown opcional has no cached price');

        $trait = $this->traitSource();
        self::assertStringContainsString(
            'CatalogoCurrencyFormatter::format(',
            $trait,
            'price display must delegate to the catalogo_core currency formatter'
        );
        self::assertStringContainsString('CatalogoCurrencyFormatter::symbol(', $trait);
    }

    // =====================================================================
    // OUM-04 / AD-4 — CSRF-guarded toggles
    // =====================================================================

    public function test_valid_toggle_persists_through_set_accessors(): void
    {
        $master = $this->masterStub();
        $subject = $this->buildSubject(
            $this->request('/index.php?page=ventas_opcionales&action=toggle_activa', 'POST', [
                'id' => '7',
                'codtarifa' => 'T1',
                'activa' => '1',
            ]),
            $master
        );

        $this->invoke($subject, 'toggle_opcional_state', ['toggle_activa']);

        self::assertSame([['activa', 'T1', 7, true]], $master->setCalls);
        self::assertNotSame([], $subject->redirects, 'a valid toggle must redirect back to the list');
        self::assertStringContainsString('b_codtarifa=T1', $subject->redirects[0]);
    }

    public function test_missing_or_invalid_csrf_blocks_toggle_and_persists_nothing(): void
    {
        $master = $this->masterStub();
        $subject = $this->buildSubject(
            $this->request('/index.php?page=ventas_opcionales&action=toggle_activa', 'POST', [
                'id' => '7',
                'codtarifa' => 'T1',
                'activa' => '1',
            ]),
            $master,
            null,
            null,
            false
        );

        $this->invoke($subject, 'toggle_opcional_state', ['toggle_activa']);

        self::assertSame([], $master->setCalls, 'an invalid CSRF token must persist nothing');
        self::assertNotSame([], $subject->errors, 'an explicit rejection must be reported');
    }

    // =====================================================================
    // OUM-05 / AD-4 — creation with validated prices and CSRF
    // =====================================================================

    public function test_new_opcional_normalizes_prices_and_persists(): void
    {
        $opcional = new UnifiedListOpcionalStub();
        $subject = $this->buildSubject(
            $this->request('/index.php?page=ventas_opcionales', 'POST', [
                'codigo' => 'OPC0002',
                'nombre' => 'Nuevo',
                'familias' => ['F1', 'F2'],
                'precio_tarifa_T1' => '1.234',
                'precio_tarifa_T2' => '12,5',
            ]),
            null,
            $opcional
        );
        $subject->tarifas = [
            (object) ['codtarifa' => 'T1', 'nombre' => 'Base', 'coddivisa' => 'EUR'],
            (object) ['codtarifa' => 'T2', 'nombre' => 'Otra', 'coddivisa' => 'USD'],
        ];

        $this->invoke($subject, 'new_opcional');

        self::assertTrue($opcional->saved, 'a valid payload must be persisted');
        self::assertSame(['F1', 'F2'], $opcional->familias);
        self::assertSame(['T1' => 1.234, 'T2' => 12.5], $opcional->precios);
        self::assertNotSame([], $subject->messages);
    }

    public function test_new_opcional_rejects_duplicate_or_bad_price(): void
    {
        $duplicate = new UnifiedListOpcionalStub(true);
        $duplicateSubject = $this->buildSubject(
            $this->request('/index.php?page=ventas_opcionales', 'POST', [
                'codigo' => 'OPC0002',
                'nombre' => 'Duplicado',
            ]),
            null,
            $duplicate
        );
        $duplicateSubject->tarifas = [(object) ['codtarifa' => 'T1', 'nombre' => 'Base', 'coddivisa' => 'EUR']];

        $this->invoke($duplicateSubject, 'new_opcional');

        self::assertFalse($duplicate->saved, 'a duplicate code must not be persisted');
        self::assertNotSame([], $duplicateSubject->errors);

        $badPrice = new UnifiedListOpcionalStub();
        $badPriceSubject = $this->buildSubject(
            $this->request('/index.php?page=ventas_opcionales', 'POST', [
                'codigo' => 'OPC0003',
                'nombre' => 'Precio invalido',
                'precio_tarifa_T1' => '12abc',
            ]),
            null,
            $badPrice
        );
        $badPriceSubject->tarifas = [(object) ['codtarifa' => 'T1', 'nombre' => 'Base', 'coddivisa' => 'EUR']];

        $this->invoke($badPriceSubject, 'new_opcional');

        self::assertFalse($badPrice->saved, 'a malformed price must not persist anything');
        self::assertSame([], $badPrice->precios);
        self::assertNotSame([], $badPriceSubject->errors);
    }

    public function test_new_opcional_persists_percentage_and_group(): void
    {
        $opcional = new UnifiedListOpcionalStub();
        $subject = $this->buildSubject(
            $this->request('/index.php?page=ventas_opcionales', 'POST', [
                'codigo' => 'OPC0009',
                'nombre' => 'Porcentual',
                'tipo_precio' => 'porcentaje',
                'porcentaje' => '12,5',
                'sid_grupo' => '3',
                'familias' => ['F1'],
            ]),
            null,
            $opcional
        );
        $subject->tarifas = [(object) ['codtarifa' => 'T1', 'nombre' => 'Base', 'coddivisa' => 'EUR']];
        $subject->tarifa_seleccionada = (object) ['codtarifa' => 'T1', 'nombre' => 'Base', 'coddivisa' => 'EUR'];

        $this->invoke($subject, 'new_opcional');

        self::assertTrue($opcional->saved, 'a valid percentage payload must be persisted');
        self::assertSame('porcentaje', $opcional->tipo_precio);
        self::assertSame(12.5, $opcional->porcentaje, 'the global percentage must be normalized');
        self::assertSame(3, $opcional->id_grupo, 'the create-modal group assignment must persist');
        self::assertSame(['T1' => 12.5], $opcional->porcentajes, 'the effective row must be written in percentage mode');
        self::assertSame([], $opcional->precios, 'percentage mode must not write fixed prices');
    }

    public function test_new_opcional_rejects_a_non_numeric_percentage(): void
    {
        $opcional = new UnifiedListOpcionalStub();
        $subject = $this->buildSubject(
            $this->request('/index.php?page=ventas_opcionales', 'POST', [
                'codigo' => 'OPC0010',
                'nombre' => 'Porcentaje invalido',
                'tipo_precio' => 'porcentaje',
                'porcentaje' => 'abc',
            ]),
            null,
            $opcional
        );
        $subject->tarifas = [(object) ['codtarifa' => 'T1', 'nombre' => 'Base', 'coddivisa' => 'EUR']];

        $this->invoke($subject, 'new_opcional');

        self::assertFalse($opcional->saved, 'a malformed percentage must not persist anything');
        self::assertSame([], $opcional->porcentajes);
        self::assertNotSame([], $subject->errors);
    }

    // =====================================================================
    // OPG-02 / AD-5, AD-6, AD-7 — groups
    // =====================================================================

    public function test_grupo_column_map_loads_once_without_n_plus_one(): void
    {
        $grupoModel = new class() {
            /** @var int */
            public $allActivosCalls = 0;

            /** @var int */
            public $getCalls = 0;

            public function all_activos()
            {
                $this->allActivosCalls++;

                return [
                    (object) ['id' => 3, 'nombre' => 'Color'],
                    (object) ['id' => 4, 'nombre' => 'Acabado'],
                ];
            }

            public function get($id)
            {
                $this->getCalls++;

                return false;
            }
        };

        $subject = $this->buildSubject($this->request('/index.php?page=ventas_opcionales'), null, null, null, true, false, $grupoModel);
        $subject->resultados = [
            (object) ['id' => 1, 'id_grupo' => 3],
            (object) ['id' => 2, 'id_grupo' => null],
            (object) ['id' => 5, 'id_grupo' => 99],
        ];

        $this->invoke($subject, 'load_grupos_cache');

        self::assertSame(1, $grupoModel->allActivosCalls, 'the group map must be loaded exactly once');
        self::assertSame(0, $grupoModel->getCalls, 'the group column must never do a per-row lookup (no N+1)');
        self::assertSame('Color', $subject->nombre_grupo_opcional(1));
        self::assertSame('-', $subject->nombre_grupo_opcional(2), 'an unassigned opcional shows the placeholder');
        self::assertSame('-', $subject->nombre_grupo_opcional(5), 'an unknown group must not leak');
        self::assertSame('Color', $subject->nombre_grupo_opcional(1));
    }

    public function test_where_id_grupo_treats_sentinel_and_rejects_injection(): void
    {
        $this->loadTrait();
        $opcional = new class() extends \FSFramework\model\tarif_opcional {
            public function __construct()
            {
                // Skip the DB constructor.
            }

            public function exposed($id_grupo, $alias = 'o')
            {
                return $this->where_id_grupo($id_grupo, $alias);
            }
        };

        self::assertSame('', $opcional->exposed(''), 'empty means no group filter');
        self::assertSame('(o.id_grupo IS NULL OR o.id_grupo = 0)', $opcional->exposed('0'), 'the "Sin grupo" sentinel maps to ungrouped rows');
        self::assertSame('o.id_grupo = 5', $opcional->exposed('5'));
        self::assertSame(
            'o.id_grupo = 5',
            $opcional->exposed("5 OR 1=1; DROP TABLE catalogo_opcionales"),
            'the group id must be intval-cast before it reaches SQL'
        );
    }

    // =====================================================================
    // OUM-06 — permission-gated delete
    // =====================================================================

    public function test_delete_is_blocked_without_allow_delete(): void
    {
        $opcional = new UnifiedListOpcionalStub();
        $subject = $this->buildSubject(
            $this->request('/index.php?page=ventas_opcionales', 'POST', ['delete' => '7']),
            null,
            $opcional,
            null,
            true,
            false
        );

        $this->invoke($subject, 'delete_opcional');

        self::assertSame([], $opcional->deleted, 'without the delete permission nothing may be deleted');
        self::assertNotSame([], $subject->errors);
    }

    // =====================================================================
    // OUM-07 / AD-11 — Excel export parity
    // =====================================================================

    public function test_export_headers_and_selected_tarifa_price_and_filter_inheritance(): void
    {
        $trait = $this->traitSource();

        foreach (["'Codigo (No editar)'", "'Ref SAP:'", "'Descripción'", "'Precio'", "'Familia'", "'Subfamilia'"] as $header) {
            self::assertStringContainsString($header, $trait, 'export header set must stay frozen: ' . $header);
        }

        self::assertStringContainsString('Spreadsheet', $trait);
        self::assertStringContainsString('$this->tarifa_seleccionada', $trait, 'export must use the selected-tarifa price');

        $export = $this->methodBody($trait, 'load_opcionales_for_export');
        self::assertNotSame('', $export, 'the filtered export row source must exist');
        self::assertStringContainsString('$this->b_codfamilia', $export, 'export must inherit the familia filter');
        self::assertStringContainsString('$this->b_codtarifa', $export, 'export must inherit the tarifa filter');
        self::assertStringContainsString('$this->b_solo_activos', $export, 'export must inherit the active filter');
    }

    // =====================================================================
    // OUM-09 / AD-9 — tarif_opcionales elimination and link repointing
    // =====================================================================

    public function test_model_url_fallbacks_point_to_ventas_opcionales(): void
    {
        $opcionalModel = FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional.php';
        $precioModel = FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional_precio.php';

        self::assertFileExists($opcionalModel);
        self::assertFileExists($precioModel);

        require_once $opcionalModel;
        require_once $precioModel;

        $opcional = new class() extends \FSFramework\model\tarif_opcional {
            public function __construct()
            {
                $this->id = null;
            }
        };
        self::assertSame(
            'index.php?page=ventas_opcionales',
            $opcional->url(),
            'the no-id tarif_opcional fallback must point at the unified page'
        );

        $opcional->id = 7;
        self::assertSame(
            'index.php?page=tarif_opcional_edit&id=7',
            $opcional->url(),
            'the id branch must stay on the surviving edit controller'
        );

        $precio = new class() extends \FSFramework\model\tarif_opcional_precio {
            public function __construct()
            {
                $this->id_opcional = null;
            }
        };
        self::assertSame(
            'index.php?page=ventas_opcionales',
            $precio->url(),
            'the no-id tarif_opcional_precio fallback must point at the unified page'
        );

        $precio->id_opcional = 9;
        self::assertSame(
            'index.php?page=tarif_opcional_edit&id=9',
            $precio->url(),
            'the id branch must stay on the surviving edit controller'
        );
    }

    public function test_deleted_page_has_no_controller_or_view(): void
    {
        self::assertFileDoesNotExist(
            FS_FOLDER . '/plugins/catalogo_core/controller/tarif_opcionales.php',
            'the legacy tarif_opcionales controller must be deleted (no redirect alias)'
        );
        self::assertFileDoesNotExist(
            FS_FOLDER . '/plugins/catalogo_core/View/tarif_opcionales.html.twig',
            'the legacy tarif_opcionales view must be deleted (no redirect alias)'
        );

        // OUM-11: the tarif_opcional_precios detail is absorbed into
        // tarif_opcional_edit and deleted with no alias.
        self::assertFileDoesNotExist(
            FS_FOLDER . '/plugins/catalogo_core/controller/tarif_opcional_precios.php',
            'the tarif_opcional_precios controller must be deleted (no redirect alias)'
        );
        self::assertFileDoesNotExist(
            FS_FOLDER . '/plugins/catalogo_core/View/tarif_opcional_precios.html.twig',
            'the tarif_opcional_precios view must be deleted (no redirect alias)'
        );
    }

    public function test_catalogo_and_tarifario_views_repoint_to_ventas_opcionales(): void
    {
        $views = [
            'plugins/catalogo_core/View/tarif_opcional_edit.html.twig',
            'plugins/catalogo_core/View/tarif_opcional.html.twig',
            'plugins/tarifario/View/tarif_actualizar_precios.html.twig',
            'plugins/tarifario/View/tarif_historial_precios.html.twig',
        ];

        foreach ($views as $relative) {
            $path = FS_FOLDER . '/' . $relative;
            self::assertFileExists($path, 'missing view: ' . $relative);

            // Composed so the WU-3 page-link audit finds no literal token here:
            // this is a negative assertion, not a link.
            $retiredPageLink = 'page=tarif_' . 'opcionales';

            $source = (string) file_get_contents($path);
            self::assertStringNotContainsString(
                $retiredPageLink,
                $source,
                $relative . ' must not link the retired page'
            );
            self::assertStringContainsString(
                'page=ventas_opcionales',
                $source,
                $relative . ' must link the unified opcionales page'
            );
        }
    }
}

/**
 * Spy database for the trait's ported `run_in_transaction()` helper.
 */
final class UnifiedListStateSpyDb
{
    /** @var list<string> */
    public array $calls = [];

    private bool $autoTransactions = true;

    public function begin_transaction()
    {
        $this->calls[] = 'begin';

        return true;
    }

    public function commit()
    {
        $this->calls[] = 'commit';

        return true;
    }

    public function rollback()
    {
        $this->calls[] = 'rollback';

        return true;
    }

    public function get_auto_transactions()
    {
        return $this->autoTransactions;
    }

    public function set_auto_transactions($value)
    {
        $this->autoTransactions = (bool) $value;
        $this->calls[] = 'auto:' . ($value ? '1' : '0');
    }
}

/**
 * Opcional-model double for creation/deletion flows.
 */
final class UnifiedListOpcionalStub
{
    public bool $saved = false;

    /** @var list<string> */
    public array $familias = [];

    /** @var array<string, float> */
    public array $precios = [];

    /** @var array<string, float> */
    public array $porcentajes = [];

    /** @var list<int> */
    public array $deleted = [];

    /** @var string|null */
    public $codigo = null;

    /** @var string|null */
    public $nombre = null;

    /** @var string|null */
    public $descripcion = null;

    /** @var string|null */
    public $ref_sap = null;

    /** @var float */
    public $precio = 0.0;

    /** @var string */
    public $tipo_precio = 'fijo';

    /** @var float|null */
    public $porcentaje = null;

    /** @var int|null */
    public $id_grupo = null;

    private bool $duplicate;

    public function __construct(bool $duplicate = false)
    {
        $this->duplicate = $duplicate;
    }

    public function get_new_codigo(): string
    {
        return 'OPC0002';
    }

    public function get_by_codigo($codigo)
    {
        return $this->duplicate ? (object) ['codigo' => $codigo] : false;
    }

    public function save(): bool
    {
        $this->saved = true;

        return true;
    }

    public function add_familia($codfamilia): bool
    {
        $this->familias[] = (string) $codfamilia;

        return true;
    }

    public function set_precio_tarifa($codtarifa, $precio): bool
    {
        $this->precios[(string) $codtarifa] = (float) $precio;

        return true;
    }

    public function set_porcentaje_tarifa($codtarifa, $porcentaje): bool
    {
        $this->porcentajes[(string) $codtarifa] = (float) $porcentaje;

        return true;
    }

    public function es_precio_porcentaje(): bool
    {
        return $this->tipo_precio === 'porcentaje';
    }

    public function get($id)
    {
        return new UnifiedListOpcionalRow((int) $id, $this);
    }
}

/**
 * Deletable row double handed back by {@see UnifiedListOpcionalStub::get()}.
 */
final class UnifiedListOpcionalRow
{
    public int $id;

    public string $codigo = 'OPC1';

    private UnifiedListOpcionalStub $stub;

    public function __construct(int $id, UnifiedListOpcionalStub $stub)
    {
        $this->id = $id;
        $this->stub = $stub;
    }

    public function delete(): bool
    {
        $this->stub->deleted[] = $this->id;

        return true;
    }
}
