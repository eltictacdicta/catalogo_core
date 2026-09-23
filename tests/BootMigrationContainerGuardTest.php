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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Entry-point independence regression for catalogo_core's boot migrations.
 *
 * Every boot migration was wrapped in a
 * `class_exists('\FSFramework\DependencyInjection\Container', false)` guard
 * that RETURNED instead of letting `Container::db()` autoload inside the
 * existing `try/catch`. Because `Kernel::boot()` never loads `Container`, that
 * branch was the default path: the migrations were skipped on every request,
 * permanently, with no self-heal.
 *
 * A same-process test can never prove this: once any earlier code loaded
 * `Container`, the guard is never exercised. This test therefore spawns fresh
 * PHP processes that never preload `Container`, invokes one guarded boot entry
 * point, and asserts the guarded work is REACHED instead of the early return.
 *
 * What it proves: the guard no longer short-circuits — `Container::db()` (or
 * the touched model) is reached. What it does NOT prove: that the DB-bound
 * migration body completes; that needs a live database. The assertion is about
 * reaching the work, never about its DB outcome. `FS_DB_TYPE` is deliberately
 * left undefined so no connection is ever attempted.
 */
final class BootMigrationContainerGuardTest extends TestCase
{
    private const CONTAINER = 'FSFramework\\DependencyInjection\\Container';
    private const GRUPO_MODEL = 'FSFramework\\model\\catalogo_articulo_opcional_grupo';
    private const INIT = 'FSFramework\\Plugins\\catalogo_core\\Init';
    private const REGISTRY = 'FSFramework\\Plugins\\catalogo_core\\Services\\CaracteristicaRegistry';
    private const TMP_DIR_NAME = 'catalogo_core_container_guard_probe/';

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
     * Each guarded boot entry point, the class that owns it, and the observable
     * that proves the early return no longer happens.
     *
     * For every site that reaches `Container::db()`, the observable is the
     * `Container` class itself (autoloaded by the call). `ensureArticuloOpcional
     * GrupoTable()` never references `Container`; its guard was vestigial, so
     * the observable is the touched model class that `touchNamespacedModel()`
     * must load.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function guardedEntryPoints(): array
    {
        return [
            'Init::migrateLegacyTables() (:540)' => [self::INIT, 'migrateLegacyTables', self::CONTAINER],
            'Init::plugin_db() (:558)' => [self::INIT, 'plugin_db', self::CONTAINER],
            'Init::migrateOpcionalExtension() (:581)' => [self::INIT, 'migrateOpcionalExtension', self::CONTAINER],
            'Init::ensureArticuloOpcionalGrupoTable() (:595)' => [self::INIT, 'ensureArticuloOpcionalGrupoTable', self::GRUPO_MODEL],
            'CaracteristicaRegistry::registerDefaults() (:194)' => [self::REGISTRY, 'registerDefaults', self::CONTAINER],
            'Init::init()' => [self::INIT, 'init', self::CONTAINER],
            'Init::upgrade()' => [self::INIT, 'upgrade', self::CONTAINER],
        ];
    }

    #[DataProvider('guardedEntryPoints')]
    public function testGuardedBootWorkIsReachedInsteadOfSkipped(
        string $class,
        string $method,
        string $observable
    ): void {
        $result = $this->runInCleanProcess($this->probeSnippet($class, $method, $observable));

        $this->assertSame(
            0,
            $result['code'],
            "{$class}::{$method}() must reach its guarded work instead of returning early.\n" . $result['output']
        );
        $this->assertStringNotContainsString('Fatal error', $result['output']);
        $this->assertSame('ok', trim($result['stdout']));
    }

    /**
     * The probe requires only Composer's autoloader (never `Container` itself),
     * verifies `Container` is still unloaded, invokes the entry point, and
     * asserts the observable class is now loaded.
     */
    private function probeSnippet(string $class, string $method, string $observable): string
    {
        $folder = var_export(FS_FOLDER, true);
        $container = var_export(self::CONTAINER, true);
        $classLiteral = var_export($class, true);
        $methodLiteral = var_export($method, true);
        $observableLiteral = var_export($observable, true);
        $tmpName = self::TMP_DIR_NAME;

        return <<<PHP
<?php
define('FS_FOLDER', {$folder});
define('FS_TMP_NAME', '{$tmpName}');
chdir(FS_FOLDER);
require FS_FOLDER . '/vendor/autoload.php';

if (class_exists({$container}, false)) {
    fwrite(STDERR, "PRELOADED\n");
    exit(3);
}

\$ref = new \ReflectionMethod({$classLiteral}, {$methodLiteral});
\$ref->setAccessible(true);
if (\$ref->isStatic()) {
    \$ref->invoke(null);
} else {
    \$ref->invoke(\$ref->getDeclaringClass()->newInstanceWithoutConstructor());
}

if (!class_exists({$observableLiteral}, false)) {
    fwrite(STDERR, "NOT_REACHED\n");
    exit(4);
}

echo "ok\n";
exit(0);
PHP;
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

        $script = tempnam(sys_get_temp_dir(), 'catalogo_guard_entry_');
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
