<?php

namespace App\Report\Period;

/** One column of a report: a calendar month ("2026-03") or year ("2026"), possibly cut by the range. */
final readonly class ReportColumn
{
    public function __construct(
        public string $key,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        /** The range covers only part of the month or year. */
        public bool $partial,
    ) {
    }

    /** Whether a month ("2026-03") falls in this column. */
    public function contains(string $month): bool
    {
        return str_starts_with($month, $this->key);
    }
}
