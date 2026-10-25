<?php

namespace App\Investment\DTO;

use App\Entity\InvestmentTransactionType;
use App\Core\Validation\Decimal;
use App\Core\Validation\DateValue;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateInvestmentTransactionRequest
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $description,

        #[Assert\NotBlank(message: 'Account is required')]
        #[Assert\Uuid]
        public string $accountId,

        #[Assert\NotBlank(message: 'Instrument is required')]
        #[Assert\Uuid]
        public string $instrumentId,

        #[Assert\NotBlank(message: 'Type is required')]
        #[Assert\Choice(choices: ['buy', 'sell'])]
        public string $type,

        #[Assert\NotBlank(message: 'Quantity is required')]
        #[Assert\Positive(message: 'Quantity must be greater than zero')]
        #[Decimal(precision: 20, scale: 6)]
        public string $quantity,

        #[Assert\NotBlank(message: 'Unit price is required')]
        #[Assert\Positive(message: 'Unit price must be greater than zero')]
        #[Decimal]
        public string $unitPrice,

        #[Assert\PositiveOrZero]
        #[Decimal]
        public string $fees = '0',

        #[Assert\PositiveOrZero]
        #[Decimal]
        public string $taxes = '0',

        #[Assert\NotBlank(message: 'Date is required')]
        #[DateValue(withTime: true)]
        public string $occurredAt = '',

        /**
         * Where it happened (IANA, e.g. "Europe/Rome"): the zone occurredAt is
         * written in. Null = unchanged on an edit, the user's own on a new one.
         */
        #[Assert\Timezone]
        public ?string $timezone = null,

        #[Assert\Length(max: 10000)]
        public ?string $notes = null,

        /**
         * What the broker actually charged/credited for the units, fees and
         * taxes excluded. Optional: defaults to quantity x unit price. Never
         * checked against it: fees, rounding and (soon) another currency make
         * the broker's figure its own.
         */
        #[Assert\Positive(message: 'Amount must be greater than zero')]
        #[Decimal]
        public ?string $amount = null,
    ) {
    }

    public function getInvestmentTransactionType(): InvestmentTransactionType
    {
        return InvestmentTransactionType::from($this->type);
    }
}
