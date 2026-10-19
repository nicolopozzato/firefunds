<?php

namespace App\Account\DTO;

final readonly class AccountValueHistoryPointResponse
{
    public function __construct(
        public string $date,
        public string $available,
        public string $invested,
        public string $total,
    ) {
    }
}
