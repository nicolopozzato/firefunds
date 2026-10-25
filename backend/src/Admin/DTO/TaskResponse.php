<?php

namespace App\Admin\DTO;

final readonly class TaskResponse
{
    public function __construct(
        /** prices, rates, backup, cleanup. */
        public string $key,
        /** When it runs, in the server's market timezone. */
        public string $cron,
        public string $nextRunAt,
        /** When it last finished with something done (success or partial). */
        public ?string $lastDoneAt,
        /** The newest run; the others page by page (GET /api/admin/tasks/{key}/runs). */
        public ?TaskRunResponse $latestRun,
    ) {
    }
}
