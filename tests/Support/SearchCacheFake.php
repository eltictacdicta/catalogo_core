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

namespace Tests\CatalogoCore\Support;

/**
 * In-memory cache double exposing the `fs_cache` surface used by
 * `articulo::tryCacheSearch()` / `new_search_tag()`.
 */
final class SearchCacheFake
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function set($key, $object, $expire = 5400): bool
    {
        $this->store[(string) $key] = $object;

        return true;
    }

    public function get($key)
    {
        return $this->store[(string) $key] ?? null;
    }

    /**
     * @return array<mixed>
     */
    public function get_array($key): array
    {
        $value = $this->get($key);

        return is_array($value) ? $value : [];
    }

    public function delete($key): bool
    {
        unset($this->store[(string) $key]);

        return true;
    }
}
