<?php

namespace App\Recurring\DTO;

use App\Entity\InvestmentInstrument;

/** The instrument a recurring purchase (PAC) buys, as it is shown. */
final readonly class InstrumentRefResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $ticker,
        /** The currency it is quoted in. */
        public string $currency,
    ) {
    }

    public static function fromNullable(?InvestmentInstrument $instrument): ?self
    {
        return $instrument === null ? null : new self((string) $instrument->getId(), $instrument->getName(), $instrument->getTicker(), $instrument->getCurrency());
    }
}
