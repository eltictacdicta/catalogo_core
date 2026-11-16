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

use FSFramework\model\catalogo_opcional_grupo;
use FSFramework\model\catalogo_opcional_grupo_idioma;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo_idioma.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo.php';

/**
 * Nombre multiidioma de los grupos de opcionales. Semántica espejo: el idioma
 * por defecto configurado ES la columna base `catalogo_opcional_grupos.nombre`;
 * la tabla hija guarda solo los demás idiomas. DB-free: el grupo enruta su API
 * de idiomas por las costuras `idioma_model()` y `language_registry()`.
 */
final class CatalogoOpcionalGrupoIdiomaTest extends TestCase
{
    public function test_default_language_reads_the_base_column(): void
    {
        $store = new FakeGrupoIdiomaStore([['GRP0001', 'es_ES', 'Obsoleto']]);
        $grupo = new FakeGrupoIdiomaChain($store, 'es_ES', 'GRP0001', 'Color');

        $this->assertSame('Color', $grupo->get_nombre_idioma('es_ES'), 'the default never reads a stale child row');
        $this->assertSame('Color', $grupo->get_nombre_idioma(), 'no code resolves the base column');
        $this->assertSame('Color', $grupo->get_nombre_idioma(''));
    }

    public function test_requested_language_wins_and_falls_back_to_base(): void
    {
        $store = new FakeGrupoIdiomaStore([['GRP0001', 'en_GB', 'Colour']]);
        $grupo = new FakeGrupoIdiomaChain($store, 'es_ES', 'GRP0001', 'Color');

        $this->assertSame('Colour', $grupo->get_nombre_idioma('en_GB'));
        $this->assertSame('Color', $grupo->get_nombre_idioma('fr_FR'), 'a language without row falls back to the base');
        $this->assertCount(1, $store->rows, 'reads must never materialise a row');
    }

    public function test_set_idioma_upserts_a_non_default_row(): void
    {
        $store = new FakeGrupoIdiomaStore([]);
        $grupo = new FakeGrupoIdiomaChain($store, 'es_ES', 'GRP0001', 'Color');

        $this->assertTrue($grupo->set_idioma('en_GB', 'Colour'));
        $this->assertSame([['GRP0001', 'en_GB', 'Colour']], $store->rows);

        $this->assertTrue($grupo->set_idioma('en_GB', 'Color (EN)'));
        $this->assertSame([['GRP0001', 'en_GB', 'Color (EN)']], $store->rows, 'a second write updates, never duplicates');
        $this->assertSame([], $grupo->baseSaves, 'a non-default language never touches the base column');
    }

    public function test_legacy_codes_are_normalised_to_the_canonical_locale(): void
    {
        $store = new FakeGrupoIdiomaStore([]);
        $grupo = new FakeGrupoIdiomaChain($store, 'es_ES', 'GRP0001', 'Color');

        $this->assertTrue($grupo->set_idioma('en', 'Colour'));
        $this->assertSame([['GRP0001', 'en_GB', 'Colour']], $store->rows, 'a bare code is stored in canonical form');
        $this->assertSame('Colour', $grupo->get_nombre_idioma('en-gb'));
        $this->assertSame('Color', $grupo->get_nombre_idioma('es'), 'a bare default code still resolves the base column');

        $this->assertTrue($grupo->set_idioma('es', 'Tono'));
        $this->assertSame('Tono', $grupo->nombre);
        $this->assertCount(1, $store->rows, 'the default language never reaches the child table');
    }

    public function test_set_idioma_with_empty_name_removes_the_row_and_never_creates_one(): void
    {
        $store = new FakeGrupoIdiomaStore([['GRP0001', 'en_GB', 'Colour']]);
        $grupo = new FakeGrupoIdiomaChain($store, 'es_ES', 'GRP0001', 'Color');

        $this->assertTrue($grupo->set_idioma('en_GB', '  '));
        $this->assertSame([], $store->rows);

        $this->assertTrue($grupo->set_idioma('fr_FR', ''));
        $this->assertSame([], $store->rows, 'an empty name for an absent language materialises nothing');
    }

    public function test_set_idioma_default_mirrors_into_the_base_column(): void
    {
        $store = new FakeGrupoIdiomaStore([]);
        $grupo = new FakeGrupoIdiomaChain($store, 'es_ES', 'GRP0001', 'Color');

        $this->assertTrue($grupo->set_idioma('es_ES', 'Tono'));
        $this->assertSame('Tono', $grupo->nombre);
        $this->assertSame(['Tono'], $grupo->baseSaves);
        $this->assertSame([], $store->rows, 'the default language is never stored in the child table');
    }

    public function test_set_idioma_default_with_empty_name_is_rejected_and_restores_the_base(): void
    {
        $store = new FakeGrupoIdiomaStore([]);
        $grupo = new FakeGrupoIdiomaChain($store, 'es_ES', 'GRP0001', 'Color');

        $this->assertFalse($grupo->set_idioma('es_ES', ''));
        $this->assertSame('Color', $grupo->nombre, 'a rejected save must not leave the in-memory base ahead');
    }

    public function test_set_idioma_is_refused_without_a_codigo(): void
    {
        $store = new FakeGrupoIdiomaStore([]);
        $grupo = new FakeGrupoIdiomaChain($store, 'es_ES', '', 'Color');

        $this->assertFalse($grupo->set_idioma('en_GB', 'Colour'));
        $this->assertSame([], $grupo->get_idiomas());
    }

    public function test_get_idiomas_returns_the_child_rows(): void
    {
        $store = new FakeGrupoIdiomaStore([['GRP0001', 'en_GB', 'Colour'], ['GRP0002', 'en_GB', 'Other']]);
        $grupo = new FakeGrupoIdiomaChain($store, 'es_ES', 'GRP0001', 'Color');

        $rows = $grupo->get_idiomas();
        $this->assertCount(1, $rows);
        $this->assertSame('Colour', $rows[0]->nombre);
    }

    public function test_child_model_declares_a_cascade_on_the_group_code(): void
    {
        $xml = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/model/table/catalogo_opcional_grupo_idiomas.xml');

        $this->assertStringContainsString('UNIQUE (codigo, codidioma)', $xml);
        $this->assertStringContainsString('REFERENCES catalogo_opcional_grupos (codigo)', $xml);
        $this->assertStringContainsString('ON DELETE CASCADE ON UPDATE CASCADE', $xml);
    }

    public function test_group_search_also_matches_translated_names(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo.php');

        $this->assertStringContainsString('catalogo_opcional_grupo_idioma::TABLE', $src);
        $this->assertStringContainsString('gi.codigo = ', $src);
    }

    public function test_editor_exposes_the_language_block_and_persists_after_the_csrf_gate(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/View/ventas_opcional_grupo.html.twig');
        $this->assertStringContainsString('name="idioma_nombre[{{ idioma.codidioma }}]"', $view);
        $this->assertStringContainsString('{% if idioma.codidioma == fsc.codidioma_edit %} required{% endif %}', $view);
        $this->assertStringNotContainsString('|raw', $view);

        $ctl = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcionalGrupo.php');
        $start = (int) strpos($ctl, 'private function guardarGrupo');
        $body = substr($ctl, $start, (int) strpos($ctl, 'private function addOpcionalAlGrupo') - $start);
        $csrf = strpos($body, 'validateFormToken()');
        $idiomas = strpos($body, 'guardarIdiomas(');
        $this->assertNotFalse($csrf);
        $this->assertNotFalse($idiomas);
        $this->assertLessThan($idiomas, $csrf, 'CSRF must be validated before the languages are persisted');
    }

    public function test_legacy_opcional_editor_exposes_the_language_block(): void
    {
        $view = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/View/tarif_opcional_edit.html.twig');
        $this->assertStringContainsString('name="idioma_nombre[{{ idioma.codidioma }}]"', $view);
        $this->assertStringContainsString('name="idioma_descripcion[{{ idioma.codidioma }}]"', $view);

        $ctl = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/controller/tarif_opcional_edit.php');
        $this->assertStringContainsString('set_idioma(', $ctl);
        $this->assertStringContainsString('idioma_nombre', $ctl);
    }
}

/**
 * Almacén en memoria compartido entre el grupo y el modelo hijo falsos.
 */
final class FakeGrupoIdiomaStore
{
    /** @var list<array{0: string, 1: string, 2: string}> [codigo, codidioma, nombre] */
    public array $rows;

    /**
     * @param list<array{0: string, 1: string, 2: string}> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }
}

final class FakeGrupoIdiomaModel extends catalogo_opcional_grupo_idioma
{
    private FakeGrupoIdiomaStore $store;

    public function __construct(FakeGrupoIdiomaStore $store, ?array $row = null)
    {
        $this->store = $store;
        $this->table_name = catalogo_opcional_grupo_idioma::TABLE;
        $this->id = $row === null ? null : 1;
        $this->codigo = $row[0] ?? null;
        $this->codidioma = $row[1] ?? 'es_ES';
        $this->nombre = $row[2] ?? '';
    }

    public function get_by_grupo_idioma($codigo, $codidioma)
    {
        foreach ($this->store->rows as $row) {
            if ($row[0] === $codigo && $row[1] === $codidioma) {
                return new self($this->store, $row);
            }
        }

        return false;
    }

    public function all_from_grupo($codigo): array
    {
        $list = [];
        foreach ($this->store->rows as $row) {
            if ($row[0] === $codigo) {
                $list[] = new self($this->store, $row);
            }
        }

        return $list;
    }

    public function exists()
    {
        return $this->id !== null;
    }

    public function save()
    {
        $nombre = trim((string) $this->nombre);
        foreach ($this->store->rows as $i => $row) {
            if ($row[0] === $this->codigo && $row[1] === $this->codidioma) {
                if ($nombre === '') {
                    array_splice($this->store->rows, $i, 1);
                } else {
                    $this->store->rows[$i][2] = $nombre;
                }

                return true;
            }
        }

        if ($nombre !== '') {
            $this->store->rows[] = [(string) $this->codigo, (string) $this->codidioma, $nombre];
        }

        return true;
    }

    protected function new_error_msg($msg)
    {
    }
}

final class FakeGrupoLanguageRegistry
{
    public function __construct(private string $defaultCode)
    {
    }

    public function get_effective_default_code(): string
    {
        return $this->defaultCode;
    }
}

final class FakeGrupoIdiomaChain extends catalogo_opcional_grupo
{
    /** @var list<string> */
    public array $baseSaves = [];

    public function __construct(
        private FakeGrupoIdiomaStore $store,
        private string $defaultCode,
        string $codigo,
        string $nombre
    ) {
        $this->table_name = catalogo_opcional_grupo::TABLE;
        $this->id = 1;
        $this->codigo = $codigo;
        $this->nombre = $nombre;
        $this->exclusivo = true;
        $this->activo = true;
        $this->orden = 0;
    }

    public function save()
    {
        if (mb_strlen(trim((string) $this->nombre)) < 1) {
            return false;
        }

        $this->baseSaves[] = (string) $this->nombre;

        return true;
    }

    protected function idioma_model()
    {
        return new FakeGrupoIdiomaModel($this->store);
    }

    protected function language_registry()
    {
        return new FakeGrupoLanguageRegistry($this->defaultCode);
    }
}
