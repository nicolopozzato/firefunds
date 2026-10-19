<?php

namespace App\Investment\Position;

use App\Entity\InvestmentTransactionType;

/**
 * Running quantity and cost basis of one holding under the average-cost
 * method: a BUY adds its full cost (its amount, what the broker charged for the
 * units, plus costs, i.e. fees and taxes); a SELL removes cost in proportion to the average cost per unit
 * at that point, so the average cost itself only ever moves on a BUY (a SELL's
 * costs reduce its proceeds, not the cost basis of what is still held).
 */
final class AverageCostPosition
{
    private string $quantity = '0';
    private string $costBasis = '0';

    public function apply(string $type, string $quantity, string $amount, string $costs): void
    {
        if ($type === InvestmentTransactionType::Buy->value) {
            $cost = bcadd($amount, $costs, 10);
            $this->quantity = bcadd($this->quantity, $quantity, 10);
            $this->costBasis = bcadd($this->costBasis, $cost, 10);

            return;
        }

        if (!$this->isClosed()) {
            $averageCost = bcdiv($this->costBasis, $this->quantity, 10);
            $this->costBasis = bcsub($this->costBasis, bcmul($averageCost, $quantity, 10), 10);
        }
        $this->quantity = bcsub($this->quantity, $quantity, 10);
    }

    /** Nothing held (never bought, or everything sold). */
    public function isClosed(): bool
    {
        return bccomp($this->quantity, '0', 10) === 0;
    }

    public function quantity(): string
    {
        return $this->quantity;
    }

    public function costBasis(): string
    {
        return $this->costBasis;
    }
}
