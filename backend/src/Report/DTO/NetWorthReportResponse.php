<?php

namespace App\Report\DTO;

/**
 * What every asset was worth at the end of each month of a year, and of the
 * year before to compare with: a net worth spreadsheet filled in by itself.
 * The change of a month is split into what was saved (income - expenses)
 * and what the market did on what is held (fees and taxes included), and
 * what the exchange rates did to what is held in other currencies; the
 * rest, if any, is money that arrived otherwise (e.g. an account's opening
 * balance). Every value is in the user's currency, each at its day's rate.
 */
final readonly class NetWorthReportResponse
{
    /**
     * @param NetWorthMonthResponse[] $months the year's twelve
     * @param NetWorthMonthResponse[] $previousMonths the year before's twelve
     * @param NetWorthRowResponse[] $cash each asset account's free cash, in the user's order; accounts empty all along the two years are left out
     * @param NetWorthRowResponse[] $investments each instrument held, every account together, largest first
     * @param NetWorthRowResponse[] $prices each instrument's closing price at the end of the month
     */
    public function __construct(
        public ?string $currencySymbol,
        public int $year,
        /** The year of the first transaction: the arrows go no further back. */
        public int $firstYear,
        /** This year: the arrows go no further on. */
        public int $currentYear,
        public array $months,
        public array $previousMonths,
        public array $cash,
        public array $investments,
        public NetWorthRowResponse $total,
        /** Income - expenses of each month (no previousOpening). */
        public NetWorthRowResponse $saved,
        /** What the market did on what is held each month: change in value - money put in (no previousOpening). */
        public NetWorthRowResponse $market,
        public array $prices,
        /** What the exchange rates did to what is held each month (no previousOpening); zero for someone with one currency. */
        public ?NetWorthRowResponse $exchange = null,
        /** @var NetWorthRowResponse[] what 1 unit of each other currency was worth in the user's at the end of each month */
        public array $rates = [],
        /** @var NetWorthRowResponse[] the net worth by currency of what is held (cash by its account's, instruments by the one they are quoted in), in the user's; empty with one currency */
        public array $currencies = [],
    ) {
    }
}
