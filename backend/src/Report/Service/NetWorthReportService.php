<?php

namespace App\Report\Service;

use App\Account\Service\AssetValueHistory;
use App\Account\Service\AssetValues;
use App\Core\Period\UserDayRange;
use App\Entity\User;
use App\Investment\Position\PriceSeries;
use App\Investment\Position\TradeCashEffect;
use App\Report\DTO\NetWorthMonthResponse;
use App\Report\DTO\NetWorthReportResponse;
use App\Report\DTO\NetWorthRowResponse;
use App\Repository\CurrencyRepository;
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
        private readonly CurrencyRepository $currencies,
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

        $row = static fn (?string $id, ?string $name, ?string $ticker, callable $valueOn, ?string $currencyCode = null) => new NetWorthRowResponse(
            id: $id,
            name: $name,
            ticker: $ticker,
            values: array_map(static fn (NetWorthMonthResponse $month) => $month->date !== null ? $valueOn($month->date) : null, $months),
            previousValues: array_map(static fn (NetWorthMonthResponse $month) => $month->date !== null ? $valueOn($month->date) : null, $previousMonths),
            previousOpening: $valueOn($previousOpening),
            currencyCode: $currencyCode,
        );

        $cash = [];
        foreach ($values->accounts as $account) {
            $id = $account->getId()->toRfc4122();
            $own = $account->getCurrency()?->getCode();
            // In the user's currency, each month at its end's rate; the account's own currency is named.
            $accountRow = $row($id, $account->getName(), null, static fn (string $day) => $values->inUserCurrency($values->cashOn($id, $day), $own, $day), $own !== $values->currency ? $own : null);
            // An account empty all along the two years (a securities-only account, one not used yet or any more) would be a row of zeros.
            if ($this->isEverNonZero($accountRow)) {
                $cash[] = $accountRow;
            }
        }

        $names = [];
        $quoteCurrency = [];
        foreach ($values->trades as $trade) {
            $names[$trade['instrument_id']] ??= [$trade['instrument_name'], $trade['instrument_ticker']];
            $quoteCurrency[$trade['instrument_id']] ??= $trade['instrument_currency'];
        }
        $investments = [];
        $prices = [];
        foreach ($values->valueByInstrument as $instrumentId => $instrumentValues) {
            $instrumentRow = $row($instrumentId, $names[$instrumentId][0], $names[$instrumentId][1], static fn (string $day) => bcadd($instrumentValues[$day] ?? '0', '0', 4));
            if (!$this->isEverNonZero($instrumentRow)) {
                continue;
            }
            $investments[] = $instrumentRow;
            $prices[] = $row($instrumentId, $names[$instrumentId][0], $names[$instrumentId][1], $this->priceOn($values->prices[$instrumentId] ?? []), $quoteCurrency[$instrumentId]);
        }
        $lastIndex = $this->lastIndex($months, $previousMonths);
        usort($investments, static fn (NetWorthRowResponse $a, NetWorthRowResponse $b) => bccomp(self::at($b, $lastIndex), self::at($a, $lastIndex), 4) ?: strcasecmp((string) $a->name, (string) $b->name));
        $order = array_flip(array_map(static fn (NetWorthRowResponse $r) => $r->id, $investments));
        usort($prices, static fn (NetWorthRowResponse $a, NetWorthRowResponse $b) => $order[$a->id] <=> $order[$b->id]);

        [$saved, $market, $exchange] = $this->changes($user, $values, $months, $previousMonths, $previousOpening);

        // What 1 unit of each other currency in play was worth in the user's, at each month's end.
        $others = array_values(array_unique(array_filter(
            [...array_values($values->currencyByAccount), ...array_values($quoteCurrency)],
            static fn (?string $code) => $code !== null && $code !== $values->currency,
        )));
        sort($others);
        $rates = array_map(
            fn (string $code) => $row($code, $code, null, static fn (string $day) => ($rate = $values->rates->rate($code, $values->currency, $day)) !== null ? bcadd($rate, '0', 6) : null, $code),
            $others,
        );

        // The net worth by currency: cash by its account's, what is held by the one it is quoted in.
        $byCurrency = [];
        if ($others !== []) {
            foreach (array_unique([$values->currency, ...$others]) as $code) {
                $byCurrency[] = $row($code, $code, null, function (string $day) use ($values, $code, $quoteCurrency): string {
                    $sum = '0';
                    foreach ($values->accounts as $account) {
                        $id = $account->getId()->toRfc4122();
                        $own = $account->getCurrency()?->getCode();
                        if ($own === $code) {
                            $sum = bcadd($sum, $values->inUserCurrency($values->cashOn($id, $day), $own, $day), 4);
                        }
                    }
                    foreach ($values->valueByInstrument as $instrumentId => $instrumentValues) {
                        if (($quoteCurrency[$instrumentId] ?? null) === $code) {
                            $sum = bcadd($sum, $instrumentValues[$day] ?? '0', 4);
                        }
                    }

                    return $sum;
                }, $code);
            }
            $byCurrency = array_values(array_filter($byCurrency, $this->isEverNonZero(...)));
        }

        return new NetWorthReportResponse(
            currencySymbol: $this->currencies->symbolOf($values->currency),
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
            exchange: $exchange,
            rates: $rates,
            currencies: $byCurrency,
        );
    }

    /**
     * What was saved (income - expenses), what the market did (the change in
     * the value held - money put in, fees and taxes included) and what the
     * exchange rates did, each month, all in the user's currency.
     *
     * Saved and money put in are valued at the day they happened, and a
     * holding's change day by day at that day's rate: what is left of the
     * change in net worth, once those are counted, is the exchange effect: what
     * the opening value and each day's change gained or lost between that day's
     * rate and the month's end. With one currency it is nothing.
     *
     * @param NetWorthMonthResponse[] $months
     * @param NetWorthMonthResponse[] $previousMonths
     * @return array{0: NetWorthRowResponse, 1: NetWorthRowResponse, 2: NetWorthRowResponse}
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
                    $contribution = TradeCashEffect::contribution($trade['type'], $trade['amount'], $trade['costs']);
                    $sum = bcadd($sum, $values->inUserCurrency($contribution, $trade['account_currency'] ?? null, $trade['day']), 4);
                }
            }

            return $sum;
        };

        /**
         * What each holding changed by day by day, valued at that day's rate,
         * between two days: everything, and the investments alone.
         *
         * @return array{0: string, 1: string} [all, investments]
         */
        $localChanges = function (string $after, string $until) use ($values): array {
            $all = '0';
            $invested = '0';
            foreach ($values->accounts as $account) {
                $id = $account->getId()->toRfc4122();
                $own = $account->getCurrency()?->getCode();
                $previousValue = $values->valueOn($id, $after);
                $previousInvested = $values->investedOn($id, $after);
                for ($day = (new \DateTimeImmutable($after))->modify('+1 day'); ($key = $day->format('Y-m-d')) <= $until; $day = $day->modify('+1 day')) {
                    $rate = $values->rateOf($own, $key);
                    $value = $values->valueOn($id, $key);
                    $investedNow = $values->investedOn($id, $key);
                    $all = bcadd($all, bcmul(bcsub($value, $previousValue, 4), $rate, 10), 10);
                    $invested = bcadd($invested, bcmul(bcsub($investedNow, $previousInvested, 4), $rate, 10), 10);
                    [$previousValue, $previousInvested] = [$value, $investedNow];
                }
            }

            return [$all, $invested];
        };

        /** @return array{0: array<?string>, 1: array<?string>, 2: array<?string>} */
        $series = function (array $months, string $opening) use ($values, $savedByMonth, $contributed, $localChanges): array {
            $saved = [];
            $market = [];
            $exchange = [];
            $previousDate = $opening;
            foreach ($months as $month) {
                if ($month->date === null) {
                    $saved[] = null;
                    $market[] = null;
                    $exchange[] = null;
                    continue;
                }
                [$all, $invested] = $localChanges($previousDate, $month->date);
                $saved[] = bcadd($savedByMonth[$month->month] ?? '0', '0', 4);
                $market[] = bcadd(bcsub($invested, $contributed($previousDate, $month->date), 10), '0', 4);
                $exchange[] = bcadd(bcsub(bcsub($values->netWorthOn($month->date), $values->netWorthOn($previousDate), 4), $all, 10), '0', 4);
                $previousDate = $month->date;
            }

            return [$saved, $market, $exchange];
        };

        [$savedPrevious, $marketPrevious, $exchangePrevious] = $series($previousMonths, $previousOpening);
        [$savedValues, $marketValues, $exchangeValues] = $series($months, end($previousMonths)->date ?? $previousOpening);

        return [
            new NetWorthRowResponse(null, null, null, $savedValues, $savedPrevious, null),
            new NetWorthRowResponse(null, null, null, $marketValues, $marketPrevious, null),
            new NetWorthRowResponse(null, null, null, $exchangeValues, $exchangePrevious, null),
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
            $price = PriceSeries::onOrBefore($priceByDate, $day);

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
