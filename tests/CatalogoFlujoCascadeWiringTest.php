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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_functions.php';
require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_flujo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_flujo_condicion.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_flujo_accion.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_flujo_articulo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_flujo_familia.php';

/**
 * Shared ordering journal: both the cascade recorder and the fake connection
 * append here so the tests can prove the cleanup runs around the row DELETE.
 */
final class CascadeWiringJournal
{
    /** @var list<string> */
    public array $events = [];

    public function add(string $event): void
    {
        $this->events[] = $event;
    }
}

/**
 * Records the FLC-09 cascade calls an owning `delete()` must trigger. Typed
 * methods mirror the real collaborator signatures.
 */
final class FlujoCascadeRecorder
{
    /** @var list<array{0:string,1:string}> */
    public array $subjectCalls = [];

    /** @var list<string> */
    public array $referenciaCalls = [];

    /** @var list<string> */
    public array $familiaCalls = [];

    public function __construct(private CascadeWiringJournal $journal)
    {
    }

    public function delete_all_from_sujeto(string $sujetoTipo, string $codigo): bool
    {
        $this->subjectCalls[] = [$sujetoTipo, $codigo];
        $this->journal->add('cascade:sujeto:' . $sujetoTipo . ':' . $codigo);

        return true;
    }

    public function delete_all_from_referencia(string $referencia): bool
    {
        $this->referenciaCalls[] = $referencia;
        $this->journal->add('cascade:referencia:' . $referencia);

        return true;
    }

    public function delete_all_from_familia(string $codfamilia): bool
    {
        $this->familiaCalls[] = $codfamilia;
        $this->journal->add('cascade:familia:' . $codfamilia);

        return true;
    }
}

/**
 * DB-free connection double shared by the owning delete paths. `$failExec`
 * simulates a rejected row DELETE so the cascade-ordering contract can prove
 * the flow cleanup is skipped when the owning row survives.
 */
final class CascadeWiringFakeDb
{
    /** @var list<string> */
    public array $executed = [];

    public bool $failExec = false;

    public function __construct(private CascadeWiringJournal $journal)
    {
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false): bool
    {
        $this->executed[] = trim((string) $sql);
        $this->journal->add('exec');

        return !$this->failExec;
    }

    public function var2str($val): string
    {
        if ($val === null) {
            return 'NULL';
        }
        if (is_bool($val)) {
            return $val ? '1' : '0';
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }

        return "'" . addslashes((string) $val) . "'";
    }
}

/**
 * FLC-09 app-side cascade wiring: deleting an opcional, a grupo, an artículo or
 * a familia must remove the flujo conditions/actions/assignments that reference
 * it. These tests drive each owning `delete()` through DB-free seams and assert
 * the exact cleanup call, mirroring the resolved `delete_all_from_*` helpers.
 *
 * The owning models are required in `setUp()` (not at file scope) because some
 * sibling suites declare namespace-level stubs for `FSFramework\model\familia`
 * that would collide during test discovery.
 */
final class CatalogoFlujoCascadeWiringTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['plugins'] = [];

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo_rel.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional_grupo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
    }

    public function test_deleting_opcional_cleans_conditions_and_actions_by_codigo(): void
    {
        $journal = new CascadeWiringJournal();
        $db = new CascadeWiringFakeDb($journal);
        $condicion = new FlujoCascadeRecorder($journal);
        $accion = new FlujoCascadeRecorder($journal);

        $model = new class($db, $condicion, $accion) extends \FSFramework\model\catalogo_opcional {
            private FlujoCascadeRecorder $condRef;
            private FlujoCascadeRecorder $accRef;

            public function __construct($db, $cond, $acc)
            {
                $this->condRef = $cond;
                $this->accRef = $acc;
                $this->db = $db;
                $this->table_name = 'catalogo_opcionales';
                $this->id = 7;
                $this->codigo = 'OPC1';
            }

            protected function grupo_rel_model(): \FSFramework\model\catalogo_opcional_grupo_rel
            {
                return new class() extends \FSFramework\model\catalogo_opcional_grupo_rel {
                    public function __construct()
                    {
                    }

                    public function delete_all_from_opcional(int $idOpcional): bool
                    {
                        return true;
                    }
                };
            }

            protected function flujo_condicion_model()
            {
                return $this->condRef;
            }

            protected function flujo_accion_model()
            {
                return $this->accRef;
            }
        };

        $this->assertTrue($model->delete());

        $this->assertSame(
            [['opcional', 'OPC1']],
            $condicion->subjectCalls,
            'deleting an opcional must clean its conditions by (tipo, código)'
        );
        $this->assertSame(
            [['opcional', 'OPC1']],
            $accion->subjectCalls,
            'deleting an opcional must clean its actions by (tipo, código)'
        );
        $this->assertContains('DELETE FROM catalogo_opcionales WHERE id = 7;', $db->executed);
        $this->assertSame(
            ['exec', 'cascade:sujeto:opcional:OPC1', 'cascade:sujeto:opcional:OPC1'],
            $journal->events,
            'both cascade cleanups must run only after the owning row DELETE succeeds'
        );
    }

    public function test_failed_opcional_delete_skips_the_flow_cascade(): void
    {
        $journal = new CascadeWiringJournal();
        $db = new CascadeWiringFakeDb($journal);
        $db->failExec = true;
        $condicion = new FlujoCascadeRecorder($journal);
        $accion = new FlujoCascadeRecorder($journal);

        $model = new class($db, $condicion, $accion) extends \FSFramework\model\catalogo_opcional {
            private FlujoCascadeRecorder $condRef;
            private FlujoCascadeRecorder $accRef;

            public function __construct($db, $cond, $acc)
            {
                $this->condRef = $cond;
                $this->accRef = $acc;
                $this->db = $db;
                $this->table_name = 'catalogo_opcionales';
                $this->id = 7;
                $this->codigo = 'OPC1';
            }

            protected function grupo_rel_model(): \FSFramework\model\catalogo_opcional_grupo_rel
            {
                return new class() extends \FSFramework\model\catalogo_opcional_grupo_rel {
                    public function __construct()
                    {
                    }

                    public function delete_all_from_opcional(int $idOpcional): bool
                    {
                        return true;
                    }
                };
            }

            protected function flujo_condicion_model()
            {
                return $this->condRef;
            }

            protected function flujo_accion_model()
            {
                return $this->accRef;
            }
        };

        $this->assertFalse($model->delete(), 'a failed owning DELETE must be reported as a failure');
        $this->assertSame([], $condicion->subjectCalls, 'a failed opcional DELETE must not clean flow conditions');
        $this->assertSame([], $accion->subjectCalls, 'a failed opcional DELETE must not clean flow actions');
    }

    public function test_deleting_grupo_cleans_conditions_and_actions_by_codigo(): void
    {
        $journal = new CascadeWiringJournal();
        $db = new CascadeWiringFakeDb($journal);
        $condicion = new FlujoCascadeRecorder($journal);
        $accion = new FlujoCascadeRecorder($journal);

        $model = new class($db, $condicion, $accion) extends \FSFramework\model\catalogo_opcional_grupo {
            private FlujoCascadeRecorder $condRef;
            private FlujoCascadeRecorder $accRef;

            public function __construct($db, $cond, $acc)
            {
                $this->condRef = $cond;
                $this->accRef = $acc;
                $this->db = $db;
                $this->table_name = 'catalogo_opcional_grupos';
                $this->id = 3;
                $this->codigo = 'GRP1';
            }

            protected function grupo_rel_model(): \FSFramework\model\catalogo_opcional_grupo_rel
            {
                return new class() extends \FSFramework\model\catalogo_opcional_grupo_rel {
                    public function __construct()
                    {
                    }

                    public function delete_all_from_grupo(int $idGrupo): bool
                    {
                        return true;
                    }
                };
            }

            protected function articulo_opcional_grupo_model(): \FSFramework\model\catalogo_articulo_opcional_grupo
            {
                return new class() extends \FSFramework\model\catalogo_articulo_opcional_grupo {
                    public function __construct()
                    {
                    }

                    public function delete_all_from_grupo(int $idGrupo): bool
                    {
                        return true;
                    }
                };
            }

            protected function flujo_condicion_model()
            {
                return $this->condRef;
            }

            protected function flujo_accion_model()
            {
                return $this->accRef;
            }
        };

        $this->assertTrue($model->delete());

        $this->assertSame([['grupo', 'GRP1']], $condicion->subjectCalls);
        $this->assertSame([['grupo', 'GRP1']], $accion->subjectCalls);
        $this->assertContains('DELETE FROM catalogo_opcional_grupos WHERE id = 3;', $db->executed);
        $this->assertSame(
            ['exec', 'cascade:sujeto:grupo:GRP1', 'cascade:sujeto:grupo:GRP1'],
            $journal->events,
            'the grupo cascade must run only after the owning row DELETE succeeds'
        );
    }

    public function test_failed_grupo_delete_skips_the_flow_cascade(): void
    {
        $journal = new CascadeWiringJournal();
        $db = new CascadeWiringFakeDb($journal);
        $db->failExec = true;
        $condicion = new FlujoCascadeRecorder($journal);
        $accion = new FlujoCascadeRecorder($journal);

        $model = new class($db, $condicion, $accion) extends \FSFramework\model\catalogo_opcional_grupo {
            private FlujoCascadeRecorder $condRef;
            private FlujoCascadeRecorder $accRef;

            public function __construct($db, $cond, $acc)
            {
                $this->condRef = $cond;
                $this->accRef = $acc;
                $this->db = $db;
                $this->table_name = 'catalogo_opcional_grupos';
                $this->id = 3;
                $this->codigo = 'GRP1';
            }

            protected function grupo_rel_model(): \FSFramework\model\catalogo_opcional_grupo_rel
            {
                return new class() extends \FSFramework\model\catalogo_opcional_grupo_rel {
                    public function __construct()
                    {
                    }

                    public function delete_all_from_grupo(int $idGrupo): bool
                    {
                        return true;
                    }
                };
            }

            protected function articulo_opcional_grupo_model(): \FSFramework\model\catalogo_articulo_opcional_grupo
            {
                return new class() extends \FSFramework\model\catalogo_articulo_opcional_grupo {
                    public function __construct()
                    {
                    }

                    public function delete_all_from_grupo(int $idGrupo): bool
                    {
                        return true;
                    }
                };
            }

            protected function flujo_condicion_model()
            {
                return $this->condRef;
            }

            protected function flujo_accion_model()
            {
                return $this->accRef;
            }
        };

        $this->assertFalse($model->delete(), 'a failed owning DELETE must be reported as a failure');
        $this->assertSame([], $condicion->subjectCalls, 'a failed grupo DELETE must not clean flow conditions');
        $this->assertSame([], $accion->subjectCalls, 'a failed grupo DELETE must not clean flow actions');
    }

    public function test_deleting_articulo_cleans_flow_assignments_by_referencia(): void
    {
        $journal = new CascadeWiringJournal();
        $db = new CascadeWiringFakeDb($journal);
        $spy = new FlujoCascadeRecorder($journal);

        $model = new class($db, $spy) extends \FSFramework\model\articulo {
            private FlujoCascadeRecorder $spyRef;

            public function __construct($db, $spy)
            {
                $this->spyRef = $spy;
                $this->db = $db;
                $this->table_name = 'articulos';
                $this->referencia = 'ART1';
                $this->exists = true;
            }

            public function set_imagen($img, $png = true)
            {
            }

            protected function flujo_articulo_model()
            {
                return $this->spyRef;
            }
        };

        $this->assertTrue($model->delete());

        $this->assertSame(['ART1'], $spy->referenciaCalls, 'the article reference must reach the flow assignment cleanup');
        $this->assertTrue(
            $this->executedContains($db, "DELETE FROM articulos WHERE referencia = 'ART1';"),
            'the owning article row DELETE must still run'
        );
        $this->assertSame(
            ['exec', 'cascade:referencia:ART1'],
            $journal->events,
            'the article flow cleanup must run only after the owning row DELETE succeeds'
        );
    }

    public function test_failed_articulo_delete_skips_the_flow_cascade(): void
    {
        $journal = new CascadeWiringJournal();
        $db = new CascadeWiringFakeDb($journal);
        $db->failExec = true;
        $spy = new FlujoCascadeRecorder($journal);

        $model = new class($db, $spy) extends \FSFramework\model\articulo {
            private FlujoCascadeRecorder $spyRef;

            public function __construct($db, $spy)
            {
                $this->spyRef = $spy;
                $this->db = $db;
                $this->table_name = 'articulos';
                $this->referencia = 'ART1';
                $this->exists = true;
            }

            public function set_imagen($img, $png = true)
            {
            }

            protected function flujo_articulo_model()
            {
                return $this->spyRef;
            }
        };

        $this->assertFalse($model->delete(), 'a failed owning DELETE must be reported as a failure');
        $this->assertSame([], $spy->referenciaCalls, 'a failed article DELETE must not clean flow assignments');
    }

    public function test_deleting_familia_cleans_flow_assignments_by_codfamilia(): void
    {
        $journal = new CascadeWiringJournal();
        $db = new CascadeWiringFakeDb($journal);
        $spy = new FlujoCascadeRecorder($journal);

        $model = new class($db, $spy) extends \FSFramework\model\familia {
            private FlujoCascadeRecorder $spyRef;

            public function __construct($db, $spy)
            {
                $this->spyRef = $spy;
                $this->db = $db;
                $this->cache = new class {
                    public function delete($key)
                    {
                        return true;
                    }
                };
                $this->table_name = 'familias';
                $this->codfamilia = 'FAM1';
                $this->madre = null;
            }

            protected function flujo_familia_model()
            {
                return $this->spyRef;
            }
        };

        $this->assertTrue($model->delete());

        $this->assertSame(['FAM1'], $spy->familiaCalls, 'the family code must reach the flow assignment cleanup');
        $this->assertTrue(
            $this->executedContains($db, "DELETE FROM familias WHERE codfamilia = 'FAM1';"),
            'the owning family row DELETE must still run'
        );
        $this->assertSame(
            ['exec', 'cascade:familia:FAM1'],
            $journal->events,
            'the family flow cleanup must run only after the owning row DELETE succeeds'
        );
    }

    public function test_failed_familia_delete_skips_the_flow_cascade(): void
    {
        $journal = new CascadeWiringJournal();
        $db = new CascadeWiringFakeDb($journal);
        $db->failExec = true;
        $spy = new FlujoCascadeRecorder($journal);

        $model = new class($db, $spy) extends \FSFramework\model\familia {
            private FlujoCascadeRecorder $spyRef;

            public function __construct($db, $spy)
            {
                $this->spyRef = $spy;
                $this->db = $db;
                $this->cache = new class {
                    public function delete($key)
                    {
                        return true;
                    }
                };
                $this->table_name = 'familias';
                $this->codfamilia = 'FAM1';
                $this->madre = null;
            }

            protected function flujo_familia_model()
            {
                return $this->spyRef;
            }
        };

        $this->assertFalse($model->delete(), 'a failed owning DELETE must be reported as a failure');
        $this->assertSame([], $spy->familiaCalls, 'a failed family DELETE must not clean flow assignments');
    }

    public function test_cascade_helpers_are_used_by_the_owning_models(): void
    {
        $expectations = [
            FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php' => 'delete_all_from_sujeto',
            FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo.php' => 'delete_all_from_sujeto',
            FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php' => 'delete_all_from_referencia',
            FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php' => 'delete_all_from_familia',
        ];

        foreach ($expectations as $path => $helper) {
            $this->assertFileExists($path);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString(
                $helper,
                $source,
                basename($path) . ' must invoke its FLC-09 cascade helper'
            );
        }
    }

    private function executedContains(CascadeWiringFakeDb $db, string $fragment): bool
    {
        foreach ($db->executed as $statement) {
            if (str_contains($statement, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
