<?php

namespace App\Core\Pagination;

final readonly class PaginatedResponse
{
    /**
     * @param mixed[] $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $pageSize,
    ) {
    }

    /**
     * @param mixed[] $items
     */
    public static function create(array $items, int $total, PaginationParams $params): self
    {
        return new self($items, $total, $params->page, $params->pageSize);
    }
}
