<?php

declare(strict_types=1);

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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Fix B — the "Asignación a familias" panel must not nest forms inside the
 * main article form (HTML forbids it: the parser ignores the inner start tag
 * and the inner `</form>` closes the article form, so the article can no
 * longer be saved). The panel controls are associated with two real forms
 * that live outside the article form, next to `form_precios_lista`.
 */
final class ArticleAssignmentFormStructureTest extends TestCase
{
    private const VIEW = '/plugins/catalogo_core/View/ventas_articulo.html.twig';

    private string $view;

    protected function setUp(): void
    {
        parent::setUp();
        $this->view = (string) file_get_contents(FS_FOLDER . self::VIEW);
    }

    public function test_assignment_panel_contains_no_nested_form_tags(): void
    {
        $panel = $this->assignmentPanel();

        $this->assertStringNotContainsString('<form', $panel, 'The assignment panel must not open a nested form');
        $this->assertStringNotContainsString('</form>', $panel, 'The assignment panel must not close the article form');
    }

    public function test_external_assignment_forms_carry_their_hidden_fields(): void
    {
        $listForm = $this->formBlock('form_asignacion_lista');
        $this->assertStringContainsString('method="get"', $listForm);
        $this->assertMatchesRegularExpression('/name="page"\s+value="ventas_articulo"/', $listForm);
        $this->assertMatchesRegularExpression('/name="ref"\s+value="\{\{ fsc\.articulo\.referencia \}\}"/', $listForm);

        $familyForm = $this->formBlock('form_asignacion_familia');
        $this->assertStringContainsString('method="post"', $familyForm);
        $this->assertStringContainsString('{{ csrf_field() }}', $familyForm);
        $this->assertStringContainsString('name="guardar_asignacion_familia"', $familyForm);
        $this->assertStringContainsString('name="sreferencia"', $familyForm);
        $this->assertStringContainsString('name="asignacion_codlista"', $familyForm);
        $this->assertStringContainsString('{{ fsc.codlista_asignacion }}', $familyForm);
    }

    public function test_panel_controls_are_associated_with_the_external_forms(): void
    {
        $panel = $this->assignmentPanel();

        $this->assertStringContainsString('name="codlista"', $panel);
        $this->assertStringContainsString('name="asignacion_codfamilia"', $panel);
        $this->assertStringContainsString('name="asignacion_en_catalogo"', $panel);
        $this->assertStringContainsString('name="asignacion_en_tarifa"', $panel);
        $this->assertStringContainsString('name="asignacion_orden"', $panel);

        $this->assertSame(
            2,
            substr_count($panel, 'form="form_asignacion_lista"'),
            'The list selector and "Cambiar" must submit through the GET form'
        );
        $this->assertSame(
            5,
            substr_count($panel, 'form="form_asignacion_familia"'),
            'Family select, both checkboxes, order input and Save must submit through the POST form'
        );
    }

    public function test_main_article_form_closes_after_its_own_save_button_and_owns_no_other_form(): void
    {
        $mainStart = mb_strpos($this->view, '<form class="form" role="form"');
        $this->assertNotFalse($mainStart, 'The article form must exist');

        $footer = mb_strpos($this->view, '<div class="panel-footer text-right"', $mainStart);
        $this->assertNotFalse($footer);

        $saveButton = mb_strpos($this->view, '<button class="btn btn-sm btn-primary" type="submit">', $footer);
        $this->assertNotFalse($saveButton, 'The article form must keep its own save button');

        $mainClose = mb_strpos($this->view, '</form>', $saveButton);
        $this->assertNotFalse($mainClose, 'The article form must still close after its own save button');

        foreach (['form_asignacion_lista', 'form_asignacion_familia', 'form_precios_lista'] as $formId) {
            $pos = mb_strpos($this->view, '<form id="' . $formId . '"');
            $this->assertNotFalse($pos, $formId . ' must exist as a real form');
            $this->assertGreaterThan($mainClose, $pos, $formId . ' must live outside the article form');
        }
    }

    private function assignmentPanel(): string
    {
        $start = mb_strpos($this->view, 'id="asignacion_familias"');
        $this->assertNotFalse($start, 'The assignment panel must exist');

        $end = mb_strpos($this->view, 'id="stock-articulo"', $start);
        $this->assertNotFalse($end, 'The stock panel must follow the assignment panel');

        return mb_substr($this->view, $start, $end - $start);
    }

    private function formBlock(string $id): string
    {
        $start = mb_strpos($this->view, '<form id="' . $id . '"');
        $this->assertNotFalse($start, $id . ' must exist');

        $end = mb_strpos($this->view, '</form>', $start);
        $this->assertNotFalse($end, $id . ' must be a closed form');

        return mb_substr($this->view, $start, $end - $start);
    }
}
