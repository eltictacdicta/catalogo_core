<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for default feature registration (CAR-11).
 *
 * Registration MUST be a targeted idempotent upsert (never `seed_if_empty`),
 * MUST run on fresh and existing installs (init + upgrade), MUST persist
 * `origen`, and a plugin-registered definition (non-empty origen) MUST NOT be
 * deletable while remaining deactivatable.
 */
final class CaracteristicaRegistrySpyDb
{
    /** @var list<string> */
    public array $execStatements = [];

    /** @var list<string> */
    public array $selectStatements = [];

    /** @var list<list<array<string, mixed>>> */
    public array $selectQueue = [];

    public function var2str($val)
    {
        if ($val === null) {
            return 'NULL';
        }
        if (is_bool($val)) {
            return $val ? 'TRUE' : 'FALSE';
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function select($sql, $params = [])
    {
        $this->selectStatements[] = trim((string) $sql);

        if ($this->selectQueue !== []) {
            return array_shift($this->selectQueue);
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $this->execStatements[] = trim((string) $sql);

        return true;
    }
}

final class CaracteristicaDefaultsTest extends TestCase
{
    private static bool $baseLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_valor.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/caracteristica_scope_value.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaRegistry.php';
        self::$baseLoaded = true;
    }

    private function registryClass(): string
    {
        return \FSFramework\Plugins\catalogo_core\Services\CaracteristicaRegistry::class;
    }

    /** @return list<string> */
    private function statementsStartingWith(array $statements, string $prefix): array
    {
        return array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_starts_with($sql, $prefix)
        ));
    }

    public function test_catalogo_core_default_is_pinned(): void
    {
        $defaults = $this->registryClass()::DEFAULTS;

        $this->assertNotEmpty($defaults);
        $medidas = null;
        foreach ($defaults as $default) {
            if (($default['codigo'] ?? '') === 'medidas') {
                $medidas = $default;
            }
        }

        $this->assertNotNull($medidas, 'catalogo_core must register its medidas default');
        $this->assertSame('Medidas', $medidas['nombre']);
        $this->assertSame('string', $medidas['tipo']);
        $this->assertSame('catalogo_core', $medidas['origen']);
        $this->assertSame(10, $medidas['orden']);
        $this->assertFalse($medidas['importable']);
        $this->assertFalse($medidas['exportable']);
        $this->assertFalse($medidas['listable']);
        $this->assertNull($medidas['valor_defecto']);
    }

    public function test_register_default_uses_a_targeted_idempotent_upsert(): void
    {
        $db = new CaracteristicaRegistrySpyDb();
        $db->selectQueue = [[]]; // no existing bool definition id needed for a string default

        $ok = $this->registryClass()::registerDefault(
            'medidas',
            'Medidas',
            'string',
            'catalogo_core',
            ['orden' => 10],
            $db
        );

        $this->assertTrue($ok);
        $inserts = $this->statementsStartingWith($db->execStatements, 'INSERT');
        $this->assertCount(1, $inserts, 'exactly one targeted upsert');
        $this->assertStringContainsString('catalogo_caracteristicas', $inserts[0]);
        $this->assertStringContainsString("'medidas'", $inserts[0]);
        $this->assertStringContainsString("'catalogo_core'", $inserts[0]);
        $this->assertMatchesRegularExpression(
            '/ON CONFLICT \(codigo\) DO NOTHING;|INSERT IGNORE INTO/i',
            $inserts[0],
            'the upsert must be idempotent and never overwrite operator edits'
        );
        $this->assertStringNotContainsString('UPDATE', $inserts[0]);
        $this->assertStringNotContainsString('seed_if_empty', implode(' ', $db->execStatements));
    }

    public function test_register_default_seeds_the_bool_pair(): void
    {
        $db = new CaracteristicaRegistrySpyDb();
        // Definition lookup returns the registered id, then the pair seed runs.
        $db->selectQueue = [[['id' => 7]]];

        $this->registryClass()::registerDefault(
            'en_catalogo',
            'En Catálogo',
            'bool',
            'tarifario',
            ['importable' => true, 'orden' => 20],
            $db
        );

        $inserts = $this->statementsStartingWith($db->execStatements, 'INSERT');
        $this->assertGreaterThanOrEqual(3, count($inserts), 'definition + the two bool pair rows');

        $pairSql = implode("\n", array_slice($inserts, 1));
        $this->assertStringContainsString("'1'", $pairSql);
        $this->assertStringContainsString("'0'", $pairSql);
        $this->assertMatchesRegularExpression(
            '/ON CONFLICT \(id_caracteristica, valor\) DO NOTHING|INSERT IGNORE INTO/i',
            $pairSql,
            'the bool pair seed must be idempotent'
        );
    }

    public function test_running_register_defaults_twice_changes_nothing(): void
    {
        $db = new CaracteristicaRegistrySpyDb();
        $db->selectQueue = [[], [['id' => 3]]];

        $this->registryClass()::registerDefaults($db);
        $firstRun = $db->execStatements;

        $db->selectQueue = [[], [['id' => 3]]];
        $this->registryClass()::registerDefaults($db);
        $secondRun = array_slice($db->execStatements, count($firstRun));

        $this->assertSame(
            count($firstRun),
            count($secondRun),
            'a second run must emit the same guarded statements, never an unconditional write'
        );

        foreach ($secondRun as $sql) {
            $this->assertMatchesRegularExpression(
                '/ON CONFLICT .* DO NOTHING|INSERT IGNORE INTO/i',
                $sql,
                'every second-run statement must be guarded'
            );
        }
    }

    public function test_init_and_upgrade_wire_default_registration(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/Init.php');

        $this->assertStringContainsString(
            'use FSFramework\\Plugins\\catalogo_core\\Services\\CaracteristicaRegistry;',
            $src,
            'Init must import the registry'
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($src, 'CaracteristicaRegistry::registerDefaults()'),
            'both init() and upgrade() must (re)register the defaults so boot order cannot skip them'
        );
    }

    public function test_registered_default_cannot_be_deleted_but_can_be_deactivated(): void
    {
        $db = new CaracteristicaRegistrySpyDb();
        $model = new class($db) extends \FSFramework\model\catalogo_caracteristica {
            /** @var list<string> */
            public array $errors = [];

            public function __construct($db)
            {
                $this->table_name = 'catalogo_caracteristicas';
                $this->db = $db;
                $this->id = 4;
                $this->codigo = 'medidas';
                $this->nombre = 'Medidas';
                $this->tipo = 'string';
                $this->activo = true;
                $this->importable = false;
                $this->exportable = false;
                $this->listable = false;
                $this->orden = 10;
                $this->origen = 'catalogo_core';
                $this->valor_defecto = null;
            }

            protected function new_error_msg($msg)
            {
                $this->errors[] = (string) $msg;
            }
        };

        $this->assertFalse($model->is_deletable(), 'a definition with origen must not be deletable');
        $this->assertFalse($model->delete(), 'delete() must refuse a registered default');
        $this->assertSame([], $db->execStatements, 'the refused delete writes nothing');
        $this->assertNotSame([], $model->errors);

        // Deactivation is allowed and persists.
        // test() checks the codigo (no existing row), then exists() finds the row.
        $db->selectQueue = [[], [['id' => 4]]];
        $model->activo = false;
        $this->assertTrue($model->save(), 'a registered default may be deactivated');
        $updates = $this->statementsStartingWith($db->execStatements, 'UPDATE');
        $this->assertCount(1, $updates);
        $this->assertStringContainsString('activo = FALSE', $updates[0]);
    }
}
