<?php

namespace App\Account\Service;

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
     */
    public function __construct(
        public array $accounts,
        public array $cashByAccount,
        public array $investedByAccount,
        public array $valueByInstrument,
        public array $trades,
        public array $prices,
    ) {
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

    /** Every asset account's value. */
    public function netWorthOn(string $day): string
    {
        return array_reduce(
            $this->accounts,
            fn (string $sum, Account $account) => bcadd($sum, $this->valueOn($account->getId()->toRfc4122(), $day), 4),
            '0.0000'
        );
    }

    /** Market value of what is held, on every account. */
    public function investmentsOn(string $day): string
    {
        return array_reduce(
            $this->investedByAccount,
            static fn (string $sum, array $values) => bcadd($sum, $values[$day] ?? '0', 4),
            '0.0000'
        );
    }
}
