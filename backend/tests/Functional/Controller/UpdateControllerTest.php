<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;

/** The administrators' notice of a new release: what the last check found, against the version running. */
class UpdateControllerTest extends WebTestCaseBase
{
    public function testAnAdministratorSeesTheLastCheck(): void
    {
        $client = static::createClient();
        $admin = static::createUser();
        $admin->setRoles(['ROLE_ADMIN']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        static::authenticate($client, $admin);

        $client->request('GET', '/api/admin/update');
        $this->assertResponseIsSuccessful();
        $status = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($status['enabled']);
        $this->assertNull($status['latest']);
        $this->assertFalse($status['updateAvailable']);

        self::getContainer()->get(SettingRepository::class)->set(Setting::KEY_LATEST_RELEASE, json_encode([
            'release' => ['version' => '99.0.0', 'url' => 'https://github.com/nicolopozzato/firefunds/releases/tag/v99.0.0', 'publishedAt' => '2026-10-30T10:00:00Z'],
            'checkedAt' => '2026-10-30T12:00:00+00:00',
        ]));
        $client->request('GET', '/api/admin/update');
        $status = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('99.0.0', $status['latest']['version']);
        $this->assertSame('2026-10-30T12:00:00+00:00', $status['checkedAt']);
        // The test environment runs "dev": not a release, so never behind.
        $this->assertFalse($status['updateAvailable']);
    }

    public function testTheCheckFailsWithoutReachingGitHubInTests(): void
    {
        $client = static::createClient();
        $admin = static::createUser();
        $admin->setRoles(['ROLE_ADMIN']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        static::authenticate($client, $admin);

        $client->request('POST', '/api/admin/tasks/updates/run');
        $this->assertResponseIsSuccessful();
        $tasks = array_column(json_decode((string) $client->getResponse()->getContent(), true)['tasks'], null, 'key');
        $this->assertSame('failed', $tasks['updates']['latestRun']['status']);
    }
}
