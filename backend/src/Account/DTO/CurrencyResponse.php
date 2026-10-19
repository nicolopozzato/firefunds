<?php

namespace App\Account\DTO;

use App\Entity\Currency;

final readonly class CurrencyResponse
{
    public function __construct(
        public string $code,
        public string $name,
        public string $symbol,
        public int $decimalPlaces,
    ) {
    }

    public static function fromEntity(Currency $currency): self
    {
        return new self(
            code: $currency->getCode(),
            name: $currency->getName(),
            symbol: $currency->getSymbol(),
            decimalPlaces: $currency->getDecimalPlaces(),
        );
    }
}
