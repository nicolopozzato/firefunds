<?php

namespace App\Report\Service;

use App\Account\Service\AssetValueHistory;
use App\Account\Service\AssetValues;
use App\Core\Period\Days;
use App\Core\Period\UserDayRange;
use App\Dashboard\DTO\DatedValueResponse;
use App\Entity\AssetAccountKind;
use App\Entity\User;
use App\Report\DTO\CategoryRowResponse;
use App\Report\DTO\CategoryTableResponse;
use App\Report\DTO\IncomeExpenseComparisonResponse;
use App\Report\DTO\IncomeExpenseReportResponse;
use App\Report\DTO\IncomeExpenseSummaryResponse;
use App\Report\DTO\ReportAccountResponse;
use App\Report\DTO\ReportPeriodResponse;
use App\Report\Period\ReportColumn;
use App\Report\Period\ReportRange;
use App\Repository\CurrencyRepository;
use App\Repository\TransactionRepository;

/**
 * The income and expenses report over any range, one column per month or
 * per year, optionally next to another range (the one right before, or the
 * same one a year earlier).
 *
 * Income and expenses are those of the dashboard, so the two always agree.
 * Money moved to an investment account is not an expense: it is reported
 * apart, as "invested". Values "at the end" are taken on the range's last
 * day even when that is still to come, as on the dashboard. Runs a fixed
 * number of queries whatever the range, the accounts or the categories.
 */
class IncomeExpenseReportService
{
    /** Most points of the net worth line. */
    private const int MAX_POINTS = 400;

    public function __construct(
        private readonly AssetValueHistory $assetValueHistory,
        private readonly TransactionRepository $transactionRepository,
        private readonly CurrencyRepository $currencies,
    ) {
    }

    public function forUser(User $user, ReportRange $range, string $by, ?string $compare, ?\DateTimeImmutable $today = null): IncomeExpenseReportResponse
    {
        $today ??= new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone()));
        $comparisonRange = $compare !== null ? $range->comparison($compare) : null;

        // One history covering both ranges (and whatever lies between them).
        $values = $this->assetValueHistory->forUser(
            $user,
            min($range->dayBefore(), $comparisonRange?->dayBefore() ?? $range->dayBefore()),
            $range->to,
        );

        $columns = $range->columns($by);
        $flows = $this->flows($user, $range);
        $periods = $this->periods($columns, $flows);
        $summary = $this->summary($range, $periods, $values, $today);

        $comparison = null;
        $comparisonFlows = null;
        $comparisonColumns = null;
        if ($comparisonRange !== null) {
            $comparisonColumns = $comparisonRange->columns($by);
            $comparisonFlows = $this->flows($user, $comparisonRange);
            $comparisonPeriods = $this->periods($comparisonColumns, $comparisonFlows);
            $comparison = new IncomeExpenseComparisonResponse(
                from: $comparisonRange->from->format('Y-m-d'),
                to: $comparisonRange->to->format('Y-m-d'),
                periods: $comparisonPeriods,
                summary: $this->summary($comparisonRange, $comparisonPeriods, $values, $today),
            );
        }

        $categoryTable = fn (string $flow) => $this->categoryTable(
            $flow,
            $columns,
            $flows,
            $summary->months,
            $comparisonColumns,
            $comparisonFlows,
        );

        return new IncomeExpenseReportResponse(
            // Everything added up is in the user's currency.
            currencySymbol: $this->currencies->symbolOf($user->getCurrency()),
            from: $range->from->format('Y-m-d'),
            to: $range->to->format('Y-m-d'),
            by: $by,
            periods: $periods,
            summary: $summary,
            comparison: $comparison,
            accounts: $this->accounts($range, $values),
            netWorth: array_map(
                static fn (string $day) => new DatedValueResponse($day, $values->netWorthOn($day)),
                Days::sample(Days::between($range->from, $range->to), self::MAX_POINTS),
            ),
            expenses: $categoryTable('expense'),
            income: $categoryTable('income'),
        );
    }

    /**
     * The range's flows (see TransactionRepository::sumFlowsByCategoryAndMonth()).
     *
     * @return array<array{flow: string, category_id: ?string, category_name: ?string, month: string, total: string}>
     */
    private function flows(User $user, ReportRange $range): array
    {
        [$start, $end] = UserDayRange::bounds($range->from, $range->to);

        return $this->transactionRepository->sumFlowsByCategoryAndMonth($user, $start, $end);
    }

    /**
     * @param ReportColumn[] $columns
     * @param array<array{flow: string, month: string, total: string}> $flows
     * @return ReportPeriodResponse[]
     */
    private function periods(array $columns, array $flows): array
    {
        return array_map(function (ReportColumn $column) use ($flows) {
            $sums = ['income' => '0', 'expense' => '0', 'invested' => '0', 'divested' => '0'];
            foreach ($flows as $row) {
                if ($column->contains($row['month'])) {
                    $sums[$row['flow']] = bcadd($sums[$row['flow']], $row['total'], 4);
                }
            }

            return new ReportPeriodResponse(
                key: $column->key,
                from: $column->from->format('Y-m-d'),
                to: $column->to->format('Y-m-d'),
                partial: $column->partial,
                income: bcadd($sums['income'], '0', 4),
                expenses: bcadd($sums['expense'], '0', 4),
                balance: bcsub($sums['income'], $sums['expense'], 4),
                invested: bcsub($sums['invested'], $sums['divested'], 4),
            );
        }, $columns);
    }

    /** @param ReportPeriodResponse[] $periods */
    private function summary(ReportRange $range, array $periods, AssetValues $values, \DateTimeImmutable $today): IncomeExpenseSummaryResponse
    {
        $sum = static fn (string $field) => array_reduce($periods, static fn (string $total, ReportPeriodResponse $period) => bcadd($total, $period->{$field}, 4), '0.0000');
        $income = $sum('income');
        $balance = $sum('balance');
        $netWorthStart = $values->netWorthOn($range->dayBefore()->format('Y-m-d'));
        $netWorthEnd = $values->netWorthOn($range->to->format('Y-m-d'));

        return new IncomeExpenseSummaryResponse(
            income: $income,
            expenses: $sum('expenses'),
            balance: $balance,
            savingsRate: bccomp($income, '0', 4) > 0 ? bcmul(bcdiv($balance, $income, 10), '100', 4) : null,
            invested: $sum('invested'),
            netWorthStart: $netWorthStart,
            netWorthEnd: $netWorthEnd,
            netWorthChange: bcsub($netWorthEnd, $netWorthStart, 4),
            months: $range->elapsedMonths($today),
        );
    }

    /**
     * Every asset account at the start and at the end of the range, in the
     * user's order. An inactive account is left out when it held nothing at
     * either end.
     *
     * @return ReportAccountResponse[]
     */
    private function accounts(ReportRange $range, AssetValues $values): array
    {
        $startDay = $range->dayBefore()->format('Y-m-d');
        $lastDay = $range->to->format('Y-m-d');

        $accounts = [];
        foreach ($values->accounts as $account) {
            $id = $account->getId()->toRfc4122();
            $own = $account->getCurrency()?->getCode();
            // In the user's currency, each end at its own day's rate.
            $start = $values->inUserCurrency($values->valueOn($id, $startDay), $own, $startDay);
            $end = $values->inUserCurrency($values->valueOn($id, $lastDay), $own, $lastDay);
            if (!$account->isActive() && bccomp($start, '0', 4) === 0 && bccomp($end, '0', 4) === 0) {
                continue;
            }

            $isInvestment = $account->getAssetKind() === AssetAccountKind::Investment;
            $accounts[] = new ReportAccountResponse(
                id: $id,
                name: $account->getName(),
                assetKind: $account->getAssetKind()?->value,
                isActive: $account->isActive(),
                start: $start,
                end: $end,
                change: bcsub($end, $start, 4),
                investedStart: $isInvestment ? $values->inUserCurrency($values->investedOn($id, $startDay), $own, $startDay) : null,
                investedEnd: $isInvestment ? $values->inUserCurrency($values->investedOn($id, $lastDay), $own, $lastDay) : null,
                currencyCode: $own,
            );
        }

        return $accounts;
    }

    /**
     * One flow's (income or expense) categories as rows, the columns as
     * columns. A category found only in the comparison range gets a row too,
     * at zero, so what stopped is seen as well.
     *
     * @param ReportColumn[] $columns
     * @param array<array{flow: string, category_id: ?string, category_name: ?string, month: string, total: string}> $flows
     * @param ?ReportColumn[] $comparisonColumns
     * @param ?array<array{flow: string, category_id: ?string, category_name: ?string, month: string, total: string}> $comparisonFlows
     */
    private function categoryTable(string $flow, array $columns, array $flows, int $months, ?array $comparisonColumns, ?array $comparisonFlows): CategoryTableResponse
    {
        $current = $this->byCategory($flow, $columns, $flows);
        $previous = $comparisonColumns !== null ? $this->byCategory($flow, $comparisonColumns, $comparisonFlows ?? []) : null;

        $names = array_map(static fn (array $category) => $category['name'], $current + ($previous ?? []));
        $zeros = static fn (array $columns) => array_fill(0, \count($columns), '0.0000');

        $rows = [];
        foreach ($names as $key => $name) {
            $values = $current[$key]['values'] ?? $zeros($columns);
            $total = $this->sum($values);
            $comparisonValues = $previous !== null ? ($previous[$key]['values'] ?? $zeros($comparisonColumns)) : null;

            $rows[] = new CategoryRowResponse(
                id: $key === '' ? null : (string) $key,
                name: $name,
                values: $values,
                total: $total,
                average: bcdiv($total, (string) $months, 4),
                comparisonValues: $comparisonValues,
                comparisonTotal: $comparisonValues !== null ? $this->sum($comparisonValues) : null,
            );
        }

        usort($rows, static function (CategoryRowResponse $a, CategoryRowResponse $b) {
            if (($a->id === null) !== ($b->id === null)) {
                return $a->id === null ? 1 : -1;
            }

            return bccomp($b->total, $a->total, 4) ?: strcasecmp((string) $a->name, (string) $b->name);
        });

        $totals = array_map(
            fn (int $index) => $this->sum(array_map(static fn (CategoryRowResponse $row) => $row->values[$index], $rows)),
            array_keys($columns),
        );
        $total = $this->sum($totals);

        return new CategoryTableResponse(
            rows: $rows,
            totals: $totals,
            total: $total,
            average: bcdiv($total, (string) $months, 4),
            comparisonTotal: $previous !== null ? $this->sum(array_map(static fn (CategoryRowResponse $row) => (string) $row->comparisonTotal, $rows)) : null,
        );
    }

    /**
     * @param ReportColumn[] $columns
     * @param array<array{flow: string, category_id: ?string, category_name: ?string, month: string, total: string}> $flows
     * @return array<string, array{name: ?string, values: string[]}> category id ('' = none) => its name and a sum per column
     */
    private function byCategory(string $flow, array $columns, array $flows): array
    {
        $categories = [];
        foreach ($flows as $row) {
            if ($row['flow'] !== $flow) {
                continue;
            }

            $key = $row['category_id'] ?? '';
            $categories[$key] ??= ['name' => $row['category_name'], 'values' => array_fill(0, \count($columns), '0.0000')];
            foreach ($columns as $index => $column) {
                if ($column->contains($row['month'])) {
                    $categories[$key]['values'][$index] = bcadd($categories[$key]['values'][$index], $row['total'], 4);
                    break;
                }
            }
        }

        return $categories;
    }

    /** @param string[] $amounts */
    private function sum(array $amounts): string
    {
        return array_reduce($amounts, static fn (string $sum, string $amount) => bcadd($sum, $amount, 4), '0.0000');
    }
}
