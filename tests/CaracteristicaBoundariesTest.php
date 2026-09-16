<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Plugin-local boundary gate (CAR-19).
 *
 * `catalogo_core` MUST NOT gain a dependency on `tarifario`; the dependency
 * direction is consumer → catalogo_core. The scan extracts tarifario-owned
 * class names from its production tree (non-vendor, non-test), strips
 * comments and reports every reference from catalogo_core production.
 *
 * The frozen baseline below is the PRE-EXISTING reference set, not a licence
 * to add more: any new name fails the gate. It is larger than the three
 * `loadTarifarioModel()` string args named in the design because three guarded
 * pre-existing integrations (`tarif_precio_historial`, `tarif_historial_precios`
 * page string, `tarif_grupo_usuario`) already existed in the tree when this
 * change started. See design §14.3.
 */
final class CaracteristicaBoundariesTest extends TestCase
{
    /**
     * Pre-existing references only. MUST NOT GROW.
     *
     * @var list<string>
     */
    private const BASELINE = [
        'tarif_grupo_rol',
        'tarif_grupo_tarifa',
        'tarif_grupo_usuario',
        'tarif_historial_precios',
        'tarif_precio_historial',
        'tarif_tarifa_rol',
    ];

    public function test_catalogo_core_production_references_only_the_frozen_baseline(): void
    {
        $owned = $this->collectTarifarioOwnedClassNames();
        $this->assertContains('tarif_grupo_tarifa', $owned, 'the scan must find tarifario-owned classes');
        $this->assertContains('tarif_tarifa_rol', $owned);

        $selfDefined = $this->collectCatalogoCoreDefinedClassNames();
        $owned = array_values(array_diff($owned, $selfDefined));

        $found = $this->scanCatalogoCoreProduction($owned);
        sort($found);

        $baseline = self::BASELINE;
        sort($baseline);

        $this->assertSame(
            $baseline,
            $found,
            'a new catalogo_core → tarifario class reference was introduced'
        );
    }

    public function test_catalogo_core_does_not_reference_the_tarifario_namespace(): void
    {
        foreach ($this->catalogoCoreProductionFiles() as $file) {
            $source = $this->stripComments((string) file_get_contents($file));
            $this->assertStringNotContainsString(
                'FSFramework\\Plugins\\tarifario',
                $source,
                'catalogo_core must not import the tarifario plugin namespace: ' . $file
            );
        }
    }

    public function test_no_entry_exists_in_the_repository_root_openspec(): void
    {
        $this->assertDirectoryDoesNotExist(
            FS_FOLDER . '/openspec/changes/caracteristicas-producto',
            'the change must never create an entry in the core openspec tree'
        );
        $this->assertDirectoryExists(
            FS_FOLDER . '/plugins/catalogo_core/openspec/changes/caracteristicas-producto',
            'the change must live in the plugin-local SDD root'
        );
    }

    public function test_dependency_direction_is_unchanged(): void
    {
        $catalogoIni = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/fsframework.ini');
        $this->assertMatchesRegularExpression('/^\s*require\s*=\s*""\s*$/m', $catalogoIni, 'catalogo_core requires no plugin');

        $tarifarioIni = (string) file_get_contents(FS_FOLDER . '/plugins/tarifario/fsframework.ini');
        $this->assertMatchesRegularExpression(
            '/^\s*require\s*=\s*"catalogo_core"\s*$/m',
            $tarifarioIni,
            'tarifario keeps depending on catalogo_core'
        );
    }

    /**
     * Frozen Composer `require` baseline, as `<plugin>::<package>` entries.
     * MUST NOT GROW.
     *
     * Neither plugin declares a `composer.json` today, so the baseline is empty
     * and the gate rejects **every** requirement a plugin adds — not only
     * packages whose name contains `tarifario`. Updating this list is a
     * deliberate dependency decision.
     *
     * @var list<string>
     */
    private const COMPOSER_REQUIRE_BASELINE = [];

    public function test_no_new_composer_requirement_is_introduced(): void
    {
        $actual = [];
        foreach (['plugins/catalogo_core', 'plugins/tarifario'] as $plugin) {
            foreach ($this->composerRequireKeys($plugin) as $package) {
                $actual[] = $plugin . '::' . $package;
            }
        }
        sort($actual);

        $baseline = self::COMPOSER_REQUIRE_BASELINE;
        sort($baseline);

        $this->assertSame(
            $baseline,
            $actual,
            'a plugin Composer requirement was added or removed; the frozen baseline must be updated deliberately'
        );
    }

    public function test_the_composer_requirement_extractor_reads_every_key(): void
    {
        // Non-vacuity guard: an empty baseline is only meaningful if the
        // extractor actually surfaces every `require` key.
        $this->assertSame(
            ['catalogo_core/extra', 'php', 'tarifario/extra'],
            $this->composerRequireKeysFromArray([
                'name' => 'demo/demo',
                'require' => ['php' => '>=8.2', 'tarifario/extra' => '^2.0', 'catalogo_core/extra' => '^1.0'],
                'require-dev' => ['phpunit/phpunit' => '^11'],
            ]),
            'every require key must be extracted (require-dev stays out of the baseline)'
        );
    }

    // =====================================================================
    // Scan helpers
    // =====================================================================

    /**
     * @return list<string>
     */
    private function composerRequireKeys(string $plugin): array
    {
        $composerPath = FS_FOLDER . '/' . $plugin . '/composer.json';
        if (!is_file($composerPath)) {
            return [];
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);
        if (!is_array($composer)) {
            return [];
        }

        return $this->composerRequireKeysFromArray($composer);
    }

    /**
     * @param array<string, mixed> $composer
     * @return list<string>
     */
    private function composerRequireKeysFromArray(array $composer): array
    {
        $keys = [];
        foreach (array_keys((array) ($composer['require'] ?? [])) as $package) {
            $keys[] = strtolower((string) $package);
        }
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function collectTarifarioOwnedClassNames(): array
    {
        $names = [];
        foreach ($this->phpFiles(FS_FOLDER . '/plugins/tarifario') as $file) {
            $source = $this->stripComments((string) file_get_contents($file));
            if (preg_match_all(
                '/\b(?:final |abstract )?(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/',
                $source,
                $matches
            )) {
                foreach ($matches[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * @return list<string>
     */
    private function collectCatalogoCoreDefinedClassNames(): array
    {
        $names = [];
        foreach ($this->catalogoCoreProductionFiles() as $file) {
            $source = $this->stripComments((string) file_get_contents($file));
            if (preg_match_all(
                '/\b(?:final |abstract )?(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/',
                $source,
                $matches
            )) {
                foreach ($matches[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * @param list<string> $owned
     * @return list<string>
     */
    private function scanCatalogoCoreProduction(array $owned): array
    {
        $found = [];
        foreach ($this->catalogoCoreProductionFiles() as $file) {
            $source = $this->stripComments((string) file_get_contents($file));
            foreach ($owned as $name) {
                if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $source)) {
                    $found[$name] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * @return list<string>
     */
    private function catalogoCoreProductionFiles(): array
    {
        return $this->phpFiles(FS_FOLDER . '/plugins/catalogo_core');
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (str_contains($path, '/vendor/')
                || str_contains($path, '/tests/')
                || str_contains($path, '/openspec/')) {
                continue;
            }

            $files[] = $path;
        }

        return $files;
    }

    private function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}
