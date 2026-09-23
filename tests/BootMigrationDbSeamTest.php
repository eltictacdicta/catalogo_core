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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Entry-point independence regression for catalogo_core's boot DB seam.
 *
 * The boot migrations used to resolve their handle through the container's
 * lazy `db` service. That service is registered lazy, so Symfony hands back a
 * proxy whose constructor never runs: it cannot seed `fs_db2`'s static engine
 * on its own. From a clean entry point `Container::db()` throws and the seam
 * returned null, silently skipping the migrations. Production only worked by
 * accident of boot order (`index.php` builds a real `fs_db2` before
 * `Kernel::boot()`); in `api.php` the plugin boot runs first, so the
 * migrations were skipped on every request with no self-heal.
 *
 * The seam now loads `base/fs_db2.php` defensively and instantiates a real
 * `\fs_db2` directly, with no dependency on the container and no dependency
 * on boot order.
 *
 * A same-process test can never prove this: once any earlier code loaded
 * `Container` or built an `fs_db2`, the ghost becomes usable and the assertion
 * would pass for the wrong reason. This test therefore spawns fresh PHP
 * processes that preload neither, invokes one boot entry point, and asserts:
 *   - `Container` is never loaded (the seam does not route through it), and
 *   - `fs_db2` is loaded by the seam, and for the seams that RETURN the
 *     handle, that the returned object is a usable `\fs_db2` (its
 *     engine-backed `date_style()` does not throw).
 *
 * What it does NOT prove: that the DB-bound migration body completes; that
 * needs a live database. `FS_DB_HOST` is deliberately left undefined so no
 * connection is ever attempted. The void entry points (`init()`, `upgrade()`,
 * the migrations) cannot expose the handle they build, so for them the
 * contract asserted is structural: `Container` untouched + `fs_db2` loaded.
 */
final class BootMigrationDbSeamTest extends TestCase
{
    private const CONTAINER = 'FSFramework\\DependencyInjection\\Container';
    private const FS_DB2 = 'fs_db2';
    private const GRUPO_MODEL = 'FSFramework\\model\\catalogo_articulo_opcional_grupo';
    private const INIT = 'FSFramework\\Plugins\\catalogo_core\\Init';
    private const REGISTRY = 'FSFramework\\Plugins\\catalogo_core\\Services\\CaracteristicaRegistry';
    private const TMP_DIR_NAME = 'catalogo_core_db_seam_probe/';

    /** @var list<string> */
    private array $tempScripts = [];

    protected function tearDown(): void
    {
        foreach ($this->tempScripts as $script) {
            if (is_file($script)) {
                @unlink($script);
            }
        }
        $this->tempScripts = [];

        parent::tearDown();
    }

    /**
     * Each boot entry point, the arguments to invoke it with, and whether it
     * returns the DB handle (and therefore supports the strong "usable db"
     * assertion).
     *
     * `CaracteristicaRegistry::resolveDb()` is private; it is invoked with an
     * explicit `null` so it takes the container-free resolution branch.
     * `ensureArticuloOpcionalGrupoTable()` never touches the DB seam — it only
     * instantiates a model — so it is kept here as a "must not go through the
     * container" control, not as a DB assertion.
     *
     * @return array<string, array{0: string, 1: string, 2: list<mixed>, 3: bool}>
     */
    public static function bootEntryPoints(): array
    {
        return [
            'Init::plugin_db()' => [self::INIT, 'plugin_db', [], true],
            'CaracteristicaRegistry::resolveDb(null)' => [self::REGISTRY, 'resolveDb', [null], true],
            'Init::migrateLegacyTables()' => [self::INIT, 'migrateLegacyTables', [], false],
            'Init::migrateOpcionalExtension()' => [self::INIT, 'migrateOpcionalExtension', [], false],
            'Init::ensureArticuloOpcionalGrupoTable()' => [self::INIT, 'ensureArticuloOpcionalGrupoTable', [], false],
            'CaracteristicaRegistry::registerDefaults()' => [self::REGISTRY, 'registerDefaults', [], false],
            'Init::init()' => [self::INIT, 'init', [], false],
            'Init::upgrade()' => [self::INIT, 'upgrade', [], false],
        ];
    }

    /**
     * @param list<mixed> $args
     */
    #[DataProvider('bootEntryPoints')]
    public function testBootSeamOpensARealDbWithoutTheContainer(
        string $class,
        string $method,
        array $args,
        bool $expectsUsableDb
    ): void {
        $site = "{$class}::{$method}()";
        $result = $this->runInCleanProcess($this->probeSnippet($class, $method, $args));

        $this->assertStringNotContainsString('Fatal error', $result['output'], $site . "\n" . $result['output']);

        $report = $this->decodeReport($result, $site);

        $this->assertFalse(
            $report['preloaded'],
            "{$site}: the probe must start with neither Container nor fs_db2 loaded.\n" . $result['output']
        );
        $this->assertFalse(
            $report['container_loaded'],
            "{$site} must not route through the container: Container was still unloaded when it started "
            . "and must stay that way.\n" . $result['output']
        );
        $this->assertTrue(
            $report['fs_db2_loaded'],
            "{$site} must load fs_db2 through the seam instead of reaching it through Container.\n"
            . $result['output']
        );

        if ($expectsUsableDb) {
            $this->assertTrue(
                $report['usable_db'],
                "{$site} must return a usable \\fs_db2; got {$report['returned']}.\n" . $result['output']
            );
        }

        if ($method === 'ensureArticuloOpcionalGrupoTable') {
            $this->assertTrue(
                $report['grupo_loaded'],
                "{$site} must still touch the catalogo_articulo_opcional_grupo model.\n" . $result['output']
            );
        }
    }

    /**
     * The probe requires only Composer's autoloader (never `Container` nor
     * `fs_db2`), verifies neither is preloaded, invokes the entry point, and
     * reports what happened as a single JSON line.
     *
     * `FS_DB_TYPE` is defined because a real `fs_db2` cannot be constructed
     * without it (every entry point defines it through `config.php`).
     * `FS_DB_HOST` is NOT defined on purpose: `date_style()` needs no
     * connection, and leaving it undefined guarantees no query ever runs.
     *
     * @param list<mixed> $args
     */
    private function probeSnippet(string $class, string $method, array $args): string
    {
        $folder = var_export(FS_FOLDER, true);
        $container = var_export(self::CONTAINER, true);
        $fsDb2 = var_export(self::FS_DB2, true);
        $grupo = var_export(self::GRUPO_MODEL, true);
        $classLiteral = var_export($class, true);
        $methodLiteral = var_export($method, true);
        $argsLiteral = var_export(array_values($args), true);
        $tmpName = self::TMP_DIR_NAME;

        return <<<PHP
<?php
define('FS_FOLDER', {$folder});
define('FS_TMP_NAME', '{$tmpName}');
define('FS_DB_TYPE', 'MYSQL');
chdir(FS_FOLDER);
require FS_FOLDER . '/vendor/autoload.php';

\$report = [
    'preloaded' => false,
    'container_loaded' => false,
    'fs_db2_loaded' => false,
    'usable_db' => null,
    'grupo_loaded' => false,
    'returned' => 'none',
];

if (class_exists({$container}, false) || class_exists({$fsDb2}, false)) {
    \$report['preloaded'] = true;
    \$report['container_loaded'] = class_exists({$container}, false);
    \$report['fs_db2_loaded'] = class_exists({$fsDb2}, false);
    echo json_encode(\$report), "\n";
    exit(0);
}

\$ref = new \ReflectionMethod({$classLiteral}, {$methodLiteral});
\$ref->setAccessible(true);
\$returned = null;
try {
    \$returned = \$ref->isStatic()
        ? \$ref->invokeArgs(null, {$argsLiteral})
        : \$ref->invokeArgs(\$ref->getDeclaringClass()->newInstanceWithoutConstructor(), {$argsLiteral});
} catch (\Throwable \$e) {
    \$returned = null;
}

if (\$returned instanceof \\fs_db2) {
    try {
        \$report['usable_db'] = \$returned->date_style() !== '';
    } catch (\Throwable \$e) {
        \$report['usable_db'] = false;
    }
}

\$report['container_loaded'] = class_exists({$container}, false);
\$report['fs_db2_loaded'] = class_exists({$fsDb2}, false);
\$report['grupo_loaded'] = class_exists({$grupo}, false);
\$report['returned'] = is_object(\$returned) ? get_class(\$returned) : gettype(\$returned);

echo json_encode(\$report), "\n";
exit(0);
PHP;
    }

    /**
     * @param array{code: int, stdout: string, stderr: string, output: string} $result
     * @return array<string, mixed>
     */
    private function decodeReport(array $result, string $site): array
    {
        $lines = array_values(array_filter(
            explode("\n", trim($result['stdout'])),
            static fn (string $line): bool => trim($line) !== ''
        ));
        $this->assertNotEmpty($lines, "{$site}: the probe produced no report.\n" . $result['output']);

        $report = json_decode((string) end($lines), true);
        $this->assertIsArray($report, "{$site}: the probe report was not valid JSON.\n" . $result['output']);

        foreach (['preloaded', 'container_loaded', 'fs_db2_loaded', 'usable_db', 'returned'] as $key) {
            $this->assertArrayHasKey($key, $report, "{$site}: the probe report is missing '{$key}'.\n" . $result['output']);
        }

        return $report;
    }

    /**
     * Runs a snippet in a brand-new PHP process. Skips (never fails) when the
     * PHP binary cannot be resolved, so an environment without a usable CLI
     * binary does not report a spurious regression.
     *
     * @return array{code: int, stdout: string, stderr: string, output: string}
     */
    private function runInCleanProcess(string $snippet): array
    {
        $php = $this->resolvePhpBinary();
        if ($php === null) {
            $this->markTestSkipped('No usable PHP CLI binary could be resolved for the subprocess probe.');
        }

        $script = tempnam(sys_get_temp_dir(), 'catalogo_db_seam_');
        if ($script === false) {
            $this->markTestSkipped('Could not create a temporary script for the subprocess probe.');
        }

        file_put_contents($script, $snippet);
        $this->tempScripts[] = $script;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open([$php, $script], $descriptors, $pipes);
        if (!is_resource($process)) {
            $this->markTestSkipped('proc_open() could not start the PHP subprocess probe.');
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [
            'code' => $code,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output' => $stdout . $stderr,
        ];
    }

    private function resolvePhpBinary(): ?string
    {
        $candidates = [PHP_BINARY, PHP_BINDIR . '/php', 'php'];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if ($candidate === 'php') {
                $resolved = trim((string) @shell_exec('command -v php 2>/dev/null'));

                return $resolved !== '' ? $resolved : null;
            }
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
