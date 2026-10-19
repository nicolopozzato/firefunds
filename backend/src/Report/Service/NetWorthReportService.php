<?php

namespace App\Report\Service;

use App\Account\Service\AssetValueHistory;
use App\Account\Service\AssetValues;
use App\Core\Period\UserDayRange;
use App\Entity\InvestmentTransactionType;
use App\Entity\User;
use App\Report\DTO\NetWorthMonthResponse;
use App\Report\DTO\NetWorthReportResponse;
use App\Report\DTO\NetWorthRowResponse;
use App\Repository\TransactionRepository;

/**
 * The net worth report of a year: every asset at the end of each month, the
 * year before alongside to compare with. Months are the user's calendar
 * months; the current one is taken today, those to come are left empty.
 * Runs a fixed number of queries whatever the accounts or instruments.
 */
class NetWorthReportService
{
    public function __construct(
        private readonly AssetValueHistory $assetValueHistory,
        private readonly TransactionRepository $transactionRepository,
    ) {
    }

    public function forUser(User $user, ?int $year = null, ?\DateTimeImmutable $today = null): NetWorthReportResponse
    {
        $today = new \DateTimeImmutable(($today ?? new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone())))->format('Y-m-d'));
        $currentYear = (int) $today->format('Y');
        $year ??= $currentYear;

        $months = $this->months($year, $today);
        $previousMonths = $this->months($year - 1, $today);
        $previousOpening = \sprintf('%d-12-31', $year - 2);
        // Every day the report reads, as Y-m-d: the opening first, then the year before and the year.
        $dates = [$previousOpening, ...$this->dates($previousMonths), ...$this->dates($months)];
        $lastDate = $this->lastDate($dates);

        $values = $this->assetValueHistory->forUser($user, new \DateTimeImmutable($previousOpening), new \DateTimeImmutable($lastDate));
        [$firstTransaction] = $this->transactionRepository->findDateRange($user);

        $row = static fn (?string $id, ?string $name, ?string $ticker, callable $valueOn) => new NetWorthRowResponse(
            id: $id,
            name: $name,
            ticker: $ticker,
            values: array_map(static fn (NetWorthMonthResponse $month) => $month->date !== null ? $valueOn($month->date) : null, $months),
            previousValues: array_map(static fn (NetWorthMonthResponse $month) => $month->date !== null ? $valueOn($month->date) : null, $previousMonths),
            previousOpening: $valueOn($previousOpening),
        );

        $cash = [];
        foreach ($values->accounts as $account) {
            $id = $account->getId()->toRfc4122();
            $accountRow = $row($id, $account->getName(), null, static fn (string $day) => $values->cashOn($id, $day));
            // An account empty all along the two years (a securities-only account, one not used yet or any more) would be a row of zeros.
            if ($this->isEverNonZero($accountRow)) {
                $cash[] = $accountRow;
            }
        }

        $names = [];
        foreach ($values->trades as $trade) {
            $names[$trade['instrument_id']] ??= [$trade['instrument_name'], $trade['instrument_ticker']];
        }
        $investments = [];
        $prices = [];
        foreach ($values->valueByInstrument as $instrumentId => $instrumentValues) {
            $instrumentRow = $row($instrumentId, $names[$instrumentId][0], $names[$instrumentId][1], static fn (string $day) => bcadd($instrumentValues[$day] ?? '0', '0', 4));
            if (!$this->isEverNonZero($instrumentRow)) {
                continue;
            }
            $investments[] = $instrumentRow;
            $prices[] = $row($instrumentId, $names[$instrumentId][0], $names[$instrumentId][1], $this->priceOn($values->prices[$instrumentId] ?? []));
        }
        $lastIndex = $this->lastIndex($months, $previousMonths);
        usort($investments, static fn (NetWorthRowResponse $a, NetWorthRowResponse $b) => bccomp(self::at($b, $lastIndex), self::at($a, $lastIndex), 4) ?: strcasecmp((string) $a->name, (string) $b->name));
        $order = array_flip(array_map(static fn (NetWorthRowResponse $r) => $r->id, $investments));
        usort($prices, static fn (NetWorthRowResponse $a, NetWorthRowResponse $b) => $order[$a->id] <=> $order[$b->id]);

        [$saved, $market] = $this->changes($user, $values, $months, $previousMonths, $previousOpening);

        return new NetWorthReportResponse(
            currencySymbol: ($values->accounts[0] ?? null)?->getCurrency()?->getSymbol(),
            year: $year,
            firstYear: min($year, $firstTransaction !== null ? (int) $firstTransaction->format('Y') : $currentYear),
            currentYear: $currentYear,
            months: $months,
            previousMonths: $previousMonths,
            cash: $cash,
            investments: $investments,
            total: $row(null, null, null, $values->netWorthOn(...)),
            saved: $saved,
            market: $market,
            prices: $prices,
        );
    }

    /**
     * What was saved (income - expenses) and what the market did (change in
     * the value held - money put in, fees and taxes included) each month.
     *
     * @param NetWorthMonthResponse[] $months
     * @param NetWorthMonthResponse[] $previousMonths
     * @return array{0: NetWorthRowResponse, 1: NetWorthRowResponse}
     */
    private function changes(User $user, AssetValues $values, array $months, array $previousMonths, string $previousOpening): array
    {
        $lastDate = $this->lastDate([$previousOpening, ...$this->dates([...$previousMonths, ...$months])]);
        [$start, $end] = UserDayRange::bounds((new \DateTimeImmutable($previousOpening))->modify('+1 day'), new \DateTimeImmutable($lastDate));
        $savedByMonth = [];
        foreach ($this->transactionRepository->sumFlowsByCategoryAndMonth($user, $start, $end) as $flow) {
            $sign = match ($flow['flow']) {
                'income' => 1,
                'expense' => -1,
                default => 0,
            };
            if ($sign !== 0) {
                $savedByMonth[$flow['month']] = bcadd($savedByMonth[$flow['month']] ?? '0', bcmul($flow['total'], (string) $sign, 4), 4);
            }
        }

        $contributed = function (string $after, string $until) use ($values): string {
            $sum = '0';
            foreach ($values->trades as $trade) {
                if ($trade['day'] > $after && $trade['day'] <= $until) {
                    $sum = bcadd($sum, $trade['type'] === InvestmentTransactionType::Buy->value
                        ? bcadd($trade['amount'], $trade['costs'], 4)
                        : bcsub('0', bcsub($trade['amount'], $trade['costs'], 4), 4), 4);
                }
            }

            return $sum;
        };

        /** @return array{0: array<?string>, 1: array<?string>} */
        $series = function (array $months, string $opening) use ($values, $savedByMonth, $contributed): array {
            $saved = [];
            $market = [];
            $previousDate = $opening;
            foreach ($months as $month) {
                if ($month->date === null) {
                    $saved[] = null;
                    $market[] = null;
                    continue;
                }
                $saved[] = bcadd($savedByMonth[$month->month] ?? '0', '0', 4);
                $change = bcsub($values->investmentsOn($month->date), $values->investmentsOn($previousDate), 4);
                $market[] = bcsub($change, $contributed($previousDate, $month->date), 4);
                $previousDate = $month->date;
            }

            return [$saved, $market];
        };

        [$savedPrevious, $marketPrevious] = $series($previousMonths, $previousOpening);
        [$savedValues, $marketValues] = $series($months, end($previousMonths)->date ?? $previousOpening);

        return [
            new NetWorthRowResponse(null, null, null, $savedValues, $savedPrevious, null),
            new NetWorthRowResponse(null, null, null, $marketValues, $marketPrevious, null),
        ];
    }

    /** @return NetWorthMonthResponse[] the year's twelve months */
    private function months(int $year, \DateTimeImmutable $today): array
    {
        $months = [];
        for ($month = 1; $month <= 12; ++$month) {
            $first = new \DateTimeImmutable(\sprintf('%d-%02d-01', $year, $month));
            $last = $first->modify('last day of this month');
            $months[] = new NetWorthMonthResponse(
                month: $first->format('Y-m'),
                date: $first > $today ? null : min($last, $today)->format('Y-m-d'),
            );
        }

        return $months;
    }

    /** @param array<?string> $dates at least one not null */
    private function lastDate(array $dates): string
    {
        return max(array_filter($dates));
    }

    /**
     * @param NetWorthMonthResponse[] $months
     * @return array<?string>
     */
    private function dates(array $months): array
    {
        return array_map(static fn (NetWorthMonthResponse $month) => $month->date, $months);
    }

    /**
     * The closing price on a day: the last one recorded on or before it.
     *
     * @param array<string, string> $priceByDate Y-m-d => price, oldest first
     * @return callable(string): ?string
     */
    private function priceOn(array $priceByDate): callable
    {
        return static function (string $day) use ($priceByDate): ?string {
            $price = null;
            foreach ($priceByDate as $date => $value) {
                if ($date > $day) {
                    break;
                }
                $price = $value;
            }

            return $price !== null ? bcadd($price, '0', 4) : null;
        };
    }

    /**
     * Where the instruments are ordered by: the year's last month already
     * gone by, or December of the year before when the year is still to come.
     *
     * @param NetWorthMonthResponse[] $months
     * @param NetWorthMonthResponse[] $previousMonths
     * @return array{0: bool, 1: int} in the year before?, month index
     */
    private function lastIndex(array $months, array $previousMonths): array
    {
        $index = \count(array_filter($this->dates($months))) - 1;

        return $index >= 0 ? [false, $index] : [true, \count($previousMonths) - 1];
    }

    /** @param array{0: bool, 1: int} $index */
    private static function at(NetWorthRowResponse $row, array $index): string
    {
        return ($index[0] ? $row->previousValues : $row->values)[$index[1]] ?? '0';
    }

    private function isEverNonZero(NetWorthRowResponse $row): bool
    {
        foreach ([...$row->values, ...$row->previousValues, $row->previousOpening] as $value) {
            if ($value !== null && bccomp($value, '0', 4) !== 0) {
                return true;
            }
        }

        return false;
    }
}
