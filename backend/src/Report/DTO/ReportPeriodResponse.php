<?php

namespace App\Report\DTO;

/** One column of the income and expenses report and what it adds up to. */
final readonly class ReportPeriodResponse
{
    public function __construct(
        /** "2026-03" for a month, "2026" for a year. */
        public string $key,
        /** Y-m-d, both included. */
        public string $from,
        public string $to,
        /** Only part of the month or year is in the range. */
        public bool $partial,
        public string $income,
        public string $expenses,
        /** income - expenses */
        public string $balance,
        /** Moved to investment accounts, net of what came back. */
        public string $invested,
    ) {
    }
}
