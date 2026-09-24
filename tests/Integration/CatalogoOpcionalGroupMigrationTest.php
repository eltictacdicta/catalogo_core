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

namespace Tests\CatalogoCore\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Source contract for the OPG-03 bridge migration wiring (DB-free).
 *
 * Pins the ordering inside migrateIfNeeded(), the idempotency pre-check keyed
 * on the (id_opcional, id_grupo) PAIR, both dialect backfills, and the
 * bridge-backed rewrite of migrateGroupedOptionalAssignments().
 */
final class CatalogoOpcionalGroupMigrationTest extends TestCase
{
    private const MIGRATION_RELATIVE = 'plugins/catalogo_core/Services/CatalogLegacyTableMigration.php';

    private function migrationSource(): string
    {
        $path = FS_FOLDER . '/' . self::MIGRATION_RELATIVE;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** Extracts a method body by brace matching from its signature. */
    private function methodSource(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            self::fail('missing migration method: ' . $signature);
        }

        $open = (int) strpos($src, '{', (int) $start);
        $depth = 0;
        $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, (int) $start, $i - $start + 1);
                }
            }
        }

        self::fail('method body is not terminated: ' . $signature);
    }

    /**
     * Collapses PHP string-concatenation artifacts and whitespace so a
     * multi-line SQL literal can be asserted as one statement.
     */
    private function normalized(string $text): string
    {
        $text = (string) preg_replace("/'\s*\.\s*'/", '', $text);

        return (string) preg_replace('/\s+/', ' ', $text);
    }

    public function test_migrate_if_needed_runs_the_bridge_migration_before_grouped_assignments(): void
    {
        $migrate = $this->methodSource($this->migrationSource(), 'function migrateIfNeeded(');

        $posBridge = strpos($migrate, 'self::migrateOpcionalGroupRelations($db);');
        $posGrouped = strpos($migrate, 'self::migrateGroupedOptionalAssignments($db);');

        $this->assertNotFalse($posBridge, 'migrateIfNeeded must call migrateOpcionalGroupRelations()');
        $this->assertNotFalse($posGrouped, 'migrateIfNeeded must call migrateGroupedOptionalAssignments()');
        $this->assertLessThan(
            $posGrouped,
            $posBridge,
            'the bridge must be created + backfilled BEFORE the grouped-assignment rewrite reads it'
        );
    }

    public function test_bridge_migration_exists_and_does_not_early_return_after_create(): void
    {
        $src = $this->migrationSource();
        $this->assertStringContainsString('function migrateOpcionalGroupRelations(', $src);

        $method = $this->methodSource($src, 'function migrateOpcionalGroupRelations(');
        $this->assertStringContainsString('self::hasPendingGroupRelations($db)', $method);
        $this->assertMatchesRegularExpression(
            '/if \(!self::tableExists\(\$db, \'catalogo_opcional_grupo_rel\'\)\)/',
            $method,
            'create-if-missing must not early-return: a half-applied run must be healed'
        );
    }

    public function test_precheck_keys_on_the_opcional_group_pair(): void
    {
        $method = $this->methodSource($this->migrationSource(), 'function hasPendingGroupRelations(');

        $this->assertMatchesRegularExpression(
            '/LEFT JOIN catalogo_opcional_grupo_rel r/',
            $method
        );
        $this->assertMatchesRegularExpression(
            '/ON r\.id_opcional = o\.id AND r\.id_grupo = o\.id_grupo/',
            $method,
            'the pre-check must key on the (id_opcional, id_grupo) pair, not on id'
        );
        $this->assertMatchesRegularExpression(
            '/WHERE o\.id_grupo IS NOT NULL AND o\.id_grupo > 0 AND r\.id IS NULL LIMIT 1/',
            $method
        );
    }

    public function test_backfills_are_idempotent_in_both_dialects(): void
    {
        $method = $this->methodSource($this->migrationSource(), 'function migrateOpcionalGroupRelations(');
        $normalized = $this->normalized($method);

        $this->assertStringContainsString(
            'INSERT INTO catalogo_opcional_grupo_rel (id_opcional, id_grupo) '
            . 'SELECT o.id, o.id_grupo FROM catalogo_opcionales o '
            . 'WHERE o.id_grupo IS NOT NULL AND o.id_grupo > 0 '
            . 'ON CONFLICT (id_opcional, id_grupo) DO NOTHING;',
            $normalized,
            'PostgreSQL backfill must be ON CONFLICT DO NOTHING'
        );
        $this->assertStringContainsString(
            'INSERT IGNORE INTO catalogo_opcional_grupo_rel (id_opcional, id_grupo) '
            . 'SELECT o.id, o.id_grupo FROM catalogo_opcionales o '
            . 'WHERE o.id_grupo IS NOT NULL AND o.id_grupo > 0;',
            $normalized,
            'MySQL backfill must be INSERT IGNORE'
        );
    }

    public function test_grouped_assignments_join_the_bridge(): void
    {
        $method = $this->methodSource($this->migrationSource(), 'function migrateGroupedOptionalAssignments(');

        $this->assertStringContainsString(
            'INNER JOIN catalogo_opcional_grupo_rel r ON r.id_opcional = ao.id_opcional',
            $method,
            'the grouped-assignment read must source membership from the bridge'
        );
        $this->assertStringContainsString("self::tableExists(\$db, 'catalogo_opcional_grupo_rel')", $method);
        $this->assertStringNotContainsString(
            'o.id_grupo IS NOT NULL AND o.id_grupo > 0',
            $method,
            'the grouped-assignment read must no longer filter on the frozen column'
        );
    }
}
