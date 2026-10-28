<?php

namespace App\Tests\Functional\Controller;

use App\Entity\ScheduledTaskRun;
use App\Entity\TaskRunStatus;
use App\Entity\TaskTrigger;
use App\Notification\Service\SystemHealth;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** What administrators are told about the server: raised while wrong, gone once fine, for them only. */
class SystemHealthTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    /** Runs one minute apart, in the order they are made: "the last 3" must mean these. */
    private int $minutes = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testATaskFailingAgainAndAgainIsToldToAdministratorsUntilItWorks(): void
    {
        $admin = static::createUser();
        $admin->setRoles(['ROLE_ADMIN']);
        $user = static::createUser();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->flush();

        $this->runs('prices', TaskRunStatus::Failed, 2);
        $this->check();
        static::authenticate($this->client, $admin);
        $this->assertNotContains('task_failing', array_column($this->notifications(), 'type'), 'two failures: retries may still fix it');

        $this->runs('prices', TaskRunStatus::Failed, 1);
        $this->check();
        $failing = array_values(array_filter($this->notifications(), static fn (array $n) => $n['type'] === 'task_failing'));
        $this->assertCount(1, $failing);
        $this->assertSame(['task' => 'prices'], $failing[0]['params']);

        static::authenticate($this->client, $user);
        $this->assertNotContains('task_failing', array_column($this->notifications(), 'type'), 'not for users');

        $this->runs('prices', TaskRunStatus::Success, 1);
        $this->check();
        static::authenticate($this->client, $admin);
        $this->assertNotContains('task_failing', array_column($this->notifications(), 'type'), 'gone once it works');
    }

    private function runs(string $task, TaskRunStatus $status, int $count): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        for ($i = 0; $i < $count; ++$i) {
            $at = new \DateTimeImmutable(sprintf('+%d minutes', ++$this->minutes));
            $run = new ScheduledTaskRun($task, TaskTrigger::Schedule, $at);
            $run->finish($status, [], null, false, $at);
            $entityManager->persist($run);
        }
        $entityManager->flush();
    }

    private function check(): void
    {
        static::getContainer()->get(SystemHealth::class)->check();
    }

    /** @return list<array<string, mixed>> */
    private function notifications(): array
    {
        $this->client->request('GET', '/api/notifications');
        $this->assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
