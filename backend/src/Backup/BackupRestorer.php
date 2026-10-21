<?php

namespace App\Backup;

use App\Core\Doctrine\PhysicalDelete;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Puts a backup back: replaces the whole database with the backup's. Used
 * only by app:backup:restore. Running the migrations afterwards (the caller's
 * job) brings an older backup up to date.
 */
#[PhysicalDelete('Restoring a backup replaces the whole database with the backup\'s, which the owner asks for by running app:backup:restore.')]
class BackupRestorer
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly Connection $connection,
        private readonly Filesystem $filesystem,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private readonly DependencyFactory $migrations,
    ) {
    }

    /**
     * What restoring this file would mean here, or an ApiException when it
     * isn't a backup of this app, or comes from a newer version.
     */
    public function inspect(string $path): RestoreCheck
    {
        $manifest = is_file($path) ? $this->backups->readManifest($path) : null;
        if (null === $manifest) {
            throw new ApiException(ErrorCode::BackupUnreadable);
        }

        $compatibility = RestoreCompatibility::of($manifest->latestMigration, $this->knownMigrations());
        if (RestoreCompatibility::Newer === $compatibility) {
            throw new ApiException(ErrorCode::BackupFromNewerVersion, ['version' => $manifest->appVersion, 'current' => $this->backups->getAppVersion()]);
        }

        return new RestoreCheck($manifest, $compatibility, $this->backups->getAppVersion());
    }

    /**
     * Replaces the database with the backup's. The caller holds BackupService::lock().
     */
    public function restore(string $path): void
    {
        $work = $this->backups->getDirectory().'/.work-'.bin2hex(random_bytes(4));
        $this->filesystem->mkdir($work);

        try {
            $this->backups->run(new Process(['tar', '-xf', $path, '-C', $work, BackupService::DUMP]));
            $this->connection->executeStatement('DROP SCHEMA public CASCADE');
            $this->connection->executeStatement('CREATE SCHEMA public');
            $this->connection->close();
            $this->backups->run($this->backups->pg('pg_restore', ['--no-owner', '--no-acl', '--exit-on-error', $work.'/'.BackupService::DUMP]));
        } finally {
            $this->backups->discard($work);
        }
    }

    /**
     * @return list<string>
     */
    private function knownMigrations(): array
    {
        $known = [];
        foreach ($this->migrations->getMigrationRepository()->getMigrations()->getItems() as $migration) {
            $known[] = (string) $migration->getVersion();
        }

        return $known;
    }
}
