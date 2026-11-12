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

namespace Tests\CatalogoCore\Controller;

use PHPUnit\Framework\TestCase;

/**
 * HTML does not allow nested forms: a browser drops the inner <form> tag, so
 * its controls end up posting with the OUTER form. The article editor keeps
 * every secondary POST (image upload, per-list prices, family assignment) in
 * its own <form> placed after the main one, and associates the controls with
 * the form="..." attribute.
 */
final class VentasArticuloFormStructureTest extends TestCase
{
    private const VIEW = '/plugins/catalogo_core/View/ventas_articulo.html.twig';

    private string $view = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Twig comments explain the form layout and mention "<form>": ignore them.
        $this->view = (string) preg_replace(
            '/\{#.*?#\}/s',
            '',
            (string) file_get_contents(FS_FOLDER . self::VIEW)
        );
    }

    public function test_main_article_form_contains_no_nested_form(): void
    {
        $open = mb_strpos($this->view, '<form class="form" role="form"');
        $this->assertNotFalse($open, 'The main article form must exist');

        $close = mb_strpos($this->view, '</form>', $open);
        $this->assertNotFalse($close, 'The main article form must be closed');

        $inner = mb_substr($this->view, $open + 1, $close - $open - 1);
        $this->assertStringNotContainsString(
            '<form',
            $inner,
            'No <form> may be nested inside the main article form'
        );
    }

    public function test_image_upload_has_its_own_form_outside_the_main_one(): void
    {
        $mainClose = mb_strpos($this->view, '</form>', (int) mb_strpos($this->view, '<form class="form" role="form"'));
        $upload = mb_strpos($this->view, '<form id="form_upload_imagen"');

        $this->assertNotFalse($upload, 'The upload needs a standalone form');
        $this->assertGreaterThan($mainClose, $upload, 'The upload form must come after the main form');
        $this->assertStringContainsString('hx-encoding="multipart/form-data"', $this->view);

        $this->assertMatchesRegularExpression(
            '/<form id="form_upload_imagen".*?\{\{ csrf_field\(\) \}\}.*?name="upload_imagen".*?name="sreferencia".*?<\/form>/s',
            $this->view,
            'The upload form must carry the CSRF field, the action marker and the article reference'
        );
    }

    public function test_upload_controls_are_associated_with_the_upload_form(): void
    {
        $this->assertMatchesRegularExpression(
            '/<input type="file" name="imagen" form="form_upload_imagen"\/>/',
            $this->view,
            'The file input must be bound to the standalone upload form'
        );
        $this->assertMatchesRegularExpression(
            '/<button[^>]*type="submit"[^>]*form="form_upload_imagen"/',
            $this->view,
            'The upload button must submit the standalone upload form'
        );
    }

    public function test_upload_form_keeps_the_delete_permission_gate(): void
    {
        $gate = '{% if fsc.allow_delete is defined and fsc.allow_delete %}';
        $form = (int) mb_strpos($this->view, '<form id="form_upload_imagen"');

        $this->assertSame(
            $gate,
            mb_substr($this->view, (int) mb_strrpos(mb_substr($this->view, 0, $form), '{% if'), mb_strlen($gate)),
            'The standalone upload form must stay behind the same permission gate as its controls'
        );
    }
}
