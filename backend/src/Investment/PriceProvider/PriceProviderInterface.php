<?php

namespace App\Investment\PriceProvider;

use App\Entity\InvestmentInstrument;

interface PriceProviderInterface
{
    /**
     * Closing prices for every day in [from, to] the provider has data for,
     * keyed by 'Y-m-d'. Fetching a range in one call is far cheaper than one
     * call per missing day when backfilling after a gap.
     *
     * @return array<string, string>
     */
    public function getClosingPrices(InvestmentInstrument $instrument, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * The current/last-traded price (live during market hours, otherwise the
     * last close) together with whether the market is open right now. Unlike
     * a closing price, the price here is not meant to be persisted: it is
     * only for showing "today" before the day's close is recorded.
     */
    public function getLiveQuote(InvestmentInstrument $instrument): LiveQuote;
}
