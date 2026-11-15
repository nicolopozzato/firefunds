<?php

namespace App\Tests\Functional\Controller;

use App\Backup\BackupManifest;
use App\Backup\BackupService;
use App\Tests\Functional\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

/**
 * A backup uploaded instead of creating the first account: only while there
 * are no users, and only a backup this version can read. Putting one back
 * replaces the database, which the test's transaction can't hold.
 */
class FirstStartRestoreTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private string $work;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->work = sys_get_temp_dir().'/first-start-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->work);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->work);
        parent::tearDown();
    }

    public function testRefusedOnceThereIsAUser(): void
    {
        static::createUser();

        $this->upload($this->backup('DoctrineMigrations\Version20261022090000'));
        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('restore_not_first_start', $this->code());
    }

    public function testAFileThatIsNotABackupIsRefused(): void
    {
        $this->assertNoUsers();
        file_put_contents($this->work.'/notes.tar', 'not an archive');

        $this->upload($this->work.'/notes.tar');
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('backup_unreadable', $this->code());

        $this->client->request('POST', '/api/setup/restore');
        $this->assertResponseStatusCodeSame(400, 'no file');
    }

    public function testABackupOfANewerVersionIsRefused(): void
    {
        $this->assertNoUsers();

        $this->upload($this->backup('DoctrineMigrations\Version99991231000000'));
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('backup_from_newer_version', $this->code());
    }

    private function assertNoUsers(): void
    {
        $this->client->request('GET', '/api/auth/check-registration');
        $this->assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true)['hasUsers']);
    }

    /** An archive with a manifest naming that migration as its newest (and an empty dump). */
    private function backup(string $latestMigration): string
    {
        $manifest = new BackupManifest('9.9.9', '2026-11-14T10:00:00+00:00', $latestMigration, BackupService::TRIGGER_MANUAL);
        file_put_contents($this->work.'/'.BackupService::MANIFEST, json_encode($manifest->toArray()));
        file_put_contents($this->work.'/'.BackupService::DUMP, '');
        $path = $this->work.'/firefunds_20261114_100000_v9.9.9.tar';
        (new Process(['tar', '-cf', $path, '-C', $this->work, BackupService::MANIFEST, BackupService::DUMP]))->mustRun();

        return $path;
    }

    private function upload(string $path): void
    {
        $this->client->request('POST', '/api/setup/restore', [], ['backup' => new UploadedFile($path, basename($path), 'application/x-tar', null, true)]);
    }

    private function code(): ?string
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true)['code'] ?? null;
    }
}
