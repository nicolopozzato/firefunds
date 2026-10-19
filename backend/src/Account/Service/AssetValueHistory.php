<?php

namespace App\Account\Service;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\User;
use App\Investment\Position\PositionHistory;
use App\Repository\AccountRepository;
use App\Repository\InvestmentPriceRepository;
use App\Repository\InvestmentTransactionRepository;

/**
 * The value of every asset account of a user day by day (the dashboard, the
 * reports): free cash from the account's movements, plus what its holdings
 * were worth at that day's closing price. Days are the user's calendar days.
 * Three queries, whatever the number of accounts or instruments.
 */
class AssetValueHistory
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly InvestmentTransactionRepository $investmentTransactionRepository,
        private readonly InvestmentPriceRepository $priceRepository,
    ) {
    }

    /** The values at the end of every day in [from, to]. */
    public function forUser(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): AssetValues
    {
        $accounts = $this->accountRepository->findByUserAndType($user, AccountType::Asset);
        $investmentAccounts = array_values(array_filter(
            $accounts,
            static fn (Account $account) => $account->getAssetKind() === AssetAccountKind::Investment,
        ));

        $cashByAccount = $this->accountRepository->getDailyBalanceHistories($accounts, $from, $to);
        $trades = $this->investmentTransactionRepository->findTradeRowsForAccounts($investmentAccounts);
        $instrumentIds = array_values(array_unique(array_column($trades, 'instrument_id')));
        $prices = $this->priceRepository->findDailyPricesForRange($instrumentIds, $from, $to);

        [$investedByAccount, $valueByInstrument] = $this->holdingValues($trades, $prices, $from, $to);

        return new AssetValues($accounts, $cashByAccount, $investedByAccount, $valueByInstrument, $trades, $prices);
    }

    /**
     * Market value of every holding day by day over [from, to], added up per
     * account and per instrument.
     *
     * @param array<array{account_id: string, instrument_id: string, type: string, quantity: string, amount: string, costs: string, day: string}> $trades
     * @param array<string, array<string, string>> $prices instrument id => (Y-m-d => price)
     * @return array{0: array<string, array<string, string>>, 1: array<string, array<string, string>>} account id => (Y-m-d => value), instrument id => (Y-m-d => value)
     */
    private function holdingValues(array $trades, array $prices, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // account id => instrument id => its trades, oldest first (the order findTradeRowsForAccounts returns them in)
        $tradesByHolding = [];
        foreach ($trades as $trade) {
            $tradesByHolding[$trade['account_id']][$trade['instrument_id']][] = $trade;
        }

        $byAccount = [];
        $byInstrument = [];
        foreach ($tradesByHolding as $accountId => $tradesByInstrument) {
            foreach ($tradesByInstrument as $instrumentId => $holdingTrades) {
                foreach (PositionHistory::daily($holdingTrades, $prices[$instrumentId] ?? [], $from, $to) as $point) {
                    $byAccount[$accountId][$point['date']] = bcadd($byAccount[$accountId][$point['date']] ?? '0', $point['value'], 4);
                    $byInstrument[$instrumentId][$point['date']] = bcadd($byInstrument[$instrumentId][$point['date']] ?? '0', $point['value'], 4);
                }
            }
        }

        return [$byAccount, $byInstrument];
    }
}
