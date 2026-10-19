<?php

namespace App\Investment\PriceProvider;

final readonly class LiveQuote
{
    public function __construct(
        public ?string $price,
        public MarketState $marketState,
    ) {
    }
}
