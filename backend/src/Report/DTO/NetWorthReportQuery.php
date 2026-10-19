<?php

namespace App\Report\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/** The options of the net worth report. */
final readonly class NetWorthReportQuery
{
    public function __construct(
        /** null = this year. */
        #[Assert\Range(min: 1970, max: 2999)]
        public ?int $year = null,
    ) {
    }
}
