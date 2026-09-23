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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\CatalogoCore\Support\FakeCatalogoIdioma;
use Tests\CatalogoCore\Support\IdiomaRegistryFake;

/**
 * Spy DB for the shared opcional controller trait. Records the explicit
 * transaction lifecycle and lets a test force begin/commit failures.
 */
final class TarifarioStateTraitSpyDb
{
    /** @var list<string> */
    public array $calls = [];

    /** @var bool */
    public bool $beginResult = true;

    /** @var bool */
    public bool $commitResult = true;

    /** When true, begin_transaction() throws to exercise the restore path. */
    public bool $throwOnBegin = false;

    /** @var bool */
    private bool $autoTransactions = true;

    public function begin_transaction()
    {
        $this->calls[] = 'begin';

        if ($this->throwOnBegin) {
            throw new \RuntimeException('begin failed');
        }

        return $this->beginResult;
    }

    public function commit()
    {
        $this->calls[] = 'commit';

        return $this->commitResult;
    }

    public function rollback()
    {
        $this->calls[] = 'rollback';

        return true;
    }

    public function get_auto_transactions()
    {
        return $this->autoTransactions;
    }

    public function set_auto_transactions($value)
    {
        $this->autoTransactions = (bool) $value;
        $this->calls[] = 'auto:' . ($value ? '1' : '0');
    }
}

/**
 * Behaviour contract for the shared transaction/price helpers used by the
 * opcional controllers.
 *
 * `run_in_transaction()` must disable the engine's per-statement
 * auto-transactions, commit only when the work returns true, roll back on
 * failure or exception, and always restore the previous setting.
 * `parse_price_input()` must accept a complete decimal and reject
 * partially-numeric or locale-ambiguous values instead of silently coercing
 * them with floatval().
 */
final class TarifarioOpcionalStateTraitTest extends TestCase
{
    private static bool $traitLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$traitLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php';
        require_once __DIR__ . '/Support/FakeCatalogoIdioma.php';
        require_once __DIR__ . '/Support/IdiomaRegistryFake.php';
        self::$traitLoaded = true;
    }

    protected function setUp(): void
    {
        parent::setUp();
        unset($_REQUEST['codidioma']);
    }

    protected function tearDown(): void
    {
        unset($_REQUEST['codidioma']);
        parent::tearDown();
    }

    private function buildSubject(TarifarioStateTraitSpyDb $db): object
    {
        return new class($db) {
            use \TarifarioOpcionalStateTrait;

            /** @var mixed */
            protected $db;

            public function __construct($db)
            {
                $this->db = $db;
            }

            public function run(callable $work): bool
            {
                return $this->run_in_transaction($work);
            }

            public function parse($raw)
            {
                return $this->parse_price_input($raw);
            }

            public function resolve(\FSFramework\model\catalogo_idioma $idioma): string
            {
                return $this->resolve_codidioma($idioma);
            }
        };
    }

    // =====================================================================
    // run_in_transaction
    // =====================================================================

    public function test_run_in_transaction_commits_when_work_succeeds(): void
    {
        $db = new TarifarioStateTraitSpyDb();
        $subject = $this->buildSubject($db);

        $autoDuringWork = null;
        $ok = $subject->run(function () use ($db, &$autoDuringWork) {
            $autoDuringWork = $db->get_auto_transactions();

            return true;
        });

        self::assertTrue($ok);
        self::assertFalse(
            $autoDuringWork,
            'per-statement auto-transactions must be disabled inside the outer transaction'
        );
        self::assertSame(['auto:0', 'begin', 'commit', 'auto:1'], $db->calls);
    }

    public function test_run_in_transaction_rolls_back_when_work_fails(): void
    {
        $db = new TarifarioStateTraitSpyDb();
        $subject = $this->buildSubject($db);

        $ok = $subject->run(static fn(): bool => false);

        self::assertFalse($ok);
        self::assertSame(['auto:0', 'begin', 'rollback', 'auto:1'], $db->calls);
    }

    public function test_run_in_transaction_rolls_back_on_exception(): void
    {
        $db = new TarifarioStateTraitSpyDb();
        $subject = $this->buildSubject($db);

        $ok = $subject->run(static function (): bool {
            throw new \RuntimeException('boom');
        });

        self::assertFalse($ok);
        self::assertSame(['auto:0', 'begin', 'rollback', 'auto:1'], $db->calls);
    }

    public function test_run_in_transaction_aborts_when_begin_fails(): void
    {
        $db = new TarifarioStateTraitSpyDb();
        $db->beginResult = false;
        $subject = $this->buildSubject($db);

        $ok = $subject->run(static fn(): bool => true);

        self::assertFalse($ok);
        self::assertSame(['auto:0', 'begin', 'auto:1'], $db->calls);
    }

    public function test_run_in_transaction_restores_auto_transactions_when_begin_throws(): void
    {
        $db = new TarifarioStateTraitSpyDb();
        $db->throwOnBegin = true;
        $subject = $this->buildSubject($db);

        $ok = $subject->run(static fn(): bool => true);

        self::assertFalse($ok);
        self::assertSame(['auto:0', 'begin', 'auto:1'], $db->calls);
        self::assertTrue(
            $db->get_auto_transactions(),
            'the previous auto-transaction setting must be restored when begin_transaction() throws'
        );
    }

    public function test_run_in_transaction_rolls_back_when_commit_fails(): void
    {
        $db = new TarifarioStateTraitSpyDb();
        $db->commitResult = false;
        $subject = $this->buildSubject($db);

        $ok = $subject->run(static fn(): bool => true);

        self::assertFalse($ok);
        self::assertSame(['auto:0', 'begin', 'commit', 'rollback', 'auto:1'], $db->calls);
    }

    // =====================================================================
    // parse_price_input
    // =====================================================================

    /**
     * @param mixed $raw
     */
    #[DataProvider('validPriceProvider')]
    public function test_parse_price_input_accepts_a_complete_decimal($raw, float $expected): void
    {
        $db = new TarifarioStateTraitSpyDb();

        self::assertSame($expected, $this->buildSubject($db)->parse($raw));
    }

    /**
     * @return array<string, array{0: mixed, 1: float}>
     */
    public static function validPriceProvider(): array
    {
        return [
            'empty means zero' => ['', 0.0],
            'integer' => ['7', 7.0],
            'dot decimal' => ['12.5', 12.5],
            'comma decimal' => ['12,5', 12.5],
            'negative' => ['-2.25', -2.25],
            'leading comma' => [',5', 0.5],
        ];
    }

    /**
     * Locale convention: a single `.` or `,` separator is ALWAYS the decimal
     * separator, never a thousands separator. `1.234` therefore means 1.234
     * (not 1234), and `1,234` means 1.234 too. Plain thousands grouping is not
     * supported; this keeps the parse unambiguous for the storefront inputs.
     */
    public function test_parse_price_input_treats_a_single_separator_as_a_decimal(): void
    {
        $subject = $this->buildSubject(new TarifarioStateTraitSpyDb());

        self::assertSame(1.234, $subject->parse('1.234'), 'a single dot is a decimal separator');
        self::assertSame(1.234, $subject->parse('1,234'), 'a single comma is a decimal separator');
        self::assertSame(1234.0, $subject->parse('1234'), 'a separator-free value stays integral');
    }

    /**
     * @param mixed $raw
     */
    #[DataProvider('invalidPriceProvider')]
    public function test_parse_price_input_rejects_partial_or_ambiguous_values($raw): void
    {
        $db = new TarifarioStateTraitSpyDb();

        self::assertNull(
            $this->buildSubject($db)->parse($raw),
            'invalid price input must be rejected, never partially parsed'
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidPriceProvider(): array
    {
        return [
            'trailing text' => ['12abc'],
            'non numeric' => ['abc'],
            'two dots' => ['1.2.3'],
            'mixed separators' => ['1.234,56'],
            'mixed separators reversed' => ['1,234.56'],
            'repeated comma' => ['1,2,3'],
            'inner spaces' => ['1 000'],
        ];
    }

    // =====================================================================
    // resolve_codidioma — GDI-10 (task 4c.5)
    // =====================================================================

    /**
     * Active-language registry double. The configured default is deliberately
     * `en` (never `es`) so a hard-coded `es` seed fails the assertion.
     *
     * @param list<array{codidioma: string, nombre?: string, activo?: bool, por_defecto?: bool}> $idiomas
     */
    private function idiomaModel(array $idiomas): FakeCatalogoIdioma
    {
        return (new FakeCatalogoIdioma())->useFakeDb(new IdiomaRegistryFake($idiomas));
    }

    /**
     * The trait derives its active language from the same total resolver every
     * other consumer uses (`catalogo_idioma::get_effective_default_code()`), not
     * from a hard-coded `'es'`.
     */
    public function test_codidioma_resolution_follows_the_configured_default(): void
    {
        $idioma = $this->idiomaModel([
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => false],
        ]);

        self::assertSame(
            'en',
            $this->buildSubject(new TarifarioStateTraitSpyDb())->resolve($idioma),
            'the trait must resolve the configured default, never a hard-coded es'
        );
    }

    /**
     * With no `por_defecto` flag the old code stayed on its `'es'` seed;
     * `get_effective_default_code()` is total and returns the lowest active code.
     */
    public function test_codidioma_resolution_is_total_without_a_configured_default(): void
    {
        $idioma = $this->idiomaModel([
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => false],
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => false],
        ]);

        self::assertSame(
            'en',
            $this->buildSubject(new TarifarioStateTraitSpyDb())->resolve($idioma),
            'the resolution must stay total, not fall back to a hard-coded es'
        );
    }

    /**
     * An explicit request value keeps its existing precedence over the resolver.
     */
    public function test_explicit_request_language_wins_over_the_resolver(): void
    {
        $_REQUEST['codidioma'] = 'es';
        $idioma = $this->idiomaModel([
            ['codidioma' => 'en', 'nombre' => 'English', 'activo' => true, 'por_defecto' => true],
            ['codidioma' => 'es', 'nombre' => 'Español', 'activo' => true, 'por_defecto' => false],
        ]);

        self::assertSame(
            'es',
            $this->buildSubject(new TarifarioStateTraitSpyDb())->resolve($idioma),
            'an explicit request language must keep winning over the configured default'
        );
    }
}
