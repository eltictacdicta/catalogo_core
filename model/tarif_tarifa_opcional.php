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
namespace FSFramework\model;

/**
 * Master state of an opcional within a tarifa.
 *
 * Keyed by `(codtarifa, id_opcional)`, this table is the authoritative source
 * for `activa`, catalog/export visibility (`en_catalogo`/`en_tarifa`) and the
 * default `orden`, mirroring `tarif_tarifa_familia`.
 *
 * Inheritance: a missing row resolves to the `tarif_opcional_ext` flags (or
 * the master defaults) without ever being persisted on read; the first
 * explicit write creates the row.
 */
class tarif_tarifa_opcional extends \fs_model
{
    /**
     * Tarifa code. Primary key (part 1).
     * @var string|null
     */
    public $codtarifa;

    /**
     * Opcional id. Primary key (part 2).
     * @var int|null
     */
    public $id_opcional;

    /**
     * Whether the opcional appears in the catalog of this tarifa.
     * @var bool
     */
    public $en_catalogo;

    /**
     * Whether the opcional is included when exporting this tarifa.
     * @var bool
     */
    public $en_tarifa;

    /**
     * Whether the opcional is active in this tarifa. Sole activation source.
     * @var bool
     */
    public $activa;

    /**
     * Default display order; family/article order overrides it.
     * @var int
     */
    public $orden;

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_tarifa_opcional');

        if ($data) {
            $this->codtarifa = $data['codtarifa'];
            $this->id_opcional = intval($data['id_opcional']);
            $this->en_catalogo = isset($data['en_catalogo']) ? $this->str2bool($data['en_catalogo']) : true;
            $this->en_tarifa = isset($data['en_tarifa']) ? $this->str2bool($data['en_tarifa']) : false;
            $this->activa = isset($data['activa']) ? $this->str2bool($data['activa']) : true;
            $this->orden = isset($data['orden']) ? intval($data['orden']) : 0;
        } else {
            $this->codtarifa = null;
            $this->id_opcional = null;
            $this->en_catalogo = true;
            $this->en_tarifa = false;
            $this->activa = true;
            $this->orden = 0;
        }
    }

    protected function install(): string
    {
        // Touch the FK targets first so the master table can be created in a
        // FK-safe order on a standalone catalogo_core install.
        if (class_exists(\FSFramework\model\tarif_tarifa::class)) {
            new tarif_tarifa();
        }
        if (class_exists(\FSFramework\model\catalogo_opcional::class)) {
            new catalogo_opcional();
        }

        return $this->seed_default_tarifa();
    }

    /**
     * Seed SQL for the default tarifa, mirroring
     * `tarif_tarifa_familia::migrate_existing_familias()`.
     *
     * One row per `catalogo_opcionales` entry, inheriting the
     * `tarif_opcional_ext` flags via COALESCE and defaulting `activa=TRUE`,
     * `orden=0`. The NOT EXISTS guard keeps the seed idempotent.
     *
     * @return string
     */
    protected function seed_default_tarifa()
    {
        $codtarifa = $this->default_tarifa_code();
        if ($codtarifa === '') {
            return '';
        }

        $codtarifa = $this->var2str($codtarifa);

        return "INSERT INTO " . $this->table_name
            . " (codtarifa, id_opcional, en_catalogo, en_tarifa, activa, orden) "
            . "SELECT $codtarifa, o.id, COALESCE(e.en_catalogo, TRUE), COALESCE(e.en_tarifa, FALSE), TRUE, 0 "
            . "FROM catalogo_opcionales o "
            . "LEFT JOIN tarif_opcional_ext e ON o.id = e.id_opcional "
            . "WHERE NOT EXISTS (SELECT 1 FROM " . $this->table_name
            . " WHERE codtarifa = $codtarifa AND id_opcional = o.id);";
    }

    /**
     * Seam over the default-tarifa lookup so the seed is unit-testable
     * without instantiating `tarif_tarifa` or reaching a live database.
     *
     * @return string
     */
    protected function default_tarifa_code()
    {
        if (!class_exists(\FSFramework\model\tarif_tarifa::class)) {
            return '';
        }

        $tarifa = new tarif_tarifa();
        $default = $tarifa->get_default();

        return $default ? (string) $default->codtarifa : '';
    }

    public function test()
    {
        $this->codtarifa = $this->no_html(trim((string) $this->codtarifa));

        if (empty($this->codtarifa) || $this->id_opcional === null) {
            $this->new_error_msg('Tarifa and opcional are required.');
            return false;
        }

        return true;
    }

    /**
     * Raw master row for a composite key, or false when absent.
     *
     * @param string $codtarifa
     * @param int $id_opcional
     * @return array<string, mixed>|false
     */
    private function fetch_master_row($codtarifa, $id_opcional)
    {
        $data = $this->db->select("SELECT * FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND id_opcional = " . $this->intval($id_opcional) . ";");

        if ($data) {
            return $data[0];
        }

        return false;
    }

    /**
     * Returns the master record for a composite key, or false when absent.
     * A missing row is NOT created here (lazy inheritance).
     *
     * @param string $codtarifa
     * @param int $id_opcional
     * @return tarif_tarifa_opcional|false
     */
    public function get($codtarifa, $id_opcional)
    {
        $row = $this->fetch_master_row($codtarifa, $id_opcional);
        if ($row) {
            return new static($row);
        }

        return false;
    }

    public function exists()
    {
        if (is_null($this->codtarifa) || is_null($this->id_opcional)) {
            return false;
        }

        return (bool) $this->db->select("SELECT * FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
            . " AND id_opcional = " . $this->intval($this->id_opcional) . ";");
    }

    /**
     * Full-row insert/update.
     *
     * Production writes go through the whitelisted single-column
     * `set_activa()`/`set_en_catalogo()`/`set_en_tarifa()`/`set_orden()`
     * setters (which use {@see update_single_field()}), so the UPDATE branch
     * below has no production caller: it is kept only as a defensive fallback
     * for a direct/legacy full-row save. Do not add new callers — a full-row
     * update would rewrite unrelated flags from possibly-stale in-memory
     * values.
     */
    public function save()
    {
        if (!$this->test()) {
            return false;
        }

        if ($this->exists()) {
            // Defensive fallback, unreachable through the toggle setters:
            // they update one whitelisted column at a time to avoid clobbering
            // concurrent flag changes.
            $sql = "UPDATE " . $this->table_name . " SET "
                . "en_catalogo = " . $this->var2str($this->en_catalogo)
                . ", en_tarifa = " . $this->var2str($this->en_tarifa)
                . ", activa = " . $this->var2str($this->activa)
                . ", orden = " . $this->intval($this->orden)
                . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
                . " AND id_opcional = " . $this->intval($this->id_opcional) . ";";
        } else {
            $sql = "INSERT INTO " . $this->table_name
                . " (codtarifa, id_opcional, en_catalogo, en_tarifa, activa, orden) VALUES ("
                . $this->var2str($this->codtarifa) . ","
                . $this->intval($this->id_opcional) . ","
                . $this->var2str($this->en_catalogo) . ","
                . $this->var2str($this->en_tarifa) . ","
                . $this->var2str($this->activa) . ","
                . $this->intval($this->orden) . ");";
        }

        return (bool) $this->db->exec($sql);
    }

    public function delete()
    {
        return (bool) $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
            . " AND id_opcional = " . $this->intval($this->id_opcional) . ";");
    }

    /**
     * All master rows of a tarifa, ordered by `orden`.
     *
     * @param string $codtarifa
     * @return array<int, tarif_tarifa_opcional>
     */
    public function all_from_tarifa($codtarifa)
    {
        $list = [];
        $data = $this->db->select("SELECT * FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " ORDER BY orden ASC, id_opcional ASC;");

        if ($data) {
            foreach ($data as $row) {
                $list[] = new static($row);
            }
        }

        return $list;
    }

    /**
     * Number of explicit master rows of a tarifa.
     *
     * @param string $codtarifa
     * @return int
     */
    public function count_from_tarifa($codtarifa)
    {
        $data = $this->db->select("SELECT COUNT(*) as total FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa) . ";");

        if ($data) {
            return intval($data[0]['total']);
        }

        return 0;
    }

    /**
     * Copies the master state of `$origen` into `$destino`.
     *
     * The destination is cleared first so the copy is authoritative, then all
     * six master columns are copied with a single INSERT ... SELECT. Mirrors
     * `tarif_tarifa_familia::copy_from_tarifa()`.
     *
     * @param string $origen
     * @param string $destino
     * @return bool
     */
    public function copy_from_tarifa($origen, $destino): bool
    {
        // Copying a tarifa onto itself is a no-op: the DELETE would remove the
        // very rows the INSERT ... SELECT is meant to read, wiping the tarifa.
        if ((string) $origen === (string) $destino) {
            return true;
        }

        // The destination is cleared and repopulated in a single transaction:
        // a failed INSERT must not leave the destination tarifa empty. Both
        // statements run with $transaction = false so nothing auto-commits
        // before the explicit commit below.
        if (!$this->db->begin_transaction()) {
            return false;
        }

        $deleted = $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($destino) . ";", false);
        if (!$deleted) {
            $this->db->rollback();
            return false;
        }

        $sql = "INSERT INTO " . $this->table_name
            . " (codtarifa, id_opcional, en_catalogo, en_tarifa, activa, orden) "
            . "SELECT " . $this->var2str($destino) . ", id_opcional, en_catalogo, en_tarifa, activa, orden "
            . "FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($origen) . ";";

        if (!$this->db->exec($sql, false)) {
            $this->db->rollback();
            return false;
        }

        if (!$this->db->commit()) {
            $this->db->rollback();
            return false;
        }

        return true;
    }

    /**
     * Inheritance seam: ext flags for a missing master row.
     *
     * Reads `tarif_opcional_ext`; when no ext row exists it falls back to the
     * master defaults (`en_catalogo = TRUE`, `en_tarifa = FALSE`) that the
     * install seed uses via COALESCE. Kept protected so lazy inheritance is
     * unit-testable without a live database.
     *
     * @param int $id_opcional
     * @return array{en_catalogo: bool, en_tarifa: bool}
     */
    protected function ext_defaults($id_opcional)
    {
        $data = $this->db->select("SELECT en_catalogo, en_tarifa FROM tarif_opcional_ext"
            . " WHERE id_opcional = " . $this->intval($id_opcional) . ";");

        if ($data) {
            return [
                'en_catalogo' => $this->str2bool($data[0]['en_catalogo']),
                'en_tarifa' => $this->str2bool($data[0]['en_tarifa']),
            ];
        }

        return ['en_catalogo' => true, 'en_tarifa' => false];
    }

    /**
     * Effective state of an opcional in a tarifa.
     *
     * Returns the master row values when it exists (`source = 'master'`), or
     * the inherited defaults (`source = 'inherit'`) when it does not. Reading
     * a missing row never persists; the first explicit write creates it.
     *
     * @param string $codtarifa
     * @param int $id_opcional
     * @return array{activa: bool, en_catalogo: bool, en_tarifa: bool, orden: int, source: string}
     */
    public function effective($codtarifa, $id_opcional)
    {
        $row = $this->fetch_master_row($codtarifa, $id_opcional);
        if ($row) {
            return [
                'activa' => isset($row['activa']) ? $this->str2bool($row['activa']) : true,
                'en_catalogo' => isset($row['en_catalogo']) ? $this->str2bool($row['en_catalogo']) : true,
                'en_tarifa' => isset($row['en_tarifa']) ? $this->str2bool($row['en_tarifa']) : false,
                'orden' => isset($row['orden']) ? intval($row['orden']) : 0,
                'source' => 'master',
            ];
        }

        $defaults = $this->ext_defaults($id_opcional);

        return [
            'activa' => true,
            'en_catalogo' => (bool) $defaults['en_catalogo'],
            'en_tarifa' => (bool) $defaults['en_tarifa'],
            'orden' => 0,
            'source' => 'inherit',
        ];
    }

    /**
     * Effective activation of an opcional in a tarifa.
     * The master `activa` is authoritative; price rows never activate.
     *
     * @param string $codtarifa
     * @param int $id_opcional
     * @return bool
     */
    public function resolve_activa($codtarifa, $id_opcional)
    {
        $state = $this->effective($codtarifa, $id_opcional);

        return $state['activa'];
    }

    /**
     * Effective catalog/export visibility of an opcional in a tarifa.
     *
     * @param string $codtarifa
     * @param int $id_opcional
     * @return bool
     */
    public function resolve_en_catalogo($codtarifa, $id_opcional)
    {
        $state = $this->effective($codtarifa, $id_opcional);

        return $state['en_catalogo'];
    }

    /**
     * Effective default order of an opcional in a tarifa.
     *
     * @param string $codtarifa
     * @param int $id_opcional
     * @return int
     */
    public function resolve_orden($codtarifa, $id_opcional)
    {
        $state = $this->effective($codtarifa, $id_opcional);

        return $state['orden'];
    }

    /**
     * Sets the master `activa` for a `(tarifa, opcional)`.
     * The first explicit write creates the master row.
     *
     * @param string $codtarifa
     * @param int $id_opcional
     * @param bool $value
     * @return bool
     */
    public function set_activa($codtarifa, $id_opcional, $value): bool
    {
        return $this->persist_toggle($codtarifa, $id_opcional, 'activa', (bool) $value);
    }

    /**
     * Sets the master `en_catalogo` for a `(tarifa, opcional)`.
     * The first explicit write creates the master row.
     *
     * @param string $codtarifa
     * @param int $id_opcional
     * @param bool $value
     * @return bool
     */
    public function set_en_catalogo($codtarifa, $id_opcional, $value): bool
    {
        return $this->persist_toggle($codtarifa, $id_opcional, 'en_catalogo', (bool) $value);
    }

    /**
     * Sets the master `en_tarifa` for a `(tarifa, opcional)`.
     * The first explicit write creates the master row.
     *
     * @param string $codtarifa
     * @param int $id_opcional
     * @param bool $value
     * @return bool
     */
    public function set_en_tarifa($codtarifa, $id_opcional, $value): bool
    {
        return $this->persist_toggle($codtarifa, $id_opcional, 'en_tarifa', (bool) $value);
    }

    /**
     * Sets the master default `orden` for a `(tarifa, opcional)`.
     * The first explicit write creates the master row.
     *
     * @param string $codtarifa
     * @param int $id_opcional
     * @param int $value
     * @return bool
     */
    public function set_orden($codtarifa, $id_opcional, $value): bool
    {
        return $this->persist_toggle($codtarifa, $id_opcional, 'orden', intval($value));
    }

    /**
     * Persists a single master flag. An existing row is updated with a
     * single-column UPDATE so concurrent toggles of unrelated flags are never
     * overwritten with stale in-memory values; a missing row is materialized
     * from the inheritance defaults on first write.
     *
     * @param string $codtarifa
     * @param int $id_opcional
     * @param string $field
     * @param mixed $value
     * @return bool
     */
    private function persist_toggle($codtarifa, $id_opcional, string $field, $value): bool
    {
        if ($this->get($codtarifa, $id_opcional)) {
            return $this->update_single_field($codtarifa, $id_opcional, $field, $value);
        }

        $row = $this->inherited_row($codtarifa, $id_opcional);
        $row->{$field} = $value;

        return (bool) $row->save();
    }

    /**
     * Atomically writes exactly one column of the master row.
     *
     * @param string $codtarifa
     * @param int $id_opcional
     * @param string $field
     * @param mixed $value
     * @return bool
     */
    private function update_single_field($codtarifa, $id_opcional, string $field, $value): bool
    {
        $allowed = ['activa', 'en_catalogo', 'en_tarifa', 'orden'];
        if (!in_array($field, $allowed, true)) {
            return false;
        }

        $formatted = ($field === 'orden') ? $this->intval($value) : $this->var2str($value);

        $sql = "UPDATE " . $this->table_name
            . " SET " . $field . " = " . $formatted
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND id_opcional = " . $this->intval($id_opcional) . ";";

        return (bool) $this->db->exec($sql);
    }

    /**
     * Builds a not-yet-persisted master row from the inheritance defaults
     * (ext flags, `activa=TRUE`, `orden=0`).
     *
     * @param string $codtarifa
     * @param int $id_opcional
     * @return static
     */
    private function inherited_row($codtarifa, $id_opcional)
    {
        $defaults = $this->ext_defaults($id_opcional);

        return new static([
            'codtarifa' => $codtarifa,
            'id_opcional' => $id_opcional,
            'en_catalogo' => $defaults['en_catalogo'],
            'en_tarifa' => $defaults['en_tarifa'],
            'activa' => true,
            'orden' => 0,
        ]);
    }
}
