<?php
/**
 * This file is part of the catalogo_core plugin
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
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * The single write path for feature values (CAR-08, CAR-09).
 *
 * Every `assign_*` materializes exactly one row for the requested
 * `(codtarifa, scope, key)`: it never fans out to other tarifas, other keys or
 * other scopes. An existing row is updated in place; `clear()` deletes it.
 * Row absence is "no value" and is NOT the same as `assign_custom(..., '')`.
 *
 * `assign_with_dual_write()` keeps the legacy visibility columns consistent
 * during the read-through soak (CAR-14).
 */
class CaracteristicaValorStore
{
    /** The two legacy visibility features that carry a dual-write target. */
    public const LEGACY_BOOL_CODIGOS = ['en_catalogo', 'en_tarifa'];

    /**
     * @param array<string, mixed> $key
     */
    public function assign_predefined(
        string $scope,
        string $codtarifa,
        array $key,
        string $codigo,
        int $idValor
    ): bool {
        return $this->assign($scope, $codtarifa, $key, $codigo, $idValor, null);
    }

    /**
     * @param array<string, mixed> $key
     */
    public function assign_custom(
        string $scope,
        string $codtarifa,
        array $key,
        string $codigo,
        string $valor
    ): bool {
        return $this->assign($scope, $codtarifa, $key, $codigo, null, $valor);
    }

    /**
     * Bool convenience: maps to the definition's seeded '1'/'0' catalog value.
     *
     * @param array<string, mixed> $key
     */
    public function assign_bool(
        string $scope,
        string $codtarifa,
        array $key,
        string $codigo,
        bool $valor
    ): bool {
        $definition = $this->definitions()[$codigo] ?? null;
        if ($definition === null) {
            return false;
        }

        $idValor = $this->catalog_value_id((int) $definition['id'], $valor ? '1' : '0');
        if ($idValor === null) {
            return false;
        }

        return $this->assign($scope, $codtarifa, $key, $codigo, $idValor, null);
    }

    /**
     * Writes the feature value and, during the soak, the equivalent legacy
     * column(s) so both read paths stay consistent (CAR-14). A dropped legacy
     * column is silently skipped (introspected, never flag-gated).
     *
     * @param array<string, mixed> $key
     */
    public function assign_with_dual_write(
        string $scope,
        string $codtarifa,
        array $key,
        string $codigo,
        bool $valor
    ): bool {
        if (!$this->assign_bool($scope, $codtarifa, $key, $codigo, $valor)) {
            return false;
        }

        return $this->legacy_writer($scope, $codtarifa, $key, $codigo, $valor);
    }

    /**
     * Deletes the scope row; row absence is "no value".
     *
     * @param array<string, mixed> $key
     */
    public function clear(string $scope, string $codtarifa, array $key, string $codigo): bool
    {
        $definition = $this->definitions()[$codigo] ?? null;
        if ($definition === null) {
            return false;
        }

        $model = $this->scope_model($scope);
        $row = $model->get($codtarifa, $key, (int) $definition['id']);
        if (!$row) {
            return true;
        }

        if (is_object($row) && method_exists($row, 'delete')) {
            return (bool) $row->delete();
        }

        return (bool) $model->delete();
    }
    /**
     * @param array<string, mixed> $key
     */
    protected function assign(
        string $scope,
        string $codtarifa,
        array $key,
        string $codigo,
        ?int $idValor,
        ?string $valor
    ): bool {
        $definition = $this->definitions()[$codigo] ?? null;
        if ($definition === null) {
            return false;
        }

        $model = $this->scope_model($scope);
        $model->codtarifa = $codtarifa;
        foreach ($model->key_columns() as $column) {
            $model->{$column} = $key[$column] ?? null;
        }
        $model->id_caracteristica = (int) $definition['id'];
        $model->id_valor = $idValor;
        $model->valor = $valor;
        $model->custom = $valor !== null;

        return (bool) $model->save();
    }

    // =====================================================================
    // Legacy dual-write (CAR-14, design §8.4)
    // =====================================================================

    /**
     * Materializes the equivalent legacy visibility column(s) for a feature
     * write. Returns TRUE when no legacy surface applies (another codigo, the
     * global scope, or a dropped column).
     *
     * @param array<string, mixed> $key
     */
    protected function legacy_writer(
        string $scope,
        string $codtarifa,
        array $key,
        string $codigo,
        bool $valor
    ): bool {
        if (!in_array($codigo, self::LEGACY_BOOL_CODIGOS, true)) {
            return true;
        }

        return match ($scope) {
            CaracteristicaResolver::SCOPE_ARTICULO => $this->dual_write_articulo(
                $codtarifa,
                (string) ($key['referencia'] ?? ''),
                $codigo,
                $valor
            ),
            CaracteristicaResolver::SCOPE_FAMILIA => $this->dual_write_familia(
                $codtarifa,
                (string) ($key['codfamilia'] ?? ''),
                $codigo,
                $valor
            ),
            default => true,
        };
    }

    private function dual_write_articulo(string $codtarifa, string $referencia, string $codigo, bool $valor): bool
    {
        if ($referencia === '' || $codtarifa === '') {
            return false;
        }

        $ok = true;

        if ($this->legacy_column_exists('tarif_articulo_precios', $codigo)) {
            $ok = $this->upsert_legacy(
                'tarif_articulo_precios',
                ['referencia' => $referencia, 'codtarifa' => $codtarifa],
                $codigo,
                $valor,
                ['precio' => 0.0, 'activo' => true]
            ) && $ok;
        }

        if ($this->legacy_column_exists('tarif_tarifa_articulo', $codigo)) {
            // The live `tarif_tarifa_articulo` has (codtarifa, referencia,
            // codfamilia, en_tarifa, en_catalogo, orden) and NO `madre`
            // column (`madre` lives on `tarif_tarifa_familia`). Design §8.4
            // lists `madre` for this insert; the live schema diverges, so the
            // insert must not emit it — otherwise every article dual-write
            // fails ("Unknown column 'madre'") and the store reports FALSE
            // even though the feature value was persisted.
            $ok = $this->upsert_legacy(
                'tarif_tarifa_articulo',
                ['codtarifa' => $codtarifa, 'referencia' => $referencia],
                $codigo,
                $valor,
                [
                    'orden' => 0,
                    'codfamilia' => $this->article_family($referencia),
                ]
            ) && $ok;
        }

        return $ok;
    }

    private function dual_write_familia(string $codtarifa, string $codfamilia, string $codigo, bool $valor): bool
    {
        if ($codfamilia === '' || $codtarifa === '') {
            return false;
        }

        if (!$this->legacy_column_exists('tarif_tarifa_familia', $codigo)) {
            return true;
        }

        return $this->upsert_legacy(
            'tarif_tarifa_familia',
            ['codtarifa' => $codtarifa, 'codfamilia' => $codfamilia],
            $codigo,
            $valor,
            [
                'madre' => $this->familia_madre($codfamilia),
                'capitulo' => '',
                'nivel' => '',
                'activa' => true,
                'orden' => 0,
            ]
        );
    }

    /**
     * INSERT-or-UPDATE of exactly one legacy column. Every other column is
     * only ever written on insert (with the pinned defaults); `coddivisa` is
     * never part of the shape (R-TAR-CUR-008).
     *
     * @param array<string, mixed> $key
     * @param array<string, mixed> $defaults
     */
    private function upsert_legacy(string $table, array $key, string $codigo, bool $valor, array $defaults): bool
    {
        $db = $this->db();
        $where = $this->pk_where($key);

        if ($db->select('SELECT 1 FROM ' . $table . ' WHERE ' . $where . ' LIMIT 1;')) {
            return (bool) $db->exec(
                'UPDATE ' . $table . ' SET ' . $codigo . ' = ' . $db->var2str($valor) . ' WHERE ' . $where . ';'
            );
        }

        $row = $key;
        foreach ($defaults as $column => $default) {
            $row[$column] = $default;
        }
        $row[$codigo] = $valor;

        $columns = [];
        $values = [];
        foreach ($row as $column => $value) {
            $columns[] = $column;
            $values[] = $db->var2str($value);
        }

        return (bool) $db->exec(
            'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ');'
        );
    }

    /**
     * @param array<string, mixed> $key
     */
    private function pk_where(array $key): string
    {
        $db = $this->db();
        $parts = [];
        foreach ($key as $column => $value) {
            $parts[] = $column . ' = ' . $db->var2str($value);
        }

        return implode(' AND ', $parts);
    }

    // =====================================================================
    // Seams (unit tests override them to stay DB-free)
    // =====================================================================

    /**
     * Definitions keyed by codigo.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function definitions(): array
    {
        $resolver = $this->resolver();

        return $resolver->definitions();
    }

    /** @return object */
    protected function resolver()
    {
        return new CaracteristicaResolver();
    }

    /**
     * @return object
     */
    protected function scope_model(string $scope)
    {
        return match ($scope) {
            CaracteristicaResolver::SCOPE_ARTICULO => new \FSFramework\model\catalogo_caracteristica_articulo(),
            CaracteristicaResolver::SCOPE_FAMILIA => new \FSFramework\model\catalogo_caracteristica_familia(),
            default => new \FSFramework\model\catalogo_caracteristica_global(),
        };
    }

    /** @return object */
    protected function valor_model()
    {
        return new \FSFramework\model\catalogo_caracteristica_valor();
    }

    protected function catalog_value_id(int $idCaracteristica, string $valor): ?int
    {
        foreach ((array) $this->valor_model()->all_from_caracteristica($idCaracteristica) as $row) {
            if ((string) $row->valor === $valor) {
                return (int) $row->id;
            }
        }

        return null;
    }

    /**
     * DB seam for the dual-write.
     *
     * @return object
     */
    protected function db()
    {
        return new \fs_db2();
    }

    /**
     * Driver-aware legacy column introspection; a dropped column makes the
     * dual-write a no-op (CAR-15 compatibility).
     */
    protected function legacy_column_exists(string $table, string $column): bool
    {
        $db = $this->db();

        if (defined('FS_DB_TYPE') && FS_DB_TYPE === 'postgresql') {
            $data = $db->select(
                'SELECT 1 FROM information_schema.columns '
                . "WHERE table_schema = 'public' AND table_name = " . $db->var2str($table)
                . ' AND column_name = ' . $db->var2str($column) . ' LIMIT 1;'
            );
        } else {
            $data = $db->select('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $db->var2str($column) . ';');
        }

        return (bool) $data;
    }

    /**
     * Articulo-family seam for the `tarif_tarifa_articulo` insert default.
     */
    protected function article_family(string $referencia): ?string
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';

        $row = (new \FSFramework\model\articulo())->get($referencia);
        if (!$row) {
            return null;
        }

        $codfamilia = trim((string) ($row->codfamilia ?? ''));

        return $codfamilia === '' ? null : $codfamilia;
    }

    /**
     * Familia-madre seam for the `tarif_tarifa_familia` insert default.
     */
    protected function familia_madre(string $codfamilia): ?string
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';

        $row = (new \FSFramework\model\familia())->get($codfamilia);
        if (!$row) {
            return null;
        }

        $madre = trim((string) ($row->madre ?? ''));

        return $madre === '' ? null : $madre;
    }
}
