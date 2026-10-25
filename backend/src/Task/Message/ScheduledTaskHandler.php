<?php

namespace App\Task\Message;

use App\Entity\TaskTrigger;
use App\Task\TaskRunner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class ScheduledTaskHandler
{
    public function __construct(private readonly TaskRunner $runner)
    {
    }

    #[AsMessageHandler]
    public function run(RunScheduledTask $message): void
    {
        $this->runner->run($message->task, TaskTrigger::Schedule);
    }

    #[AsMessageHandler]
    public function retry(RetryOverdueTasks $message): void
    {
        $this->runner->retryOverdue();
    }
}
