<?php

namespace App\Backup;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Process\Process;

/**
 * Backups of the database: one archive (tar) per backup, in the backups
 * directory (a volume next to compose.yml's `data`), holding pg_dump's
 * custom-format dump and a manifest.json (BackupManifest). The files are the
 * administrator's own copies of the data, not user data: pruning old ones is a
 * plain file delete.
 */
class BackupService
{
    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_BEFORE_RESTORE = 'before_restore';

    public const MANIFEST = 'manifest.json';
    public const DUMP = 'database.dump';

    private const FILE_PATTERN = '/^firefunds_\d{8}_\d{6}_[A-Za-z0-9.-]+\.tar$/';

    public function __construct(
        private readonly Connection $connection,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly Filesystem $filesystem,
        #[Autowire('%app.backup_directory%')]
        private readonly string $directory,
        #[Autowire(env: 'APP_VERSION')]
        private readonly string $appVersion,
        #[Autowire('%kernel.project_dir%/var/share')]
        private readonly string $lockDirectory,
    ) {
    }

    public function getAppVersion(): string
    {
        return $this->appVersion;
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * Whether the backups directory lives on a mounted volume: without one
     * the backups vanish with the container, and the administrator must be told.
     * True when it can't be told.
     */
    public function isOnVolume(): bool
    {
        $mountinfo = @file_get_contents('/proc/self/mountinfo');
        $real = realpath($this->directory);
        if (false === $mountinfo || false === $real) {
            return true;
        }
        foreach (explode("\n", $mountinfo) as $line) {
            $mountPoint = explode(' ', $line)[4] ?? '';
            if ('' === $mountPoint || '/' === $mountPoint) {
                continue;
            }
            if ($real === $mountPoint || str_starts_with($real, rtrim($mountPoint, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Takes the lock that keeps two backups, or a backup and a restore, from
     * overlapping. Release it when done.
     */
    public function lock(): LockInterface
    {
        $this->filesystem->mkdir($this->lockDirectory);
        $lock = $this->lockFactory->createLock('backup', 3600);
        if (!$lock->acquire()) {
            throw new ApiException(ErrorCode::BackupInProgress, [], 409);
        }

        return $lock;
    }

    /**
     * @param self::TRIGGER_* $trigger
     */
    public function create(string $trigger): BackupFile
    {
        $lock = $this->lock();
        try {
            return $this->write($trigger);
        } finally {
            $lock->release();
        }
    }

    /**
     * As create(), for a caller that already holds lock().
     *
     * @param self::TRIGGER_* $trigger
     */
    public function write(string $trigger): BackupFile
    {
        $this->filesystem->mkdir($this->directory);
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $work = $this->directory.'/.work-'.bin2hex(random_bytes(4));
        $this->filesystem->mkdir($work);

        try {
            $manifest = new BackupManifest($this->appVersion, $now->format(\DATE_ATOM), $this->latestMigration(), $trigger);
            $this->run($this->pg('pg_dump', ['-Fc', '--no-owner', '--no-acl', '-f', $work.'/'.self::DUMP]));
            $this->filesystem->dumpFile($work.'/'.self::MANIFEST, json_encode($manifest->toArray(), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

            // Two backups in the same second (a restore saves the data first) must not overwrite each other.
            $base = \sprintf('firefunds_%s_v%s', $now->format('Ymd_His'), preg_replace('/[^A-Za-z0-9.-]/', '-', $this->appVersion));
            $name = $base.'.tar';
            for ($n = 2; is_file($this->directory.'/'.$name); ++$n) {
                $name = \sprintf('%s-%d.tar', $base, $n);
            }
            $partial = $this->directory.'/'.$name.'.partial';
            $this->run(new Process(['tar', '-cf', $partial, '-C', $work, self::MANIFEST, self::DUMP]));
            $this->filesystem->rename($partial, $this->directory.'/'.$name, true);
        } finally {
            $this->discard($work);
        }

        return new BackupFile($name, $this->directory.'/'.$name, (int) filesize($this->directory.'/'.$name), $manifest);
    }

    /**
     * @return list<BackupFile> newest first
     */
    public function list(): array
    {
        $files = [];
        foreach (glob($this->directory.'/firefunds_*.tar') ?: [] as $path) {
            $name = basename($path);
            if (preg_match(self::FILE_PATTERN, $name)) {
                $files[] = new BackupFile($name, $path, (int) filesize($path), $this->readManifest($path));
            }
        }
        usort($files, static fn (BackupFile $a, BackupFile $b) => strcmp($b->name, $a->name));

        return $files;
    }

    public function find(string $name): ?BackupFile
    {
        if (!preg_match(self::FILE_PATTERN, $name) || !is_file($this->directory.'/'.$name)) {
            return null;
        }
        $path = $this->directory.'/'.$name;

        return new BackupFile($name, $path, (int) filesize($path), $this->readManifest($path));
    }

    /** When the newest backup was made, null if none. */
    public function lastBackupAt(): ?\DateTimeImmutable
    {
        foreach ($this->list() as $file) {
            if (null !== $file->manifest) {
                return new \DateTimeImmutable($file->manifest->createdAt);
            }
        }

        return null;
    }

    /**
     * Deletes the backups older than $days, never the newest one.
     *
     * @return int how many were deleted
     */
    public function prune(int $days): int
    {
        $limit = $this->clock->now()->modify(\sprintf('-%d days', $days));
        $deleted = 0;
        foreach (\array_slice($this->list(), 1) as $file) {
            if (null !== $file->manifest && new \DateTimeImmutable($file->manifest->createdAt) < $limit) {
                $this->discard($file->path);
                ++$deleted;
            }
        }

        return $deleted;
    }

    /** Deletes a backup file, or a working directory and what is in it. These are files, not rows of the database. */
    public function discard(string $path): void
    {
        if (is_dir($path)) {
            foreach (glob($path.'/*') ?: [] as $inner) {
                @unlink($inner);
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    /** The manifest of any archive, or null when it isn't a readable FireFunds backup. */
    public function readManifest(string $path): ?BackupManifest
    {
        $process = new Process(['tar', '-xOf', $path, self::MANIFEST]);
        $process->run();
        if (!$process->isSuccessful()) {
            return null;
        }
        $data = json_decode($process->getOutput(), true);

        return \is_array($data) ? BackupManifest::fromArray($data) : null;
    }

    /** The newest migration applied to the database, as the migrations table names it. */
    public function latestMigration(): ?string
    {
        try {
            $latest = $this->connection->fetchOne('SELECT version FROM doctrine_migration_versions ORDER BY version DESC LIMIT 1');
        } catch (\Throwable) {
            return null;
        }

        return false === $latest ? null : (string) $latest;
    }

    /**
     * A PostgreSQL client command aimed at the app's database.
     *
     * @param list<string> $arguments
     */
    public function pg(string $command, array $arguments): Process
    {
        $params = $this->connection->getParams();
        $process = new Process(
            [$command, '-h', (string) ($params['host'] ?? 'localhost'), '-p', (string) ($params['port'] ?? 5432), '-U', (string) ($params['user'] ?? ''), '-d', (string) ($params['dbname'] ?? ''), ...$arguments],
            null,
            ['PGPASSWORD' => (string) ($params['password'] ?? ''), 'LC_ALL' => 'C'],
        );
        $process->setTimeout(1800);

        return $process;
    }

    public function run(Process $process): void
    {
        $process->run();
        if (!$process->isSuccessful()) {
            throw new ApiException(ErrorCode::BackupFailed, ['reason' => trim($process->getErrorOutput()) ?: 'exit code '.$process->getExitCode()], 500);
        }
    }
}
