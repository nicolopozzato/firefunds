<?php

namespace App\Currency\DTO;

final readonly class ExchangeRatesOverviewResponse
{
    /**
     * @param ExchangeRateCurrencyResponse[] $currencies
     */
    public function __construct(
        /** The user's own currency: every rate is in it. */
        public string $currency,
        public array $currencies,
    ) {
    }
}
