<?php

namespace App\Account\Service;

use App\Currency\Service\CurrencyConverter;
use App\Currency\Service\RateTable;
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
        private readonly CurrencyConverter $converter,
    ) {
    }

    /** The values of every asset account of the user at the end of every day in [from, to]. */
    public function forUser(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): AssetValues
    {
        return $this->forAccounts($this->accountRepository->findByUserAndType($user, AccountType::Asset), $from, $to);
    }

    /**
     * The same for these accounts only (one account's page: its chart), of any
     * type; the holdings are those of the investment accounts among them.
     *
     * @param Account[] $accounts
     */
    public function forAccounts(array $accounts, \DateTimeImmutable $from, \DateTimeImmutable $to): AssetValues
    {
        $investmentAccounts = array_values(array_filter(
            $accounts,
            static fn (Account $account) => $account->getAssetKind() === AssetAccountKind::Investment,
        ));

        $cashByAccount = $this->accountRepository->getDailyBalanceHistories($accounts, $from, $to);
        $trades = $this->investmentTransactionRepository->findTradeRowsForAccounts($investmentAccounts);
        $instrumentIds = array_values(array_unique(array_column($trades, 'instrument_id')));
        $prices = $this->priceRepository->findDailyPricesForRange($instrumentIds, $from, $to);

        // What adds accounts up is in the user's currency: every currency in play, loaded once.
        $currencyByAccount = [];
        foreach ($accounts as $account) {
            $currencyByAccount[$account->getId()->toRfc4122()] = $account->getCurrency()?->getCode();
        }
        $user = ($accounts[0] ?? null)?->getUser();
        $currency = $user?->getCurrency() ?? 'EUR';
        $inPlay = array_unique(array_filter([$currency, ...array_values($currencyByAccount), ...array_column($trades, 'instrument_currency')]));
        $rates = $user === null || \count($inPlay) < 2 ? new RateTable([]) : $this->converter->tableFor($user, $inPlay, $from, $to);

        [$investedByAccount, $valueByInstrument] = $this->holdingValues($trades, $prices, $rates, $currency, $from, $to);

        return new AssetValues($accounts, $cashByAccount, $investedByAccount, $valueByInstrument, $trades, $prices, $rates, $currency, $currencyByAccount);
    }

    /**
     * Market value of every holding day by day over [from, to], added up per
     * account and per instrument.
     *
     * @param array<array{account_id: string, instrument_id: string, type: string, quantity: string, amount: string, costs: string, day: string}> $trades
     * @param array<string, array<string, string>> $prices instrument id => (Y-m-d => price), in the instrument's currency
     * @return array{0: array<string, array<string, string>>, 1: array<string, array<string, string>>} account id => (Y-m-d => value), instrument id => (Y-m-d => value)
     */
    private function holdingValues(array $trades, array $prices, RateTable $rates, string $currency, \DateTimeImmutable $from, \DateTimeImmutable $to): array
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
                // The price in the currency of the account holding it.
                $price = $rates->convertSeries($prices[$instrumentId] ?? [], $holdingTrades[0]['instrument_currency'], $holdingTrades[0]['account_currency'] ?? $holdingTrades[0]['instrument_currency']);
                $accountCurrency = $holdingTrades[0]['account_currency'] ?? $holdingTrades[0]['instrument_currency'];
                foreach (PositionHistory::daily($holdingTrades, $price, $from, $to) as $point) {
                    $byAccount[$accountId][$point['date']] = bcadd($byAccount[$accountId][$point['date']] ?? '0', $point['value'], 4);
                    // An instrument's value is added up over accounts, which can be in different currencies: in the user's.
                    $inUser = $accountCurrency === $currency ? $point['value'] : ($rates->convertTo4($point['value'], $accountCurrency, $currency, $point['date']) ?? $point['value']);
                    $byInstrument[$instrumentId][$point['date']] = bcadd($byInstrument[$instrumentId][$point['date']] ?? '0', $inUser, 4);
                }
            }
        }

        return [$byAccount, $byInstrument];
    }
}
