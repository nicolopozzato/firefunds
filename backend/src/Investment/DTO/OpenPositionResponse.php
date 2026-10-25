<?php

namespace App\Investment\DTO;

final readonly class OpenPositionResponse
{
    /**
     * @param PositionHistoryPointResponse[] $history
     */
    public function __construct(
        public string $instrumentId,
        public string $instrumentName,
        public ?string $instrumentTicker,
        public string $quantity,
        public string $costBasis,
        public string $averageCost,
        public ?string $openedOn,
        /** The latest price in the account's currency (what the value is worked out from). */
        public ?string $currentPrice,
        /** The same price as quoted, in the instrument's currency. */
        public ?string $quotePrice,
        /** ISO 4217 code of the currency the instrument is quoted in. */
        public ?string $quoteCurrency,
        /** ISO 4217 code of the account's currency: the value, cost and gain are in it. */
        public ?string $currencyCode,
        public ?string $currentValue,
        public ?string $gainAmount,
        public ?string $gainPercent,
        /** Capital gains tax percentage applied (the instrument's, or the user's). */
        public string $taxRate,
        /** Tax owed on the gain if sold today: 0 at a loss; null without a price. */
        public ?string $estimatedTax,
        public array $history,
    ) {
    }

    /**
     * @param array{instrumentId: string, instrumentName: string, instrumentTicker: ?string, quantity: string, costBasis: string, averageCost: string, openedOn: ?string, currentPrice: ?string, currentValue: ?string, gainAmount: ?string, gainPercent: ?string} $row
     * @param array<array{date: string, value: string, costBasis: string}> $historyRows
     */
    public static function fromRow(array $row, array $historyRows): self
    {
        return new self(
            instrumentId: $row['instrumentId'],
            instrumentName: $row['instrumentName'],
            instrumentTicker: $row['instrumentTicker'],
            quantity: $row['quantity'],
            costBasis: $row['costBasis'],
            averageCost: $row['averageCost'],
            openedOn: $row['openedOn'],
            currentPrice: $row['currentPrice'],
            quotePrice: $row['quotePrice'] ?? null,
            quoteCurrency: $row['quoteCurrency'] ?? null,
            currencyCode: $row['accountCurrency'] ?? null,
            currentValue: $row['currentValue'],
            gainAmount: $row['gainAmount'],
            gainPercent: $row['gainPercent'],
            taxRate: $row['taxRate'],
            estimatedTax: $row['estimatedTax'],
            history: array_map(
                static fn (array $point) => new PositionHistoryPointResponse($point['date'], $point['value'], $point['costBasis']),
                $historyRows
            ),
        );
    }
}
