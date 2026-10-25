<?php

namespace App\Investment\Service;

use App\Currency\Service\CurrencyConverter;
use App\Currency\Service\RateTable;
use App\Entity\User;

/**
 * What a holding is worth is quantity x price, the price in the currency the
 * instrument is quoted in and the value in the currency of the account that
 * holds it (its cost is, too): the price is converted, each day at its day's
 * rate. This loads the rates the trades of some accounts need, and only when
 * some instrument is quoted in another currency than its account's.
 */
class HoldingRates
{
    public function __construct(private readonly CurrencyConverter $converter)
    {
    }

    /**
     * @param array<array{instrument_currency: string, account_currency: ?string}> $trades
     */
    public function forTrades(User $user, array $trades, \DateTimeInterface $from, \DateTimeInterface $to): RateTable
    {
        $codes = [];
        foreach ($trades as $trade) {
            if ($trade['account_currency'] !== null && $trade['instrument_currency'] !== $trade['account_currency']) {
                $codes[$trade['instrument_currency']] = true;
                $codes[$trade['account_currency']] = true;
            }
        }

        return $codes === [] ? new RateTable([]) : $this->converter->tableFor($user, array_keys($codes), $from, $to);
    }
}
