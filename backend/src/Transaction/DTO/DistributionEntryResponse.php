<?php

namespace App\Transaction\DTO;

final readonly class DistributionEntryResponse
{
    public function __construct(
        public ?string $id,
        public ?string $name,
        public string $total,
    ) {
    }
}
