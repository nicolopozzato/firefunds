<?php

namespace App\Task;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\ScheduledTaskRun;
use App\Entity\TaskRunStatus;
use App\Entity\TaskTrigger;
use App\Repository\ScheduledTaskRunRepository;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;

/**
 * Runs the scheduled tasks and records every run (ScheduledTaskRun), one at a
 * time per task (a lock shared by the web and scheduler containers).
 *
 * A run the scheduler missed or that failed is retried (retryOverdue(), every
 * 15 minutes): a task is overdue when nothing has been done since its last
 * scheduled time, up to MAX_ATTEMPTS tries. The scheduler's own messages can't
 * be retried: one that fails (say, the database not up yet after a restart) is
 * dropped and would otherwise wait for the next slot.
 */
class TaskRunner
{
    private const MAX_ATTEMPTS = 3;

    /** @var array<string, ScheduledTaskInterface> */
    private array $tasks;

    /**
     * @param iterable<string, ScheduledTaskInterface> $tasks
     */
    public function __construct(
        #[AutowireIterator('app.scheduled_task', defaultIndexMethod: 'key')]
        iterable $tasks,
        private readonly ScheduledTaskRunRepository $runs,
        private readonly TaskRunLog $log,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $doctrine,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'APP_TIMEZONE')]
        private readonly string $timezone,
    ) {
        $this->tasks = iterator_to_array($tasks);
    }

    /** @return array<string, ScheduledTaskInterface> key => task */
    public function tasks(): array
    {
        return $this->tasks;
    }

    public function get(string $key): ScheduledTaskInterface
    {
        return $this->tasks[$key] ?? throw ApiException::notFound(ErrorCode::TaskNotFound);
    }

    /**
     * Runs a task now and records how it went. A task that throws is a failed
     * run, not an exception: only a task already running is refused.
     */
    public function run(string $key, TaskTrigger $trigger): ScheduledTaskRun
    {
        $task = $this->get($key);
        $lock = $this->lockFactory->createLock('scheduled-task-'.$key, 3 * 3600);
        if (!$lock->acquire()) {
            throw new ApiException(ErrorCode::TaskAlreadyRunning, [], Response::HTTP_CONFLICT);
        }

        try {
            $run = new ScheduledTaskRun($key, $trigger, $this->clock->now());
            $this->entityManager->persist($run);
            $this->entityManager->flush();
            $id = $run->getId();

            try {
                $result = $task->run();
            } catch (\Throwable $e) {
                $this->logger->error('Scheduled task {task} failed: {error}', ['task' => $key, 'error' => $e->getMessage(), 'exception' => $e]);
                $result = new TaskResult(TaskRunStatus::Failed, [], $e->getMessage());
            }

            // A database error closes the EntityManager, and a task may clear it: the run is loaded again.
            if (!$this->entityManager->isOpen()) {
                $this->doctrine->resetManager();
            }
            $run = $this->entityManager->find(ScheduledTaskRun::class, $id) ?? $run;
            $run->finish($result->status, $result->details, $result->error, $result->idle, $this->clock->now());
            $this->entityManager->flush();
            $this->log->compact($run);

            return $run;
        } finally {
            $lock->release();
        }
    }

    /** Runs again every task with nothing done since its last scheduled time, unless tried too often already. */
    public function retryOverdue(): void
    {
        foreach ($this->tasks as $key => $task) {
            $since = $this->previousRunAt($task);
            $runs = $this->runs->since($key, $since);
            $done = array_filter($runs, static fn (ScheduledTaskRun $run) => $run->getStatus() !== TaskRunStatus::Failed);
            if ($done !== [] || \count($runs) >= self::MAX_ATTEMPTS) {
                continue;
            }

            try {
                $this->run($key, TaskTrigger::Retry);
            } catch (ApiException) {
                // Running already: nothing to retry.
            }
        }
    }

    /** The last scheduled time at or before now. */
    public function previousRunAt(ScheduledTaskInterface $task): \DateTimeImmutable
    {
        $previous = (new CronExpression($task->cron()))->getPreviousRunDate($this->localNow(), 0, true);

        return \DateTimeImmutable::createFromInterface($previous)->setTimezone(new \DateTimeZone('UTC'));
    }

    /** The next scheduled time after now. */
    public function nextRunAt(ScheduledTaskInterface $task): \DateTimeImmutable
    {
        $next = (new CronExpression($task->cron()))->getNextRunDate($this->localNow());

        return \DateTimeImmutable::createFromInterface($next)->setTimezone(new \DateTimeZone('UTC'));
    }

    private function localNow(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone($this->timezone));
    }
}
