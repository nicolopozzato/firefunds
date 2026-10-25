<?php

namespace App;

use App\Task\Message\RetryOverdueTasks;
use App\Task\Message\RunScheduledTask;
use App\Task\ScheduledTaskInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Every scheduled task (App\Task\ScheduledTaskInterface) at its own times, in
 * APP_TIMEZONE, plus the retry of the runs that failed or never happened, at
 * minutes no task uses so a retry never races the run it would repeat.
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    /**
     * @param iterable<ScheduledTaskInterface> $tasks
     */
    public function __construct(
        private CacheInterface $cache,
        #[Autowire(env: 'APP_TIMEZONE')]
        private string $timezone,
        #[AutowireIterator('app.scheduled_task')]
        private iterable $tasks,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        $schedule = (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
            ->add(RecurringMessage::cron('7,22,37,52 * * * *', new RetryOverdueTasks(), $this->timezone));

        foreach ($this->tasks as $task) {
            $schedule->add(RecurringMessage::cron($task->cron(), new RunScheduledTask($task::key()), $this->timezone));
        }

        return $schedule;
    }
}
