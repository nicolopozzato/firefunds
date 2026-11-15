<?php

namespace App\Report\Service;

use App\Account\Service\AssetValueHistory;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\RecurringTransaction;
use App\Entity\User;
use App\Recurring\Service\RecurringTransactionService;
use App\Report\DTO\CashFlowAccountResponse;
use App\Report\DTO\CashFlowItemResponse;
use App\Report\DTO\CashFlowPeriodResponse;
use App\Report\DTO\CashFlowReportResponse;
use App\Repository\TransactionRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * The cash flow report: the asset accounts' cash from today's balance on, a
 * column per day, week or month, and the net worth it makes with what is
 * held (at today's prices: no return is assumed). Three layers, each optional:
 * - entered: money transactions already written for the days to come;
 * - recurring: the occurrences the recurrences will still make (a recurring
 *   purchase takes its amount and fee out of its investment account's cash,
 *   and adds the amount to what it holds: the fee is what the net worth loses);
 * - estimated: the rest of the income and expenses, per account and
 *   category, from the mean of the latest complete months (optionally
 *   weighed with the same month a year earlier), leaving out what
 *   recurrences made or look like (the same two accounts): they are
 *   forecast one by one. A month expects what
 *   the mean says beyond what is already there (what happened so far this
 *   month, what is entered for later), from tomorrow.
 * An estimate is spread evenly over its month's days.
 */
class CashFlowReportService
{
    public const BY_DAY = 'day';
    public const BY_WEEK = 'week';
    public const BY_MONTH = 'month';

    public const ENTERED = 'entered';
    public const RECURRING = 'recurring';
    public const ESTIMATED = 'estimated';
    public const LAYERS = [self::ENTERED, self::RECURRING, self::ESTIMATED];

    /** The finer columns only that far ahead (in months): a year of days is no chart. */
    public const MAX_MONTHS = [self::BY_DAY => 3, self::BY_WEEK => 12, self::BY_MONTH => 24];

    private const SCALE = 8;

    public function __construct(
        private readonly AssetValueHistory $assetValueHistory,
        private readonly TransactionRepository $transactionRepository,
        private readonly RecurringTransactionService $recurringService,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $layers
     */
    public function forUser(User $user, int $months, string $by, int $average, int $seasonal, array $layers): CashFlowReportResponse
    {
        $today = new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($user->getTimezone()))->format('Y-m-d'));
        $periods = $this->periods($today, $this->periodEnd($today->modify("+{$months} months"), $by), $by);
        $end = end($periods)[1];
        $todayKey = $today->format('Y-m-d');

        $values = $this->assetValueHistory->forUser($user, $today, $today);
        /** @var array<string, Account> $accounts */
        $accounts = [];
        foreach ($values->accounts as $account) {
            $accounts[$account->getId()->toRfc4122()] = $account;
        }
        $toUser = fn (string $amount, string $accountId) => $values->inUserCurrency($amount, $values->currencyByAccount[$accountId] ?? null, $todayKey);

        $index = $this->periodIndex($periods);
        $flow = static fn () => [self::ENTERED => '0', self::RECURRING => '0', self::ESTIMATED => '0'];
        $columns = array_map(static fn () => ['income' => $flow(), 'expense' => $flow(), 'invested' => $flow(), 'items' => [], 'deltas' => [], 'bought' => []], $periods);

        // One movement of an account's cash, in its currency; what it counts as in the column (in the user's).
        $move = function (int $column, string $accountId, string $amount) use (&$columns): void {
            $columns[$column]['deltas'][$accountId] = bcadd($columns[$column]['deltas'][$accountId] ?? '0', $amount, self::SCALE);
        };
        $count = function (int $column, string $kind, string $layer, string $amount) use (&$columns): void {
            if ($kind !== 'transfer') {
                $columns[$column][$kind][$layer] = bcadd($columns[$column][$kind][$layer], $amount, self::SCALE);
            }
        };

        if (\in_array(self::ENTERED, $layers, true)) {
            foreach ($this->transactionRepository->findPlanned($user, $today, $end) as $transaction) {
                $from = $this->assetId($transaction->getSourceAccount(), $accounts);
                $to = $this->assetId($transaction->getDestinationAccount(), $accounts);
                $column = $index[$transaction->getOccurredAt()->format('Y-m-d')] ?? null;
                if ($column === null || ($from === null && $to === null)) {
                    continue;
                }
                $arriving = $transaction->getDestinationAmount() ?? $transaction->getAmount();
                $kind = $this->kind($from, $to);
                $from !== null && $move($column, $from, bcsub('0', $transaction->getAmount(), 4));
                $to !== null && $move($column, $to, $arriving);
                $amount = $from !== null ? $toUser($transaction->getAmount(), $from) : $toUser($arriving, (string) $to);
                $count($column, $kind, self::ENTERED, $amount);
                $columns[$column]['items'][] = new CashFlowItemResponse(
                    source: self::ENTERED,
                    flow: $kind,
                    amount: bcround($amount, 4),
                    label: $transaction->getDescription() ?? $transaction->getCategory()?->getName(),
                    day: $transaction->getOccurredAt()->format('Y-m-d'),
                    accountName: $accounts[$from ?? $to]->getName(),
                    toAccountName: $kind === 'transfer' ? $accounts[(string) $to]->getName() : null,
                    transactionId: $transaction->getId()->toRfc4122(),
                );
            }
        }

        if (\in_array(self::RECURRING, $layers, true)) {
            foreach ($this->recurringService->expected($user, $end) as ['recurring' => $recurring, 'day' => $day, 'amount' => $amount]) {
                $column = $index[$day->format('Y-m-d')] ?? null;
                $from = $this->assetId($recurring->getSourceAccount(), $accounts);
                $to = $recurring->isPurchase() ? null : $this->assetId($recurring->getDestinationAccount(), $accounts);
                if ($column === null || ($from === null && $to === null)) {
                    continue;
                }
                $arriving = $recurring->getDestinationAmount() ?? $amount;
                $kind = $recurring->isPurchase() ? 'invested' : $this->kind($from, $to);
                $from !== null && $move($column, $from, bcsub('0', $amount, 4));
                $to !== null && $move($column, $to, $arriving);
                if ($recurring->isPurchase() && $from !== null) {
                    $columns[$column]['bought'][$from] = bcadd($columns[$column]['bought'][$from] ?? '0', bcsub($amount, $recurring->getFees() ?? '0', 4), self::SCALE);
                }
                $inUser = $from !== null ? $toUser($amount, $from) : $toUser($arriving, (string) $to);
                $count($column, $kind, self::RECURRING, $inUser);
                $columns[$column]['items'][] = $this->recurringItem($recurring, $kind, $inUser, $day, $accounts[$from ?? $to], $kind === 'transfer' ? $accounts[(string) $to] : null);
            }
        }

        $estimateMonths = 0;
        if (\in_array(self::ESTIMATED, $layers, true)) {
            $estimateMonths = $this->estimate($user, $today, $end, $average, $seasonal, $index, $accounts, $toUser, $move, $count, $columns);
        }

        // Cash and value (cash plus what is held) at each column's end, from today's.
        $balances = [];
        $worth = [];
        $opening = [];
        $openingValue = [];
        foreach ($accounts as $id => $account) {
            $balance = $opening[$id] = $values->cashOn($id, $todayKey);
            $held = $values->investedOn($id, $todayKey);
            $openingValue[$id] = bcadd($balance, $held, 4);
            foreach ($columns as $i => $column) {
                $balance = bcadd($balance, $column['deltas'][$id] ?? '0', self::SCALE);
                $held = bcadd($held, $column['bought'][$id] ?? '0', self::SCALE);
                $balances[$id][$i] = $balance;
                $worth[$id][$i] = bcadd($balance, $held, self::SCALE);
            }
        }

        $round = static fn (array $layers) => array_map(static fn (string $amount) => bcround($amount, 4), $layers);
        $responses = [];
        foreach ($periods as $i => [$start, $periodEnd]) {
            $total = '0';
            $netWorth = '0';
            foreach ($accounts as $id => $account) {
                $total = bcadd($total, $toUser($balances[$id][$i], $id), self::SCALE);
                $netWorth = bcadd($netWorth, $toUser($worth[$id][$i], $id), self::SCALE);
            }
            $items = $columns[$i]['items'];
            // By day, the estimates (spread over the column) last; the larger first.
            usort($items, static fn (CashFlowItemResponse $a, CashFlowItemResponse $b) => ($a->day ?? '9') <=> ($b->day ?? '9') ?: bccomp($b->amount, $a->amount, 4));
            $responses[] = new CashFlowPeriodResponse(
                start: $start->format('Y-m-d'),
                end: $periodEnd->format('Y-m-d'),
                income: $round($columns[$i]['income']),
                expense: $round($columns[$i]['expense']),
                invested: $round($columns[$i]['invested']),
                balance: bcround($total, 4),
                netWorth: bcround($netWorth, 4),
                items: $items,
            );
        }

        $openingTotal = '0';
        $openingNetWorth = '0';
        $accountResponses = [];
        foreach ($accounts as $id => $account) {
            $openingTotal = bcadd($openingTotal, $toUser($opening[$id], $id), 4);
            $openingNetWorth = bcadd($openingNetWorth, $toUser($openingValue[$id], $id), 4);
            $line = array_map(fn (string $balance) => bcround($toUser($balance, $id), 4), $balances[$id] ?? []);
            $valueLine = array_map(fn (string $value) => bcround($toUser($value, $id), 4), $worth[$id] ?? []);
            // Nothing in it and nothing expected: an account not in use (one closed, one never used).
            if (bccomp($openingValue[$id], '0', 4) === 0 && array_filter($valueLine, static fn (string $value) => bccomp($value, '0', 4) !== 0) === []) {
                continue;
            }
            $own = $values->currencyByAccount[$id] ?? null;
            $accountResponses[] = new CashFlowAccountResponse(
                id: $id,
                name: $account->getName(),
                currencyCode: $own !== $values->currency ? $own : null,
                opening: bcround($toUser($opening[$id], $id), 4),
                balances: $line,
                openingValue: bcround($toUser($openingValue[$id], $id), 4),
                values: $valueLine,
            );
        }

        return new CashFlowReportResponse(
            currency: $values->currency,
            today: $todayKey,
            by: $by,
            opening: $openingTotal,
            openingNetWorth: $openingNetWorth,
            periods: $responses,
            accounts: $accountResponses,
            estimateMonths: $estimateMonths,
        );
    }

    /**
     * The estimate layer, added to the columns. Returns how many past months
     * its mean is made of (0: not enough history, nothing estimated).
     *
     * @param array<string, int> $index
     * @param array<string, Account> $accounts
     * @param array<int, array<string, mixed>> $columns
     */
    private function estimate(User $user, \DateTimeImmutable $today, \DateTimeImmutable $end, int $average, int $seasonal, array $index, array $accounts, callable $toUser, callable $move, callable $count, array &$columns): int
    {
        [$earliest] = $this->transactionRepository->findDateRange($user);
        if ($earliest === null) {
            return 0;
        }
        $currentMonth = $today->modify('first day of this month');
        // A month is complete from the first one started from its first day.
        $firstComplete = $earliest->format('d') === '01' ? $earliest : $earliest->modify('first day of next month');
        $meanFrom = max($currentMonth->modify("-{$average} months"), $firstComplete);
        $meanMonths = $this->monthsBetween($meanFrom, $currentMonth);
        if ($meanMonths === 0) {
            return 0;
        }

        // Through the end: what is entered for the months to come is part of what they expect.
        $rows = $this->transactionRepository->sumUnplannedFlowsByAccountAndMonth($user, min($meanFrom, $currentMonth->modify('-12 months')), $end);
        /** @var array<string, array{account: string, flow: string, category: ?string, name: ?string, months: array<string, string>}> $series */
        $series = [];
        foreach ($rows as $row) {
            if (!isset($accounts[$row['account_id']])) {
                continue;
            }
            $key = $row['account_id'].'|'.$row['flow'].'|'.$row['category_id'];
            $series[$key] ??= ['account' => $row['account_id'], 'flow' => $row['flow'], 'category' => $row['category_id'], 'name' => $row['category_name'], 'months' => []];
            $series[$key]['months'][$row['month']] = $row['total'];
        }

        $weight = bcdiv((string) $seasonal, '100', 4);
        $meanFromKey = $meanFrom->format('Y-m');
        $currentKey = $currentMonth->format('Y-m');
        $firstCompleteKey = $firstComplete->format('Y-m');
        foreach ($series as &$one) {
            $sum = '0';
            foreach ($one['months'] as $month => $total) {
                if ($month >= $meanFromKey && $month < $currentKey) {
                    $sum = bcadd($sum, $total, 4);
                }
            }
            $one['mean'] = bcdiv($sum, (string) $meanMonths, self::SCALE);
        }
        unset($one);

        // What a month is expected to bring: the mean, weighed with the same month of the latest year gone (when there is one).
        $expected = function (array $one, \DateTimeImmutable $month) use ($weight, $currentKey, $firstCompleteKey): string {
            if (bccomp($weight, '0', 4) === 0) {
                return $one['mean'];
            }
            $sameMonth = $month;
            while ($sameMonth->format('Y-m') >= $currentKey) {
                $sameMonth = $sameMonth->modify('-1 year');
            }
            if ($sameMonth->format('Y-m') < $firstCompleteKey) {
                return $one['mean'];
            }
            $last = $one['months'][$sameMonth->format('Y-m')] ?? '0';

            return bcadd(bcmul($one['mean'], bcsub('1', $weight, 4), self::SCALE), bcmul($last, $weight, self::SCALE), self::SCALE);
        };

        // Per series, what each column gets: what the month still expects, spread over its days from tomorrow.
        $byColumn = [];
        $daily = [];
        for ($day = $today->modify('+1 day'); $day <= $end; $day = $day->modify('+1 day')) {
            $month = $day->modify('first day of this month');
            $monthKey = $month->format('Y-m');
            foreach ($series as $key => $one) {
                if (!isset($daily[$key][$monthKey])) {
                    $amount = bcsub($expected($one, $month), $one['months'][$monthKey] ?? '0', self::SCALE);
                    $days = (int) $month->format('t') - ($monthKey === $currentKey ? (int) $today->format('j') : 0);
                    $daily[$key][$monthKey] = bccomp($amount, '0', self::SCALE) > 0 && $days > 0 ? bcdiv($amount, (string) $days, self::SCALE) : '0';
                }
                $column = $index[$day->format('Y-m-d')];
                $byColumn[$column][$key] = bcadd($byColumn[$column][$key] ?? '0', $daily[$key][$monthKey], self::SCALE);
            }
        }

        foreach ($byColumn as $column => $amounts) {
            $items = [];
            foreach ($amounts as $key => $amount) {
                if (bccomp($amount, '0', 4) === 0) {
                    continue;
                }
                $one = $series[$key];
                $move($column, $one['account'], $one['flow'] === 'expense' ? bcsub('0', $amount, self::SCALE) : $amount);
                $inUser = $toUser($amount, $one['account']);
                $count($column, $one['flow'], self::ESTIMATED, $inUser);
                // One line per category, every account together.
                $itemKey = $one['flow'].'|'.$one['category'];
                $items[$itemKey] ??= ['flow' => $one['flow'], 'category' => $one['category'], 'name' => $one['name'], 'amount' => '0'];
                $items[$itemKey]['amount'] = bcadd($items[$itemKey]['amount'], $inUser, self::SCALE);
            }
            foreach ($items as $item) {
                $columns[$column]['items'][] = new CashFlowItemResponse(
                    source: self::ESTIMATED,
                    flow: $item['flow'],
                    amount: bcround($item['amount'], 4),
                    label: $item['name'],
                    categoryId: $item['category'],
                );
            }
        }

        return $meanMonths;
    }

    /**
     * The columns from today through `end`: the first starts today, the
     * others on a Monday or a month's first day.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function periods(\DateTimeImmutable $today, \DateTimeImmutable $end, string $by): array
    {
        $periods = [];
        for ($start = $today; $start <= $end; $start = $periodEnd->modify('+1 day')) {
            $periodEnd = min($this->periodEnd($start, $by), $end);
            $periods[] = [$start, $periodEnd];
        }

        return $periods;
    }

    private function periodEnd(\DateTimeImmutable $day, string $by): \DateTimeImmutable
    {
        return match ($by) {
            self::BY_DAY => $day,
            // ISO weeks: 'sunday this week' is the Sunday after this week's Monday.
            self::BY_WEEK => $day->modify('sunday this week'),
            default => $day->modify('last day of this month'),
        };
    }

    /**
     * Each day of the report => its column.
     *
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $periods
     *
     * @return array<string, int>
     */
    private function periodIndex(array $periods): array
    {
        $index = [];
        foreach ($periods as $i => [$start, $end]) {
            for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
                $index[$day->format('Y-m-d')] = $i;
            }
        }

        return $index;
    }

    /** Whole months from one month's first day to another's. */
    private function monthsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ($from >= $to) {
            return 0;
        }
        $diff = $from->diff($to);

        return $diff->y * 12 + $diff->m;
    }

    /**
     * The account's id when it is one of the asset accounts the report follows.
     *
     * @param array<string, Account> $accounts
     */
    private function assetId(?Account $account, array $accounts): ?string
    {
        if ($account === null || $account->getType() !== AccountType::Asset) {
            return null;
        }
        $id = $account->getId()->toRfc4122();

        return isset($accounts[$id]) ? $id : null;
    }

    private function kind(?string $from, ?string $to): string
    {
        return match (true) {
            $from !== null && $to !== null => 'transfer',
            $from !== null => 'expense',
            default => 'income',
        };
    }

    private function recurringItem(RecurringTransaction $recurring, string $kind, string $amount, \DateTimeImmutable $day, Account $account, ?Account $to): CashFlowItemResponse
    {
        return new CashFlowItemResponse(
            source: self::RECURRING,
            flow: $kind,
            amount: bcround($amount, 4),
            label: $recurring->getDescription(),
            day: $day->format('Y-m-d'),
            accountName: $account->getName(),
            toAccountName: $to?->getName(),
            recurringId: $recurring->getId()->toRfc4122(),
        );
    }
}
