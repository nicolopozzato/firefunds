<?php

namespace App\Update;

/** What the administrators see: the version running, the latest published, whether it is newer. */
final readonly class UpdateStatus
{
    public function __construct(
        public bool $enabled,
        public string $currentVersion,
        public ?Release $latest,
        public bool $updateAvailable,
        public ?string $checkedAt,
    ) {
    }
}
