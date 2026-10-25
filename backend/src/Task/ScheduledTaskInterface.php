<?php

namespace App\Task;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Something the scheduler runs on its own (src/Schedule.php), through
 * TaskRunner, which records every run (ScheduledTaskRun) and retries a run
 * that failed or never happened. Every task must be safe to run again at any
 * moment: each catches up on what it missed. Ordered by
 * `#[AsTaggedItem(priority)]`, the order of the administrator's page.
 */
#[AutoconfigureTag('app.scheduled_task')]
interface ScheduledTaskInterface
{
    /** The task's name in the run log and the API ("prices"). */
    public static function key(): string;

    /** When it runs: a cron expression in APP_TIMEZONE. */
    public function cron(): string;

    public function run(): TaskResult;
}
