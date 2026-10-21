<?php

namespace App\Tests\Integration\Backup;

use App\Backup\BackupService;
use App\Core\Exception\ApiException;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

/**
 * Real pg_dump and tar against the test database, in a temporary directory.
 */
class BackupServiceTest extends KernelTestCase
{
    private string $directory;
    private MockClock $clock;
    private BackupService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->directory = sys_get_temp_dir().'/firefunds-backups-'.uniqid();
        $this->clock = new MockClock('2026-10-21 03:05:00 UTC');
        $container = self::getContainer();
        $this->service = new BackupService(
            $container->get(Connection::class),
            $container->get(LockFactory::class),
            $this->clock,
            new Filesystem(),
            $this->directory,
            '9.8.7',
            $this->directory.'/locks',
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testABackupIsAnArchiveWithItsManifest(): void
    {
        $file = $this->service->create(BackupService::TRIGGER_MANUAL);

        $this->assertSame('firefunds_20261021_030500_v9.8.7.tar', $file->name);
        $this->assertFileExists($file->path);
        $this->assertGreaterThan(0, $file->size);
        $this->assertSame('9.8.7', $file->manifest?->appVersion);
        $this->assertSame('manual', $file->manifest->trigger);
        $this->assertSame('2026-10-21T03:05:00+00:00', $file->manifest->createdAt);
        $this->assertNotNull($file->manifest->latestMigration);
        $this->assertSame([$file->name], array_map(static fn ($f) => $f->name, $this->service->list()));
        $this->assertNull($this->service->find('../../etc/passwd'));
    }

    public function testTwoBackupsInTheSameSecondKeepBoth(): void
    {
        $first = $this->service->create(BackupService::TRIGGER_MANUAL);
        $second = $this->service->create(BackupService::TRIGGER_BEFORE_RESTORE);

        $this->assertNotSame($first->name, $second->name);
        $this->assertCount(2, $this->service->list());
    }

    public function testOnlyBackupsPastTheRetentionAreDeletedAndTheNewestStays(): void
    {
        $this->service->create(BackupService::TRIGGER_SCHEDULED); // 2026-10-21
        $this->clock->modify('+5 days');
        $recent = $this->service->create(BackupService::TRIGGER_SCHEDULED); // 2026-10-26
        $this->clock->modify('+20 days');

        // A retention of 7 days would also take the newest (20 days old): it stays.
        $this->assertSame(1, $this->service->prune(7));
        $this->assertSame([$recent->name], array_map(static fn ($f) => $f->name, $this->service->list()));
        $this->assertSame(0, $this->service->prune(7));
    }

    public function testAFileThatIsNotABackupHasNoManifest(): void
    {
        (new Filesystem())->dumpFile($this->directory.'/other.tar', 'not a tar');

        $this->assertNull($this->service->readManifest($this->directory.'/other.tar'));
        $this->assertSame([], $this->service->list());
    }

    public function testBackupsDoNotOverlap(): void
    {
        $lock = $this->service->lock();
        try {
            $this->expectException(ApiException::class);
            $this->service->create(BackupService::TRIGGER_MANUAL);
        } finally {
            $lock->release();
        }
    }
}
