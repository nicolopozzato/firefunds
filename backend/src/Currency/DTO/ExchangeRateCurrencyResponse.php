<?php

namespace App\Currency\DTO;

use App\Currency\Service\RateStatus;

/** A currency the user has rates for, with its latest one in their own currency. */
final readonly class ExchangeRateCurrencyResponse
{
    public function __construct(
        public string $currency,
        public ?string $date,
        public ?string $rate,
        public RateStatus $status = RateStatus::Ok,
        /** The newest day with a rate of its own (shared or the user's); later days carry it over. */
        public ?string $latestDate = null,
    ) {
    }
}
