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

namespace Tests\CatalogoCore\Support;

/**
 * In-memory stand-in for `catalogo_articulo_familia_estructura` keyed by
 * (codlista, referencia). Counts reads/writes so tests can pin "one query, no
 * N+1" and the inert-when-off guarantees without a database.
 *
 * Loaded from setUpBeforeClass() (not at file scope) because it extends a
 * legacy, non-autoloaded model: a file-scope declaration would collide with the
 * `FSFramework\model\familia` stub that InitUpgradeTest declares during
 * discovery.
 */
final class FakeFamilyAssignmentStore
{
    /**
     * @var array<string, array{codlista: string, referencia: string, codfamilia: ?string, en_tarifa: bool, en_catalogo: bool, orden: int}>
     */
    public array $rows = [];

    public int $selects = 0;
    public int $writes = 0;
    public int $deletes = 0;

    public function seed(
        string $codlista,
        string $referencia,
        ?string $codfamilia,
        bool $enTarifa = true,
        bool $enCatalogo = true,
        int $orden = 0
    ): void {
        $this->rows[$this->key($codlista, $referencia)] = [
            'codlista' => $codlista,
            'referencia' => $referencia,
            'codfamilia' => $codfamilia,
            'en_tarifa' => $enTarifa,
            'en_catalogo' => $enCatalogo,
            'orden' => $orden,
        ];
    }

    public function key(string $codlista, string $referencia): string
    {
        return $codlista . '|' . $referencia;
    }

    /**
     * @return array{codlista: string, referencia: string, codfamilia: ?string, en_tarifa: bool, en_catalogo: bool, orden: int}|null
     */
    public function find(string $codlista, string $referencia): ?array
    {
        return $this->rows[$this->key($codlista, $referencia)] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allForArticle(string $referencia): array
    {
        $this->selects++;

        $found = [];
        foreach ($this->rows as $row) {
            if ($row['referencia'] === $referencia) {
                $found[] = $row;
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function put(array $row): void
    {
        $this->writes++;
        $this->rows[$this->key((string) $row['codlista'], (string) $row['referencia'])] = [
            'codlista' => (string) $row['codlista'],
            'referencia' => (string) $row['referencia'],
            'codfamilia' => $row['codfamilia'] !== null ? (string) $row['codfamilia'] : null,
            'en_tarifa' => (bool) $row['en_tarifa'],
            'en_catalogo' => (bool) $row['en_catalogo'],
            'orden' => (int) $row['orden'],
        ];
    }

    public function remove(string $codlista, string $referencia): void
    {
        $this->deletes++;
        unset($this->rows[$this->key($codlista, $referencia)]);
    }
}

/**
 * DB-free `catalogo_articulo_familia_estructura` double backed by the store.
 * Mirrors the production model contract (`get`, `all_from_article`, `exists`,
 * `save`, `delete`) without opening a connection.
 */
final class FakeFamilyAssignmentModel extends \FSFramework\model\catalogo_articulo_familia_estructura
{
    public function __construct(private FakeFamilyAssignmentStore $store, array $data = [])
    {
        // Skip parent::__construct (no DB).
        $this->table_name = self::TABLE;
        $this->codlista = $data['codlista'] ?? null;
        $this->referencia = $data['referencia'] ?? null;
        $this->codfamilia = $data['codfamilia'] ?? null;
        $this->en_tarifa = $data['en_tarifa'] ?? true;
        $this->en_catalogo = $data['en_catalogo'] ?? true;
        $this->orden = $data['orden'] ?? 0;
    }

    public function get($codlista, $referencia)
    {
        $row = $this->store->find((string) $codlista, (string) $referencia);

        return $row === null ? false : new self($this->store, $row);
    }

    public function all_from_article($referencia)
    {
        $list = [];
        foreach ($this->store->allForArticle((string) $referencia) as $row) {
            $list[] = new self($this->store, $row);
        }

        return $list;
    }

    public function exists()
    {
        return $this->store->find((string) $this->codlista, (string) $this->referencia) !== null;
    }

    public function save()
    {
        $this->store->put([
            'codlista' => $this->codlista,
            'referencia' => $this->referencia,
            'codfamilia' => $this->codfamilia,
            'en_tarifa' => $this->en_tarifa,
            'en_catalogo' => $this->en_catalogo,
            'orden' => $this->orden,
        ]);

        return true;
    }

    public function delete()
    {
        $this->store->remove((string) $this->codlista, (string) $this->referencia);

        return true;
    }
}
