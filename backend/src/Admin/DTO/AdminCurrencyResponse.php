<?php

namespace App\Admin\DTO;

use App\Entity\Currency;

final readonly class AdminCurrencyResponse
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $symbol,
        public int $decimalPlaces,
        public bool $isEnabled,
    ) {
    }

    public static function fromEntity(Currency $currency): self
    {
        return new self(
            id: (string) $currency->getId(),
            code: $currency->getCode(),
            name: $currency->getName(),
            symbol: $currency->getSymbol(),
            decimalPlaces: $currency->getDecimalPlaces(),
            isEnabled: $currency->isEnabled(),
        );
    }
}
