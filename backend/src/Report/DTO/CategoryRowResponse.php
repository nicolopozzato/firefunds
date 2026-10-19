<?php

namespace App\Report\DTO;

/** A category's row: what it adds up to in each column of the report. */
final readonly class CategoryRowResponse
{
    /**
     * @param string[] $values one per column, in the columns' order
     * @param ?string[] $comparisonValues one per column of the comparison range; null without a comparison
     */
    public function __construct(
        /** null = no category. */
        public ?string $id,
        public ?string $name,
        public array $values,
        public string $total,
        /** total per month of the range (see IncomeExpenseSummaryResponse::$months). */
        public string $average,
        public ?array $comparisonValues,
        public ?string $comparisonTotal,
    ) {
    }
}
