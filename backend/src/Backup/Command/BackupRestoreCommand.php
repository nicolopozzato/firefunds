<?php

namespace App\Backup\Command;

use App\Backup\BackupRestorer;
use App\Backup\BackupService;
use App\Backup\RestoreCompatibility;
use App\Core\Exception\ApiException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The only way to put a backup back (docs for self-hosters: README, "Restore").
 * Checks the backup against this version first, saves what is in the database
 * now, replaces it, then runs the migrations an older backup lacks.
 */
#[AsCommand(name: 'app:backup:restore', description: 'Replace ALL the data with a backup (a file from the backups directory, or a path)')]
class BackupRestoreCommand extends Command
{
    public function __construct(
        private readonly BackupRestorer $restorer,
        private readonly BackupService $backups,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Argument(description: 'Backup file name (in the backups directory) or full path')] string $file,
        #[Option(description: 'Do not ask for confirmation')] bool $yes = false,
    ): int {
        $path = is_file($file) ? $file : $this->backups->getDirectory().'/'.basename($file);

        try {
            $check = $this->restorer->inspect($path);
        } catch (ApiException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Backup' => basename($path)],
            ['Made' => $check->manifest->createdAt],
            ['By FireFunds' => $check->manifest->appVersion],
            ['This installation' => $check->currentVersion],
        );
        $io->writeln(RestoreCompatibility::Same === $check->compatibility
            ? 'Same database version: nothing to migrate.'
            : 'Older database version: the migrations will run after restoring.');
        $io->warning('Everything now in the database is replaced by the backup (a copy of the current data is saved first).');

        if (!$yes && !$io->confirm('Restore this backup?', false)) {
            $io->writeln('Nothing changed.');

            return Command::SUCCESS;
        }

        $lock = $this->backups->lock();
        try {
            $before = $this->backups->write(BackupService::TRIGGER_BEFORE_RESTORE);
            $io->writeln('Current data saved as '.$before->name);

            try {
                $this->restorer->restore($path);
            } catch (\Throwable $e) {
                $io->error('Restoring failed: '.$e->getMessage());
                $io->writeln(\sprintf('The data before the attempt is in %s: restore that file to go back.', $before->name));

                return Command::FAILURE;
            }

            $application = $this->getApplication();
            \assert($application instanceof Application);
            $migrate = $application->find('doctrine:migrations:migrate');
            $input = new ArrayInput(['--allow-no-migration' => true, '--all-or-nothing' => true]);
            $input->setInteractive(false);
            $status = $migrate->run($input, $output);
            if (Command::SUCCESS !== $status) {
                $io->error('The backup was restored but the migrations failed.');

                return Command::FAILURE;
            }
        } finally {
            $lock->release();
        }

        $io->success('Backup restored.');

        return Command::SUCCESS;
    }
}
