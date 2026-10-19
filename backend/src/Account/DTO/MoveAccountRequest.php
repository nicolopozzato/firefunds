<?php

namespace App\Account\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Where a dragged account lands: right after `afterId` or right before
 * `beforeId` (exactly one), both accounts of the same type. Relative to a
 * neighbour rather than an index, so it works on a filtered or paginated list
 * that doesn't show every account.
 */
final readonly class MoveAccountRequest
{
    public function __construct(
        #[Assert\Uuid]
        public ?string $afterId = null,
        #[Assert\Uuid]
        public ?string $beforeId = null,
    ) {
    }

    #[Assert\IsTrue(message: 'Give exactly one of afterId and beforeId.')]
    public function isExactlyOneNeighbourGiven(): bool
    {
        return ($this->afterId === null) !== ($this->beforeId === null);
    }
}
