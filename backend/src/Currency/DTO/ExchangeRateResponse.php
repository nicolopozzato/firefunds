<?php

namespace App\Currency\DTO;

/** One day's rate of a currency, in the user's own currency. */
final readonly class ExchangeRateResponse
{
    public function __construct(
        public string $date,
        /** What 1 unit of the currency is worth in the user's currency. */
        public string $rate,
        /** ecb, yahoo, filled (carried over from the day before) or manual (the user's). */
        public string $source,
        /** The rate before the user's correction; only for a manual one. */
        public ?string $originalRate,
    ) {
    }
}
