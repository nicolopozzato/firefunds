<?php

namespace App\Investment\Position;

/**
 * Day-by-day value and cost basis of one holding (one instrument on one
 * account), for the charts.
 */
final class PositionHistory
{
    /**
     * Value and cost basis at the end of each day in [from, to]: `value` is
     * the quantity held as of that day times the latest price on or before
     * it; `costBasis` the average-cost basis as of that day. Trades before
     * `from` count too, so a holding opened before the range starts at its
     * real value instead of zero.
     *
     * @param array<array{type: string, quantity: string, amount: string, costs: string, day: string}> $trades oldest first
     * @param array<string, string> $priceByDate Y-m-d => price, oldest first, possibly starting before `from`
     * @return array<array{date: string, value: string, costBasis: string}>
     */
    public static function daily(array $trades, array $priceByDate, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $position = new AverageCostPosition();
        $nextTrade = 0;
        $tradeCount = \count($trades);

        $firstDay = $from->format('Y-m-d');
        $lastDay = $to->format('Y-m-d');

        $lastPrice = PriceSeries::before($priceByDate, $firstDay);

        $points = [];
        for ($date = new \DateTimeImmutable($firstDay); ($day = $date->format('Y-m-d')) <= $lastDay; $date = $date->modify('+1 day')) {
            while ($nextTrade < $tradeCount && $trades[$nextTrade]['day'] <= $day) {
                $trade = $trades[$nextTrade++];
                $position->apply($trade['type'], $trade['quantity'], $trade['amount'], $trade['costs']);
            }

            $lastPrice = $priceByDate[$day] ?? $lastPrice;
            $value = $lastPrice !== null ? bcmul($position->quantity(), $lastPrice, 10) : '0';

            $points[] = [
                'date' => $day,
                'value' => bcadd($value, '0', 4),
                'costBasis' => bcadd($position->costBasis(), '0', 4),
            ];
        }

        return $points;
    }
}
