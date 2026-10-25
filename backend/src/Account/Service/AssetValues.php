<?php

namespace App\Account\Service;

use App\Currency\Service\RateTable;
use App\Entity\Account;

/**
 * What every asset account of a user was worth at the end of each day of a
 * range, as computed by AssetValueHistory: free cash plus the market value of
 * what is held, per account and per instrument.
 */
final readonly class AssetValues
{
    /**
     * @param Account[] $accounts every asset account, inactive ones included
     * @param array<string, array<string, string>> $cashByAccount account id => (Y-m-d => free cash)
     * @param array<string, array<string, string>> $investedByAccount account id => (Y-m-d => market value held); only accounts holding something
     * @param array<string, array<string, string>> $valueByInstrument instrument id => (Y-m-d => market value held, every account)
     * @param array<array{account_id: string, instrument_id: string, instrument_name: string, instrument_ticker: ?string, type: string, quantity: string, amount: string, costs: string, day: string}> $trades every trade, oldest first per holding
     * @param array<string, array<string, string>> $prices instrument id => (Y-m-d => closing price), from the last one before the range
     * @param array<string, ?string> $currencyByAccount account id => its currency code
     *
     * What an account holds is in its own currency (cashOn, investedOn, valueOn);
     * what adds accounts up (netWorthOn, investmentsOn, valueByInstrument) is in
     * `$currency`, the user's, each amount at its day's rate.
     */
    public function __construct(
        public array $accounts,
        public array $cashByAccount,
        public array $investedByAccount,
        public array $valueByInstrument,
        public array $trades,
        public array $prices,
        public RateTable $rates = new RateTable([]),
        public string $currency = 'EUR',
        public array $currencyByAccount = [],
    ) {
    }

    /**
     * An amount in an account's currency, in the user's at a day's rate. As it
     * is when it is the same currency, or when there is no rate.
     */
    public function inUserCurrency(string $amount, ?string $accountCurrency, string $day): string
    {
        if ($accountCurrency === null || $accountCurrency === $this->currency) {
            return $amount;
        }

        return $this->rates->convert($amount, $accountCurrency, $this->currency, $day) ?? $amount;
    }

    /** The user's currency per unit of the account's on a day (1 when the same or unknown). */
    public function rateOf(?string $accountCurrency, string $day): string
    {
        return $accountCurrency === null || $accountCurrency === $this->currency ? '1' : ($this->rates->rate($accountCurrency, $this->currency, $day) ?? '1');
    }

    public function cashOn(string $accountId, string $day): string
    {
        return bcadd($this->cashByAccount[$accountId][$day] ?? '0', '0', 4);
    }

    public function investedOn(string $accountId, string $day): string
    {
        return bcadd($this->investedByAccount[$accountId][$day] ?? '0', '0', 4);
    }

    /** Free cash plus what is held. */
    public function valueOn(string $accountId, string $day): string
    {
        return bcadd($this->cashOn($accountId, $day), $this->investedOn($accountId, $day), 4);
    }

    /** Every asset account's value, in the user's currency. */
    public function netWorthOn(string $day): string
    {
        return array_reduce(
            $this->accounts,
            function (string $sum, Account $account) use ($day) {
                $id = $account->getId()->toRfc4122();

                return bcadd($sum, $this->inUserCurrency($this->valueOn($id, $day), $this->currencyByAccount[$id] ?? null, $day), 4);
            },
            '0.0000'
        );
    }

    /** Market value of what is held, on every account, in the user's currency. */
    public function investmentsOn(string $day): string
    {
        $sum = '0.0000';
        foreach ($this->investedByAccount as $id => $values) {
            $sum = bcadd($sum, $this->inUserCurrency($values[$day] ?? '0', $this->currencyByAccount[$id] ?? null, $day), 4);
        }

        return $sum;
    }
}
