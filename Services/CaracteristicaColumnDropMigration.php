<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * CAR-15 — gated reversible column drop for the legacy visibility flags.
 *
 * Two independent, idempotent, introspective clauses:
 *
 *  1. `dropD12OpcionalColumns()` — the opcional-owned `en_catalogo`/`en_tarifa`
 *     flags on `tarif_opcional_ext` and `tarif_tarifa_opcional`. Gated **only**
 *     on the CAR-12 behaviour-preservation parity test
 *     (`plugins/catalogo_core/tests/OpcionalVisibilityParityTest.php`): the
 *     derived read-only indicator replaces the flags immediately, so no
 *     article/family soak is required.
 *  2. `dropLegacyArticleFamilyColumns()` — the legacy article/family columns
 *     (WU-7). The feature path is the default, so it runs by default; it
 *     refuses only while the legacy read path was **explicitly** selected
 *     (`CaracteristicaConfig::legacy_read_explicitly_enabled()`, the emergency
 *     opt-out), in which case it returns FALSE and changes no schema.
 *
 * Reversibility: the feature value tables are the source of truth and are
 * **never** touched. Re-adding the dropped columns as nullable and re-deriving
 * from the resolver reproduces every previously stored legacy value — the
 * executable proof is `OpcionalVisibilityParityTest`, `CaracteristicaReadThroughTest`
 * and `CaracteristicaBackfillTest`. A pre-drop dump is the operator-level
 * safety net (documented, not automated).
 *
 * The migration is deliberately NOT called from `Init::init()`: dropping
 * schema is an explicit, operator-gated deploy step.
 *
 * Operator runbook — clause 1 (D12 opcional flags):
 *   1. Keep a pre-drop dump of `tarif_opcional_ext` and `tarif_tarifa_opcional`
 *      (operator-level safety net; not automated).
 *   2. Confirm `tests/OpcionalVisibilityParityTest.php` is green (CAR-12
 *      behaviour-preservation parity — the gate).
 *   3. Run `CaracteristicaColumnDropMigration::dropD12OpcionalColumns($db)`
 *      once (idempotent: a second run finds no columns and does nothing).
 *   4. Clear the Twig cache (the opcional views render the derived indicator).
 *   Schema parity is only complete after step 3: until the columns are dropped
 *   the plugin still tolerates them, but `hasColumn()` reports them present.
 *   Because the columns still exist in a not-yet-dropped database while the
 *   model/XML no longer declare them, `fs_schema` will not recreate them — the
 *   only supported path is this explicit, gated call.
 *
 * Clause 2 (legacy article/family flags) stays closed until WU-7 ships with the
 * read-through soak.
 *
 * Ordered prerequisite for clause 2 (DEV-17): the tarifario catalog membership
 * filters (`tarif_tarifa_articulo.en_catalogo` / `tarif_tarifa_familia.en_catalogo`
 * SQL pre-filters in the catalog view, plus the two model helpers that filter on
 * the same columns) remain legacy-by-design during the soak. They MUST be
 * rewritten to the feature tables (or removed) **before** clause 2 drops those
 * columns: the drop would otherwise leave the membership queries referencing a
 * column that no longer exists. Do not run clause 2 before that rewrite lands.
 *
 * Operator runbook — clause 2 + dead-column cleanup (post-soak):
 *   1. Keep a pre-drop dump (operator-level safety net; not automated).
 *   2. Confirm the catalog membership filters no longer read the legacy columns
 *      (DEV-17 closure) and that the soak closed DEV-18 (no write path bypasses
 *      the feature store).
 *   3. Run `dropLegacyArticleFamilyColumns($db)` once (idempotent).
 *   4. Run `dropDeadOpcionalColumns($db)` once (idempotent): the leftover
 *      `catalogo_opcionales` flags.
 *   5. Clear the Twig cache.
 *
 * Closed-loop entry point (WU-7 7.3): {@see runPostSoak()} runs the whole
 * post-soak sequence in the declared order (clause 1 → clause 2 → dead-column
 * cleanup) and reports the resulting schema. It is the operator-facing invoker
 * of the runbook above; `runPostSoak()` is NEVER called from `Init::init()`,
 * `Init::upgrade()` or any request path — dropping schema is always an
 * explicit, operator-gated deploy step.
 */
class CaracteristicaColumnDropMigration
{
    /** Clause 1 tables: the opcional-owned surfaces remove their flags. */
    public const D12_TABLES = ['tarif_opcional_ext', 'tarif_tarifa_opcional'];

    /** Clause 2 tables: the legacy article/family visibility surfaces. */
    public const LEGACY_TABLES = [
        'tarif_articulo_precios',
        'tarif_tarifa_articulo',
        'tarif_tarifa_familia',
        'tarif_familia_ext',
    ];

    /**
     * Dedicated gated step: the dead `catalogo_opcionales` visibility columns.
     *
     * They are present in deployed databases, no model declares them, and they
     * are outside `LEGACY_TABLES` (CAR-15's clause 2 keeps its pinned list).
     * They are dropped by `dropDeadOpcionalColumns()` after the same soak gate.
     */
    public const DEAD_OPCIONAL_TABLES = ['catalogo_opcionales'];

    /** The two dropped flags. */
    public const FLAGS = ['en_catalogo', 'en_tarifa'];

    /**
     * The ordered post-soak steps of the closed loop, in execution order.
     *
     * The order is a contract, not a convention: DEV-17's legacy membership
     * filters must be rewritten to the feature tables **before clause 2** runs,
     * and the dead-column cleanup follows it. {@see runPostSoak()} iterates
     * this map, so a reordering here is a deliberate, reviewable change.
     *
     * @var array<string, string> step key => the service method it invokes
     */
    public const POST_SOAK_STEPS = [
        'clause1' => 'dropD12OpcionalColumns',
        'clause2' => 'dropLegacyArticleFamilyColumns',
        'dead_opcional' => 'dropDeadOpcionalColumns',
    ];

    /**
     * Clause 1 — DROP the opcional visibility flags (idempotent).
     *
     * Gate: the CAR-12 behavior-preservation parity test must be green. That
     * gate is a repository/test-level precondition, not a runtime flag: the
     * caller is the D12 work unit (or the operator running the documented
     * deploy step), and `OpcionalVisibilityParityTest` is the executable proof.
     */
    public static function dropD12OpcionalColumns(\fs_db2 $db): bool
    {
        return self::dropFlags($db, self::D12_TABLES);
    }

    /**
     * Clause 2 — DROP the legacy article/family visibility flags (post-soak).
     *
     * The feature path is the default, so this runs by default. It refuses
     * (returns FALSE, no schema change) only while the legacy read path was
     * **explicitly** selected — the emergency opt-out
     * (`CaracteristicaConfig::legacy_read_explicitly_enabled()`). The refusal is
     * load-bearing: in that state the legacy columns are authoritative, so
     * dropping them would break the catalog.
     *
     * `tarif_familia_ext` may already lack the columns (divergence 1: the
     * retired table has only `codfamilia`/`capitulo`/`nivel`) ⇒ the
     * `hasColumn()` guard makes it a no-op for that table.
     */
    public static function dropLegacyArticleFamilyColumns(\fs_db2 $db): bool
    {
        if (static::legacy_read_explicitly_enabled()) {
            return false;
        }

        return self::dropFlags($db, self::LEGACY_TABLES);
    }

    /**
     * Dead-column cleanup — DROP the unused `catalogo_opcionales` visibility
     * columns (idempotent, gated on the same legacy opt-out as clause 2).
     *
     * The columns are present in deployed databases but no model declares them
     * and nothing reads or writes them: they are leftovers of the pre-D12
     * opcional-owned visibility. They are **not** part of CAR-15 clause 2, so
     * they must not linger behind it either.
     *
     * `catalogo_opcional_precios.en_catalogo` is deliberately NOT included: it
     * is the per-lista price inclusion flag and stays.
     */
    public static function dropDeadOpcionalColumns(\fs_db2 $db): bool
    {
        if (static::legacy_read_explicitly_enabled()) {
            return false;
        }

        return self::dropFlags($db, self::DEAD_OPCIONAL_TABLES);
    }

    /**
     * Legacy opt-out gate seam. Overridable so the gate is unit-testable without
     * defining a process-wide constant (a defined constant cannot be unset and
     * would leak into any non-isolated test process).
     */
    protected static function legacy_read_explicitly_enabled(): bool
    {
        return CaracteristicaConfig::legacy_read_explicitly_enabled();
    }

    /**
     * Closed-loop operator entry point (WU-7 7.3).
     *
     * Runs the whole post-soak sequence in the declared order — clause 1 →
     * clause 2 → dead-column cleanup ({@see POST_SOAK_STEPS}) — and reports the
     * resulting schema, so an operator runs the documented runbook once instead
     * of three manual calls. It is:
     *
     *  - **explicit**: never called from `Init::init()`, `Init::upgrade()` or
     *    any request path. Only an operator (or the `tools/` runner) invokes it,
     *    always with a `\fs_db2` connection in hand;
     *  - **idempotent**: every clause probes `hasColumn()` before its `ALTER`,
     *    so a second run emits no DDL and reports nothing left to drop;
     *  - **introspective**: the report is derived from the live schema
     *    ({@see pendingVisibilityColumns()}), never from assumptions. It lists
     *    what the run actually dropped and what (if anything) remains.
     *
     * Two preconditions gate the whole loop, and it refuses atomically — a
     * refusal runs **no** step and changes no schema:
     *
     *  1. the legacy read path must NOT be explicitly selected. The feature path
     *     is the default, so this holds while
     *     `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` is undefined or TRUE;
     *     defining it as FALSE (the emergency opt-out) refuses the loop, because
     *     the legacy columns are then authoritative;
     *  2. the DEV-17 closure must be attested by the caller
     *     (`$dev17MembershipFiltersRewritten`). The tarifario catalog membership
     *     filters still read `tarif_tarifa_articulo.en_catalogo` /
     *     `tarif_tarifa_familia.en_catalogo` and MUST be rewritten to the feature
     *     tables before clause 2 drops those columns, or the membership queries
     *     become invalid SQL. This parameter has **no permissive default**: the
     *     operator must state the rewrite landed.
     *
     * Honest limitation of precondition 2: it is an operator attestation, not a
     * machine check. The DEV-17 surface spans a tarifario-owned controller
     * (`tarif_catalogo_view.php`), which catalogo_core must not reference
     * (CAR-19 boundary), and during the soak several catalogo_core paths
     * legitimately still read the legacy columns (the CAR-13 backfill, the
     * CAR-14 dual-write and the models themselves), so a source scan could not
     * tell a membership filter apart from a soak-window read. The ordered
     * prerequisite is therefore carried by an explicit, required attestation and
     * frozen by `TarifCatalogoMembershipFilterRatificationTest` (tarifario).
     *
     * @return array{
     *     status: 'refused'|'applied'|'failed',
     *     legacy_read_explicitly_enabled: bool,
     *     dev17_membership_filters_rewritten: bool,
     *     reason: string,
     *     steps: array<string, bool>,
     *     dropped: list<string>,
     *     remaining: list<string>
     * }
     */
    public static function runPostSoak(\fs_db2 $db, bool $dev17MembershipFiltersRewritten): array
    {
        $before = self::pendingVisibilityColumns($db);

        if (static::legacy_read_explicitly_enabled()) {
            return self::postSoakReport(
                'refused',
                true,
                $dev17MembershipFiltersRewritten,
                'the legacy read path is explicitly selected: ' . CaracteristicaConfig::READ_THROUGH_FLAG
                    . ' is FALSE, so the legacy columns remain authoritative',
                [],
                $before,
                $db
            );
        }

        if (!$dev17MembershipFiltersRewritten) {
            return self::postSoakReport(
                'refused',
                false,
                false,
                'DEV-17 is not attested: the legacy catalog membership filters must be rewritten to the'
                    . ' feature tables before clause 2 drops the columns they filter on',
                [],
                $before,
                $db
            );
        }

        $steps = [];
        foreach (array_keys(self::POST_SOAK_STEPS) as $step) {
            $steps[$step] = match ($step) {
                'clause1' => self::dropD12OpcionalColumns($db),
                'clause2' => self::dropLegacyArticleFamilyColumns($db),
                default => self::dropDeadOpcionalColumns($db),
            };
        }

        $failed = array_keys(array_filter($steps, static fn (bool $ok): bool => !$ok));

        return self::postSoakReport(
            $failed === [] ? 'applied' : 'failed',
            false,
            true,
            $failed === []
                ? 'all post-soak clauses applied'
                : 'the ALTER failed for: ' . implode(', ', $failed),
            $steps,
            $before,
            $db
        );
    }

    /**
     * Introspective probe: every `table.column` the gated clauses would drop and
     * that is still present, in the declared table order. Read-only: it emits no
     * DDL.
     *
     * `catalogo_opcional_precios.en_catalogo` is deliberately absent: it is the
     * per-lista price inclusion flag and is never a drop candidate.
     *
     * @return list<string>
     */
    public static function pendingVisibilityColumns(\fs_db2 $db): array
    {
        $pending = [];
        foreach (self::gatedTables() as $table) {
            foreach (self::FLAGS as $column) {
                if (self::hasColumn($db, $table, $column)) {
                    $pending[] = $table . '.' . $column;
                }
            }
        }

        return $pending;
    }

    /**
     * Every table a gated clause probes, in the declared order.
     *
     * @return list<string>
     */
    public static function gatedTables(): array
    {
        return array_values(array_unique(array_merge(
            self::D12_TABLES,
            self::LEGACY_TABLES,
            self::DEAD_OPCIONAL_TABLES
        )));
    }

    /**
     * Introspective column probe (MySQL/MariaDB and PostgreSQL branches,
     * mirroring `TarifOpcionalExtMigration`).
     */
    public static function hasColumn(\fs_db2 $db, string $table, string $column): bool
    {
        if (self::isPostgres()) {
            $data = $db->select(
                'SELECT 1 FROM information_schema.columns '
                . "WHERE table_schema = 'public' AND table_name = " . $db->var2str($table)
                . ' AND column_name = ' . $db->var2str($column) . ' LIMIT 1;'
            );
        } else {
            $data = $db->select(
                'SHOW COLUMNS FROM `' . $table . '` LIKE ' . $db->var2str($column) . ';'
            );
        }

        return (bool) $data;
    }

    /**
     * Drops both flags from every given table, one guarded `ALTER` at a time.
     * Idempotent: a missing column emits nothing.
     *
     * @param list<string> $tables
     */
    private static function dropFlags(\fs_db2 $db, array $tables): bool
    {
        foreach ($tables as $table) {
            foreach (self::FLAGS as $column) {
                if (!self::hasColumn($db, $table, $column)) {
                    continue;
                }

                if (!(bool) $db->exec('ALTER TABLE ' . $table . ' DROP COLUMN ' . $column . ';')) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function isPostgres(): bool
    {
        return defined('FS_DB_TYPE') && FS_DB_TYPE === 'postgresql';
    }

    /**
     * Builds the closed-loop report from the live schema: `remaining` is
     * re-probed after the steps ran, and `dropped` is the difference against the
     * pre-run probe (so a re-run reports nothing left to drop).
     *
     * @param array<string, bool> $steps
     * @param list<string>        $before
     * @return array{
     *     status: 'refused'|'applied'|'failed',
     *     legacy_read_explicitly_enabled: bool,
     *     dev17_membership_filters_rewritten: bool,
     *     reason: string,
     *     steps: array<string, bool>,
     *     dropped: list<string>,
     *     remaining: list<string>
     * }
     */
    private static function postSoakReport(
        string $status,
        bool $legacyReadExplicitlyEnabled,
        bool $dev17MembershipFiltersRewritten,
        string $reason,
        array $steps,
        array $before,
        \fs_db2 $db
    ): array {
        $remaining = self::pendingVisibilityColumns($db);

        return [
            'status' => $status,
            'legacy_read_explicitly_enabled' => $legacyReadExplicitlyEnabled,
            'dev17_membership_filters_rewritten' => $dev17MembershipFiltersRewritten,
            'reason' => $reason,
            'steps' => $steps,
            'dropped' => array_values(array_diff($before, $remaining)),
            'remaining' => $remaining,
        ];
    }
}
