<?php

namespace App\Dashboard\DTO;

/** One point of a daily series: an amount, or a percentage for a performance line. */
final readonly class DatedValueResponse
{
    public function __construct(
        /** Y-m-d */
        public string $date,
        public string $value,
    ) {
    }
}
