<?php

namespace App\Investment\DTO;

use App\Entity\InvestmentTransactionType;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateInvestmentTransactionRequest
{
    public function __construct(
        public ?string $description,

        #[Assert\NotBlank(message: 'Account is required')]
        public string $accountId,

        #[Assert\NotBlank(message: 'Instrument is required')]
        public string $instrumentId,

        #[Assert\NotBlank(message: 'Type is required')]
        #[Assert\Choice(choices: ['buy', 'sell'])]
        public string $type,

        #[Assert\NotBlank(message: 'Quantity is required')]
        #[Assert\Positive(message: 'Quantity must be greater than zero')]
        public string $quantity,

        #[Assert\NotBlank(message: 'Unit price is required')]
        #[Assert\Positive(message: 'Unit price must be greater than zero')]
        public string $unitPrice,

        #[Assert\PositiveOrZero]
        public string $fees = '0',

        #[Assert\PositiveOrZero]
        public string $taxes = '0',

        #[Assert\NotBlank(message: 'Date is required')]
        public string $occurredAt = '',

        public ?string $notes = null,

        /**
         * What the broker actually charged/credited for the units, fees and
         * taxes excluded. Optional: defaults to quantity x unit price. Must be
         * a plausible rounding of it (InvestmentTransaction::isPlausibleAmount()).
         */
        #[Assert\Positive(message: 'Amount must be greater than zero')]
        public ?string $amount = null,
    ) {
    }

    public function getInvestmentTransactionType(): InvestmentTransactionType
    {
        return InvestmentTransactionType::from($this->type);
    }
}
