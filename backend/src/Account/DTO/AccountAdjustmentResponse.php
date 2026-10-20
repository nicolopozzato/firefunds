<?php

namespace App\Account\DTO;

use App\Entity\AccountAdjustment;

final readonly class AccountAdjustmentResponse
{
    public function __construct(
        public string $id,
        public string $date,
        /** Signed: positive adds to the balance. */
        public string $amount,
        public ?string $description,
    ) {
    }

    public static function fromEntity(AccountAdjustment $adjustment): self
    {
        return new self(
            id: $adjustment->getId()->toRfc4122(),
            date: $adjustment->getDate()->format('Y-m-d'),
            amount: $adjustment->getAmount(),
            description: $adjustment->getDescription(),
        );
    }
}
