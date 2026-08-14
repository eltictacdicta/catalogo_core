<?php
declare(strict_types=1);

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\ArticuloSearchQueryBuilder;
use PHPUnit\Framework\TestCase;

final class ArticuloSearchQueryBuilderTest extends TestCase
{
    /** @param mixed $value */
    private function quote($value): string
    {
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    public function testEmptyQueryProducesNoCondition(): void
    {
        $quote = fn (string $value): string => $this->quote($value);
        $this->assertSame('', ArticuloSearchQueryBuilder::buildTextSearchCondition('', $quote));
    }

    public function testNumericQueryMatchesBarcodeExactly(): void
    {
        $quote = fn (string $value): string => $this->quote($value);
        $sql = ArticuloSearchQueryBuilder::buildTextSearchCondition('1234567890123', $quote);

        $this->assertStringContainsString("codbarras = '1234567890123'", $sql);
        $this->assertStringContainsString("referencia = '1234567890123'", $sql);
    }

    public function testSingleWordQueryMatchesDescription(): void
    {
        $quote = fn (string $value): string => $this->quote($value);
        $sql = ArticuloSearchQueryBuilder::buildTextSearchCondition('tornillo', $quote);

        $this->assertStringContainsString("lower(descripcion) LIKE '%tornillo%'", $sql);
        $this->assertStringContainsString("lower(referencia) LIKE '%tornillo%'", $sql);
    }

    public function testMultiWordQueryUsesFuzzyReferenceMatch(): void
    {
        $quote = fn (string $value): string => $this->quote($value);
        $sql = ArticuloSearchQueryBuilder::buildTextSearchCondition('foo bar', $quote);

        $this->assertStringContainsString("lower(referencia) LIKE '%foo%bar%'", $sql);
        $this->assertStringContainsString("lower(descripcion) LIKE '%foo%'", $sql);
        $this->assertStringContainsString("lower(descripcion) LIKE '%bar%'", $sql);
        $this->assertStringContainsString(' AND ', $sql);
    }

    public function testEscapeForLikeEscapesWildcards(): void
    {
        $this->assertSame('100|%', ArticuloSearchQueryBuilder::escapeForLike('100%'));
        $this->assertSame('a|_b', ArticuloSearchQueryBuilder::escapeForLike('a_b'));
    }

    public function testAppendTextSearchConditionsAddsSeparator(): void
    {
        $quote = fn (string $value): string => $this->quote($value);
        $sql = 'SELECT * FROM articulos';
        ArticuloSearchQueryBuilder::appendTextSearchConditions($sql, ' AND', 'demo', $quote);

        $this->assertStringStartsWith('SELECT * FROM articulos AND (', $sql);
    }
}
