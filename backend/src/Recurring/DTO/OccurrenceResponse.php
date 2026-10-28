<?php

namespace App\Recurring\DTO;

use App\Entity\RecurringTransaction;

/** One day a recurrence falls on: still to come, or due and waiting for the user. */
final readonly class OccurrenceResponse
{
    public function __construct(
        public string $day,
        public string $time,
        /** That day's amount (a planned change may have come by then). */
        public string $amount,
        public RecurringTransactionResponse $recurring,
    ) {
    }

    public static function of(RecurringTransaction $recurring, \DateTimeImmutable $day, RecurringTransactionResponse $response): self
    {
        return new self($day->format('Y-m-d'), $recurring->getTime(), $recurring->amountOn($day), $response);
    }
}
