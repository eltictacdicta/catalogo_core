<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
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

namespace Tests\CatalogoCore\Controller;

use FSFramework\model\tarif_articulo_imagen;
use FSFramework\model\tarif_tarifa_articulo;
use FSFramework\model\tarif_tarifa_articulo_etiqueta;
use FSFramework\Plugins\catalogo_core\Controller\VentasArticulo;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * WU-2 absorption contract: the canonical ventas_articulo detail absorbs the
 * retired tarifario edit surface (spec ART-01..ART-06, ART-08; AD-W2-2,
 * AD-W2-3, AD-W2-4, AD-W2-5).
 *
 * DB-free by design: an anonymous VentasArticulo subclass skips the controller
 * constructor and stubs the protected seam factories, so the absorbed behavior
 * (rename, familia/flags, per-tarifa sync, etiquetas, images, gate order) runs
 * without a database while the real production method bodies execute. Source
 * contracts pin the parts that cannot be exercised without a DB (htmx view
 * hygiene, frozen markers, link repoints, retired surfaces).
 */
final class VentasArticuloArticleEditAbsorptionTest extends TestCase
{
    private const CONTROLLER = '/plugins/catalogo_core/Controller/VentasArticulo.php';
    private const VIEW = '/plugins/catalogo_core/View/ventas_articulo.html.twig';
    private const PRECIO_MODEL = '/plugins/catalogo_core/model/tarif_articulo_precio.php';

    /** @var object */
    private $controller;
    /** @var object */
    public $trackedArticulo;
    /** @var object */
    public $trackedTarifaArticulo;
    /** @var object */
    public $trackedEtiqueta;
    /** @var object */
    public $trackedEtiquetaFamilia;
    /** @var object */
    public $trackedImagen;

    public bool $csrfValid = true;
    public bool $guardAllowed = true;
    public bool $renameAllowed = true;
    public bool $saveResult = true;
    public bool $etiquetaResult = true;
    public bool $ensureFamilyAllowed = true;

    public int $csrfChecks = 0;
    public int $guardCalls = 0;
    public int $articuloFactoryCalls = 0;
    public int $tarifaArticuloFactoryCalls = 0;
    public int $articuloGetCalls = 0;
    public int $articuloSaveCount = 0;
    public int $renameCalls = 0;
    public string $renamedTo = '';
    public int $tarifaGetCalls = 0;
    public int $tarifaAddCalls = 0;
    public int $tarifaRowSaveCount = 0;
    public array $lastTarifaAdd = [];
    public int $imagenAllCalls = 0;
    public int $imagenGetCalls = 0;
    public int $imagenDeleteCalls = 0;
    public int $imagenDestacarCalls = 0;
    public array $lastEtiquetas = [];
    public int $etiquetaReplaceCalls = 0;
    public array $imagenes = [];

    /** @var array<string, mixed> */
    public $tarifaRow = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
    }

    /**
     * Loads the legacy base classes, the moved models and the controller in the
     * isolated per-test process (never in setUpBeforeClass: the parent process
     * already declares `FSFramework\model\familia` as an InitUpgradeTest stub,
     * so requiring the real file there is a fatal redeclaration).
     */
    private function loadProductionClasses(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa_articulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa_articulo_etiqueta.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa_etiqueta_familia.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_articulo_imagen.php';

        foreach (['articulo'] as $short) {
            if (!class_exists($short, false)) {
                \fs_model_autoloader::ensureGlobalAlias($short);
            }
        }

        if (is_file(FS_FOLDER . self::CONTROLLER)) {
            require_once FS_FOLDER . self::CONTROLLER;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        $this->loadProductionClasses();
        $this->resetState();
        $this->resetModelCoreLog();
        $this->trackedArticulo = $this->buildTrackedArticulo();
        $this->trackedImagen = $this->buildTrackedImagen();
        $this->trackedTarifaArticulo = $this->buildTrackedTarifaArticulo();
        $this->trackedEtiqueta = $this->buildTrackedEtiqueta();
        $this->trackedEtiquetaFamilia = $this->buildTrackedEtiquetaFamilia();
        $this->controller = $this->buildController();
        $this->controller->setRequestObj(Request::create('/index.php?page=ventas_articulo', 'POST', []));
        $this->controller->setUser((object) ['admin' => true, 'nick' => 'admin']);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    private function resetState(): void
    {
        $this->csrfValid = true;
        $this->guardAllowed = true;
        $this->renameAllowed = true;
        $this->saveResult = true;
        $this->etiquetaResult = true;
        $this->ensureFamilyAllowed = true;
        $this->csrfChecks = 0;
        $this->guardCalls = 0;
        $this->articuloFactoryCalls = 0;
        $this->tarifaArticuloFactoryCalls = 0;
        $this->articuloGetCalls = 0;
        $this->articuloSaveCount = 0;
        $this->renameCalls = 0;
        $this->renamedTo = '';
        $this->tarifaGetCalls = 0;
        $this->tarifaAddCalls = 0;
        $this->tarifaRowSaveCount = 0;
        $this->lastTarifaAdd = [];
        $this->imagenAllCalls = 0;
        $this->imagenGetCalls = 0;
        $this->imagenDeleteCalls = 0;
        $this->imagenDestacarCalls = 0;
        $this->lastEtiquetas = [];
        $this->etiquetaReplaceCalls = 0;
        $this->imagenes = [];
        $this->tarifaRow = null;
    }

    private function resetModelCoreLog(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('core_log');
        $prop->setAccessible(true);
        $prop->setValue(null, new \fs_core_log());
    }

    private function source(string $relative): string
    {
        $path = FS_FOLDER . $relative;
        if (!is_file($path)) {
            self::fail('missing path: ' . $relative);
        }

        return (string) file_get_contents($path);
    }

    /** Extracts a method body by brace matching from its signature. */
    private function methodSource(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            self::fail('missing method: ' . $signature);
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

    private function buildTrackedArticulo(): object
    {
        $outer = $this;
        return new class($outer) extends \articulo {
            public $outer;
            public function __construct($outer)
            {
                // Skip parent::__construct (no DB); seed the fields the controller writes.
                $this->outer = $outer;
                $this->table_name = 'articulos';
                $this->referencia = 'REF-1';
                $this->descripcion = '';
                $this->pvp = 0.0;
                $this->codfamilia = null;
                $this->codfabricante = null;
                $this->codimpuesto = null;
                $this->stockfis = 0.0;
                $this->stockmin = 0.0;
                $this->stockmax = 0.0;
                $this->bloqueado = false;
                $this->sevende = true;
                $this->secompra = true;
                $this->publico = false;
                $this->nostock = false;
                $this->observaciones = '';
                $this->codbarras = '';
                $this->equivalencia = '';
                $this->partnumber = '';
            }

            public function get($ref)
            {
                $this->outer->articuloGetCalls++;
                return $ref === $this->referencia ? $this : false;
            }

            public function set_referencia($ref)
            {
                $this->outer->renameCalls++;
                $this->outer->renamedTo = (string) $ref;
                if (!$this->outer->renameAllowed) {
                    return false;
                }
                $this->referencia = $ref;
                return true;
            }

            public function save(): bool
            {
                $this->outer->articuloSaveCount++;
                return $this->outer->saveResult;
            }

            public function get_errors(): array
            {
                return [];
            }
        };
    }

    private function buildTrackedImagen(): object
    {
        $outer = $this;
        return new class($outer) extends tarif_articulo_imagen {
            public $outer;
            public function __construct($outer)
            {
                $this->outer = $outer;
                $this->table_name = 'tarif_articulo_imagenes';
                $this->id = 0;
                $this->referencia = '';
                $this->nombre_archivo = '';
                $this->destacada = false;
            }

            public function all_from_articulo($referencia)
            {
                $this->outer->imagenAllCalls++;
                return $this->outer->imagenes;
            }

            public function get($id)
            {
                $this->outer->imagenGetCalls++;
                foreach ($this->outer->imagenes as $imagen) {
                    if ((int) $imagen->id === (int) $id) {
                        return $imagen;
                    }
                }
                return false;
            }

            public function delete(): bool
            {
                $this->outer->imagenDeleteCalls++;
                return true;
            }

            public function set_destacada()
            {
                $this->outer->imagenDestacarCalls++;
                return $this;
            }
        };
    }

    private function buildTrackedTarifaArticulo(): object
    {
        $outer = $this;
        return new class($outer) extends tarif_tarifa_articulo {
            public $outer;
            public function __construct($outer)
            {
                $this->outer = $outer;
                $this->table_name = 'tarif_tarifa_articulo';
                $this->codtarifa = '';
                $this->referencia = '';
                $this->codfamilia = null;
            }

            public function get($codtarifa, $referencia)
            {
                $this->outer->tarifaGetCalls++;
                if ($this->outer->tarifaRow === null) {
                    return false;
                }

                $row = new static($this->outer);
                $row->codtarifa = $codtarifa;
                $row->referencia = $referencia;
                $row->codfamilia = $this->outer->tarifaRow;
                return $row;
            }

            public function add_articulo_to_tarifa($codtarifa, $referencia, $codfamilia = null)
            {
                $this->outer->tarifaAddCalls++;
                $this->outer->lastTarifaAdd = [$codtarifa, $referencia, $codfamilia];
                return $this;
            }

            public function save(): bool
            {
                $this->outer->tarifaRowSaveCount++;
                $this->outer->tarifaRow = $this->codfamilia;
                return true;
            }
        };
    }

    private function buildTrackedEtiqueta(): object
    {
        $outer = $this;
        return new class($outer) extends tarif_tarifa_articulo_etiqueta {
            public $outer;
            public function __construct($outer)
            {
                $this->outer = $outer;
                $this->table_name = 'tarif_tarifa_articulo_etiqueta';
                $this->codtarifa = '';
                $this->referencia = '';
                $this->etiqueta = '';
            }

            public function replace_etiquetas_articulo($codtarifa, $referencia, $etiquetas)
            {
                $this->outer->etiquetaReplaceCalls++;
                $this->outer->lastEtiquetas = (array) $etiquetas;
                return $this->outer->etiquetaResult;
            }

            public function get_etiquetas_articulo($codtarifa, $referencia)
            {
                return $this->outer->lastEtiquetas;
            }
        };
    }

    private function buildTrackedEtiquetaFamilia(): object
    {
        $outer = $this;
        return new class($outer) extends \FSFramework\model\tarif_tarifa_etiqueta_familia {
            public $outer;
            public function __construct($outer)
            {
                $this->outer = $outer;
                $this->table_name = 'tarif_tarifa_etiqueta_familia';
            }

            public function get_etiquetas_familia($codtarifa, $codfamilia)
            {
                return ['ETI-A', 'ETI-B'];
            }
        };
    }

    private function buildController(): object
    {
        $outer = $this;
        $controller = new class($outer) extends VentasArticulo {
            public $outer;
            public function __construct($outer)
            {
                // Skip parent::__construct — heavy FS init would hit the DB.
                $this->outer = $outer;
                $this->core_log = new \fs_core_log(VentasArticulo::class);
                $this->allow_delete = true;
                $this->idiomas = [];
                $this->codtarifa = '';
                $this->tarifas = [];
                $this->imagenes = [];
                $this->articulo_etiquetas_disponibles = [];
                $this->articulo_etiquetas_seleccionadas = [];
            }

            protected function validateFormToken(): bool
            {
                $this->outer->csrfChecks++;
                return $this->outer->csrfValid;
            }

            protected function articulo_model(): \articulo
            {
                $this->outer->articuloFactoryCalls++;
                return $this->outer->trackedArticulo;
            }

            protected function tarifa_model(): \FSFramework\model\tarif_tarifa
            {
                return new class() extends \FSFramework\model\tarif_tarifa {
                    public function __construct()
                    {
                        $this->table_name = 'tarif_tarifas';
                    }
                };
            }

            protected function tarifa_articulo_model(): \FSFramework\model\tarif_tarifa_articulo
            {
                $this->outer->tarifaArticuloFactoryCalls++;
                return $this->outer->trackedTarifaArticulo;
            }

            protected function etiqueta_model(): \FSFramework\model\tarif_tarifa_articulo_etiqueta
            {
                return $this->outer->trackedEtiqueta;
            }

            protected function etiqueta_familia_model(): \FSFramework\model\tarif_tarifa_etiqueta_familia
            {
                return $this->outer->trackedEtiquetaFamilia;
            }

            protected function imagen_model(): \FSFramework\model\tarif_articulo_imagen
            {
                return $this->outer->trackedImagen;
            }

            protected function tarifa_familia_model(): \FSFramework\model\tarif_tarifa_familia
            {
                return new class() extends \FSFramework\model\tarif_tarifa_familia {
                    public function __construct()
                    {
                        $this->table_name = 'tarif_tarifa_familia';
                    }
                };
            }

            protected function ensureTarifaFamilyAvailable(?string $codfamilia): bool
            {
                return $this->outer->ensureFamilyAllowed;
            }

            protected function puedeEditarArticulo(string $referencia, string $codtarifa): bool
            {
                $this->outer->guardCalls++;
                return $this->outer->guardAllowed;
            }

            public function setUser(object $user): void
            {
                $this->user = $user;
            }

            public function setRequestObj(Request $request): void
            {
                $this->request = $request;
            }

            public function setArticulo(?\articulo $articulo): void
            {
                $this->articulo = $articulo;
            }

            public function callEditar(Request $request): void
            {
                $this->editarArticulo($request);
            }

            public function callEliminar(Request $request): void
            {
                $this->eliminarArticulo($request);
            }

            public function callLoadImagenes(\articulo $art): void
            {
                $this->loadImagenes($art);
            }

            public function callDeleteImagen(\articulo $art): void
            {
                $this->deleteImagen($art);
            }

            public function callDestacarImagen(\articulo $art): void
            {
                $this->destacarImagen($art);
            }
        };

        return $controller;
    }

    private function requestPost(array $fields): Request
    {
        $_POST = $fields;
        $_REQUEST = $fields;
        return Request::create('/index.php?page=ventas_articulo', 'POST', $fields);
    }

    private function runEditar(array $fields): void
    {
        $request = $this->requestPost($fields);
        $this->controller->setRequestObj($request);
        $this->controller->setArticulo($this->trackedArticulo);
        $this->controller->callEditar($request);
    }

    // =====================================================================
    // ART-01 — reference change, familia/flags, per-tarifa sync, etiquetas
    // =====================================================================

    public function test_reference_change_uses_the_article_reference_setter_and_rejects_invalid(): void
    {
        $this->runEditar([
            'sreferencia' => 'REF-1',
            'snueva_referencia' => 'REF-2',
            'sdescripcion' => 'Changed',
        ]);

        $this->assertSame(1, $this->renameCalls, 'The reference change must go through the model reference setter');
        $this->assertSame('REF-2', $this->renamedTo);
        $this->assertGreaterThanOrEqual(1, $this->articuloSaveCount, 'A valid rename must continue to the article save');

        // Denied rename: an error is reported and no article is renamed/saved.
        $this->resetState();
        $this->trackedArticulo = $this->buildTrackedArticulo();
        $this->renameAllowed = false;
        $this->runEditar([
            'sreferencia' => 'REF-1',
            'snueva_referencia' => 'REF-9',
            'sdescripcion' => 'Changed',
        ]);

        $this->assertSame(1, $this->renameCalls, 'The invalid/duplicate reference must reach the setter that rejects it');
        $this->assertSame('REF-9', $this->renamedTo);
        $this->assertSame(0, $this->articuloSaveCount, 'A rejected rename must not save the article');
        $errors = $this->controller->get_errors();
        $this->assertNotEmpty($errors, 'A rejected rename must report an error');
    }

    public function test_familia_and_flags_are_assigned_on_save(): void
    {
        $this->runEditar([
            'sreferencia' => 'REF-1',
            'sdescripcion' => 'Desc',
            'scodfamilia' => 'FAM-1',
            'scodfabricante' => '',
            'scodimpuesto' => '',
            'scodbarras' => '123',
            'sbloqueado' => '1',
            'ssevende' => '1',
            'ssecompra' => '1',
            'spublico' => '1',
            'snostock' => '1',
        ]);

        $this->assertGreaterThanOrEqual(1, $this->articuloSaveCount);
        $this->assertSame('FAM-1', $this->trackedArticulo->codfamilia);
        $this->assertSame('123', $this->trackedArticulo->codbarras);
        $this->assertTrue((bool) $this->trackedArticulo->bloqueado);
        $this->assertTrue((bool) $this->trackedArticulo->publico);
        $this->assertTrue((bool) $this->trackedArticulo->nostock);
    }

    public function test_per_tarifa_sync_uses_tarif_tarifa_articulo_for_the_active_tarifa(): void
    {
        // Existing row: it must be updated in place.
        $this->controller->codtarifa = 'TAR-1';
        $this->tarifaRow = 'OLD-FAM';
        $this->runEditar([
            'sreferencia' => 'REF-1',
            'scodfamilia' => 'FAM-1',
        ]);

        $this->assertSame(1, $this->tarifaGetCalls, 'The sync must read tarif_tarifa_articulo for (tarifa, referencia)');
        $this->assertSame(1, $this->tarifaRowSaveCount, 'An existing per-tarifa row must be saved with the new family');
        $this->assertSame('FAM-1', $this->tarifaRow);
        $this->assertSame(0, $this->tarifaAddCalls, 'An existing row must not be re-added');

        // Missing row: it must be added through the model helper.
        $this->resetState();
        $this->trackedArticulo = $this->buildTrackedArticulo();
        $this->controller->codtarifa = 'TAR-1';
        $this->runEditar([
            'sreferencia' => 'REF-1',
            'scodfamilia' => 'FAM-2',
        ]);

        $this->assertSame(1, $this->tarifaAddCalls, 'A missing per-tarifa row must be created through add_articulo_to_tarifa()');
        $this->assertSame(['TAR-1', 'REF-1', 'FAM-2'], $this->lastTarifaAdd);
    }

    public function test_etiquetas_persist_through_tarif_tarifa_articulo_etiqueta(): void
    {
        $this->controller->codtarifa = 'TAR-1';
        $this->runEditar([
            'sreferencia' => 'REF-1',
            'scodfamilia' => 'FAM-1',
            'etiquetas' => ['ETI-A', 'ETI-C'],
        ]);

        $this->assertSame(1, $this->etiquetaReplaceCalls, 'Etiquetas must persist through replace_etiquetas_articulo()');
        $this->assertSame(
            ['ETI-A'],
            $this->lastEtiquetas,
            'Only etiquetas allowed for the selected family/tarifa may persist'
        );
    }

    public function test_etiqueta_failure_does_not_discard_the_article_save(): void
    {
        $this->controller->codtarifa = 'TAR-1';
        $this->etiquetaResult = false;
        $this->runEditar([
            'sreferencia' => 'REF-1',
            'scodfamilia' => 'FAM-1',
            'etiquetas' => ['ETI-A'],
        ]);

        $this->assertGreaterThanOrEqual(1, $this->articuloSaveCount, 'A failed etiqueta step must not discard the successful article save');
        $errors = $this->controller->get_errors();
        $this->assertNotEmpty($errors, 'A failed etiqueta step must report an error');
    }

    // =====================================================================
    // ART-01 — the images entry point belongs to the canonical detail
    // =====================================================================

    public function test_images_entry_point_is_owned_by_the_canonical_detail(): void
    {
        $src = $this->source(self::CONTROLLER);

        foreach (['loadImagenes', 'uploadImagen', 'deleteImagen', 'destacarImagen', 'imagen_model('] as $needle) {
            $this->assertStringContainsString($needle, $src, 'Canonical detail must own ' . $needle);
        }
        $this->assertStringContainsString('public array $imagenes', $src, 'The view needs the loaded image list');
        $this->assertStringContainsString('tarif_articulo_imagen', $src, 'Images must resolve the catalogo_core image model');

        $this->trackedArticulo->referencia = 'REF-1';
        $this->controller->setArticulo($this->trackedArticulo);
        $this->controller->callLoadImagenes($this->trackedArticulo);
        $this->assertSame(1, $this->imagenAllCalls, 'loadImagenes() must list the images of the loaded article');
    }

    public function test_image_mutations_stay_scoped_to_the_loaded_article(): void
    {
        $src = $this->source(self::CONTROLLER);
        $delete = $this->methodSource($src, 'protected function deleteImagen');

        $this->assertStringContainsString('referencia', $delete, 'deleteImagen must scope the deletion to the loaded article');
        $this->assertStringContainsString('imagen_model(', $delete, 'deleteImagen must resolve the image through the seam factory');
        $this->assertStringContainsString('$art->referencia', $delete, 'The scope check must compare against the loaded referencia');

        // Behavior: an image belonging to another article must not be deleted.
        $foreign = new class() extends tarif_articulo_imagen {
            public function __construct()
            {
                $this->table_name = 'tarif_articulo_imagenes';
                $this->id = 5;
                $this->referencia = 'OTHER';
            }
        };
        $this->imagenes = [$foreign];
        $this->trackedArticulo->referencia = 'REF-1';
        $this->controller->setArticulo($this->trackedArticulo);

        $request = $this->requestPost(['delete_imagen' => 5]);
        $this->controller->setRequestObj($request);
        $this->controller->callDeleteImagen($this->trackedArticulo);

        $this->assertSame(0, $this->imagenDeleteCalls, 'An image of another article must never be deleted (cross-article guard)');

        // Own image: it is deleted.
        $outer = $this;
        $own = new class($outer) extends tarif_articulo_imagen {
            public $outer;
            public function __construct($outer)
            {
                $this->outer = $outer;
                $this->table_name = 'tarif_articulo_imagenes';
                $this->id = 6;
                $this->referencia = 'REF-1';
            }
            public function delete(): bool
            {
                $this->outer->imagenDeleteCalls++;
                return true;
            }
        };
        $this->imagenes = [$own];
        $this->controller->setRequestObj($this->requestPost(['delete_imagen' => 6]));
        $this->controller->callDeleteImagen($this->trackedArticulo);
        $this->assertSame(1, $this->imagenDeleteCalls, 'An image of the loaded article must be deleted');
    }

    // =====================================================================
    // ART-02 — gate, CSRF order, WU-1 endpoint reuse
    // =====================================================================

    public function test_price_editing_reuses_the_wu1_tab_endpoint(): void
    {
        $src = $this->source(self::CONTROLLER);

        $this->assertStringNotContainsString(
            'guardar_precio_tab',
            $src,
            'Canonical detail must not duplicate the WU-1 price row/save logic'
        );

        $rows = $this->source('/plugins/catalogo_core/View/Hooks/partials/articulo_precios_rows.html.twig');
        $this->assertStringContainsString(
            'page=tarif_tab_precios',
            $rows,
            'Per-tarifa price editing must go through the WU-1 tab endpoint'
        );
    }

    public function test_mutations_gate_through_the_neutral_permission_event(): void
    {
        // Default resolution: zero listeners ⇒ allow.
        \FSFramework\Event\FSEventDispatcher::reset();
        $controller = new class() extends VentasArticulo {
            public function __construct()
            {
            }
            public function setUser(object $user): void
            {
                $this->user = $user;
            }
            public function callGuard(string $referencia, string $codtarifa): bool
            {
                return $this->puedeEditarArticulo($referencia, $codtarifa);
            }
        };
        $controller->setUser((object) ['admin' => false, 'nick' => 'user1']);

        $this->assertTrue(
            $controller->callGuard('REF-1', 'TAR-1'),
            'The neutral gate must default-allow when no listener is registered (ART-07)'
        );

        // Denied verdict: no factory, no save, stored values unchanged.
        $this->guardAllowed = false;
        $this->runEditar([
            'sreferencia' => 'REF-1',
            'sdescripcion' => 'MUTATED',
            'scodfamilia' => 'FAM-1',
        ]);

        $this->assertSame(1, $this->guardCalls);
        $this->assertSame(0, $this->articuloGetCalls, 'A denied mutation must not load the article model');
        $this->assertSame(0, $this->articuloSaveCount, 'A denied mutation must not save');
        $this->assertSame('', $this->trackedArticulo->descripcion, 'A denied mutation must leave stored values unchanged');
        $this->assertSame(0, $this->tarifaAddCalls, 'A denied mutation must not touch the per-tarifa row');
    }

    public function test_csrf_failure_runs_no_model_factory_or_save(): void
    {
        $this->csrfValid = false;
        $this->runEditar([
            'sreferencia' => 'REF-1',
            'scodfamilia' => 'FAM-1',
        ]);

        $this->assertSame(1, $this->csrfChecks, 'The CSRF check must run first');
        $this->assertSame(0, $this->guardCalls, 'CSRF must be validated before the permission gate');
        $this->assertSame(0, $this->articuloGetCalls, 'CSRF failure must reject before any model access');
        $this->assertSame(0, $this->articuloSaveCount, 'No save may happen without a valid CSRF token');
        $this->assertSame(0, $this->articuloFactoryCalls, 'No model factory may run without a valid CSRF token');
    }

    // =====================================================================
    // ART-03 — article-opcional contract preserved
    // =====================================================================

    public function test_article_opcional_contract_is_preserved(): void
    {
        $src = $this->source(self::CONTROLLER);

        $this->assertStringContainsString('public array $articulo_opcionales', $src);
        $this->assertStringContainsString('addOpcionalArticulo', $src);
        $this->assertStringContainsString('catalogo_articulo_opcional', $src, 'Add/remove must reuse the catalogo_core opcional models');

        $view = $this->source(self::VIEW);
        $this->assertStringContainsString('#opcionales', $view);
        $this->assertStringContainsString('tab_opcionales.html.twig', $view);
    }

    // =====================================================================
    // Threat matrix — destructive delete is POST-only and CSRF guarded
    // =====================================================================

    public function test_article_delete_is_csrf_guarded_post_only(): void
    {
        $src = $this->source(self::CONTROLLER);
        $delete = $this->methodSource($src, 'protected function eliminarArticulo');

        $this->assertStringContainsString('eliminar_articulo', $src, 'Delete must ride a POST mutation field');
        $this->assertStringContainsString('validateFormToken()', $delete, 'Delete must be CSRF validated');
        $this->assertStringContainsString('allow_delete', $delete, 'Delete must keep the allow_delete guard');
        $this->assertStringNotContainsString('FS_DEMO', $delete, 'FS_DEMO was removed — no demo guard must remain');

        $this->assertStringNotContainsString(
            "query->has('delete')",
            $src,
            'The legacy ?delete= GET branch must be removed (POST-only delete)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/get\(\s*[\'"]delete[\'"]/',
            $src,
            'No delete mutation may read a GET delete parameter'
        );
    }

    // =====================================================================
    // ART-04 — htmx 4 + Alpine CSP hygiene (view)
    // =====================================================================

    public function test_view_uses_htmx4_and_alpine_hygiene(): void
    {
        $view = $this->source(self::VIEW);

        $this->assertStringContainsString("htmx.boot({'allowScriptTags': false})", $view, 'Host view must boot htmx 4 once');
        $this->assertStringContainsString('alpine.boot()', $view, 'Host view must boot Alpine CSP once');
        $this->assertStringContainsString('Alpine.data(', $view, 'View logic must register Alpine components');
        $this->assertStringContainsString('alpine:init', $view, 'Registration must happen behind alpine:init');
        $this->assertStringContainsString('csp_nonce_attr()', $view, 'Scripts must carry the CSP nonce');
        $this->assertStringContainsString('[x-cloak]', $view, 'Hidden Alpine components must use [x-cloak]');
        $this->assertStringContainsString('hx-post', $view, 'Mutations must use hx-post');

        foreach (['bootbox', '|raw', 'htmx:afterSwap', 'htmx:afterRequest', 'hx-delete', 'hx-put', 'hx-patch'] as $banned) {
            $this->assertStringNotContainsString($banned, $view, $banned . ' is forbidden in the migrated view (ART-04)');
        }

        $this->assertDoesNotMatchRegularExpression(
            '/\bon(click|change|input|submit)\s*=/',
            $view,
            'Inline on* handlers must be replaced by Alpine x-on: colon bindings'
        );
    }

    public function test_view_has_no_legacy_jquery_or_bootbox_flows(): void
    {
        $view = $this->source(self::VIEW);

        foreach (['bootbox', 'FSAjaxLoader', 'securePost', '$(document).ready', '$.ajax', '(function ($)', 'jQuery'] as $banned) {
            $this->assertStringNotContainsString($banned, $view, 'Legacy jQuery/bootbox flow must be gone: ' . $banned);
        }
    }

    public function test_view_preserves_tabs_and_frozen_markers(): void
    {
        $view = $this->source(self::VIEW);

        // ART-05: ONE unified #datos pane hosts the article-scoped sections and
        // #opcionales stays the only secondary tab.
        $this->assertSame(
            1,
            substr_count($view, 'id="datos"'),
            'The migrated view must render exactly one #datos pane'
        );
        $this->assertStringContainsString('href="#datos"', $view, 'The unified pane must be the active tab');
        $this->assertStringContainsString(
            'href="#opcionales"',
            $view,
            'The #opcionales tab must survive as the only secondary tab'
        );
        $this->assertSame(
            2,
            substr_count($view, 'role="presentation"'),
            'Only #datos and #opcionales may remain as tabs (ART-05)'
        );
        foreach (['href="#precios"', 'href="#stock"'] as $retiredTab) {
            $this->assertStringNotContainsString(
                $retiredTab,
                $view,
                $retiredTab . ' must not remain a separate tab (ART-05)'
            );
        }
        foreach (['href="#precios-tarifa"', 'href="#stock-articulo"'] as $inPaneAnchor) {
            $this->assertStringContainsString(
                $inPaneAnchor,
                $view,
                $inPaneAnchor . ' must survive as an in-pane section anchor'
            );
        }

        // Locked literals for ART-08 (VentasArticuloControllerTest stays green).
        foreach (['#multiidioma', '#opcionales', 'tab_multiidioma.html.twig', 'tab_opcionales.html.twig', 'fsc.articulo.pvp'] as $locked) {
            $this->assertStringContainsString($locked, $view, 'Migrated view must keep the locked literal ' . $locked);
        }

        foreach (['ventas_articulo_tabs_after', 'ventas_articulo_tab_pane_after'] as $marker) {
            $this->assertStringContainsString(
                "render_hook('" . $marker . "', {'fsc': fsc, 'user': user, 'empresa': empresa, 'i18n': i18n,"
                . " 'caracteristicas': fsc.caracteristicas_context()})",
                $view,
                'Migrated view must keep the frozen marker ' . $marker
                . ' with its four frozen keys and the added feature context'
            );
        }
    }

    // =====================================================================
    // ART-06 — inbound links repoint to the canonical detail
    // =====================================================================

    public function test_inbound_links_repoint_to_the_canonical_detail(): void
    {
        $precio = $this->source(self::PRECIO_MODEL);
        $precioUrl = $this->methodSource($precio, 'public function url()');
        $this->assertStringContainsString('page=ventas_articulo', $precioUrl, 'tarif_articulo_precio::url() must repoint to the canonical detail');
        $this->assertStringNotContainsString('tarif_articulo_edit', $precioUrl);
        $this->assertStringNotContainsString('tarif_articulo_precios', $precioUrl);

        $tarifArticulo = $this->source('/plugins/tarifario/model/tarif_articulo.php');
        $urlMethod = $this->methodSource($tarifArticulo, 'public function url()');
        $this->assertStringContainsString('page=ventas_articulo', $urlMethod, 'tarif_articulo::url() must repoint to the canonical detail');
        $this->assertStringNotContainsString('tarif_articulo_edit', $urlMethod);
        if (strpos($tarifArticulo, 'function url_tarifario') !== false) {
            $urlTarifario = $this->methodSource($tarifArticulo, 'function url_tarifario');
            $delegates = strpos($urlTarifario, '$this->url()') !== false;
            $this->assertTrue(
                $delegates || strpos($urlTarifario, 'page=ventas_articulo') !== false,
                'url_tarifario() must resolve to the canonical detail (directly or via url())'
            );
            $this->assertStringNotContainsString('tarif_articulo_edit', $urlTarifario);
        }

        foreach ([
            '/plugins/tarifario/View/tarif_actualizar_precios.html.twig',
            '/plugins/tarifario/View/tarif_historial_precios.html.twig',
        ] as $viewPath) {
            $src = $this->source($viewPath);
            $this->assertStringNotContainsString(
                'tarif_articulo_edit',
                $src,
                $viewPath . ' must not link the retired edit slug'
            );
        }
    }
}
