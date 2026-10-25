<?php

namespace App\Task\Tasks;

use App\Backup\BackupService;
use App\Service\SettingsService;
use App\Task\ScheduledTaskInterface;
use App\Task\TaskResult;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Clock\ClockInterface;

/**
 * Checks every hour whether a backup is due (the administrator sets how often
 * and for how long, Administration → Backups): makes it, then deletes the
 * ones past the retention, never the newest.
 */
#[AsTaggedItem(priority: 20)]
final class BackupTask implements ScheduledTaskInterface
{
    /** Checked once an hour: a backup a little early still counts as due. */
    private const TOLERANCE = '10 minutes';

    public function __construct(
        private readonly BackupService $backups,
        private readonly SettingsService $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function key(): string
    {
        return 'backup';
    }

    public function cron(): string
    {
        return '5 * * * *';
    }

    public function run(): TaskResult
    {
        $settings = $this->settings->getBackup();
        if (!$settings->enabled) {
            return TaskResult::idle(['skipped' => 'off']);
        }
        $last = $this->backups->lastBackupAt();
        $due = $this->clock->now()->modify(\sprintf('-%d hours', $settings->intervalHours))->modify('+'.self::TOLERANCE);
        if ($last !== null && $last > $due) {
            return TaskResult::idle(['skipped' => 'not_due']);
        }

        $file = $this->backups->create(BackupService::TRIGGER_SCHEDULED);

        return TaskResult::success(['file' => $file->name, 'size' => $file->size, 'deleted' => $this->backups->prune($settings->retentionDays)]);
    }
}
