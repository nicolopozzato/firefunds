<?php

namespace App\Transaction\DTO;

final readonly class DateRangeResponse
{
    public function __construct(
        public ?string $earliest,
        public ?string $latest,
    ) {
    }
}
