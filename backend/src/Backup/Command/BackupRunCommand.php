<?php

namespace App\Backup\Command;

use App\Backup\BackupService;
use App\Service\SettingsService;
use App\Task\Tasks\BackupTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What the scheduler runs every hour (App\Task\Tasks\BackupTask, which
 * records the run), from the console: a backup if the administrator's
 * settings say one is due, or now with --force; then the expired ones go.
 */
#[AsCommand(name: 'app:backup:run', description: 'Make a backup if one is due (or now, with --force), then delete the expired ones')]
class BackupRunCommand extends Command
{
    public function __construct(
        private readonly BackupTask $task,
        private readonly BackupService $backups,
        private readonly SettingsService $settings,
    ) {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Back up now, whatever the settings say')] bool $force = false): int
    {
        if (!$force) {
            $details = $this->task->run()->details;
            if (isset($details['skipped'])) {
                $io->writeln($details['skipped'] === 'off' ? 'Automatic backups are off.' : 'No backup due yet.');
            } else {
                $io->success(\sprintf('Backup %s (%s), %d older one(s) deleted', $details['file'], $this->size($details['size']), $details['deleted']));
            }

            return Command::SUCCESS;
        }

        $file = $this->backups->create(BackupService::TRIGGER_MANUAL);
        $io->success(\sprintf('Backup %s (%s)', $file->name, $this->size($file->size)));

        $retentionDays = $this->settings->getBackup()->retentionDays;
        $deleted = $this->backups->prune($retentionDays);
        if ($deleted > 0) {
            $io->writeln(\sprintf('Deleted %d backup(s) older than %d days.', $deleted, $retentionDays));
        }

        return Command::SUCCESS;
    }

    private function size(int $bytes): string
    {
        return round($bytes / 1024 / 1024, 1).' MB';
    }
}
