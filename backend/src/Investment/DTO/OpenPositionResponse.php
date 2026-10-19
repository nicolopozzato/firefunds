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
        public ?string $currentPrice,
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
