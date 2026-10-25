<?php

namespace App\Admin\DTO;

/**
 * Administration → Scheduled tasks: each task with its latest runs, and what
 * the price and rate fetches keep up to date, with how recent it is.
 */
final readonly class TasksOverviewResponse
{
    /**
     * @param TaskResponse[] $tasks
     * @param list<array{symbol: string, latest: ?string, latestClose: ?string, status: string}> $symbols
     *        status: ok, stale (no close of the provider's for days), missing (never one)
     * @param list<array{currency: string, latest: ?string, published: ?string, source: ?string, status: string}> $currencies
     *        status: ok, stale (the shared rates stopped), missing (none)
     */
    public function __construct(
        /** APP_TIMEZONE: the tasks' times are in it. */
        public string $timezone,
        public array $tasks,
        public array $symbols,
        public array $currencies,
    ) {
    }
}
