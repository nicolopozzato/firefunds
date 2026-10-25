<?php

namespace App\Task;

use App\Entity\TaskRunStatus;

/** What a run of a task did: how it went, and what it reports for the administrator's page. */
final readonly class TaskResult
{
    /**
     * @param array<string, mixed> $details counts, codes, symbols: never sentences (the page words them)
     */
    public function __construct(
        public TaskRunStatus $status,
        public array $details = [],
        /** The technical reason, for a run that did nothing. */
        public ?string $error = null,
        /** Nothing to do (no backup due, nothing to fetch): runs like it in a row are kept as one. */
        public bool $idle = false,
    ) {
    }

    /** @param array<string, mixed> $details */
    public static function success(array $details = []): self
    {
        return new self(TaskRunStatus::Success, $details);
    }

    /** @param array<string, mixed> $details */
    public static function idle(array $details = []): self
    {
        return new self(TaskRunStatus::Success, $details, idle: true);
    }
}
