<?php

namespace App\Task;

use App\Admin\DTO\TaskResponse;
use App\Admin\DTO\TaskRunResponse;
use App\Admin\DTO\TasksOverviewResponse;
use App\Core\Pagination\PaginatedResponse;
use App\Core\Pagination\PaginationParams;
use App\Currency\Service\UserExchangeRates;
use App\Investment\Service\PriceCalendar;
use App\Repository\ExchangeRateRepository;
use App\Repository\InvestmentPriceRepository;
use App\Repository\ScheduledTaskRunRepository;
use Symfony\Component\Clock\ClockInterface;

/** What the administrator sees of the scheduled tasks and of the data they fetch. */
class TasksOverview
{
    /**
     * A market is closed at most a long weekend (Easter: Friday to Monday), so
     * the provider's newest close is never this many days before yesterday.
     */
    private const PRICE_STALE_AFTER_DAYS = 5;

    public function __construct(
        private readonly TaskRunner $runner,
        private readonly ScheduledTaskRunRepository $runs,
        private readonly InvestmentPriceRepository $prices,
        private readonly ExchangeRateRepository $rates,
        private readonly PriceCalendar $calendar,
        private readonly ClockInterface $clock,
    ) {
    }

    /** One page of a task's runs, newest first. */
    public function runs(string $key, PaginationParams $params): PaginatedResponse
    {
        $this->runner->get($key);
        $now = $this->clock->now();
        [$runs, $total] = $this->runs->page($key, $params);

        return PaginatedResponse::create(array_map(static fn ($run) => TaskRunResponse::of($run, $now), $runs), $total, $params);
    }

    public function build(): TasksOverviewResponse
    {
        $now = $this->clock->now();
        $tasks = [];
        foreach ($this->runner->tasks() as $key => $task) {
            $latest = $this->runs->latest($key, 1)[0] ?? null;
            $tasks[] = new TaskResponse(
                $key,
                $task->cron(),
                $this->runner->nextRunAt($task)->format(\DATE_ATOM),
                $this->runs->lastDoneAt($key)?->format(\DATE_ATOM),
                $latest !== null ? TaskRunResponse::of($latest, $now) : null,
            );
        }

        $yesterday = $this->calendar->yesterday();
        $priceStaleBefore = $yesterday->modify('-'.self::PRICE_STALE_AFTER_DAYS.' days')->format('Y-m-d');
        $symbols = array_map(static fn (array $row) => [...$row, 'status' => match (true) {
            $row['latestClose'] === null => 'missing',
            $row['latestClose'] < $priceStaleBefore => 'stale',
            default => 'ok',
        }], $this->prices->latestBySymbol());

        $rateStaleBefore = $yesterday->modify('-'.UserExchangeRates::STALE_AFTER_DAYS.' days')->format('Y-m-d');
        $currencies = [];
        foreach ($this->rates->latestShared(array_keys($this->rates->neededCurrencies())) as $currency => $row) {
            $currencies[] = ['currency' => $currency, ...$row, 'status' => match (true) {
                $row['latest'] === null => 'missing',
                $row['latest'] < $rateStaleBefore => 'stale',
                default => 'ok',
            }];
        }

        return new TasksOverviewResponse($this->calendar->timezone()->getName(), $tasks, $symbols, $currencies);
    }
}
