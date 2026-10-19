<?php

namespace App\Dashboard\Service;

use App\Account\Service\AssetValueHistory;
use App\Core\Period\Days;
use App\Core\Period\UserDayRange;
use App\Dashboard\DTO\DashboardAccountResponse;
use App\Dashboard\DTO\DashboardInstrumentResponse;
use App\Dashboard\DTO\DashboardResponse;
use App\Dashboard\DTO\DashboardSummaryResponse;
use App\Dashboard\DTO\DatedValueResponse;
use App\Entity\Account;
use App\Entity\InvestmentTransactionType;
use App\Entity\User;
use App\Repository\InvestmentTransactionRepository;
use App\Repository\TransactionRepository;
use App\Transaction\DTO\DistributionEntryResponse;
use App\Transaction\DTO\TransactionResponse;

/**
 * The dashboard for one period, modelled on Firefly III's home page: the
 * period's income, expenses and net worth over every account, then the
 * accounts the user chose to show (balance line, latest transactions) and
 * how each instrument held went.
 *
 * Days are the user's calendar days. Values "at the end of the period" are
 * taken on its last day even when that is still to come (e.g. the current
 * month), so transactions dated later in the period count. Runs a fixed
 * number of queries whatever the number of accounts or instruments.
 */
class DashboardService
{
    private const int LATEST_TRANSACTIONS = 5;
    /** Most points per line: a long period (years) gets one point every few days instead of one a day. */
    private const int MAX_POINTS = 400;

    public function __construct(
        private readonly AssetValueHistory $assetValueHistory,
        private readonly TransactionRepository $transactionRepository,
        private readonly InvestmentTransactionRepository $investmentTransactionRepository,
    ) {
    }

    public function forUser(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): DashboardResponse
    {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        // The period starts where the day before it ends.
        $dayBefore = $from->modify('-1 day');
        $startDay = $dayBefore->format('Y-m-d');
        $firstDay = $from->format('Y-m-d');
        $lastDay = $to->format('Y-m-d');

        $values = $this->assetValueHistory->forUser($user, $dayBefore, $to);
        $assetAccounts = $values->accounts;
        $trades = $values->trades;
        $prices = $values->prices;
        $valueByInstrument = $values->valueByInstrument;
        $valueOn = $values->valueOn(...);

        [$periodStart, $periodEnd] = UserDayRange::bounds($user, $from, $to);
        $expensesByCategory = $this->toEntries($this->transactionRepository->sumByCategory($user, 'withdrawal', $periodStart, $periodEnd));
        $expenseAccounts = $this->toEntries($this->transactionRepository->sumByDestinationAccount($user, 'withdrawal', $periodStart, $periodEnd));
        $revenueAccounts = $this->toEntries($this->transactionRepository->sumBySourceAccount($user, 'deposit', $periodStart, $periodEnd));

        $income = $this->total($revenueAccounts);
        $expenses = $this->total($expenseAccounts);
        $balance = bcsub($income, $expenses, 4);

        $netWorthStart = $values->netWorthOn($startDay);
        $netWorthEnd = $values->netWorthOn($lastDay);
        $netWorthChange = bcsub($netWorthEnd, $netWorthStart, 4);

        $contributionsByInstrument = $this->contributions($trades, $firstDay, $lastDay);
        $investmentsStart = $values->investmentsOn($startDay);
        $investmentsEnd = $values->investmentsOn($lastDay);
        $investmentContributions = array_reduce($contributionsByInstrument, static fn (string $sum, string $c) => bcadd($sum, $c, 4), '0.0000');

        $visibleAccounts = array_values(array_filter(
            $assetAccounts,
            static fn (Account $account) => $account->isActive() && $account->isShownOnDashboard(),
        ));
        $latestTransactions = $this->latestTransactions($visibleAccounts, $periodStart, $periodEnd);
        $days = Days::between($from, $to);
        $chartDays = Days::sample($days, self::MAX_POINTS);

        return new DashboardResponse(
            currencySymbol: ($assetAccounts[0] ?? null)?->getCurrency()?->getSymbol(),
            summary: new DashboardSummaryResponse(
                income: $income,
                expenses: $expenses,
                balance: $balance,
                savingsRate: $this->percentage($balance, $income),
                netWorthStart: $netWorthStart,
                netWorthEnd: $netWorthEnd,
                netWorthChange: $netWorthChange,
                netWorthChangePercent: $this->percentage($netWorthChange, $netWorthStart),
                investmentsStart: $investmentsStart,
                investmentsEnd: $investmentsEnd,
                investmentContributions: $investmentContributions,
                investmentGain: bcsub(bcsub($investmentsEnd, $investmentsStart, 4), $investmentContributions, 4),
            ),
            accounts: array_map(
                function (Account $account) use ($valueOn, $chartDays, $lastDay, $latestTransactions) {
                    $id = $account->getId()->toRfc4122();

                    return new DashboardAccountResponse(
                        id: $id,
                        name: $account->getName(),
                        assetKind: $account->getAssetKind()?->value,
                        currencySymbol: $account->getCurrency()?->getSymbol(),
                        balance: $valueOn($id, $lastDay),
                        history: array_map(static fn (string $day) => new DatedValueResponse($day, $valueOn($id, $day)), $chartDays),
                        transactions: $latestTransactions[$id] ?? [],
                    );
                },
                $visibleAccounts
            ),
            expensesByCategory: $expensesByCategory,
            expenseAccounts: $expenseAccounts,
            revenueAccounts: $revenueAccounts,
            instruments: $this->instruments($trades, $valueByInstrument, $contributionsByInstrument, $prices, $startDay, $days, $chartDays),
        );
    }

    /**
     * Money put into each instrument over [firstDay, lastDay]: what the BUYs
     * cost, fees and taxes included, minus what the SELLs brought back, net
     * of them. Only instruments traded in the period.
     *
     * @param array<array{instrument_id: string, type: string, amount: string, costs: string, day: string}> $trades
     * @return array<string, string> instrument id => contributions
     */
    private function contributions(array $trades, string $firstDay, string $lastDay): array
    {
        $byInstrument = [];
        foreach ($trades as $trade) {
            if ($trade['day'] < $firstDay || $trade['day'] > $lastDay) {
                continue;
            }

            $flow = $trade['type'] === InvestmentTransactionType::Buy->value
                ? bcadd($trade['amount'], $trade['costs'], 4)
                : bcsub('0', bcsub($trade['amount'], $trade['costs'], 4), 4);
            $byInstrument[$trade['instrument_id']] = bcadd($byInstrument[$trade['instrument_id']] ?? '0', $flow, 4);
        }

        return $byInstrument;
    }

    /**
     * Every instrument held at some point in the period or traded in it.
     *
     * @param array<array{instrument_id: string, instrument_name: string, instrument_ticker: ?string}> $trades
     * @param array<string, array<string, string>> $valueByInstrument
     * @param array<string, string> $contributionsByInstrument
     * @param array<string, array<string, string>> $prices
     * @param string[] $days the period's days
     * @param string[] $chartDays the days the performance line has a point on
     * @return DashboardInstrumentResponse[]
     */
    private function instruments(array $trades, array $valueByInstrument, array $contributionsByInstrument, array $prices, string $startDay, array $days, array $chartDays): array
    {
        $lastDay = $days[\count($days) - 1];
        $names = [];
        foreach ($trades as $trade) {
            $names[$trade['instrument_id']] ??= [$trade['instrument_name'], $trade['instrument_ticker']];
        }

        $instruments = [];
        foreach ($valueByInstrument as $instrumentId => $values) {
            $wasHeld = array_filter($values, static fn (string $value) => bccomp($value, '0', 4) !== 0) !== [];
            if (!$wasHeld && !isset($contributionsByInstrument[$instrumentId])) {
                continue;
            }

            $valueStart = $values[$startDay] ?? '0.0000';
            $valueEnd = $values[$lastDay] ?? '0.0000';
            $contributions = bcadd($contributionsByInstrument[$instrumentId] ?? '0', '0', 4);
            $performance = $this->performance($prices[$instrumentId] ?? [], $startDay, $days, array_flip($chartDays));

            $instruments[] = new DashboardInstrumentResponse(
                instrumentId: $instrumentId,
                instrumentName: $names[$instrumentId][0],
                instrumentTicker: $names[$instrumentId][1],
                valueStart: $valueStart,
                valueEnd: $valueEnd,
                contributions: $contributions,
                gain: bcsub(bcsub($valueEnd, $valueStart, 4), $contributions, 4),
                values: array_map(static fn (string $day) => new DatedValueResponse($day, $values[$day] ?? '0.0000'), $chartDays),
                priceChangePercent: $performance !== [] ? $performance[\count($performance) - 1]->value : null,
                performance: $performance,
            );
        }

        usort($instruments, static fn (DashboardInstrumentResponse $a, DashboardInstrumentResponse $b) => bccomp($b->valueEnd, $a->valueEnd, 4));

        return $instruments;
    }

    /**
     * The price's change day by day, in percent of its price when the period
     * starts (or of its first price in the period, if it had none before).
     * Prices carry over days with no quote (weekends, holidays).
     *
     * @param array<string, string> $priceByDate Y-m-d => price, oldest first, possibly starting before the period
     * @param string[] $days
     * @param array<string, int> $chartDays the days to return a point for (as keys)
     * @return DatedValueResponse[]
     */
    private function performance(array $priceByDate, string $startDay, array $days, array $chartDays): array
    {
        $price = null;
        foreach ($priceByDate as $date => $value) {
            if ($date > $startDay) {
                break;
            }
            $price = $value;
        }

        $base = $price;
        $points = [];
        foreach ($days as $day) {
            $price = $priceByDate[$day] ?? $price;
            if ($price === null || bccomp($price, '0', 10) <= 0) {
                continue;
            }
            $base ??= $price;
            if (isset($chartDays[$day])) {
                $points[] = new DatedValueResponse($day, bcmul(bcsub(bcdiv($price, $base, 10), '1', 10), '100', 4));
            }
        }

        return $points;
    }

    /**
     * The latest transactions of each account, loaded in one query per kind.
     *
     * @param Account[] $accounts
     * @return array<string, TransactionResponse[]> account id => newest first
     */
    private function latestTransactions(array $accounts, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): array
    {
        $idsByAccount = $this->transactionRepository->findLatestIdsPerAccount($accounts, $periodStart, $periodEnd, self::LATEST_TRANSACTIONS);

        $moneyIds = [];
        $investmentIds = [];
        foreach ($idsByAccount as $rows) {
            foreach ($rows as [$id, $source]) {
                if ($source === 'money') {
                    $moneyIds[$id] = $id;
                } else {
                    $investmentIds[$id] = $id;
                }
            }
        }

        $responses = [];
        foreach ($this->transactionRepository->findWithRelationsByIds(array_values($moneyIds)) as $transaction) {
            $responses[(string) $transaction->getId()] = TransactionResponse::fromEntity($transaction);
        }
        foreach ($this->investmentTransactionRepository->findWithRelationsByIds(array_values($investmentIds)) as $transaction) {
            $responses[(string) $transaction->getId()] = TransactionResponse::fromInvestmentEntity($transaction);
        }

        return array_map(
            static fn (array $rows) => array_map(static fn (array $row) => $responses[$row[0]], $rows),
            $idsByAccount
        );
    }

    /**
     * @param array<array{id: ?string, name: ?string, total: string}> $rows
     * @return DistributionEntryResponse[]
     */
    private function toEntries(array $rows): array
    {
        return array_map(
            static fn (array $row) => new DistributionEntryResponse(id: $row['id'], name: $row['name'], total: $row['total']),
            $rows
        );
    }

    /** @param DistributionEntryResponse[] $entries */
    private function total(array $entries): string
    {
        return array_reduce($entries, static fn (string $sum, DistributionEntryResponse $entry) => bcadd($sum, $entry->total, 4), '0.0000');
    }

    /** part as a percentage of whole; null unless whole is positive. */
    private function percentage(string $part, string $whole): ?string
    {
        if (bccomp($whole, '0', 10) <= 0) {
            return null;
        }

        return bcmul(bcdiv($part, $whole, 10), '100', 4);
    }
}
