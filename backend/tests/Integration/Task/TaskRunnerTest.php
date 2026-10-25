<?php

namespace App\Tests\Integration\Task;

use App\Core\Exception\ApiException;
use App\Entity\TaskRunStatus;
use App\Entity\TaskTrigger;
use App\Repository\ScheduledTaskRunRepository;
use App\Task\ScheduledTaskInterface;
use App\Task\TaskResult;
use App\Task\TaskRunLog;
use App\Task\TaskRunner;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Every run is recorded, a task that throws is a failed run, and a run that
 * failed or never happened since the task's last scheduled time is retried,
 * a few times at most.
 */
class TaskRunnerTest extends KernelTestCase
{
    private MockClock $clock;
    private ScheduledTaskRunRepository $runs;

    protected function setUp(): void
    {
        self::bootKernel();
        // 10:30 in Rome: the "daily at 10:00" task below last had its turn half an hour ago.
        $this->clock = new MockClock('2026-10-21 08:30:00 UTC');
        $this->runs = self::getContainer()->get(ScheduledTaskRunRepository::class);
    }

    public function testARunIsRecordedWithWhatTheTaskReports(): void
    {
        $runner = $this->runner(new FakeTask('fake', static fn () => TaskResult::success(['done' => 3])));

        $run = $runner->run('fake', TaskTrigger::Manual);

        $this->assertSame(TaskRunStatus::Success, $run->getStatus());
        $this->assertSame(['done' => 3], $run->getDetails());
        $this->assertSame(TaskTrigger::Manual, $run->getTrigger());
        $this->assertNotNull($run->getFinishedAt());
        $this->assertCount(1, $this->runs->latest('fake', 10));
    }

    public function testATaskThatThrowsIsAFailedRun(): void
    {
        $runner = $this->runner(new FakeTask('fake', static fn () => throw new \RuntimeException('Yahoo is down')));

        $run = $runner->run('fake', TaskTrigger::Schedule);

        $this->assertSame(TaskRunStatus::Failed, $run->getStatus());
        $this->assertSame('Yahoo is down', $run->getError());
    }

    public function testAnUnknownTaskIsRefused(): void
    {
        $this->expectException(ApiException::class);
        $this->runner()->run('nope', TaskTrigger::Manual);
    }

    public function testATaskWithNothingDoneSinceItsTurnIsRetriedAFewTimes(): void
    {
        $attempts = 0;
        $failing = new FakeTask('failing', static function () use (&$attempts) {
            ++$attempts;

            return new TaskResult(TaskRunStatus::Failed);
        });
        $runner = $this->runner($failing);

        // Its 10:00 turn never happened (the database was down, say): retried.
        $runner->retryOverdue();
        $this->assertSame(1, $attempts);
        $this->assertSame(TaskTrigger::Retry, $this->runs->latest('failing', 1)[0]->getTrigger());

        $runner->retryOverdue();
        $runner->retryOverdue();
        $runner->retryOverdue();
        $this->assertSame(3, $attempts, 'three tries, then it waits for its next turn');

        // The next day's turn: tried again.
        $this->clock->modify('+1 day');
        $runner->retryOverdue();
        $this->assertSame(4, $attempts);
    }

    public function testATaskDoneSinceItsTurnIsLeftAlone(): void
    {
        $attempts = 0;
        $task = new FakeTask('fine', static function () use (&$attempts) {
            ++$attempts;

            return new TaskResult(TaskRunStatus::Partial);
        });
        $runner = $this->runner($task);

        $runner->run('fine', TaskTrigger::Schedule);
        $runner->retryOverdue();

        $this->assertSame(1, $attempts, 'partial is something done: retrying would not help');
    }

    public function testRunsWithNothingToDoInARowAreKeptAsOne(): void
    {
        $result = TaskResult::idle(['skipped' => 'not_due']);
        $runner = $this->runner(new FakeTask('fake', static function () use (&$result) {
            return $result;
        }));

        $first = $runner->run('fake', TaskTrigger::Schedule);
        $this->clock->modify('+1 hour');
        $runner->run('fake', TaskTrigger::Schedule);
        $this->clock->modify('+1 hour');
        $third = $runner->run('fake', TaskTrigger::Schedule);

        $runs = $this->runs->latest('fake', 10);
        $this->assertCount(1, $runs);
        $this->assertSame(3, $third->getRepeats());
        $this->assertEquals($first->getStartedAt(), $third->getFirstStartedAt());

        // Something done breaks the series, and a run with nothing to do after it starts a new one.
        $result = TaskResult::success(['file' => 'x.tar']);
        $this->clock->modify('+1 hour');
        $runner->run('fake', TaskTrigger::Schedule);
        $result = TaskResult::idle(['skipped' => 'not_due']);
        $this->clock->modify('+1 hour');
        $runner->run('fake', TaskTrigger::Schedule);

        $this->assertSame([1, 1, 3], array_map(static fn ($run) => $run->getRepeats(), $this->runs->latest('fake', 10)));
    }

    public function testRunsOlderThanTheRetentionAreDeleted(): void
    {
        $runner = $this->runner(new FakeTask('fake', static fn () => TaskResult::success()));
        $runner->run('fake', TaskTrigger::Schedule);
        $this->clock->modify('+'.(TaskRunLog::RETENTION_DAYS - 1).' days');
        $runner->run('fake', TaskTrigger::Schedule);

        $log = self::getContainer()->get(TaskRunLog::class);
        $this->assertSame(0, $log->prune($this->clock->now()));
        $this->clock->modify('+2 days');
        $this->assertSame(1, $log->prune($this->clock->now()), 'the first run is past the retention');
        $this->assertCount(1, $this->runs->latest('fake', 10));
    }

    public function testTheTurnsAreInTheMarketTimezone(): void
    {
        $runner = $this->runner($task = new FakeTask('fake', static fn () => TaskResult::success()));

        $this->assertEquals(new \DateTimeImmutable('2026-10-21 08:00:00 UTC'), $runner->previousRunAt($task), '10:00 in Rome');
        $this->assertEquals(new \DateTimeImmutable('2026-10-22 08:00:00 UTC'), $runner->nextRunAt($task));
    }

    private function runner(ScheduledTaskInterface ...$tasks): TaskRunner
    {
        $container = self::getContainer();

        return new TaskRunner(
            array_combine(array_map(static fn (FakeTask $task) => $task->name, $tasks), $tasks),
            $this->runs,
            $container->get(TaskRunLog::class),
            $container->get(EntityManagerInterface::class),
            $container->get(ManagerRegistry::class),
            new LockFactory(new InMemoryStore()),
            $this->clock,
            new NullLogger(),
            'Europe/Rome',
        );
    }
}

/** A task due every day at 10:00, doing what the test says. */
final class FakeTask implements ScheduledTaskInterface
{
    /** @param \Closure(): TaskResult $work */
    public function __construct(public readonly string $name, private readonly \Closure $work)
    {
    }

    public static function key(): string
    {
        return 'fake';
    }

    public function cron(): string
    {
        return '0 10 * * *';
    }

    public function run(): TaskResult
    {
        return ($this->work)();
    }
}
