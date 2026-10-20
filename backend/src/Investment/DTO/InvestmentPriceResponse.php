<?php

namespace App\Investment\DTO;

use App\Entity\InvestmentPrice;

final readonly class InvestmentPriceResponse
{
    public function __construct(
        public string $date,
        public string $price,
        /** market (the provider's close), filled (carried over from the day before) or manual (the user's). */
        public string $source,
        /** The provider's value, when there is one: what "restore" goes back to. */
        public ?string $originalPrice,
    ) {
    }

    public static function fromEntity(InvestmentPrice $price): self
    {
        return new self(
            date: $price->getDate()->format('Y-m-d'),
            price: $price->getPrice(),
            source: $price->getSource()->value,
            originalPrice: $price->getOriginalPrice(),
        );
    }
}
