<?php

namespace App\Investment\Service;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\InvestmentTransactionType;
use App\Entity\User;
use App\Investment\DTO\MonthlyInvestmentFlowResponse;
use App\Investment\DTO\PortfolioPositionAccountResponse;
use App\Investment\DTO\PortfolioPositionResponse;
use App\Investment\DTO\PortfolioResponse;
use App\Investment\DTO\PositionHistoryPointResponse;
use App\Investment\Position\CapitalGainsTax;
use App\Investment\Position\PositionHistory;
use App\Repository\AccountRepository;
use App\Repository\InvestmentPriceRepository;
use App\Repository\InvestmentTransactionRepository;

/**
 * The user's whole portfolio, every investment account at once: each
 * instrument held as one position whatever account it is on, how it weighs
 * on the net worth, how it moved over a date range and the money put in
 * month by month.
 *
 * Positions are folded account by account (each broker keeps its own average
 * cost) and only then added up per instrument. Runs a fixed number of queries
 * whatever the number of accounts, instruments or trades, like
 * OpenPositionService.
 */
class PortfolioService
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly InvestmentTransactionRepository $transactionRepository,
        private readonly InvestmentPriceRepository $priceRepository,
    ) {
    }

    public function forUser(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): PortfolioResponse
    {
        $assetAccounts = $this->accountRepository->findByUserAndType($user, AccountType::Asset);
        $investmentAccounts = array_values(array_filter(
            $assetAccounts,
            static fn (Account $account) => $account->getAssetKind() === AssetAccountKind::Investment,
        ));

        $balances = $this->accountRepository->getCurrentBalances($assetAccounts);
        $trades = $this->transactionRepository->findTradeRowsForAccounts($investmentAccounts);

        $instrumentIds = array_values(array_unique(array_column($trades, 'instrument_id')));
        $latestPrices = $this->priceRepository->findLatestPrices($instrumentIds);
        $pricesInRange = $this->priceRepository->findDailyPricesForRange($instrumentIds, $from, $to);

        $accountNames = [];
        foreach ($investmentAccounts as $account) {
            $accountNames[$account->getId()->toRfc4122()] = $account->getName();
        }

        // account id => instrument id => its trades, oldest first (the order findTradeRowsForAccounts returns them in)
        $tradesByHolding = [];
        foreach ($trades as $trade) {
            $tradesByHolding[$trade['account_id']][$trade['instrument_id']][] = $trade;
        }

        $holdingsByInstrument = [];
        $historyByInstrument = [];
        foreach ($tradesByHolding as $accountId => $tradesByInstrument) {
            foreach ($tradesByInstrument as $instrumentId => $holdingTrades) {
                // Closed holdings too: what they were worth before being sold belongs in the portfolio's history.
                $historyByInstrument[$instrumentId] = $this->addHistories(
                    $historyByInstrument[$instrumentId] ?? [],
                    array_column(PositionHistory::daily($holdingTrades, $pricesInRange[$instrumentId] ?? [], $from, $to), null, 'date'),
                );

                $position = $this->transactionRepository->positionsFromTrades($holdingTrades)[0] ?? null;
                if ($position !== null) {
                    $holdingsByInstrument[$instrumentId][] = $position + [
                        'accountId' => $accountId,
                        'accountName' => $accountNames[$accountId],
                    ];
                }
            }
        }

        $userRate = $user->getCapitalGainsTaxRate();
        $positions = [];
        foreach ($holdingsByInstrument as $instrumentId => $holdings) {
            $positions[] = $this->position($holdings, $latestPrices[$instrumentId] ?? null, $userRate, $historyByInstrument[$instrumentId]);
        }

        $marketValue = '0.0000';
        $costBasis = '0.0000';
        $estimatedTax = '0.0000';
        foreach ($positions as $position) {
            $marketValue = bcadd($marketValue, $position['currentValue'] ?? '0', 4);
            $costBasis = bcadd($costBasis, $position['costBasis'], 4);
            $estimatedTax = bcadd($estimatedTax, $position['estimatedTax'] ?? '0', 4);
        }

        $cash = '0.0000';
        foreach ($investmentAccounts as $account) {
            $cash = bcadd($cash, $balances[$account->getId()->toRfc4122()] ?? '0', 4);
        }
        $netWorth = bcadd(array_reduce($balances, static fn (string $sum, string $balance) => bcadd($sum, $balance, 4), '0.0000'), $marketValue, 4);

        usort($positions, static fn (array $a, array $b) => bccomp($b['currentValue'] ?? '0', $a['currentValue'] ?? '0', 4));

        $gainAmount = bcsub($marketValue, $costBasis, 4);

        return new PortfolioResponse(
            currencySymbol: ($investmentAccounts[0] ?? null)?->getCurrency()?->getSymbol(),
            marketValue: $marketValue,
            costBasis: $costBasis,
            gainAmount: $gainAmount,
            gainPercent: $this->percentage($gainAmount, $costBasis) ?? '0.0000',
            estimatedTax: $estimatedTax,
            cash: $cash,
            netWorth: $netWorth,
            netWorthShare: $this->percentage($marketValue, $netWorth),
            positions: array_map(
                fn (array $position) => $this->toPositionResponse($position, $marketValue, $netWorth),
                $positions
            ),
            history: $this->toHistoryResponse(array_reduce($historyByInstrument, $this->addHistories(...), [])),
            monthlyFlows: $this->monthlyFlows($trades, $from, $to),
        );
    }

    /**
     * One instrument added up over the accounts holding it.
     *
     * @param array<array{accountId: string, accountName: string, quantity: string, costBasis: string, openedOn: ?string, taxRate: ?string, instrumentId: string, instrumentName: string, instrumentTicker: ?string}> $holdings
     * @param array<string, array{value: string, costBasis: string}> $history
     * @return array<string, mixed>
     */
    private function position(array $holdings, ?string $price, string $userRate, array $history): array
    {
        $taxRate = $holdings[0]['taxRate'] ?? $userRate;
        $quantity = '0';
        $costBasis = '0';
        $estimatedTax = '0';
        $openedOn = null;
        $accounts = [];

        foreach ($holdings as $holding) {
            $quantity = bcadd($quantity, $holding['quantity'], 6);
            $costBasis = bcadd($costBasis, $holding['costBasis'], 4);
            if ($holding['openedOn'] !== null && ($openedOn === null || $holding['openedOn'] < $openedOn)) {
                $openedOn = $holding['openedOn'];
            }

            $value = $price !== null ? bcmul($holding['quantity'], $price, 4) : null;
            $gain = $value !== null ? bcsub($value, $holding['costBasis'], 4) : null;
            if ($gain !== null) {
                $estimatedTax = bcadd($estimatedTax, CapitalGainsTax::on($gain, $taxRate), 4);
            }

            $accounts[] = new PortfolioPositionAccountResponse(
                accountId: $holding['accountId'],
                accountName: $holding['accountName'],
                quantity: $holding['quantity'],
                costBasis: $holding['costBasis'],
                averageCost: $holding['averageCost'],
                currentValue: $value,
                gainAmount: $gain,
                gainPercent: $gain !== null ? $this->percentage($gain, $holding['costBasis']) ?? '0.0000' : null,
            );
        }

        $currentValue = $price !== null ? bcmul($quantity, $price, 4) : null;
        $gainAmount = $currentValue !== null ? bcsub($currentValue, $costBasis, 4) : null;

        return [
            'instrumentId' => $holdings[0]['instrumentId'],
            'instrumentName' => $holdings[0]['instrumentName'],
            'instrumentTicker' => $holdings[0]['instrumentTicker'],
            'quantity' => $quantity,
            'costBasis' => $costBasis,
            'averageCost' => bcdiv($costBasis, $quantity, 4),
            'openedOn' => $openedOn,
            'currentPrice' => $price,
            'currentValue' => $currentValue,
            'gainAmount' => $gainAmount,
            'gainPercent' => $gainAmount !== null ? $this->percentage($gainAmount, $costBasis) ?? '0.0000' : null,
            'taxRate' => $taxRate,
            'estimatedTax' => $price !== null ? $estimatedTax : null,
            'accounts' => $accounts,
            'history' => $history,
        ];
    }

    /** @param array<string, mixed> $position */
    private function toPositionResponse(array $position, string $marketValue, string $netWorth): PortfolioPositionResponse
    {
        $value = $position['currentValue'];

        return new PortfolioPositionResponse(
            instrumentId: $position['instrumentId'],
            instrumentName: $position['instrumentName'],
            instrumentTicker: $position['instrumentTicker'],
            quantity: $position['quantity'],
            costBasis: $position['costBasis'],
            averageCost: $position['averageCost'],
            openedOn: $position['openedOn'],
            currentPrice: $position['currentPrice'],
            currentValue: $value,
            gainAmount: $position['gainAmount'],
            gainPercent: $position['gainPercent'],
            taxRate: $position['taxRate'],
            estimatedTax: $position['estimatedTax'],
            portfolioShare: $value !== null ? $this->percentage($value, $marketValue) : null,
            netWorthShare: $value !== null ? $this->percentage($value, $netWorth) : null,
            accounts: $position['accounts'],
            history: $this->toHistoryResponse($position['history']),
        );
    }

    /**
     * Two histories over the same days added up, day by day.
     *
     * @param array<string, array{value: string, costBasis: string}> $sum date => totals
     * @param array<string, array{value: string, costBasis: string}> $points date => point
     * @return array<string, array{value: string, costBasis: string}> date => totals
     */
    private function addHistories(array $sum, array $points): array
    {
        foreach ($points as $date => $point) {
            $sum[$date] = [
                'value' => bcadd($sum[$date]['value'] ?? '0', $point['value'], 4),
                'costBasis' => bcadd($sum[$date]['costBasis'] ?? '0', $point['costBasis'], 4),
            ];
        }

        return $sum;
    }

    /**
     * @param array<string, array{value: string, costBasis: string}> $history
     * @return PositionHistoryPointResponse[]
     */
    private function toHistoryResponse(array $history): array
    {
        return array_map(
            static fn (string $date, array $point) => new PositionHistoryPointResponse($date, $point['value'], $point['costBasis']),
            array_keys($history),
            $history
        );
    }

    /**
     * Every month touching [from, to], also those with no trade, so a chart
     * shows the gaps.
     *
     * @param array<array{type: string, amount: string, costs: string, day: string}> $trades
     * @return MonthlyInvestmentFlowResponse[]
     */
    private function monthlyFlows(array $trades, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $months = [];
        for ($month = $from->modify('first day of this month'); $month <= $to; $month = $month->modify('+1 month')) {
            $months[$month->format('Y-m')] = ['bought' => '0', 'sold' => '0'];
        }

        $firstDay = $from->format('Y-m-d');
        $lastDay = $to->format('Y-m-d');
        foreach ($trades as $trade) {
            if ($trade['day'] < $firstDay || $trade['day'] > $lastDay) {
                continue;
            }

            $month = substr($trade['day'], 0, 7);
            if ($trade['type'] === InvestmentTransactionType::Buy->value) {
                $months[$month]['bought'] = bcadd($months[$month]['bought'], bcadd($trade['amount'], $trade['costs'], 4), 4);
            } else {
                $months[$month]['sold'] = bcadd($months[$month]['sold'], bcsub($trade['amount'], $trade['costs'], 4), 4);
            }
        }

        return array_map(
            static fn (string $month, array $flow) => new MonthlyInvestmentFlowResponse(
                $month,
                bcadd($flow['bought'], '0', 4),
                bcadd($flow['sold'], '0', 4),
                bcsub($flow['bought'], $flow['sold'], 4),
            ),
            array_keys($months),
            $months
        );
    }

    /** part as a percentage of whole; null unless whole is positive. */
    private function percentage(string $part, string $whole): ?string
    {
        if (bccomp($whole, '0', 10) <= 0) {
            return null;
        }

        return bcmul(bcdiv($part, $whole, 10), '100', 4);
    }
}
