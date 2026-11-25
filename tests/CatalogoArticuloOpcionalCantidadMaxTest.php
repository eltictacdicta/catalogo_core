<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', dirname(__DIR__, 3));
}
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional.php';

/**
 * Conexión falsa: registra el SQL y devuelve filas configuradas.
 */
final class CantidadMaxFakeDb
{
    /** @var list<string> */
    public array $sql = [];
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public function var2str($v)
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            return $v ? 'TRUE' : 'FALSE';
        }

        return "'" . addslashes((string) $v) . "'";
    }

    public function select($sql, $params = [])
    {
        $this->sql[] = $sql;

        return $this->rows;
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $this->sql[] = $sql;

        return true;
    }

    public function lastval()
    {
        return 1;
    }
}

final class CantidadMaxRelModel extends \FSFramework\model\catalogo_articulo_opcional
{
    public bool $valid = true;
    public bool $existsRelation = false;

    public function __construct(CantidadMaxFakeDb $db, $data = false)
    {
        $this->db = $db;
        $this->table_name = 'catalogo_articulo_opcional';
        if ($data) {
            $this->id = (int) $data['id'];
            $this->referencia = $data['referencia'];
            $this->id_opcional = (int) $data['id_opcional'];
            $this->obligatorio = false;
            $this->cantidad_max = max(1, (int) ($data['cantidad_max'] ?? 1));
        } else {
            $this->cantidad_max = self::CANTIDAD_MAX_DEFECTO;
        }
    }

    public function validate_opcional_for_articulo(int $idOpcional)
    {
        return $this->valid ? true : 'grupo';
    }

    public function exists_relation($referencia, $id_opcional)
    {
        return $this->existsRelation;
    }
}

final class CatalogoArticuloOpcionalCantidadMaxTest extends TestCase
{
    public function testSchemaDeclaresNotNullColumnDefaultingToOne(): void
    {
        $xml = simplexml_load_file(FS_FOLDER . '/plugins/catalogo_core/model/table/catalogo_articulo_opcional.xml');
        $found = null;
        foreach ($xml->columna as $c) {
            if ((string) $c->nombre === 'cantidad_max') {
                $found = $c;
            }
        }

        $this->assertNotNull($found, 'columna cantidad_max');
        $this->assertSame('integer', (string) $found->tipo);
        $this->assertSame('NO', (string) $found->nulo);
        $this->assertSame('1', (string) $found->defecto);
    }

    public function testAddInsertsDefaultOneWhenNoMaxIsGiven(): void
    {
        $db = new CantidadMaxFakeDb();
        (new CantidadMaxRelModel($db))->add('R1', 5);

        $this->assertStringContainsString('cantidad_max', $db->sql[0]);
        $this->assertMatchesRegularExpression('/,\s*1\);$/', $db->sql[0]);
    }

    public function testAddInsertsGivenMax(): void
    {
        $db = new CantidadMaxFakeDb();
        (new CantidadMaxRelModel($db))->add('R1', 5, false, 6);

        $this->assertMatchesRegularExpression('/,\s*6\);$/', $db->sql[0]);
    }

    public function testAddOnExistingRelationUpdatesMaxOnlyWhenGiven(): void
    {
        $db = new CantidadMaxFakeDb();
        $model = new CantidadMaxRelModel($db);
        $model->existsRelation = true;

        $model->add('R1', 5, false, 9);
        $this->assertStringContainsString('SET cantidad_max = 9', $db->sql[0]);

        $db->sql = [];
        $model->add('R1', 5, true);
        $this->assertCount(1, $db->sql, 'sin máximo solo se toca obligatorio');
        $this->assertStringContainsString('obligatorio', $db->sql[0]);
        $this->assertStringNotContainsString('cantidad_max', $db->sql[0]);
    }

    public function testSetCantidadMaxRejectsLessThanOne(): void
    {
        $db = new CantidadMaxFakeDb();
        $model = new CantidadMaxRelModel($db);

        $this->assertFalse($model->set_cantidad_max('R1', 5, 0));
        $this->assertSame([], $db->sql);
        $this->assertTrue($model->set_cantidad_max('R1', 5, 3));
    }

    public function testGetCantidadesMaxFromArticuloBuildsMap(): void
    {
        $db = new CantidadMaxFakeDb();
        $db->rows = [
            ['id_opcional' => '5', 'cantidad_max' => '6'],
            ['id_opcional' => '7', 'cantidad_max' => '0'],
        ];

        $this->assertSame([5 => 6, 7 => 1], (new CantidadMaxRelModel($db))->get_cantidades_max_from_articulo('R1'));
    }

    public function testSavePersistsMax(): void
    {
        $db = new CantidadMaxFakeDb();
        $model = new CantidadMaxRelModel($db);
        $model->referencia = 'R1';
        $model->id_opcional = 5;
        $model->cantidad_max = 4;
        $model->save();

        $this->assertStringContainsString('cantidad_max', $db->sql[array_key_last($db->sql)]);
        $this->assertMatchesRegularExpression('/,\s*4\);$/', $db->sql[array_key_last($db->sql)]);
    }
}
