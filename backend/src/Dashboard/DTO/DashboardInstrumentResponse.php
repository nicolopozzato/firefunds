<?php

namespace App\Dashboard\DTO;

/** An instrument held at some point in the period, every account added up. */
final readonly class DashboardInstrumentResponse
{
    /**
     * @param DatedValueResponse[] $values
     * @param DatedValueResponse[] $performance
     */
    public function __construct(
        public string $instrumentId,
        public string $instrumentName,
        public ?string $instrumentTicker,
        public string $valueStart,
        public string $valueEnd,
        /** Money put in over the period: BUYs minus SELLs, as DashboardSummaryResponse::$investmentContributions. */
        public string $contributions,
        /** valueEnd - valueStart - contributions. */
        public string $gain,
        /** Market value of what is held, every account added up, on the same days as DashboardAccountResponse::$history. */
        public array $values,
        /** The price's change over the period, in percent; null without prices. */
        public ?string $priceChangePercent,
        /**
         * The price's change since the period started, day by day, in percent:
         * how the instrument went, whatever was bought or sold meanwhile. Starts
         * at the first day with a known price; every few days over a long
         * period, like DashboardAccountResponse::$history.
         */
        public array $performance,
    ) {
    }
}
