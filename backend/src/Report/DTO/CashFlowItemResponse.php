<?php

namespace App\Report\DTO;

/**
 * One thing a column of the cash flow report expects: a transaction already
 * entered, a recurrence's occurrence, or a category's estimate for the column.
 * The amount is positive, in the user's currency.
 */
final readonly class CashFlowItemResponse
{
    public function __construct(
        /** entered, recurring or estimated */
        public string $source,
        /** income, expense, invested (a recurring purchase) or transfer */
        public string $flow,
        public string $amount,
        /** The description; a category's name for an estimate (null: no category). */
        public ?string $label,
        /** Y-m-d; null for an estimate, spread over the column. */
        public ?string $day = null,
        /** The asset account paying or receiving (a transfer's source). */
        public ?string $accountName = null,
        /** A transfer's destination. */
        public ?string $toAccountName = null,
        public ?string $transactionId = null,
        public ?string $recurringId = null,
        public ?string $categoryId = null,
    ) {
    }
}
