<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Administration → Backups: only an administrator, the settings, making one
 * now, downloading it, and the scheduler's command following the settings.
 */
class BackupsControllerTest extends WebTestCaseBase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(self::getContainer()->getParameter('app.backup_directory'));
        parent::tearDown();
    }

    public function testOnlyAnAdministratorSeesBackups(): void
    {
        static::authenticate($this->client, static::createUser());

        $this->client->request('GET', '/api/admin/backups');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testTheDefaultsAreADailyBackupKeptTwoWeeks(): void
    {
        $this->loginAsAdmin();

        $data = $this->request('GET', '/api/admin/backups');
        $this->assertTrue($data['enabled']);
        $this->assertSame(24, $data['intervalHours']);
        $this->assertSame(14, $data['retentionDays']);
        $this->assertSame([], $data['backups']);
    }

    public function testTheSettingsAreValidatedAndSaved(): void
    {
        $this->loginAsAdmin();

        $this->request('PUT', '/api/admin/backups/settings', ['enabled' => true, 'intervalHours' => 5, 'retentionDays' => 14]);
        $this->assertResponseStatusCodeSame(422);
        $this->request('PUT', '/api/admin/backups/settings', ['enabled' => true, 'intervalHours' => 12, 'retentionDays' => 0]);
        $this->assertResponseStatusCodeSame(422);

        $data = $this->request('PUT', '/api/admin/backups/settings', ['enabled' => false, 'intervalHours' => 168, 'retentionDays' => 90]);
        $this->assertResponseIsSuccessful();
        $this->assertFalse($data['enabled']);
        $this->assertSame(168, $data['intervalHours']);
        $this->assertSame(90, $data['retentionDays']);
        $this->assertSame($data, $this->request('GET', '/api/admin/backups'));
    }

    public function testABackupMadeNowCanBeDownloaded(): void
    {
        $this->loginAsAdmin();

        $data = $this->request('POST', '/api/admin/backups');
        $this->assertResponseStatusCodeSame(201);
        $this->assertCount(1, $data['backups']);
        $backup = $data['backups'][0];
        $this->assertSame('manual', $backup['trigger']);

        $this->client->request('GET', '/api/admin/backups/'.$backup['name'].'/download');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('attachment', $this->client->getResponse()->headers->get('Content-Disposition'));

        $this->client->request('GET', '/api/admin/backups/nope.tar/download');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testTheScheduledCommandFollowsTheSettings(): void
    {
        $this->loginAsAdmin();
        $command = new CommandTester(self::getContainer()->get('console.command_loader')->get('app:backup:run'));

        $this->request('PUT', '/api/admin/backups/settings', ['enabled' => false, 'intervalHours' => 24, 'retentionDays' => 14]);
        $command->execute([]);
        $this->assertCount(0, $this->request('GET', '/api/admin/backups')['backups'], 'off: nothing');

        $this->request('PUT', '/api/admin/backups/settings', ['enabled' => true, 'intervalHours' => 24, 'retentionDays' => 14]);
        $command->execute([]);
        $this->assertCount(1, $this->request('GET', '/api/admin/backups')['backups'], 'on and none yet: one');

        $command->execute([]);
        $this->assertCount(1, $this->request('GET', '/api/admin/backups')['backups'], 'not due yet');

        $command->execute(['--force' => true]);
        $this->assertCount(2, $this->request('GET', '/api/admin/backups')['backups'], 'forced');
    }

    private function loginAsAdmin(): void
    {
        $user = static::createUser();
        $user->setRoles(['ROLE_ADMIN']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        static::authenticate($this->client, $user);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $uri, ?array $body = null): array
    {
        $this->client->jsonRequest($method, $uri, $body ?? []);

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }
}
