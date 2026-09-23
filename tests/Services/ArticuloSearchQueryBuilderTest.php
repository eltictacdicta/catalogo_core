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

        $this->assertStringContainsString("a.codbarras = '1234567890123'", $sql);
        $this->assertStringContainsString("a.referencia = '1234567890123'", $sql);
    }

    public function testSingleWordQueryMatchesDescription(): void
    {
        $quote = fn (string $value): string => $this->quote($value);
        $sql = ArticuloSearchQueryBuilder::buildTextSearchCondition('tornillo', $quote);

        $this->assertStringContainsString("lower(a.descripcion) LIKE '%tornillo%'", $sql);
        $this->assertStringContainsString("lower(d.descripcion) LIKE '%tornillo%'", $sql);
        $this->assertStringContainsString("lower(a.referencia) LIKE '%tornillo%'", $sql);
    }

    public function testMultiWordQueryUsesFuzzyReferenceMatch(): void
    {
        $quote = fn (string $value): string => $this->quote($value);
        $sql = ArticuloSearchQueryBuilder::buildTextSearchCondition('foo bar', $quote);

        $this->assertStringContainsString("lower(a.referencia) LIKE '%foo%bar%'", $sql);
        $this->assertStringContainsString("lower(a.descripcion) LIKE '%foo%'", $sql);
        $this->assertStringContainsString("lower(a.descripcion) LIKE '%bar%'", $sql);
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

    public function testTheLanguageDescriptionPredicateMatchesBothTheBaseAndTheTranslations(): void
    {
        $quote = fn (string $value): string => $this->quote($value);
        $predicate = ArticuloSearchQueryBuilder::languageDescriptionPredicate("'%tornillo%'", $quote);

        $this->assertSame(
            "(lower(a.descripcion) LIKE '%tornillo%' ESCAPE '|'"
            . " OR lower(d.descripcion) LIKE '%tornillo%' ESCAPE '|')",
            $predicate
        );
    }

    public function testTheLanguageDescriptionJoinTargetsTheTranslationsTable(): void
    {
        $this->assertSame(
            ' LEFT JOIN articulo_descripciones d ON d.referencia = a.referencia',
            ArticuloSearchQueryBuilder::languageDescriptionJoin()
        );
    }

    public function testEveryBaseColumnReferenceIsQualifiedWithTheArticleAlias(): void
    {
        $quote = fn (string $value): string => $this->quote($value);

        foreach (['1234567890123', 'tornillo', 'foo bar'] as $query) {
            $sql = ArticuloSearchQueryBuilder::buildTextSearchCondition($query, $quote);

            // An unqualified base reference after the LEFT JOIN is ambiguous.
            $this->assertDoesNotMatchRegularExpression('/(?<![a-z.])referencia\b/', $sql);
            $this->assertDoesNotMatchRegularExpression('/(?<![a-z.])partnumber\b/', $sql);
            $this->assertDoesNotMatchRegularExpression('/(?<![a-z.])equivalencia\b/', $sql);
            $this->assertDoesNotMatchRegularExpression('/(?<![a-z.])codbarras\b/', $sql);
            $this->assertDoesNotMatchRegularExpression('/(?<![a-z.])descripcion\b/', $sql);
        }
    }
}
