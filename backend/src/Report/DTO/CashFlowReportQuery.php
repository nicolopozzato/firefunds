<?php

namespace App\Report\DTO;

use App\Report\Service\CashFlowReportService;
use Symfony\Component\Validator\Constraints as Assert;

/** The options of the cash flow report. */
final readonly class CashFlowReportQuery
{
    public function __construct(
        /** How far ahead, in months from today. */
        #[Assert\Choice(choices: [1, 3, 6, 12, 24])]
        public int $months = 12,
        /** A column per day, week (from Monday) or month; days up to 3 months ahead, weeks up to a year. */
        #[Assert\Choice(choices: [CashFlowReportService::BY_DAY, CashFlowReportService::BY_WEEK, CashFlowReportService::BY_MONTH])]
        public string $by = CashFlowReportService::BY_MONTH,
        /** The estimate's mean: over how many past months. */
        #[Assert\Range(min: 1, max: 24)]
        public int $average = 6,
        /** How much the same month a year earlier weighs against that mean, in %. */
        #[Assert\Range(min: 0, max: 100)]
        public int $seasonal = 0,
        /** What goes into the forecast: entered, recurring, estimated (comma separated). */
        public string $layers = 'entered,recurring,estimated',
    ) {
    }

    /** @return list<string> */
    public function layerList(): array
    {
        return array_values(array_intersect(CashFlowReportService::LAYERS, explode(',', $this->layers)));
    }
}
