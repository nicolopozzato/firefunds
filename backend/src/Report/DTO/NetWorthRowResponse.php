<?php

namespace App\Report\DTO;

/**
 * A row of the net worth report: a value at the end of each month of the
 * year and of the year before (null for months still to come).
 */
final readonly class NetWorthRowResponse
{
    /**
     * @param array<?string> $values one per month of the year
     * @param array<?string> $previousValues one per month of the year before
     */
    public function __construct(
        /** The account's or the instrument's id; null for a total. */
        public ?string $id,
        public ?string $name,
        /** Instruments only. */
        public ?string $ticker,
        public array $values,
        public array $previousValues,
        /** At the end of December two years before: what January of the year before changed from. */
        public ?string $previousOpening,
    ) {
    }
}
