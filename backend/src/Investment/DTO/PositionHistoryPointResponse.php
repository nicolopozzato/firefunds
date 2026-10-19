<?php

namespace App\Investment\DTO;

final readonly class PositionHistoryPointResponse
{
    public function __construct(
        public string $date,
        public string $value,
        public string $costBasis,
    ) {
    }
}
