<?php

namespace App\Recurring\DTO;

use App\Core\Validation\DateValue;
use App\Core\Validation\Decimal;
use Symfony\Component\Validator\Constraints as Assert;

/** Confirming the next occurrence of a recurrence that waits for it: the amount actually paid and its day, when they differ. */
final readonly class ConfirmOccurrenceRequest
{
    public function __construct(
        #[Assert\Positive]
        #[Decimal]
        public ?string $amount = null,

        #[DateValue]
        public ?string $day = null,
    ) {
    }
}
