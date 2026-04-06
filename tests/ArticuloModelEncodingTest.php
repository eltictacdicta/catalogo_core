<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
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

/**
 * Tests para evitar doble escape HTML al hidratar artículos desde persistencia.
 */

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

class ArticuloModelEncodingTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
    }

    public function testConstructorDecodesPersistedHtmlEntities(): void
    {
        $model = new \FSFramework\model\articulo([
            'referencia' => 'TEST-001',
            'tipo' => 'producto',
            'codfamilia' => 'FAM001',
            'codfabricante' => null,
            'descripcion' => 'Botellero SubZero Linea Americana Inox 36&quot; &amp; acero',
            'pvp' => '0',
            'factualizado' => '2026-04-06',
            'costemedio' => '0',
            'preciocoste' => '0',
            'codimpuesto' => null,
            'stockfis' => '0',
            'stockmin' => '0',
            'stockmax' => '0',
            'controlstock' => '0',
            'nostock' => '0',
            'bloqueado' => '0',
            'secompra' => '0',
            'sevende' => '1',
            'publico' => '1',
            'equivalencia' => '',
            'partnumber' => '',
            'codbarras' => '',
            'observaciones' => 'Observacion &quot;demo&quot; &amp; mas',
            'codsubcuentacom' => null,
            'codsubcuentairpfcom' => null,
            'trazabilidad' => '0',
        ]);

        $this->assertSame('Botellero SubZero Linea Americana Inox 36" & acero', $model->descripcion);
        $this->assertSame('Observacion "demo" & mas', $model->observaciones);
    }

    public function testTestNormalizesHtmlBeforePersistence(): void
    {
        $model = new \FSFramework\model\articulo([
            'referencia' => 'TEST-002',
            'tipo' => 'producto',
            'codfamilia' => 'FAM001',
            'codfabricante' => null,
            'descripcion' => 'Texto &quot;existente&quot;',
            'pvp' => '0',
            'factualizado' => '2026-04-06',
            'costemedio' => '0',
            'preciocoste' => '0',
            'codimpuesto' => null,
            'stockfis' => '0',
            'stockmin' => '0',
            'stockmax' => '0',
            'controlstock' => '0',
            'nostock' => '0',
            'bloqueado' => '0',
            'secompra' => '0',
            'sevende' => '1',
            'publico' => '1',
            'equivalencia' => '',
            'partnumber' => '',
            'codbarras' => '',
            'observaciones' => '&lt;script&gt;alert(1)&lt;/script&gt;',
            'codsubcuentacom' => null,
            'codsubcuentairpfcom' => null,
            'trazabilidad' => '0',
        ]);

        $this->assertTrue($model->test());
        $this->assertSame('Texto &quot;existente&quot;', $model->descripcion);
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $model->observaciones);
    }
}