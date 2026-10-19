<?php

namespace App\Account\DTO;

use App\Core\Validation\Decimal;
use App\Core\Validation\DateValue;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateAccountRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Name is required')]
        #[Assert\Length(max: 255)]
        public string $name,

        #[Assert\Length(max: 34)]
        public ?string $iban = null,

        #[Assert\Length(max: 11)]
        public ?string $bic = null,

        #[Assert\Length(max: 64)]
        public ?string $accountNumber = null,

        #[Assert\Length(max: 10000)]
        public ?string $notes = null,

        public bool $isActive = true,

        #[Assert\Length(exactly: 3)]
        public ?string $currencyCode = null,

        #[Decimal]
        public ?string $openingBalance = null,

        #[DateValue]
        public ?string $openingBalanceDate = null,
    ) {
    }
}
