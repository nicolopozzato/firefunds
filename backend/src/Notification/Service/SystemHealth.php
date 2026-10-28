<?php

namespace App\Notification\Service;

use App\Backup\BackupService;
use App\Entity\ScheduledTaskRun;
use App\Entity\TaskRunStatus;
use App\Repository\ScheduledTaskRunRepository;
use App\Service\SettingsService;
use App\Task\TasksOverview;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * What the administrators are told about the server, checked after every
 * task run: a task whose last runs all failed (the retries too), backups
 * missing for longer than planned, prices or rates no longer arriving. Each
 * closes by itself once it is fine again.
 */
class SystemHealth
{
    /** A task failing this many times in a row: the run and its retries. */
    private const FAILURES = 3;
    /** Room after the planned interval before a backup counts as missing. */
    private const BACKUP_GRACE_HOURS = 6;

    public function __construct(
        private readonly NotificationCenter $notifications,
        private readonly ScheduledTaskRunRepository $runs,
        private readonly TasksOverview $overview,
        private readonly BackupService $backups,
        private readonly SettingsService $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function check(): void
    {
        $overview = $this->overview->build();

        foreach ($overview->tasks as $task) {
            $this->toggle('task_failing', 'task:'.$task->key, $this->isFailing($task->key), ['task' => $task->key]);
        }

        $settings = $this->settings->getBackup();
        $last = $this->backups->lastBackupAt();
        $due = $this->clock->now()->modify('-'.($settings->intervalHours + self::BACKUP_GRACE_HOURS).' hours');
        $this->toggle('backup_overdue', 'backup', $settings->enabled && $last !== null && $last < $due, [
            'since' => $last?->format(\DateTimeInterface::ATOM),
        ]);

        $stale = static fn (array $rows, string $field) => array_values(array_map(
            static fn (array $row) => (string) $row[$field],
            array_filter($rows, static fn (array $row) => $row['status'] !== 'ok'),
        ));
        $symbols = $stale($overview->symbols, 'symbol');
        $this->toggle('prices_stale', 'prices', $symbols !== [], ['count' => \count($symbols), 'symbols' => implode(', ', \array_slice($symbols, 0, 5))]);
        $currencies = $stale($overview->currencies, 'currency');
        $this->toggle('rates_stale', 'rates', $currencies !== [], ['count' => \count($currencies), 'currencies' => implode(', ', $currencies)]);

        $this->entityManager->flush();
    }

    private function isFailing(string $task): bool
    {
        $finished = array_filter(
            $this->runs->latest($task, self::FAILURES + 2),
            static fn (ScheduledTaskRun $run) => $run->getStatus() !== TaskRunStatus::Running,
        );
        $last = \array_slice(array_values($finished), 0, self::FAILURES);

        return \count($last) === self::FAILURES
            && array_filter($last, static fn (ScheduledTaskRun $run) => $run->getStatus() !== TaskRunStatus::Failed) === [];
    }

    /** @param array<string, string|int|float|null> $params */
    private function toggle(string $type, string $key, bool $on, array $params): void
    {
        if ($on) {
            $this->notifications->raiseForAdministrators($type, $key, $params);
        } else {
            $this->notifications->resolve($key);
        }
    }
}
