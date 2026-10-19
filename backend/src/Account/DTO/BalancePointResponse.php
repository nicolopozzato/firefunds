<?php

namespace App\Account\DTO;

final readonly class BalancePointResponse
{
    public function __construct(
        public string $date,
        public string $balance,
    ) {
    }
}
