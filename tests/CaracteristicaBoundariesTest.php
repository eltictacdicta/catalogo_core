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
        // The change may be active or archived, but it MUST live in the
        // plugin-local SDD root. Pinning the active path would turn this
        // boundary gate red the moment the change is archived, and the archive
        // move is a legitimate lifecycle step, not a boundary violation.
        $pluginChangesRoot = FS_FOLDER . '/plugins/catalogo_core/openspec/changes';
        $archivedCopies = glob($pluginChangesRoot . '/archive/*-caracteristicas-producto') ?: [];

        $this->assertTrue(
            is_dir($pluginChangesRoot . '/caracteristicas-producto') || $archivedCopies !== [],
            'the change must live in the plugin-local SDD root (active or archived)'
        );
    }

    public function test_no_root_openspec_entry_exists_for_this_change(): void
    {
        $this->assertDirectoryDoesNotExist(
            FS_FOLDER . '/openspec/changes/caracteristicas-plugin-scope',
            'this change must never create an entry in the repository-root openspec tree'
        );
    }

    public function test_ownership_helper_reads_only_origen_and_the_core_registry(): void
    {
        $path = FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaOwnership.php';
        $this->assertFileExists($path);
        $source = $this->stripComments((string) file_get_contents($path));

        $this->assertStringContainsString('origen', $source, 'the rule must read the row origen');
        $this->assertStringContainsString('FSFramework\\Core\\Plugins', $source, 'the rule must use the core registry');
        $this->assertStringContainsString('Plugins::enabled()', $source);

        $this->assertStringNotContainsString(
            'FSFramework\\Plugins\\tarifario',
            $source,
            'the ownership helper must not import the tarifario namespace'
        );
        $this->assertStringNotContainsString("'tarifario'", $source, 'no hardcoded plugin-name literal');
        $this->assertStringNotContainsString('"tarifario"', $source, 'no hardcoded plugin-name literal');
    }

    public function test_the_three_wiring_sites_call_the_shared_ownership_rule(): void
    {
        $sites = [
            'Services/CaracteristicaResolver.php',
            'Controller/VentasCaracteristicas.php',
            'Services/CaracteristicaValorBatchReader.php',
        ];

        foreach ($sites as $relative) {
            $path = FS_FOLDER . '/plugins/catalogo_core/' . $relative;
            $this->assertFileExists($path, $relative . ' must exist');
            $source = $this->stripComments((string) file_get_contents($path));

            $this->assertStringContainsString(
                'CaracteristicaOwnership',
                $source,
                $relative . ' must import the shared ownership helper'
            );
            $this->assertMatchesRegularExpression(
                '/ownership\(\)\s*->\s*is_active\(/',
                $source,
                $relative . ' must call the shared ownership rule'
            );
        }
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
    // VCG-08 — visibility gate ownership safety
    // =====================================================================

    /**
     * The accessor and the four render seams answer from the canonical codigos
     * only: no `'tarifario'` literal, path or namespace may be introduced.
     */
    public function test_visibility_gate_has_no_plugin_name_literal(): void
    {
        $methods = [
            'Services/CaracteristicaResolver.php' => ['active_visibility_codigos', 'is_visibility_active'],
            'extras/VentasArticulosListTrait.php' => ['caracteristica_resolver', 'visibilidad_activa'],
            'extras/VentasOpcionalesListTrait.php' => ['visibilidad_activa'],
            'controller/tarif_tab_precios.php' => ['visibilidad_activa'],
            'controller/tarif_opcional_edit.php' => ['visibilidad_activa'],
        ];

        foreach ($methods as $relative => $names) {
            $source = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/' . $relative);
            foreach ($names as $name) {
                $body = $this->stripComments($this->methodSource($source, $name));
                $this->assertNotSame('', $body, $relative . '::' . $name . ' must exist');

                foreach (["'tarifario'", '"tarifario"', 'plugins/tarifario/', '@tarifario/'] as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        $body,
                        $relative . '::' . $name . ' must not hardcode a plugin name/path'
                    );
                }
            }
        }
    }

    public function test_the_four_render_servers_expose_visibilidad_activa(): void
    {
        $servers = [
            'extras/VentasArticulosListTrait.php',
            'extras/VentasOpcionalesListTrait.php',
            'controller/tarif_tab_precios.php',
            'controller/tarif_opcional_edit.php',
        ];

        foreach ($servers as $relative) {
            $source = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/' . $relative);
            $this->assertMatchesRegularExpression(
                '/function\s+visibilidad_activa\s*\(\s*\)\s*:\s*array/',
                $source,
                $relative . ' must expose the no-arg visibilidad_activa(): array seam'
            );
        }
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

    /**
     * Brace-balanced extraction of a named method (signature + body) from a
     * class/trait source. Empty string when the method is absent.
     */
    private function methodSource(string $source, string $method): string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $method) {
                continue;
            }

            $out = '';
            $depth = 0;
            $started = false;
            for ($k = $i; $k < $count; $k++) {
                $token = $tokens[$k];
                $text = is_array($token) ? $token[1] : $token;
                $out .= $text;

                if ($text === '{') {
                    $depth++;
                    $started = true;
                } elseif ($text === '}') {
                    $depth--;
                    if ($started && $depth === 0) {
                        return $out;
                    }
                } elseif ($text === ';' && !$started) {
                    return $out;
                }
            }
        }

        return '';
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
