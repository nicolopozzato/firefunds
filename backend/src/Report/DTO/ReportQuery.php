<?php

namespace App\Report\DTO;

use App\Report\Period\ReportRange;
use Symfony\Component\Validator\Constraints as Assert;

/** The options of the income and expenses report. */
final readonly class ReportQuery
{
    public function __construct(
        /** Y-m-d, both included; checked by CalendarRange, like every range. */
        public string $from = '',
        public string $to = '',
        /** A column per month or per year. */
        #[Assert\Choice(choices: [ReportRange::BY_MONTH, ReportRange::BY_YEAR])]
        public string $by = ReportRange::BY_MONTH,
        /** none, the range right before, or the same range a year earlier. */
        #[Assert\Choice(choices: ['none', ReportRange::COMPARE_PREVIOUS, ReportRange::COMPARE_LAST_YEAR])]
        public string $compare = 'none',
    ) {
    }
}
