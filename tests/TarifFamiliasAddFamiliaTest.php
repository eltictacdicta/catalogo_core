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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * DB-free contract for the create-or-link add-familia flow in the moved
 * tarif_familias controller: descripcion resolution over the base catalog
 * and unique codfamilia derivation from a typed name.
 */
final class TarifFamiliasAddFamiliaTest extends TestCase
{
    private const CONTROLLER_PATH = '/plugins/catalogo_core/controller/tarif_familias.php';

    private function loadControllerClass(): void
    {
        if (class_exists('tarif_familias', false)) {
            return;
        }

        if (!is_file(FS_FOLDER . self::CONTROLLER_PATH)) {
            self::fail('missing catalogo_core path: plugins/catalogo_core/controller/tarif_familias.php (moved controller not present)');
        }

        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . self::CONTROLLER_PATH;
    }

    private function makeController()
    {
        $this->loadControllerClass();

        return new class extends \tarif_familias {
            public function __construct()
            {
            }
        };
    }

    /**
     * @param array<int, string> $taken codes reported as already existing
     */
    private function invokeSuggestCode(object $controller, string $descripcion, array $taken = [])
    {
        $ref = new \ReflectionMethod($controller, 'suggest_familia_code');
        $ref->setAccessible(true);

        return $ref->invoke($controller, $descripcion, function (string $candidate) use ($taken) {
            return in_array($candidate, $taken, true);
        });
    }

    public function test_find_familia_by_descripcion_matches_case_insensitive(): void
    {
        $controller = $this->makeController();
        $ref = new \ReflectionMethod($controller, 'find_familia_by_descripcion');
        $ref->setAccessible(true);

        $catalogo = [
            (object) ['codfamilia' => 'FAM001', 'descripcion' => 'Pernos Inoxidable'],
            (object) ['codfamilia' => 'FAM002', 'descripcion' => 'Tuercas'],
        ];

        $result = $ref->invoke($controller, $catalogo, '  pernos inoxidable ');
        $this->assertNotNull($result, 'Trimmed case-insensitive name must match an existing catalog familia');
        $this->assertSame('FAM001', $result->codfamilia);
    }

    public function test_find_familia_by_descripcion_returns_null_when_missing(): void
    {
        $controller = $this->makeController();
        $ref = new \ReflectionMethod($controller, 'find_familia_by_descripcion');
        $ref->setAccessible(true);

        $result = $ref->invoke($controller, [
            (object) ['codfamilia' => 'FAM002', 'descripcion' => 'Tuercas'],
        ], 'Arandelas');

        $this->assertNull($result, 'An unknown name must resolve to null so the caller creates the familia');
    }

    public function test_suggest_familia_code_derives_from_ascii_description(): void
    {
        $controller = $this->makeController();

        $this->assertSame('PERNOSIN', $this->invokeSuggestCode($controller, 'Pernos Inox M8'));
        $this->assertSame('ABC12345', $this->invokeSuggestCode($controller, 'abc-12345'));
    }

    public function test_suggest_familia_code_truncates_to_eight_chars(): void
    {
        $controller = $this->makeController();

        $this->assertSame('HERRAMIE', $this->invokeSuggestCode($controller, 'Herramientas manuales'));
    }

    public function test_suggest_familia_code_resolves_collisions_with_suffix(): void
    {
        $controller = $this->makeController();

        $this->assertSame(
            'PERNOS01',
            $this->invokeSuggestCode($controller, 'Pernos Inox M8', ['PERNOSIN']),
            'First collision must append the 01 suffix within the 8-char limit'
        );
    }

    public function test_suggest_familia_code_falls_back_to_fam_when_non_alnum(): void
    {
        $controller = $this->makeController();

        $this->assertSame('FAM', $this->invokeSuggestCode($controller, '*** --- ***'));
    }

    public function test_suggest_familia_code_returns_null_when_exhausted(): void
    {
        $controller = $this->makeController();
        $ref = new \ReflectionMethod($controller, 'suggest_familia_code');
        $ref->setAccessible(true);

        $this->assertNull(
            $ref->invoke($controller, 'Pernos Inox M8', static fn (string $candidate) => true),
            'Exhausting every bounded candidate must give up with null'
        );
    }
}
