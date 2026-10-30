<?php

namespace App\Task\Tasks;

use App\Task\ScheduledTaskInterface;
use App\Task\TaskResult;
use App\Update\UpdateCheck;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** Twice a day: whether a newer FireFunds is out on GitHub (UpdateCheck), unless the administrator turned it off. */
#[AsTaggedItem(priority: 5)]
final class CheckUpdatesTask implements ScheduledTaskInterface
{
    public function __construct(private readonly UpdateCheck $updates)
    {
    }

    public static function key(): string
    {
        return 'updates';
    }

    public function cron(): string
    {
        return '40 9,21 * * *';
    }

    public function run(): TaskResult
    {
        if (!$this->updates->isEnabled()) {
            return TaskResult::idle(['skipped' => 'off']);
        }
        $latest = $this->updates->check();
        $status = $this->updates->status();

        return TaskResult::idle(['latest' => $latest?->version, 'current' => $status->currentVersion, 'available' => $status->updateAvailable]);
    }
}
