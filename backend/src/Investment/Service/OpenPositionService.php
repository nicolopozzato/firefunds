<?php

namespace App\Investment\Service;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Investment\DTO\OpenPositionResponse;
use App\Investment\Position\CapitalGainsTax;
use App\Investment\Position\PositionHistory;
use App\Repository\InvestmentPriceRepository;
use App\Repository\InvestmentTransactionRepository;

/**
 * The open positions of one investment account, as shown in its "Open
 * positions" section: each with current market data and its own daily
 * value/cost-basis history over a date range.
 *
 * Also the per-account totals (invested value and gain) shown wherever an
 * investment account's balance appears, for many accounts at once.
 *
 * Runs a fixed number of queries whatever the number of positions or accounts
 * (the trades, the latest prices, and for the history the prices in range)
 * and reads only the prices the range needs, not each instrument's whole
 * history.
 */
class OpenPositionService
{
    public function __construct(
        private readonly InvestmentTransactionRepository $transactionRepository,
        private readonly InvestmentPriceRepository $priceRepository,
        private readonly HoldingRates $holdingRates,
        private readonly PriceCalendar $calendar,
    ) {
    }

    /**
     * @return OpenPositionResponse[] empty for anything but an investment account
     */
    public function forAccount(Account $account, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($account->getType() !== AccountType::Asset || $account->getAssetKind() !== AssetAccountKind::Investment) {
            return [];
        }

        $trades = $this->transactionRepository->findTradeRows($account);
        $positions = $this->transactionRepository->positionsFromTrades($trades);

        if ($positions === []) {
            return [];
        }

        $instrumentIds = array_column($positions, 'instrumentId');
        $latestPrices = $this->priceRepository->findLatestPrices($instrumentIds);
        $pricesInRange = $this->priceRepository->findDailyPricesForRange($instrumentIds, $from, $to);

        // Quoted in the instrument's currency, valued in the account's: converted day by day,
        // and the latest price at yesterday's rate (the day prices and rates are fetched through).
        $today = $this->calendar->yesterday();
        $rates = $this->holdingRates->forTrades($account->getUser(), $trades, $from, max($to, $today));

        $tradesByInstrument = [];
        foreach ($trades as $trade) {
            $tradesByInstrument[$trade['instrument_id']][] = $trade;
        }

        $userRate = $account->getUser()->getCapitalGainsTaxRate();

        return array_map(
            function (array $position) use ($latestPrices, $pricesInRange, $tradesByInstrument, $rates, $today, $userRate, $from, $to) {
                $quoteCurrency = $position['quoteCurrency'] ?? $position['accountCurrency'];
                $accountCurrency = $position['accountCurrency'] ?? $quoteCurrency;
                $quotePrice = $latestPrices[$position['instrumentId']] ?? null;
                $price = $quotePrice !== null ? $rates->convertTo4($quotePrice, $quoteCurrency, $accountCurrency, $today->format('Y-m-d')) : null;

                return OpenPositionResponse::fromRow(
                    $this->withMarketData($position, $price, $userRate) + ['quotePrice' => $quotePrice, 'quoteCurrency' => $quoteCurrency],
                    PositionHistory::daily(
                        $tradesByInstrument[$position['instrumentId']] ?? [],
                        $rates->convertSeries($pricesInRange[$position['instrumentId']] ?? [], $quoteCurrency, $accountCurrency),
                        $from,
                        $to,
                    ),
                );
            },
            $positions
        );
    }

    /**
     * Current market value of everything held on each investment account
     * (quantity x latest recorded price, summed over its open positions), its
     * cost basis, and the unrealized gain/loss against it. A position on an
     * instrument with no price yet adds 0 to the value but still counts its
     * cost basis, so one missing quote doesn't hide the rest of the portfolio.
     * An investment account with nothing held gets zeros; any other account
     * is left out.
     *
     * @param Account[] $accounts
     * The estimated tax is summed position by position, each at its own rate
     * and only on a gain: for Italian funds/ETFs a loss on one position does
     * not offset another's gain.
     *
     * With a day, the same at the end of that day: the trades up to it, at
     * the latest price on or before it.
     *
     * @return array<string, array{investedValue: string, costBasis: string, gainAmount: string, gainPercent: string, estimatedTax: string}> account id => totals
     */
    public function investedSummaries(array $accounts, ?\DateTimeInterface $on = null): array
    {
        $investmentAccounts = array_values(array_filter(
            $accounts,
            static fn (Account $account) => $account->getType() === AccountType::Asset && $account->getAssetKind() === AssetAccountKind::Investment,
        ));

        if ($investmentAccounts === []) {
            return [];
        }

        $lastDay = $on?->format('Y-m-d');
        $tradesByAccount = [];
        $tradeRows = [];
        foreach ($this->transactionRepository->findTradeRowsForAccounts($investmentAccounts) as $trade) {
            if ($lastDay === null || $trade['day'] <= $lastDay) {
                $tradesByAccount[$trade['account_id']][] = $trade;
                $tradeRows[] = $trade;
            }
        }

        // Priced in the instrument's currency, valued in the account's: at that day's rate.
        $valueDay = $on ?? $this->calendar->yesterday();
        $rates = $this->holdingRates->forTrades($investmentAccounts[0]->getUser(), $tradeRows, $valueDay, $valueDay);

        $positionsByAccount = array_map(
            fn (array $trades) => $this->transactionRepository->positionsFromTrades($trades),
            $tradesByAccount
        );

        $instrumentIds = array_values(array_unique(array_merge(...array_map(
            static fn (array $positions) => array_column($positions, 'instrumentId'),
            array_values($positionsByAccount)
        ))));
        $latestPrices = $on !== null
            ? $this->priceRepository->findPricesOn($instrumentIds, $on)
            : $this->priceRepository->findLatestPrices($instrumentIds);

        $summaries = [];
        foreach ($investmentAccounts as $account) {
            $id = $account->getId()->toRfc4122();
            $value = '0';
            $costBasis = '0';
            $estimatedTax = '0';
            $userRate = $account->getUser()->getCapitalGainsTaxRate();

            foreach ($positionsByAccount[$id] ?? [] as $position) {
                $costBasis = bcadd($costBasis, $position['costBasis'], 10);

                $price = $latestPrices[$position['instrumentId']] ?? null;
                if ($price !== null) {
                    $price = $rates->convertTo4($price, $position['quoteCurrency'] ?? $position['accountCurrency'], $position['accountCurrency'] ?? $position['quoteCurrency'], $valueDay->format('Y-m-d'));
                }
                if ($price !== null) {
                    $positionValue = bcmul($position['quantity'], $price, 10);
                    $value = bcadd($value, $positionValue, 10);
                    $estimatedTax = bcadd($estimatedTax, CapitalGainsTax::on(
                        bcsub($positionValue, $position['costBasis'], 10),
                        $position['taxRate'] ?? $userRate,
                    ), 10);
                }
            }

            $value = bcadd($value, '0', 4);
            $costBasis = bcadd($costBasis, '0', 4);
            $gainAmount = bcsub($value, $costBasis, 4);

            $summaries[$id] = [
                'investedValue' => $value,
                'costBasis' => $costBasis,
                'gainAmount' => $gainAmount,
                'gainPercent' => bccomp($costBasis, '0', 10) !== 0
                    ? bcmul(bcdiv($gainAmount, $costBasis, 10), '100', 4)
                    : '0.0000',
                'estimatedTax' => bcadd($estimatedTax, '0', 4),
            ];
        }

        return $summaries;
    }

    /**
     * Current price and value, and the unrealized gain/loss (amount and
     * percentage of cost basis). A position on an instrument with no recorded
     * price yet gets nulls for these rather than being dropped.
     *
     * @param array{instrumentId: string, instrumentName: string, instrumentTicker: ?string, quantity: string, costBasis: string, averageCost: string, openedOn: ?string} $position
     * @return array{instrumentId: string, instrumentName: string, instrumentTicker: ?string, quantity: string, costBasis: string, averageCost: string, openedOn: ?string, currentPrice: ?string, currentValue: ?string, gainAmount: ?string, gainPercent: ?string}
     */
    private function withMarketData(array $position, ?string $price, string $userRate): array
    {
        $taxRate = $position['taxRate'] ?? $userRate;
        unset($position['taxRate']);

        if ($price === null) {
            return $position + [
                'currentPrice' => null,
                'currentValue' => null,
                'gainAmount' => null,
                'gainPercent' => null,
                'taxRate' => $taxRate,
                'estimatedTax' => null,
            ];
        }

        $currentValue = bcmul($position['quantity'], $price, 4);
        $gainAmount = bcsub($currentValue, $position['costBasis'], 4);
        $gainPercent = bccomp($position['costBasis'], '0', 10) !== 0
            ? bcmul(bcdiv($gainAmount, $position['costBasis'], 10), '100', 4)
            : '0.0000';

        return $position + [
            'currentPrice' => $price,
            'currentValue' => $currentValue,
            'gainAmount' => $gainAmount,
            'gainPercent' => $gainPercent,
            'taxRate' => $taxRate,
            'estimatedTax' => CapitalGainsTax::on($gainAmount, $taxRate),
        ];
    }
}
