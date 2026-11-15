<?php

namespace App\Report\DTO;

/** An asset account in the cash flow report: its cash, and its value (cash and what it holds), in the user's currency. */
final readonly class CashFlowAccountResponse
{
    /**
     * @param string[] $balances cash at each column's end
     * @param string[] $values value at each column's end
     */
    public function __construct(
        public string $id,
        public string $name,
        /** Its own currency, when not the user's. */
        public ?string $currencyCode,
        public string $opening,
        public array $balances,
        public string $openingValue,
        public array $values,
    ) {
    }
}
