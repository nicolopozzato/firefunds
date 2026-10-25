<?php

namespace App\Currency\Service;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Currency\DTO\ExchangeRateCurrencyResponse;
use App\Currency\DTO\ExchangeRateResponse;
use App\Entity\ExchangeRateOverride;
use App\Entity\User;
use App\Investment\Service\PriceCalendar;
use App\Repository\CurrencyRepository;
use App\Repository\ExchangeRateOverrideRepository;
use App\Repository\ExchangeRateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The rates as the user sees and edits them: what 1 unit of a currency is
 * worth in their own one, whatever the euro-based way they are stored in.
 * Their corrections are theirs alone (ExchangeRateOverride): a day is the
 * shared rate until they enter one, and goes back to it when they take it away.
 */
class UserExchangeRates
{
    /**
     * Shared rates reach yesterday after each fetch (carried over on the days
     * without one); this many days further behind, the fetch is failing.
     */
    public const STALE_AFTER_DAYS = 3;

    public function __construct(
        private readonly ExchangeRateRepository $rates,
        private readonly ExchangeRateOverrideRepository $overrides,
        private readonly CurrencyRepository $currencies,
        private readonly EntityManagerInterface $entityManager,
        private readonly PriceCalendar $calendar,
    ) {
    }

    /**
     * The currencies the user has rates for (those they hold or quote in, the
     * euro when theirs is another), each with its latest rate.
     *
     * @return ExchangeRateCurrencyResponse[]
     */
    public function currenciesFor(User $user): array
    {
        $own = $user->getCurrency();
        $codes = array_keys($this->rates->neededCurrencies($user));
        if ($own !== 'EUR' && $codes !== []) {
            $codes[] = 'EUR';
        }
        $codes = array_values(array_filter(array_unique($codes), static fn (string $code) => $code !== $own));
        sort($codes);

        $yesterday = $this->calendar->yesterday();
        $latest = $this->rates->findEffective($user, array_unique([...$codes, $own]), $yesterday, $yesterday);
        $table = new RateTable(array_map(static fn (array $days) => array_map(static fn (array $row) => $row['rate'], $days), $latest));
        $day = $yesterday->format('Y-m-d');
        $latest = $this->rates->latestDates($user, array_values(array_diff(array_unique([...$codes, $own]), ['EUR'])));
        $staleBefore = $yesterday->modify('-'.self::STALE_AFTER_DAYS.' days')->format('Y-m-d');

        return array_map(
            function (string $code) use ($table, $own, $day, $latest, $staleBefore) {
                [$status, $latestDate] = $this->status($code, $own, $latest, $staleBefore);

                return new ExchangeRateCurrencyResponse($code, $day, $this->rounded($table->rate($code, $own, $day)), $status, $latestDate);
            },
            $codes,
        );
    }

    /**
     * A rate in the user's currency needs the rates of both currencies against
     * the euro (the euro's own is always 1): missing if either has none at all,
     * stale if either has shared rates that stopped and nothing newer of the
     * user's. Only the user's rates (a currency no provider has) never go stale:
     * that is their choice.
     *
     * @param array<string, array{shared: ?string, own: ?string}> $latest
     * @return array{RateStatus, ?string}
     */
    private function status(string $code, string $own, array $latest, string $staleBefore): array
    {
        $status = RateStatus::Ok;
        $latestDate = null;
        foreach (array_diff(array_unique([$code, $own]), ['EUR']) as $currency) {
            ['shared' => $shared, 'own' => $mine] = $latest[$currency] ?? ['shared' => null, 'own' => null];
            if ($shared === null && $mine === null) {
                return [RateStatus::Missing, null];
            }
            $newest = max($shared ?? '', $mine ?? '');
            if ($shared !== null && $newest < $staleBefore) {
                $status = RateStatus::Stale;
            }
            $latestDate = $latestDate === null ? $newest : min($latestDate, $newest);
        }

        return [$status, $latestDate];
    }

    /**
     * Every day of [from, to] of one currency in the user's own, newest first.
     *
     * @return ExchangeRateResponse[]
     */
    public function days(User $user, string $currency, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $currency = $this->assertUsable($user, $currency);
        $own = $user->getCurrency();

        $effective = $this->rates->findEffective($user, array_unique([$currency, $own]), $from, $to);
        $table = new RateTable(array_map(static fn (array $days) => array_map(static fn (array $row) => $row['rate'], $days), $effective));
        // For the euro the rows come from the user's own currency (EUR has none).
        $driver = $currency === 'EUR' ? $own : $currency;

        $responses = [];
        foreach ($effective[$driver] ?? [] as $day => $row) {
            if ($day < $from->format('Y-m-d') || $day > $to->format('Y-m-d')) {
                continue;
            }
            $original = null;
            if ($row['source'] === 'manual' && $row['original'] !== null) {
                // The same conversion with the shared rate where the user's correction is.
                $shared = [$driver => [$day => $row['original']]];
                if ($own !== 'EUR' && $own !== $driver) {
                    $shared[$own] = array_map(static fn (array $r) => $r['rate'], $effective[$own] ?? []);
                }
                $original = $this->rounded((new RateTable($shared))->rate($currency, $own, $day));
            }
            $responses[] = new ExchangeRateResponse($day, $this->rounded($table->rate($currency, $own, $day)) ?? '0', $row['source'], $original);
        }

        return array_reverse($responses);
    }

    /** The user's own rate for a day: what 1 unit of the currency is worth in theirs. */
    public function set(User $user, string $currency, \DateTimeImmutable $day, string $worthInOwn): void
    {
        $currency = $this->assertUsable($user, $currency);
        $own = $user->getCurrency();
        if ($day > $this->today($user)) {
            throw new ApiException(ErrorCode::RateDateInFuture);
        }

        // Stored against the euro: the euro itself has no row, so a rate "1 EUR = x own" is the own currency's.
        if ($currency === 'EUR') {
            $target = $own;
            $perEuro = bcadd($worthInOwn, '0', 8);
        } else {
            $target = $currency;
            $ownPerEuro = $own === 'EUR' ? '1' : $this->perEuroOn($user, $own, $day);
            $perEuro = bcdiv($ownPerEuro, $worthInOwn, 8);
        }
        if (bccomp($perEuro, '0', 8) <= 0) {
            throw new ApiException(ErrorCode::RateCurrencyInvalid, ['currency' => $currency]);
        }

        $override = $this->overrides->findOneByDay($user, $target, $day);
        if ($override !== null) {
            $override->setRate($perEuro);
        } else {
            $this->entityManager->persist(new ExchangeRateOverride($user, $target, $day, $perEuro));
        }
        $this->entityManager->flush();
    }

    /** Takes the user's rate away: the day is the shared rate again. */
    public function revertToShared(User $user, string $currency, \DateTimeImmutable $day): void
    {
        $currency = $this->assertUsable($user, $currency);
        $target = $currency === 'EUR' ? $user->getCurrency() : $currency;

        $override = $this->overrides->findOneByDay($user, $target, $day) ?? throw ApiException::notFound(ErrorCode::RateNotFound);
        $override->markDeleted();
        $this->entityManager->flush();
    }

    /** The code, if rates against the user's currency exist for it: any other than their own. */
    private function assertUsable(User $user, string $currency): string
    {
        $code = strtoupper($currency);
        if ($code === $user->getCurrency() || $this->currencies->findOneByCode($code) === null) {
            throw new ApiException(ErrorCode::RateCurrencyInvalid, ['currency' => $code]);
        }

        return $code;
    }

    private function perEuroOn(User $user, string $currency, \DateTimeImmutable $day): string
    {
        $row = $this->rates->findEffective($user, [$currency], $day, $day)[$currency] ?? [];

        return $row === [] ? throw new ApiException(ErrorCode::RateCurrencyInvalid, ['currency' => $currency]) : end($row)['rate'];
    }

    private function today(User $user): \DateTimeImmutable
    {
        return new \DateTimeImmutable((new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone())))->format('Y-m-d'));
    }

    /** Rounded to 8 decimals, the zeros beyond the fourth dropped: "1.1234", "0.84028". */
    private function rounded(?string $rate): ?string
    {
        if ($rate === null) {
            return null;
        }
        [$integer, $decimals] = explode('.', bcadd($rate, '0.000000005', 8));

        return $integer.'.'.str_pad(rtrim($decimals, '0'), 4, '0');
    }
}
