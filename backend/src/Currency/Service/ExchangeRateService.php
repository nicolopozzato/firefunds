<?php

namespace App\Currency\Service;

use App\Currency\Provider\ExchangeRateProviderInterface;
use App\Entity\ExchangeRateSource;
use App\Entity\User;
use App\Investment\Service\PriceCalendar;
use App\Repository\ExchangeRateRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Keeps the shared rates up to date: for every currency somebody needs
 * (ExchangeRateRepository::neededCurrencies), from the first day they need it
 * (or the day after the newest rate we have) through yesterday, every
 * calendar day, with the provider's rate where it publishes one and the
 * previous one carried over elsewhere. The last days are re-checked each time:
 * a rate published late replaces the carried-over one.
 */
class ExchangeRateService
{
    private const REFRESH_WINDOW_DAYS = 7;
    /** The ECB starts here: nothing earlier exists to fetch. */
    private const EARLIEST = '1999-01-04';

    /**
     * @param iterable<ExchangeRateProviderInterface> $providers in priority order
     */
    public function __construct(
        private readonly ExchangeRateRepository $repository,
        #[AutowireIterator('app.exchange_rate_provider')]
        private readonly iterable $providers,
        private readonly PriceCalendar $calendar,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetches what is missing: for everyone, or for what one user needs.
     *
     * @return string[] the currencies no provider had a rate for
     */
    public function sync(?User $user = null): array
    {
        $yesterday = $this->calendar->yesterday();
        $fromByCurrency = [];
        foreach ($this->repository->neededCurrencies($user) as $currency => $since) {
            $start = new \DateTimeImmutable(max($since ?? $yesterday->modify('-30 days')->format('Y-m-d'), self::EARLIEST));
            $latest = $this->repository->latestDate($currency);
            $earliestStored = $this->repository->earliestDate($currency);
            // Nothing stored yet, or the data reaches back further than what we hold: from where it starts.
            // Otherwise only the last days, which a late publication can still change.
            $from = $latest === null || $earliestStored === null || $start < $earliestStored
                ? $start
                : $latest->modify('-'.self::REFRESH_WINDOW_DAYS.' days');
            if ($from <= $yesterday) {
                $fromByCurrency[$currency] = $from;
            }
        }

        $unresolved = array_keys($fromByCurrency);
        if ($fromByCurrency === []) {
            return [];
        }
        $earliest = min($fromByCurrency);

        foreach ($this->providers as $provider) {
            if ($unresolved === []) {
                break;
            }
            foreach ($provider->getRates($unresolved, $earliest, $yesterday) as $currency => $byDay) {
                $byDay = array_filter($byDay, static fn (string $_, string $day) => $day >= $fromByCurrency[$currency]->format('Y-m-d'), \ARRAY_FILTER_USE_BOTH);
                if ($byDay === []) {
                    continue;
                }
                $this->store($currency, $fromByCurrency[$currency], $yesterday, $byDay, $provider->source());
                $unresolved = array_values(array_diff($unresolved, [$currency]));
            }
        }

        foreach ($unresolved as $currency) {
            $this->logger->warning('No exchange rate found for {currency}', ['currency' => $currency]);
        }

        return $unresolved;
    }

    /**
     * sync() for what one user has just come to need (an instrument or an
     * account in another currency), without ever failing the request that
     * asked: the scheduled run catches up anyway.
     */
    public function syncForUser(User $user): void
    {
        try {
            $this->sync($user);
        } catch (\Throwable $e) {
            $this->logger->warning('Exchange rates fetch for a user failed: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Every calendar day from $from through $to: the provider's rate on the
     * days it has one, else the previous rate (carried over); days before the
     * first rate there is are left empty.
     *
     * @param array<string, string> $published Y-m-d => rate, oldest first
     */
    private function store(string $currency, \DateTimeImmutable $from, \DateTimeImmutable $to, array $published, ExchangeRateSource $source): void
    {
        $before = $this->repository->latestBefore($currency, $from);
        $previous = $before !== null ? $before[1] : null;

        $rows = [];
        for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
            $day = $date->format('Y-m-d');
            if (isset($published[$day])) {
                $previous = $published[$day];
                $rows[$day] = [$previous, $source];
            } elseif ($previous !== null) {
                $rows[$day] = [$previous, ExchangeRateSource::Filled];
            }
        }

        $this->repository->upsert($currency, $rows);
    }
}
