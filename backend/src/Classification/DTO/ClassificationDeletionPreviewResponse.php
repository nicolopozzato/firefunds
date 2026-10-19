<?php

namespace App\Classification\DTO;

/**
 * How many transactions a category or tag is still set on, shown before the
 * user confirms deleting it.
 */
final readonly class ClassificationDeletionPreviewResponse
{
    public function __construct(
        public int $transactions,
    ) {
    }
}
