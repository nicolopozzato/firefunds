<?php

namespace App\Backup;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

/**
 * A backup uploaded on an installation nobody uses yet (no users), instead of
 * creating the first account: a new server, or one rebuilt after a problem,
 * starts again from the old data. Once there is a user the web never
 * restores: app:backup:restore does, since it replaces data in use.
 */
class FirstStartRestore
{
    public function __construct(
        private readonly BackupRestorer $restorer,
        private readonly BackupService $backups,
        private readonly UserRepository $users,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function isAvailable(): bool
    {
        return 0 === $this->users->count([]);
    }

    /**
     * Checks the file, puts it back and brings it up to this version. When
     * putting it back fails, the installation is left new again.
     */
    public function restore(string $path): RestoreCheck
    {
        $this->assertAvailable();
        $check = $this->restorer->inspect($path);

        $lock = $this->backups->lock();
        try {
            // Somebody may have registered, or restored, while the file was uploading.
            $this->assertAvailable();
            set_time_limit(0);
            try {
                $this->restorer->restore($path);
                $this->migrate();
            } catch (\Throwable $e) {
                $this->restorer->emptyDatabase();
                $this->migrate();

                throw $e;
            }
        } finally {
            $lock->release();
        }

        return $check;
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new ApiException(ErrorCode::RestoreNotFirstStart, [], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Every migration the database lacks, as the container does on start. In
     * a process of its own: Doctrine's migrations remember within a process
     * that their table exists, and here it was just dropped.
     */
    private function migrate(): void
    {
        $process = new Process(['php', $this->projectDir.'/bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration', '--all-or-nothing', '--env='.$this->environment]);
        $process->setTimeout(1800);
        $this->backups->run($process);
    }
}
