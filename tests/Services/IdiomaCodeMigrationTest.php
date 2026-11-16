<?php
declare(strict_types=1);

namespace Tests\CatalogoCore\Services;

use FSFramework\model\catalogo_idioma;
use FSFramework\Plugins\catalogo_core\Services\IdiomaCodeMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/IdiomaCodeMigration.php';

final class IdiomaCodeMigrationFakeDb extends \fs_db2
{
    /** @var list<string> */
    public array $executed = [];

    /**
     * @param list<string> $codes
     * @param list<string> $tables
     */
    public function __construct(private array $codes, private array $tables)
    {
        // Deliberately skip parent::__construct(): no engine, no DB connection.
    }

    public function var2str($val)
    {
        return "'" . $val . "'";
    }

    public function table_exists($name, $list = false)
    {
        return in_array($name, $this->tables, true);
    }

    public function select($sql, $params = [])
    {
        return array_map(static fn (string $c): array => ['codidioma' => $c], $this->codes);
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $this->executed[] = $sql;
        return true;
    }

    public function begin_transaction()
    {
        return true;
    }

    public function commit()
    {
        return true;
    }

    public function rollback()
    {
        return true;
    }
}

final class IdiomaCodeMigrationTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function codes(): array
    {
        return [
            'bare es' => ['es', 'es_ES'],
            'bare en is British' => ['en', 'en_GB'],
            'bare fr' => ['FR', 'fr_FR'],
            'lowercase region' => ['es_es', 'es_ES'],
            'hyphen' => ['en-gb', 'en_GB'],
            'canonical untouched' => ['pt_BR', 'pt_BR'],
            'garbage untouched' => ['esp', 'esp'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('codes')]
    public function testNormalizeCode(string $input, string $expected): void
    {
        $this->assertSame($expected, catalogo_idioma::normalize_code($input));
    }

    public function testRenamesLegacyCodesInRegistryAndExistingChildTables(): void
    {
        $db = new IdiomaCodeMigrationFakeDb(['es', 'en', 'fr_FR'], ['catalogo_idiomas', 'articulo_descripciones']);

        IdiomaCodeMigration::migrateIfNeeded($db);

        $this->assertSame([
            "UPDATE catalogo_idiomas SET codidioma = 'es_ES' WHERE codidioma = 'es';",
            "UPDATE articulo_descripciones SET codidioma = 'es_ES' WHERE codidioma = 'es';",
            "UPDATE catalogo_idiomas SET codidioma = 'en_GB' WHERE codidioma = 'en';",
            "UPDATE articulo_descripciones SET codidioma = 'en_GB' WHERE codidioma = 'en';",
        ], $db->executed);
    }

    public function testCanonicalRegistryExecutesNothing(): void
    {
        $db = new IdiomaCodeMigrationFakeDb(['es_ES', 'en_GB'], ['catalogo_idiomas', 'articulo_descripciones']);

        IdiomaCodeMigration::migrateIfNeeded($db);

        $this->assertSame([], $db->executed);
    }

    public function testCollidingCodesAreLeftUntouched(): void
    {
        $db = new IdiomaCodeMigrationFakeDb(['es', 'es_ES'], ['catalogo_idiomas']);

        ini_set('error_log', '/dev/null'); // the skip is logged on purpose
        try {
            IdiomaCodeMigration::migrateIfNeeded($db);
        } finally {
            ini_restore('error_log');
        }

        $this->assertSame([], $db->executed);
    }
}
