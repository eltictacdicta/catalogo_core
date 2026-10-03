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

use FSFramework\Plugins\catalogo_core\Services\OpcionalImagenService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * OVE-03 — single image store and serve. The service validates MIME by content
 * (never by extension or client filename), writes through the DDEV-safe atomic
 * fallback, returns a bare filename and physically deletes without traversal.
 *
 * Every test runs against an isolated temp directory the test creates and
 * removes; nothing is written under the repository's `imgs/opcionales/`.
 */
#[CoversClass(OpcionalImagenService::class)]
final class OpcionalImagenServiceTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = rtrim(sys_get_temp_dir(), '/') . '/opcional_imagen_' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_upload_rejects_non_image_content(): void
    {
        $source = $this->tempFile('<?php echo "not an image";');

        $result = $this->service()->upload('OPC0001', ['tmp_name' => $source, 'error' => UPLOAD_ERR_OK]);

        $this->assertNull($result, 'non-image content must be rejected');
        $this->assertSame([], $this->writtenFiles(), 'no file may be written for a rejected upload');
    }

    public function test_upload_rejects_missing_temporary_file(): void
    {
        $result = $this->service()->upload('OPC0001', ['tmp_name' => $this->dir . 'nope.png', 'error' => UPLOAD_ERR_OK]);

        $this->assertNull($result);
        $this->assertSame([], $this->writtenFiles());
    }

    public function test_upload_returns_a_bare_filename_and_writes_the_file(): void
    {
        $source = $this->tempFile($this->pngBytes());

        $result = $this->service()->upload('OPC0001', ['tmp_name' => $source, 'error' => UPLOAD_ERR_OK]);

        $this->assertIsString($result);
        $this->assertSame(basename($result), $result, 'the stored value must be a bare filename');
        $this->assertStringNotContainsString('/', $result);
        $this->assertStringNotContainsString('\\', $result);
        $this->assertStringStartsWith('OPC0001_', $result);
        $this->assertStringEndsWith('.png', $result);
        $this->assertFileExists($this->dir . $result);
    }

    public function test_upload_never_uses_the_client_supplied_filename(): void
    {
        $source = $this->tempFile($this->pngBytes());

        $result = $this->service()->upload('OPC0001', [
            'tmp_name' => $source,
            'error' => UPLOAD_ERR_OK,
            'name' => '../../evil.php',
        ]);

        $this->assertIsString($result);
        $this->assertStringNotContainsString('evil', $result);
        $this->assertStringNotContainsString('..', $result);
        $this->assertFileExists($this->dir . $result);
    }

    public function test_upload_from_base64_rejects_invalid_payload(): void
    {
        $error = null;

        $result = $this->service()->upload_from_base64('OPC0001', '@@@not-base64@@@', $error);

        $this->assertNull($result);
        $this->assertNotNull($error);
        $this->assertSame([], $this->writtenFiles());
    }

    public function test_upload_from_base64_rejects_non_image_bytes(): void
    {
        $error = null;

        $result = $this->service()->upload_from_base64('OPC0001', base64_encode('plain text payload'), $error);

        $this->assertNull($result);
        $this->assertNotNull($error);
        $this->assertSame([], $this->writtenFiles());
    }

    public function test_upload_from_base64_accepts_a_data_uri_png(): void
    {
        $error = null;

        $result = $this->service()->upload_from_base64(
            'OPC0002',
            'data:image/png;base64,' . base64_encode($this->pngBytes()),
            $error
        );

        $this->assertIsString($result);
        $this->assertNull($error);
        $this->assertSame(basename($result), $result);
        $this->assertFileExists($this->dir . $result);
    }

    /**
     * Every allow-listed content type is accepted and mapped to its canonical
     * extension, proving the allow-list is content-driven (not extension-driven).
     */
    #[DataProvider('allowedImageProvider')]
    public function test_upload_from_base64_maps_each_allowed_type(string $base64, string $extension): void
    {
        $error = null;

        $result = $this->service()->upload_from_base64('OPC0099', $base64, $error);

        $this->assertIsString($result, 'expected type ' . $extension . ' to be accepted, error: ' . (string) $error);
        $this->assertNull($error);
        $this->assertStringEndsWith('.' . $extension, $result);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function allowedImageProvider(): array
    {
        return [
            'jpeg' => ['/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==', 'jpg'],
            'png' => ['iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'png'],
            'gif' => ['R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', 'gif'],
            'webp' => ['UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAUAmJaQAA3AA/vuUAAA=', 'webp'],
        ];
    }

    public function test_delete_file_removes_the_physical_file(): void
    {
        $service = $this->service();
        $source = $this->tempFile($this->pngBytes());

        $name = $service->upload('OPC0003', ['tmp_name' => $source, 'error' => UPLOAD_ERR_OK]);
        $this->assertIsString($name);
        $this->assertFileExists($this->dir . $name);

        $service->delete_file($name);

        $this->assertFileDoesNotExist($this->dir . $name);
    }

    public function test_delete_file_never_escapes_the_images_directory(): void
    {
        $parent = dirname(rtrim($this->dir, '/'));
        $sentinel = $parent . '/opcional_sentinel_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($sentinel, 'keep me');

        try {
            $this->service()->delete_file('../' . basename($sentinel));
            $this->assertFileExists($sentinel, 'a traversal attempt must never delete outside the images dir');
        } finally {
            @unlink($sentinel);
        }
    }

    private function service(): OpcionalImagenService
    {
        return new OpcionalImagenService($this->dir);
    }

    /**
     * @return list<string>
     */
    private function writtenFiles(): array
    {
        return array_values(array_filter(glob($this->dir . '*') ?: [], 'is_file'));
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'opcional_src_');
        file_put_contents($path, $contents);

        return $path;
    }

    private function pngBytes(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        );
    }
}
