<?php

namespace App\Task\Tasks;

use App\Entity\TaskRunStatus;
use App\Recurring\Service\RecurringTransactionService;
use App\Task\ScheduledTaskInterface;
use App\Task\TaskResult;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Every quarter of an hour: the recurring transactions that create
 * themselves, once their day and time have come (catching up on any missed);
 * those the user enters, recognised as they arrive or reminded when late.
 */
#[AsTaggedItem(priority: 25)]
final class RecurringTransactionsTask implements ScheduledTaskInterface
{
    public function __construct(
        private readonly RecurringTransactionService $recurring,
    ) {
    }

    public static function key(): string
    {
        return 'recurring';
    }

    public function cron(): string
    {
        return '*/15 * * * *';
    }

    public function run(): TaskResult
    {
        $details = [...$this->recurring->createDue(), ...$this->recurring->followManual()];

        if ($details['failed'] > 0) {
            return new TaskResult(TaskRunStatus::Partial, $details);
        }

        return $details['created'] + $details['linked'] + $details['reminded'] === 0 ? TaskResult::idle($details) : TaskResult::success($details);
    }
}
