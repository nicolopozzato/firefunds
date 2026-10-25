<?php

namespace App\Task\Tasks;

use App\Entity\TaskRunStatus;
use App\Investment\Service\InvestmentPriceService;
use App\Task\ScheduledTaskInterface;
use App\Task\TaskResult;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The instruments' daily closes, through yesterday. Shortly after local
 * midnight (APP_TIMEZONE, the same timezone PriceCalendar ends "yesterday" in)
 * so the day that just ended is recorded as soon as possible, then a few more
 * times during the day in case a market closing late in another timezone
 * wasn't done publishing its close. The last days are always re-checked, and
 * any day missed entirely is backfilled.
 */
#[AsTaggedItem(priority: 40)]
final class FetchPricesTask implements ScheduledTaskInterface
{
    public function __construct(private readonly InvestmentPriceService $prices)
    {
    }

    public static function key(): string
    {
        return 'prices';
    }

    public function cron(): string
    {
        return '10 0,4,8,12 * * *';
    }

    public function run(): TaskResult
    {
        $outcome = $this->prices->updateAllInstruments();
        $notUpdated = \count($outcome['empty']) + \count($outcome['failed']);
        $details = ['symbols' => $outcome['symbols'], 'empty' => $outcome['empty'], 'failed' => array_keys($outcome['failed'])];

        if ($outcome['symbols'] === 0) {
            return TaskResult::idle($details);
        }
        if ($notUpdated === 0) {
            return TaskResult::success($details);
        }
        $errors = array_map(static fn (string $symbol, string $error) => "{$symbol}: {$error}", array_keys($outcome['failed']), $outcome['failed']);

        // Not a single symbol: the provider is down (or the database), worth another try.
        return new TaskResult(
            $notUpdated === $outcome['symbols'] ? TaskRunStatus::Failed : TaskRunStatus::Partial,
            $details,
            $errors !== [] ? implode("\n", $errors) : null,
        );
    }
}
