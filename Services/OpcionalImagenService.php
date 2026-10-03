<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
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
 */
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Single-image store/serve for opcionales (OVE-03 / AD-4).
 *
 * Mirrors the article mechanics deliberately without touching
 * `tarif_articulo_imagen`: content-based MIME allow-list, a bare filename
 * return value, and the DDEV-safe atomic write fallback
 * (`move_uploaded_file → copy → file_put_contents`).
 *
 * The target directory is injectable so tests can run against a temporary
 * directory; production uses `OPCIONAL_IMAGES_DIR` relative to the working
 * directory, matching the article uploader.
 */
class OpcionalImagenService
{
    /** Images are held outside the plugin so they survive plugin updates. */
    public const OPCIONAL_IMAGES_DIR = 'imgs/opcionales/';

    /** MIME allow-list mapped to the canonical extension. */
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private string $imagesDir;

    public function __construct(?string $imagesDir = null)
    {
        $this->imagesDir = $imagesDir ?? self::OPCIONAL_IMAGES_DIR;
    }

    /**
     * Stores an uploaded file and returns the bare filename, or null on any
     * validation or write failure. The client-supplied name is never used.
     *
     * @param array{tmp_name?: string, error?: int|string, name?: string} $file
     */
    public function upload(string $codigo, array $file): ?string
    {
        $error = $file['error'] ?? UPLOAD_ERR_OK;
        if ((int) $error !== UPLOAD_ERR_OK) {
            return null;
        }

        $tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmp === '' || !is_file($tmp)) {
            return null;
        }

        $mime = $this->detectMimeFromFile($tmp);
        if ($mime === null || !isset(self::ALLOWED_MIME[$mime])) {
            return null;
        }

        $ignored = '';

        return $this->store($codigo, self::ALLOWED_MIME[$mime], static function (string $dest) use ($tmp): bool {
            return self::moveFile($tmp, $dest);
        }, $ignored);
    }

    /**
     * Stores an image from a base64 payload (data URI accepted) and returns the
     * bare filename, or null with `$error` populated.
     */
    public function upload_from_base64(string $codigo, string $base64, ?string &$error = null): ?string
    {
        $error = null;

        $base64 = (string) preg_replace('/^data:image\/\w+;base64,/', '', $base64);
        $base64 = str_replace(["\r", "\n", ' '], '', $base64);

        $data = base64_decode($base64);
        if ($data === false || $data === '') {
            $error = 'Error al decodificar base64';

            return null;
        }

        $mime = $this->detectMimeFromBuffer($data);
        if ($mime === null || !isset(self::ALLOWED_MIME[$mime])) {
            $error = 'Tipo de imagen no permitido: ' . (string) $mime;

            return null;
        }

        $message = '';
        $filename = $this->store($codigo, self::ALLOWED_MIME[$mime], static function (string $dest) use ($data): bool {
            return @file_put_contents($dest, $data) !== false;
        }, $message);

        if ($filename === null) {
            $error = $message !== '' ? $message : 'No se pudo guardar la imagen';
        }

        return $filename;
    }

    /**
     * Physically deletes a file under the images directory. Only the basename
     * is honoured, so a traversal-style value can never escape the directory.
     */
    public function delete_file(string $filename): void
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return;
        }

        $path = $this->imagesDir . $filename;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Shared store path: ensure the directory, generate the unique name, run
     * the writer and verify the file exists.
     */
    private function store(string $codigo, string $extension, callable $writer, string &$error): ?string
    {
        if (!$this->ensureDir()) {
            $error = 'No se pudo crear o acceder al directorio de imagenes';

            return null;
        }

        $filename = $this->generateFilename($codigo, $extension);
        $destination = $this->imagesDir . $filename;

        if (!$writer($destination) || !is_file($destination)) {
            $error = 'No se pudo guardar el archivo en disco';

            return null;
        }

        return $filename;
    }

    private function ensureDir(): bool
    {
        if (!is_dir($this->imagesDir) && !@mkdir($this->imagesDir, 0755, true) && !is_dir($this->imagesDir)) {
            return false;
        }

        return is_dir($this->imagesDir) && is_writable($this->imagesDir);
    }

    private function generateFilename(string $codigo, string $extension): string
    {
        $safe = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $codigo);

        return $safe . '_' . time() . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . strtolower($extension);
    }

    /**
     * DDEV-safe atomic write fallback (mirrors tarif_articulo_imagen::upload()):
     * move_uploaded_file → copy → file_put_contents.
     */
    private static function moveFile(string $tmp, string $destination): bool
    {
        if (is_uploaded_file($tmp)) {
            if (@move_uploaded_file($tmp, $destination)) {
                return true;
            }
        }

        if (@copy($tmp, $destination)) {
            @unlink($tmp);

            return true;
        }

        $content = @file_get_contents($tmp);
        if ($content !== false) {
            return @file_put_contents($destination, $content) !== false;
        }

        return false;
    }

    private function detectMimeFromFile(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) ? $mime : null;
    }

    private function detectMimeFromBuffer(string $data): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($data);

        return is_string($mime) ? $mime : null;
    }
}
