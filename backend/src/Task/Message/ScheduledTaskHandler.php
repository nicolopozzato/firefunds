<?php

namespace App\Task\Message;

use App\Entity\TaskTrigger;
use App\Notification\Push\PushDelivery;
use App\Notification\Service\SystemHealth;
use App\Task\TaskRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class ScheduledTaskHandler
{
    public function __construct(
        private readonly TaskRunner $runner,
        private readonly SystemHealth $health,
        private readonly PushDelivery $push,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function run(RunScheduledTask $message): void
    {
        $this->runner->run($message->task, TaskTrigger::Schedule);
        $this->afterRun();
    }

    #[AsMessageHandler]
    public function retry(RetryOverdueTasks $message): void
    {
        $this->runner->retryOverdue();
        $this->afterRun();
    }

    /**
     * What the run may have changed for the users: the server's alerts, then
     * the notifications due on their devices (alerts now, reminders at the
     * summary time). Never a reason to fail the run itself.
     */
    private function afterRun(): void
    {
        try {
            $this->health->check();
            $this->push->deliver();
        } catch (\Throwable $e) {
            $this->logger->error('Notifications after a scheduled run failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
