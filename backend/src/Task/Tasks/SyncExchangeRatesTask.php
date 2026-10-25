<?php

namespace App\Task\Tasks;

use App\Currency\Service\ExchangeRateService;
use App\Entity\TaskRunStatus;
use App\Repository\ExchangeRateRepository;
use App\Task\ScheduledTaskInterface;
use App\Task\TaskResult;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The exchange rates of the currencies somebody needs, through yesterday:
 * right after the prices, a few times a day since the ECB publishes late in
 * the afternoon and the last days are re-checked.
 */
#[AsTaggedItem(priority: 30)]
final class SyncExchangeRatesTask implements ScheduledTaskInterface
{
    public function __construct(
        private readonly ExchangeRateService $rates,
        private readonly ExchangeRateRepository $repository,
    ) {
    }

    public static function key(): string
    {
        return 'rates';
    }

    public function cron(): string
    {
        return '20 0,6,12 * * *';
    }

    public function run(): TaskResult
    {
        $needed = array_keys($this->repository->neededCurrencies());
        if ($needed === []) {
            return TaskResult::idle(['currencies' => [], 'unresolved' => []]);
        }
        $unresolved = $this->rates->sync();
        $details = ['currencies' => $needed, 'unresolved' => $unresolved];

        if ($unresolved === []) {
            return TaskResult::success($details);
        }

        // No provider answered for any of them: down, worth another try. Some only: they don't have those.
        return new TaskResult(\count($unresolved) === \count($needed) ? TaskRunStatus::Failed : TaskRunStatus::Partial, $details);
    }
}
