<?php

namespace App\Investment\DTO;

use App\Entity\InvestmentInstrumentType;
use App\Core\Validation\Decimal;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class InvestmentInstrumentRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Name is required')]
        #[Assert\Length(max: 255)]
        public string $name,

        #[Assert\NotBlank(message: 'Type is required')]
        #[Assert\Choice(choices: ['etf', 'stock', 'bond', 'fund', 'cryptocurrency', 'other'])]
        public string $type,

        #[Assert\Length(max: 20)]
        public ?string $ticker = null,

        #[Assert\Length(max: 20)]
        public ?string $yahooTicker = null,

        #[Assert\Length(max: 12)]
        public ?string $isin = null,

        /** Percentage, 0-100; null = the user's capital gains tax rate. */
        #[Assert\Range(min: 0, max: 100)]
        #[Decimal(precision: 7, scale: 4)]
        public ?string $capitalGainsTaxRate = null,

        /**
         * ISO 4217 code of the currency it is quoted in. Left out: kept on an
         * edit, and on a new instrument what Yahoo says, else the user's.
         */
        #[Assert\Length(exactly: 3)]
        public ?string $currencyCode = null,
    ) {
    }

    public function getInstrumentType(): InvestmentInstrumentType
    {
        return InvestmentInstrumentType::from($this->type);
    }
}
