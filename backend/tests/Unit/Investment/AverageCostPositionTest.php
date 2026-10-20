<?php

namespace App\Tests\Unit\Investment;

use App\Investment\Position\AverageCostPosition;
use PHPUnit\Framework\TestCase;

/**
 * The average-cost position as a broker keeps it: back at zero nothing
 * carries over, and selling beyond the holding opens a negative position
 * with its own basis, which a later BUY covers.
 */
class AverageCostPositionTest extends TestCase
{
    public function testASellAtTheAverageLeavesTheAverageAlone(): void
    {
        $position = $this->trades(['buy', '4', '400'], ['sell', '1', '130']);

        $this->assertSame(['3', '300'], $this->state($position));
    }

    /** The review's case: a SELL entered before the BUY of the same day used to leave 1,000 of basis behind. */
    public function testNothingCarriesOverOnceBackAtZero(): void
    {
        $position = $this->trades(['sell', '10', '1000'], ['buy', '10', '1000']);
        $this->assertSame(['0', '0'], $this->state($position));

        $position->apply('buy', '10', '1300', '0');
        $this->assertSame(['10', '1300'], $this->state($position), 'average 130, not 230');
    }

    public function testSellingBeyondTheHoldingOpensANegativePositionAtItsShareOfTheProceeds(): void
    {
        // 10 held at 100; 15 sold at 120: 10 close the holding, 5 open a negative one worth -600.
        $position = $this->trades(['buy', '10', '1000'], ['sell', '15', '1800']);
        $this->assertSame(['-5', '-600'], $this->state($position));

        // Covering 2 takes out 2/5 of it, the average stays 120.
        $position->apply('buy', '2', '220', '0');
        $this->assertSame(['-3', '-360'], $this->state($position));

        // Buying 8 at 110 covers the 3 left and opens 5 at their share of the cost.
        $position->apply('buy', '8', '880', '0');
        $this->assertSame(['5', '550'], $this->state($position));
    }

    public function testCostsAddToABuyAndReduceASellsProceeds(): void
    {
        $position = new AverageCostPosition();
        $position->apply('sell', '2', '200', '4');

        $this->assertSame(['-2', '-196'], $this->state($position));
    }

    /** @param array{0: string, 1: string, 2: string} ...$trades type, quantity, amount (no costs) */
    private function trades(array ...$trades): AverageCostPosition
    {
        $position = new AverageCostPosition();
        foreach ($trades as [$type, $quantity, $amount]) {
            $position->apply($type, $quantity, $amount, '0');
        }

        return $position;
    }

    /** @return array{0: string, 1: string} quantity, cost basis, without trailing zeros */
    private function state(AverageCostPosition $position): array
    {
        $trim = static fn (string $value) => rtrim(rtrim($value, '0'), '.') ?: '0';

        return [$trim($position->quantity()), $trim($position->costBasis())];
    }
}
