<?php

namespace App\Backup\Command;

use App\Backup\BackupService;
use App\Service\SettingsService;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run by the scheduler every hour: makes a backup when the administrator's
 * settings say one is due, then deletes the ones past the retention.
 */
#[AsCommand(name: 'app:backup:run', description: 'Make a backup if one is due (or now, with --force), then delete the expired ones')]
class BackupRunCommand extends Command
{
    /** The scheduler checks once an hour: a backup a little early still counts as due. */
    private const TOLERANCE = '10 minutes';

    public function __construct(
        private readonly BackupService $backups,
        private readonly SettingsService $settings,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Back up now, whatever the settings say')] bool $force = false): int
    {
        $settings = $this->settings->getBackup();

        if (!$force) {
            if (!$settings->enabled) {
                $io->writeln('Automatic backups are off.');

                return Command::SUCCESS;
            }
            $last = $this->backups->lastBackupAt();
            $due = $this->clock->now()->modify(\sprintf('-%d hours', $settings->intervalHours))->modify('+'.self::TOLERANCE);
            if (null !== $last && $last > $due) {
                return Command::SUCCESS;
            }
        }

        $file = $this->backups->create($force ? BackupService::TRIGGER_MANUAL : BackupService::TRIGGER_SCHEDULED);
        $io->success(\sprintf('Backup %s (%s)', $file->name, $this->size($file->size)));

        $deleted = $this->backups->prune($settings->retentionDays);
        if ($deleted > 0) {
            $io->writeln(\sprintf('Deleted %d backup(s) older than %d days.', $deleted, $settings->retentionDays));
        }

        return Command::SUCCESS;
    }

    private function size(int $bytes): string
    {
        return round($bytes / 1024 / 1024, 1).' MB';
    }
}
