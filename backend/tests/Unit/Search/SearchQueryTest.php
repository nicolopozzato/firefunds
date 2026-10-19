<?php

namespace App\Tests\Unit\Search;

use App\Core\Search\SearchQuery;
use PHPUnit\Framework\TestCase;

class SearchQueryTest extends TestCase
{
    public function testWordsAreSeparateTerms(): void
    {
        $search = SearchQuery::parse('  kfc   panino ');

        $this->assertSame(['kfc', 'panino'], $search->include);
        $this->assertSame([], $search->exclude);
    }

    public function testQuotedPhraseStaysTogether(): void
    {
        $this->assertSame(['amazon telefono', '1/3'], SearchQuery::parse('"amazon telefono" 1/3')->include);
    }

    public function testDashExcludesAWordOrPhrase(): void
    {
        $search = SearchQuery::parse('amazon -libro -"menu bambini"');

        $this->assertSame(['amazon'], $search->include);
        $this->assertSame(['libro', 'menu bambini'], $search->exclude);
    }

    public function testLoneDashAndStrayQuotesAreIgnored(): void
    {
        $this->assertSame(['amazon', 'telefono'], SearchQuery::parse('amazon - "telefono')->include);
    }

    public function testNothingToSearchIsNull(): void
    {
        $this->assertNull(SearchQuery::parse(null));
        $this->assertNull(SearchQuery::parse('   '));
        $this->assertNull(SearchQuery::parse('- "" --'));
    }

    public function testLikeWildcardsAreSearchedAsTyped(): void
    {
        [$condition, $params] = SearchQuery::parse('50% a_b')->toSql('h', 'p');

        $this->assertSame('(h ILIKE unaccent(CAST(:p_i0 AS text)) AND h ILIKE unaccent(CAST(:p_i1 AS text)))', $condition);
        $this->assertSame(['p_i0' => '%50\%%', 'p_i1' => '%a\_b%'], $params);
    }
}
