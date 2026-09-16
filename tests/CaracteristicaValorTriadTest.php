<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the value triad invariant (CAR-04) on the shared
 * abstract scope base.
 *
 * DB-free: an anonymous subclass of the global scope model stubs the
 * `definition_for()` / `catalogo_value_for()` seams and never touches a
 * database. `custom` must be derived, never accepted as caller input.
 */
final class CaracteristicaValorTriadTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $definition = null;

    /** @var array<string, mixed>|false */
    private $catalogValue = false;

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
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_global.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_familia.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_articulo.php';
        self::$baseLoaded = true;
    }

    private function buildScopeModel(): object
    {
        $definition = $this->definition;
        $catalogValue = $this->catalogValue;

        return new class($definition, $catalogValue) extends \FSFramework\model\catalogo_caracteristica_global {
            /** @var list<string> */
            public array $errors = [];

            private ?array $definition;

            /** @var array<string, mixed>|false */
            private $catalogValue;

            public function __construct(?array $definition = null, $catalogValue = false)
            {
                $this->table_name = 'catalogo_caracteristica_global';
                $this->db = null;
                $this->definition = $definition;
                $this->catalogValue = $catalogValue;
                $this->codtarifa = null;
                $this->id_caracteristica = null;
                $this->id_valor = null;
                $this->valor = null;
                $this->custom = false;
            }

            protected function new_error_msg($msg)
            {
                $this->errors[] = (string) $msg;
            }

            protected function definition_for(int $idCaracteristica)
            {
                return $this->definition;
            }

            protected function catalogo_value_for(int $idValor)
            {
                return $this->catalogValue;
            }
        };
    }

    public function test_exactly_one_value_representation_is_enforced(): void
    {
        $this->definition = ['id' => 1, 'tipo' => 'string'];

        $both = $this->buildScopeModel();
        $both->codtarifa = 'T1';
        $both->id_caracteristica = 1;
        $both->id_valor = 3;
        $both->valor = 'custom';
        $this->assertFalse($both->test(), 'a row with both id_valor and valor must be rejected');
        $this->assertNotSame([], $both->errors);

        $neither = $this->buildScopeModel();
        $neither->codtarifa = 'T1';
        $neither->id_caracteristica = 1;
        $this->assertFalse($neither->test(), 'a row with neither representation must be rejected');
        $this->assertNotSame([], $neither->errors);
    }

    public function test_bool_rejects_custom_values(): void
    {
        $this->definition = ['id' => 2, 'tipo' => 'bool'];

        $model = $this->buildScopeModel();
        $model->codtarifa = 'T1';
        $model->id_caracteristica = 2;
        $model->valor = 'custom';
        $model->custom = true;

        $this->assertFalse($model->test(), 'a bool definition must reject a custom value');
        $this->assertNotSame([], $model->errors);
    }

    public function test_custom_is_derived_and_empty_string_is_a_stored_value(): void
    {
        $this->definition = ['id' => 1, 'tipo' => 'string'];

        $model = $this->buildScopeModel();
        $model->codtarifa = 'T1';
        $model->id_caracteristica = 1;
        $model->valor = '';

        $this->assertTrue($model->test(), 'an empty custom string is a stored value');
        $this->assertTrue($model->custom, 'custom must be derived from hasValor, not caller input');
        $this->assertSame('', $model->valor, 'the empty string must stay distinguishable from null');
    }

    public function test_predefined_value_must_belong_to_the_definition(): void
    {
        $this->definition = ['id' => 1, 'tipo' => 'bool'];
        $this->catalogValue = ['id' => 3, 'id_caracteristica' => 1, 'valor' => '1'];

        $model = $this->buildScopeModel();
        $model->codtarifa = 'T1';
        $model->id_caracteristica = 1;
        $model->id_valor = 3;

        $this->assertTrue($model->test(), 'an owned predefined value must pass');
        $this->assertFalse($model->custom, 'a predefined value is not custom');

        $this->catalogValue = ['id' => 9, 'id_caracteristica' => 2, 'valor' => '1'];
        $foreign = $this->buildScopeModel();
        $foreign->codtarifa = 'T1';
        $foreign->id_caracteristica = 1;
        $foreign->id_valor = 9;
        $this->assertFalse($foreign->test(), 'a predefined value owned by another definition must be rejected');
    }

    public function test_definition_must_exist(): void
    {
        $this->definition = null;

        $model = $this->buildScopeModel();
        $model->codtarifa = 'T1';
        $model->id_caracteristica = 99;
        $model->valor = 'x';

        $this->assertFalse($model->test(), 'a missing definition must be rejected');
    }
}
