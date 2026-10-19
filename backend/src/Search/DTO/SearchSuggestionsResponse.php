<?php

namespace App\Search\DTO;

/**
 * The shortcuts under the search box: accounts and instruments whose name
 * matches what is typed, each opening its own page.
 */
final readonly class SearchSuggestionsResponse
{
    public function __construct(
        /** @var array{id: string, name: string, type: string}[] */
        public array $accounts,
        /** @var array{id: string, name: string, ticker: ?string, type: string}[] */
        public array $instruments,
    ) {
    }
}
