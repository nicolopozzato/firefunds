<?php

namespace App\Report\DTO;

/** A column of the net worth report: the end of a month. */
final readonly class NetWorthMonthResponse
{
    public function __construct(
        /** Y-m */
        public string $month,
        /** Y-m-d: the month's last day, today for the current month; null for a month still to come. */
        public ?string $date,
    ) {
    }
}
