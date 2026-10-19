<?php

namespace App\Report\DTO;

/** Categories as rows, the report's columns as columns. */
final readonly class CategoryTableResponse
{
    /**
     * @param CategoryRowResponse[] $rows largest total first, no category last
     * @param string[] $totals one per column
     */
    public function __construct(
        public array $rows,
        public array $totals,
        public string $total,
        public string $average,
        public ?string $comparisonTotal,
    ) {
    }
}
