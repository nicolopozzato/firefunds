<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Administration → Scheduled tasks: for administrators only; a task can be run from there. */
class TasksControllerTest extends WebTestCaseBase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testOnlyAnAdministratorSeesTheTasks(): void
    {
        static::authenticate($this->client, static::createUser());
        $this->client->request('GET', '/api/admin/tasks');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testEveryTaskIsListedWithItsNextTurn(): void
    {
        $this->loginAsAdmin();

        $overview = $this->request('GET', '/api/admin/tasks');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['prices', 'rates', 'recurring', 'backup', 'cleanup'], array_column($overview['tasks'], 'key'));
        $this->assertSame('Europe/Rome', $overview['timezone']);
        $this->assertNotEmpty($overview['tasks'][0]['nextRunAt']);
        $this->assertIsArray($overview['symbols']);
        $this->assertIsArray($overview['currencies']);
    }

    public function testATaskRunNowIsRecorded(): void
    {
        $this->loginAsAdmin();

        $overview = $this->request('POST', '/api/admin/tasks/cleanup/run');

        $this->assertResponseIsSuccessful();
        $cleanup = array_column($overview['tasks'], null, 'key')['cleanup'];
        $this->assertSame('manual', $cleanup['latestRun']['trigger']);
        $this->assertSame('success', $cleanup['latestRun']['status']);
        $this->assertArrayHasKey('revoked', $cleanup['latestRun']['details']);
        $this->assertNotNull($cleanup['lastDoneAt']);

        $missing = $this->request('POST', '/api/admin/tasks/nope/run');
        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('task_not_found', $missing['code']);
    }

    public function testTheRunsComePageByPage(): void
    {
        $this->loginAsAdmin();
        $this->request('POST', '/api/admin/tasks/prices/run');
        $this->request('POST', '/api/admin/tasks/rates/run');

        $page = $this->request('GET', '/api/admin/tasks/prices/runs?page=1&pageSize=10');
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $page['total']);
        $this->assertSame(10, $page['pageSize']);
        $this->assertSame('manual', $page['items'][0]['trigger']);
        $this->assertSame(1, $page['items'][0]['repeats']);

        $this->request('GET', '/api/admin/tasks/nope/runs');
        $this->assertResponseStatusCodeSame(404);
    }

    private function loginAsAdmin(): void
    {
        $user = static::createUser();
        $user->setRoles(['ROLE_ADMIN']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        static::authenticate($this->client, $user);
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $uri): array
    {
        $this->client->jsonRequest($method, $uri);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
