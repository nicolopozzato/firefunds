<?php

namespace App\Core\Pagination;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

final readonly class PaginationParams
{
    private const int MAX_PAGE_SIZE = 500;

    /**
     * A hard ceiling for "unlimited" requests (pageSize=-1), so a combobox that
     * needs "all of them" can't accidentally pull an unbounded result set.
     */
    private const int UNLIMITED_PAGE_SIZE = 5000;

    public function __construct(
        public int $page,
        public int $pageSize,
        public bool $isUnlimited = false,
    ) {
    }

    /**
     * Reads page/pageSize from the query string, defaulting pageSize to the
     * user's own preference (set in Preferences) when not explicitly passed.
     * pageSize=-1 is the explicit "give me everything" escape hatch, used by
     * selection UI (comboboxes) that must show every option, not just one page.
     */
    public static function fromRequest(Request $request, User $user): self
    {
        $page = max(1, $request->query->getInt('page', 1));
        $requestedPageSize = $request->query->getInt('pageSize', $user->getPageSize());

        if ($requestedPageSize === -1) {
            return new self(1, self::UNLIMITED_PAGE_SIZE, isUnlimited: true);
        }

        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $requestedPageSize));

        return new self($page, $pageSize);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }
}
