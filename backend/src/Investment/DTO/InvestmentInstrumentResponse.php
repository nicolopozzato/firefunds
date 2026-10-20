<?php

namespace App\Investment\DTO;

use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentPrice;

final readonly class InvestmentInstrumentResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public ?string $ticker,
        public ?string $yahooTicker,
        public ?string $isin,
        /** The instrument's own capital gains tax percentage; null = the user's. */
        public ?string $capitalGainsTaxRate,
        public string $createdAt,
        public string $updatedAt,
        /** The detail only: the latest price recorded (imported or the user's), whatever the period shown. */
        public ?InvestmentPriceResponse $latestPrice = null,
    ) {
    }

    public static function fromEntity(InvestmentInstrument $instrument, ?InvestmentPrice $latestPrice = null): self
    {
        return new self(
            id: (string) $instrument->getId(),
            name: $instrument->getName(),
            type: $instrument->getType()->value,
            ticker: $instrument->getTicker(),
            yahooTicker: $instrument->getYahooTicker(),
            isin: $instrument->getIsin(),
            capitalGainsTaxRate: $instrument->getCapitalGainsTaxRate(),
            createdAt: $instrument->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $instrument->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            latestPrice: $latestPrice !== null ? InvestmentPriceResponse::fromEntity($latestPrice) : null,
        );
    }
}
