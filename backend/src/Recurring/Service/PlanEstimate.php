<?php

namespace App\Recurring\Service;

use App\Currency\Service\CurrencyConverter;
use App\Entity\InvestmentPlanMode;
use App\Entity\RecurringTransaction;
use App\Repository\InvestmentPriceRepository;

/**
 * What a recurring purchase (PAC) would buy at the latest price recorded:
 * the units and the amount, worked out as its plan mode says. Only a guess,
 * shown before the purchase and filled into the trade form, where the user
 * puts the broker's figures.
 */
class PlanEstimate
{
    public function __construct(
        private readonly InvestmentPriceRepository $prices,
        private readonly CurrencyConverter $converter,
    ) {
    }

    /**
     * @return array{quantity: ?string, unitPrice: ?string, amount: ?string, priceDate: ?string}
     *                                  unitPrice in the instrument's currency, amount in the account's; null where
     *                                  there is no price (or no rate) to work it out from
     */
    public function on(RecurringTransaction $recurring, \DateTimeImmutable $day): array
    {
        $instrument = $recurring->getInstrument();
        \assert($instrument !== null);
        $mode = $recurring->getPlanMode();
        $amount = $mode === InvestmentPlanMode::Units ? null : $recurring->amountOn($day);
        $quantity = $mode === InvestmentPlanMode::Units ? $recurring->getQuantity() : null;

        $price = $this->prices->findLatestRecorded($instrument);
        $unitPrice = $price !== null ? $this->unitPriceInAccountCurrency($recurring, $price->getPrice(), $price->getDate()->format('Y-m-d')) : null;
        if ($price === null || $unitPrice === null || bccomp($unitPrice, '0', 8) <= 0) {
            return ['quantity' => $quantity, 'unitPrice' => $price?->getPrice(), 'amount' => $amount, 'priceDate' => $price?->getDate()->format('Y-m-d')];
        }

        switch ($mode) {
            case InvestmentPlanMode::Units:
                $amount = bcadd(bcmul((string) $quantity, $unitPrice, 8), '0.005', 2);
                break;
            case InvestmentPlanMode::WholeUnits:
                $quantity = bcdiv((string) $amount, $unitPrice, 0);
                $amount = bcadd(bcmul($quantity, $unitPrice, 8), '0.005', 2);
                break;
            default:
                $quantity = bcdiv((string) $amount, $unitPrice, 6);
        }

        return ['quantity' => $quantity, 'unitPrice' => $price->getPrice(), 'amount' => $amount, 'priceDate' => $price->getDate()->format('Y-m-d')];
    }

    /** The amount the occurrence on that day is expected to cost, in the account's currency: null when unknown. */
    public function amountOn(RecurringTransaction $recurring, \DateTimeImmutable $day): ?string
    {
        return $this->on($recurring, $day)['amount'];
    }

    private function unitPriceInAccountCurrency(RecurringTransaction $recurring, string $price, string $day): ?string
    {
        $quote = $recurring->getInstrument()?->getCurrency();
        $account = $recurring->getSourceAccount()?->getCurrency()?->getCode();
        if ($quote === null || $account === null || $quote === $account) {
            return $price;
        }
        $date = new \DateTimeImmutable($day);

        return $this->converter->tableFor($recurring->getUser(), [$quote, $account], $date, $date)->convertTo4($price, $quote, $account, $day);
    }
}
