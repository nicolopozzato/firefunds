<?php

namespace App\Investment\PriceProvider;

final readonly class LiveQuote
{
    public function __construct(
        public ?string $price,
        public MarketState $marketState,
        /** When the provider was asked: a quote may be served from a cache for a while (LiveQuoteService). */
        public \DateTimeImmutable $fetchedAt = new \DateTimeImmutable(),
    ) {
    }
}
