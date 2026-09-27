<?php
/**
 * This file is part of the catalogo_core plugin
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

/**
 * CAR-20 — enabled-plugin ownership scope for feature definitions.
 *
 * A definition is active when its `origen` is empty (operator-created) or when
 * a non-empty `origen` names a plugin the framework registry reports as
 * enabled. The predicate is DB-free: it reads only the row's `origen` and the
 * enabled-plugin set.
 *
 * Two control mechanisms are exercised here:
 *  - an anonymous subclass overriding `enabled_plugins()` (a fixed set), and
 *  - the real helper reading `$GLOBALS['plugins']` (pinned to `[]` by
 *    `tests/bootstrap.php`), which proves the fail-closed default.
 */
final class CaracteristicaOwnershipTest extends TestCase
{
    private static bool $baseLoaded = false;

    /** @var array<int, string> */
    private array $pluginsSnapshot = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaOwnership.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaResolver.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaValorBatchReader.php';
        require_once FS_FOLDER . '/src/Controller/PageController.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasCaracteristicas.php';
        self::$baseLoaded = true;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginsSnapshot = $GLOBALS['plugins'] ?? [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['plugins'] = $this->pluginsSnapshot;

        parent::tearDown();
    }

    /**
     * Ownership helper with a frozen enabled set (DB-free seam override).
     *
     * @param array<int, string> $enabled
     */
    private function ownershipWith(array $enabled): object
    {
        return new class($enabled) extends \FSFramework\Plugins\catalogo_core\Services\CaracteristicaOwnership {
            /** @param array<int, string> $enabled */
            public function __construct(private array $enabled)
            {
            }

            /** @return array<int, string> */
            protected function enabled_plugins(): array
            {
                return $this->enabled;
            }
        };
    }

    /** Plugin-owned model built without a DB (the constructor is bypassed). */
    private function pluginOwnedModel(string $origen): object
    {
        return new class($origen) extends \FSFramework\model\catalogo_caracteristica {
            public function __construct(string $origenValue)
            {
                $this->id = 4;
                $this->origen = $origenValue;
            }
        };
    }

    // =====================================================================
    // CAR-20 (c) — operator rows are the unconditional exception
    // =====================================================================

    public function test_empty_origen_is_active_regardless_of_the_enabled_set(): void
    {
        $this->assertTrue($this->ownershipWith([])->is_active(''));
        $this->assertTrue($this->ownershipWith(['tarifario'])->is_active(''));
    }

    // =====================================================================
    // CAR-20 (a) — enabled owner is active, disabled owner is inert
    // =====================================================================

    public function test_non_empty_origen_is_active_only_when_its_owner_is_enabled(): void
    {
        $helper = $this->ownershipWith(['tarifario', 'catalogo_core']);

        $this->assertTrue($helper->is_active('tarifario'));
        $this->assertTrue($helper->is_active('catalogo_core'));
        $this->assertFalse($helper->is_active('otro_plugin'));
    }

    public function test_origen_surrounding_whitespace_is_trimmed_before_the_check(): void
    {
        $helper = $this->ownershipWith(['tarifario']);

        $this->assertTrue($helper->is_active('  tarifario  '));
        $this->assertTrue($helper->is_active("\ttarifario\n"));
        $this->assertFalse($helper->is_active('  otro_plugin  '));
    }

    // =====================================================================
    // CAR-20 (d) — registry unavailable fails closed (D7)
    // =====================================================================

    public function test_registry_unavailable_fails_closed_for_plugin_owned_definitions(): void
    {
        $GLOBALS['plugins'] = [];
        $helper = new \FSFramework\Plugins\catalogo_core\Services\CaracteristicaOwnership();

        $this->assertTrue($helper->is_active(''));
        $this->assertTrue($helper->is_active('   '));
        $this->assertFalse($helper->is_active('tarifario'));
        $this->assertFalse($helper->is_active('catalogo_core'));

        // A populated registry flips the same name to active on a fresh instance.
        $GLOBALS['plugins'] = ['tarifario'];
        $fresh = new \FSFramework\Plugins\catalogo_core\Services\CaracteristicaOwnership();
        $this->assertTrue($fresh->is_active('tarifario'));
        $this->assertFalse($fresh->is_active('catalogo_core'));
    }

    // =====================================================================
    // CAR-11 — plugin-owned inert while disabled, still non-deletable
    // =====================================================================

    public function test_plugin_owned_definition_is_inert_while_disabled_and_stays_non_deletable(): void
    {
        $model = $this->pluginOwnedModel('tarifario');

        $this->assertFalse($model->is_deletable(), 'a non-empty origen is never deletable');

        $GLOBALS['plugins'] = [];
        $disabled = new \FSFramework\Plugins\catalogo_core\Services\CaracteristicaOwnership();
        $this->assertFalse(
            $disabled->is_active((string) $model->origen),
            'a disabled owner makes the definition inert'
        );

        $GLOBALS['plugins'] = ['tarifario'];
        $enabled = new \FSFramework\Plugins\catalogo_core\Services\CaracteristicaOwnership();
        $this->assertTrue(
            $enabled->is_active((string) $model->origen),
            're-enabling the owner makes the definition active again, unchanged'
        );
        $this->assertFalse(
            $model->is_deletable(),
            'enabling the owner does not make a plugin-owned definition deletable'
        );
    }

    // =====================================================================
    // Cross-path agreement — resolver, panel listing and batch reader
    // =====================================================================

    /**
     * One operator row (`origen = ''`) plus one plugin row
     * (`origen = 'tarifario'`), both `listable`.
     *
     * @return list<array<string, mixed>>
     */
    private function threePathFixture(): array
    {
        return [
            [
                'id' => 10,
                'codigo' => 'medidas',
                'nombre' => 'Medidas',
                'tipo' => 'string',
                'activo' => true,
                'listable' => true,
                'orden' => 0,
                'origen' => '',
                'valor_defecto' => null,
            ],
            [
                'id' => 20,
                'codigo' => 'en_catalogo',
                'nombre' => 'En Catálogo',
                'tipo' => 'bool',
                'activo' => true,
                'listable' => true,
                'orden' => 1,
                'origen' => 'tarifario',
                'valor_defecto' => null,
            ],
        ];
    }

    /**
     * The active `codigo` set from each of the three independent read paths,
     * fed the same fixture. Fresh instances are built per call, so the enabled
     * set must be re-read after a toggle (D9).
     *
     * @param list<array<string, mixed>> $fixture
     * @return array{resolver: list<string>, panel: list<string>, reader: list<string>}
     */
    private function activeSetsFromThreeReadPaths(array $fixture): array
    {
        $resolver = new class($fixture) extends \FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            protected function definition_model()
            {
                return new class($this->rows) {
                    /** @param list<array<string, mixed>> $rows */
                    public function __construct(private array $rows)
                    {
                    }

                    /** @return list<array<string, mixed>> */
                    public function all(bool $onlyActive = false): array
                    {
                        if (!$onlyActive) {
                            return $this->rows;
                        }

                        return array_values(array_filter(
                            $this->rows,
                            static fn (array $definition): bool => (bool) ($definition['activo'] ?? true)
                        ));
                    }
                };
            }
        };

        $panel = new class($fixture) extends \FSFramework\Plugins\catalogo_core\Controller\VentasCaracteristicas {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            public function exposeLoadDefiniciones(): void
            {
                $this->load_definiciones();
            }

            protected function caracteristica_model()
            {
                return new class($this->rows) {
                    /** @param list<array<string, mixed>> $rows */
                    public function __construct(private array $rows)
                    {
                    }

                    /** @return list<object> */
                    public function all($onlyActive = false): array
                    {
                        return array_map(
                            static fn (array $row): object => (object) [
                                'codigo' => (string) $row['codigo'],
                                'origen' => (string) ($row['origen'] ?? ''),
                            ],
                            $this->rows
                        );
                    }
                };
            }
        };

        $reader = new class($fixture) extends \FSFramework\Plugins\catalogo_core\Services\CaracteristicaValorBatchReader {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            protected function db()
            {
                return new class($this->rows) {
                    /** @param list<array<string, mixed>> $rows */
                    public function __construct(private array $rows)
                    {
                    }

                    /** @return list<array<string, mixed>> */
                    public function select($sql): array
                    {
                        return $this->rows;
                    }

                    public function var2str($value): string
                    {
                        return "'" . $value . "'";
                    }
                };
            }
        };

        $panel->exposeLoadDefiniciones();

        $sets = [
            'resolver' => array_keys($resolver->definitions()),
            'panel' => array_map(
                static fn ($definition): string => (string) $definition->codigo,
                $panel->definiciones
            ),
            'reader' => array_map(
                static fn (array $column): string => (string) $column['codigo'],
                $reader->columns()
            ),
        ];

        foreach ($sets as &$set) {
            sort($set);
        }
        unset($set);

        return $sets;
    }

    public function test_all_three_read_paths_agree_on_the_active_set(): void
    {
        $fixture = $this->threePathFixture();

        // Disabled owner: only the operator row is active, identically everywhere.
        $GLOBALS['plugins'] = [];
        $disabled = $this->activeSetsFromThreeReadPaths($fixture);
        $this->assertSame(['medidas'], $disabled['resolver']);
        $this->assertSame($disabled['resolver'], $disabled['panel'], 'the panel must agree with the resolver');
        $this->assertSame($disabled['resolver'], $disabled['reader'], 'the batch reader must agree with the resolver');

        // Re-enabled owner on fresh instances: both rows become active, still in agreement.
        $GLOBALS['plugins'] = ['tarifario'];
        $enabled = $this->activeSetsFromThreeReadPaths($fixture);
        $this->assertSame(['en_catalogo', 'medidas'], $enabled['resolver']);
        $this->assertSame($enabled['resolver'], $enabled['panel'], 'the panel must agree with the resolver');
        $this->assertSame($enabled['resolver'], $enabled['reader'], 'the batch reader must agree with the resolver');
    }
}
