<?php

namespace App\Currency\Service;

use App\Entity\User;
use App\Repository\ExchangeRateRepository;

/**
 * Loads the rates a user sees (the shared ones, their own corrections on top)
 * into a RateTable for the currencies and days a computation needs.
 */
class CurrencyConverter
{
    public function __construct(private readonly ExchangeRateRepository $rates)
    {
    }

    /**
     * @param string[] $currencies the ones to convert between (the euro needs no rows)
     */
    public function tableFor(User $user, array $currencies, \DateTimeInterface $from, \DateTimeInterface $to): RateTable
    {
        $others = array_values(array_unique(array_filter($currencies, static fn (string $code) => $code !== 'EUR')));
        $perEuro = [];
        foreach ($this->rates->findEffective($user, $others, $from, $to) as $currency => $days) {
            $perEuro[$currency] = array_map(static fn (array $row) => $row['rate'], $days);
        }

        return new RateTable($perEuro);
    }
}
