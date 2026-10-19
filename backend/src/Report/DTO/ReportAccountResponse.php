<?php

namespace App\Report\DTO;

/** An asset account at the start and at the end of the range. */
final readonly class ReportAccountResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $assetKind,
        public bool $isActive,
        /** Free cash plus, for an investment account, what is held at market value. */
        public string $start,
        public string $end,
        public string $change,
        /** Investment accounts: the market value of what is held, part of start and end; null otherwise. */
        public ?string $investedStart,
        public ?string $investedEnd,
    ) {
    }
}
