<?php

namespace App\Investment\Position;

use App\Entity\InvestmentTransactionType;

/**
 * Running quantity and cost basis of one holding under the average-cost
 * method, as a broker keeps it.
 *
 * A BUY adds its full cost (its amount, what the broker charged for the
 * units, plus costs, i.e. fees and taxes); a SELL removes cost in proportion
 * to the average cost per unit at that point, so the average itself only
 * moves on a BUY (a SELL's costs reduce its proceeds, not the cost basis of
 * what is still held).
 *
 * Selling more than is held is allowed (decided): the units sold beyond the
 * holding open a negative position whose basis is what they brought in (the
 * SELL's proceeds, net of costs, for that share), negative. A BUY on a
 * negative position covers it first, the same way; whatever is left over
 * opens a positive one at its own cost. A position back at zero has no basis
 * left: nothing carries over to the next one.
 */
final class AverageCostPosition
{
    private const SCALE = 10;

    private string $quantity = '0';
    private string $costBasis = '0';

    public function apply(string $type, string $quantity, string $amount, string $costs): void
    {
        $isBuy = $type === InvestmentTransactionType::Buy->value;
        // The units' effect on the position and on its basis: a BUY's cost
        // adds, a SELL's proceeds (net of costs) take away.
        $units = $isBuy ? $quantity : bcmul($quantity, '-1', self::SCALE);
        $basis = $isBuy ? bcadd($amount, $costs, self::SCALE) : bcsub('0', bcsub($amount, $costs, self::SCALE), self::SCALE);

        if ($this->isClosed() || $this->sign($this->quantity) === $this->sign($units)) {
            // Opens the position, or adds to it.
            $this->quantity = bcadd($this->quantity, $units, self::SCALE);
            $this->costBasis = bcadd($this->costBasis, $basis, self::SCALE);

            return;
        }

        // Reduces the position: at its own average, so the average stays.
        $held = $this->absolute($this->quantity);
        if (bccomp($quantity, $held, self::SCALE) < 0) {
            $this->costBasis = bcsub($this->costBasis, bcdiv(bcmul($this->costBasis, $quantity, self::SCALE), $held, self::SCALE), self::SCALE);
            $this->quantity = bcadd($this->quantity, $units, self::SCALE);

            return;
        }

        // Closes it; units beyond it open the other side at their share of the trade.
        $beyond = bcsub($quantity, $held, self::SCALE);
        $this->quantity = bcadd($this->quantity, $units, self::SCALE);
        $this->costBasis = bccomp($beyond, '0', self::SCALE) === 0
            ? '0'
            : bcdiv(bcmul($basis, $beyond, self::SCALE), $quantity, self::SCALE);
    }

    /** Nothing held (never traded, or back to zero). */
    public function isClosed(): bool
    {
        return bccomp($this->quantity, '0', self::SCALE) === 0;
    }

    public function quantity(): string
    {
        return $this->quantity;
    }

    public function costBasis(): string
    {
        return $this->costBasis;
    }

    private function sign(string $value): int
    {
        return bccomp($value, '0', self::SCALE);
    }

    private function absolute(string $value): string
    {
        return $this->sign($value) < 0 ? bcmul($value, '-1', self::SCALE) : $value;
    }
}
