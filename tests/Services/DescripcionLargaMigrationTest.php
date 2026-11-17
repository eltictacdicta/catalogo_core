<?php
declare(strict_types=1);

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\DescripcionLargaMigration;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/catalogo_core/Services/DescripcionLargaMigration.php';

final class DescripcionLargaMigrationFakeDb extends \fs_db2
{
    /** @var list<string> */
    public array $executed = [];

    /**
     * @param list<string> $columns
     */
    public function __construct(private array $columns, private bool $copyOk = true, private bool $tableExists = true)
    {
        // Deliberately skip parent::__construct(): no engine, no DB connection.
    }

    public function table_exists($name, $list = false)
    {
        return $this->tableExists;
    }

    public function get_columns($table_name)
    {
        return array_map(static fn (string $c): array => ['name' => $c], $this->columns);
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $this->executed[] = $sql;

        return str_starts_with($sql, 'UPDATE') ? $this->copyOk : true;
    }
}

final class DescripcionLargaMigrationTest extends TestCase
{
    public function test_old_column_is_added_copied_and_dropped(): void
    {
        $db = new DescripcionLargaMigrationFakeDb(['id', 'descripcion', 'descripcion_corta']);

        DescripcionLargaMigration::migrateIfNeeded($db);

        $this->assertCount(3, $db->executed);
        $this->assertStringContainsString('ADD COLUMN descripcion_larga text', $db->executed[0]);
        $this->assertStringContainsString('SET descripcion_larga = descripcion_corta', $db->executed[1]);
        $this->assertStringContainsString('DROP COLUMN descripcion_corta', $db->executed[2]);
    }

    public function test_existing_new_column_is_not_added_again_nor_overwritten(): void
    {
        $db = new DescripcionLargaMigrationFakeDb(['id', 'descripcion_corta', 'descripcion_larga']);

        DescripcionLargaMigration::migrateIfNeeded($db);

        $this->assertCount(2, $db->executed);
        $this->assertStringContainsString("descripcion_larga IS NULL OR descripcion_larga = ''", $db->executed[0]);
        $this->assertStringContainsString('DROP COLUMN descripcion_corta', $db->executed[1]);
    }

    public function test_failed_copy_keeps_the_old_column(): void
    {
        $db = new DescripcionLargaMigrationFakeDb(['id', 'descripcion_corta'], false);

        DescripcionLargaMigration::migrateIfNeeded($db);

        $this->assertStringNotContainsString('DROP COLUMN', implode("\n", $db->executed));
    }

    public function test_migrated_or_missing_table_runs_nothing(): void
    {
        $migrated = new DescripcionLargaMigrationFakeDb(['id', 'descripcion', 'descripcion_larga']);
        DescripcionLargaMigration::migrateIfNeeded($migrated);
        $this->assertSame([], $migrated->executed);

        $missing = new DescripcionLargaMigrationFakeDb(['descripcion_corta'], true, false);
        DescripcionLargaMigration::migrateIfNeeded($missing);
        $this->assertSame([], $missing->executed);
    }
}
