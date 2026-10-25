<?php

namespace App\Currency\Service;

/**
 * The rates a computation needs, loaded once: for each currency its rate on
 * the days it has (units of the currency for 1 EUR; the euro itself is always
 * 1). A day without a rate takes the latest one before it, and before the
 * first one the first: a figure from before the data starts is still valued.
 * Any pair is a division through the euro (100 USD at 1 EUR = 1.10 USD and
 * 0.85 GBP: 100 / 1.10 x 0.85 = 77.27 GBP).
 */
final class RateTable
{
    /**
     * @param array<string, array<string, string>> $perEuro currency => (Y-m-d => rate), oldest first
     */
    public function __construct(private readonly array $perEuro)
    {
    }

    /** Units of $currency for 1 EUR on a day; null for a currency with no rate at all. */
    public function perEuro(string $currency, string $day): ?string
    {
        if ($currency === 'EUR') {
            return '1';
        }
        $days = $this->perEuro[$currency] ?? null;
        if ($days === null || $days === []) {
            return null;
        }
        $dates = array_keys($days);
        $low = 0;
        $high = \count($dates) - 1;
        if ($day < $dates[0]) {
            return $days[$dates[0]];
        }
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if ($dates[$middle] <= $day) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $days[$dates[$low]];
    }

    /** What 1 unit of $from is worth in $to on a day; null when either has no rate. */
    public function rate(string $from, string $to, string $day): ?string
    {
        if ($from === $to) {
            return '1';
        }
        $fromPerEuro = $this->perEuro($from, $day);
        $toPerEuro = $this->perEuro($to, $day);
        if ($fromPerEuro === null || $toPerEuro === null || bccomp($fromPerEuro, '0', 8) <= 0) {
            return null;
        }

        return bcdiv($toPerEuro, $fromPerEuro, 16);
    }

    /** An amount in $from as it was worth in $to on a day; null when there is no rate. */
    public function convert(string $amount, string $from, string $to, string $day): ?string
    {
        $rate = $this->rate($from, $to, $day);

        return $rate === null ? null : self::round(bcmul($amount, $rate, 16), 10);
    }

    /** convert(), as a price or an amount is kept: 4 decimals. */
    public function convertTo4(string $amount, string $from, string $to, string $day): ?string
    {
        $converted = $this->convert($amount, $from, $to, $day);

        return $converted === null ? null : self::round($converted, 4);
    }

    /**
     * A series of values by day (a price per day) converted, each at its own
     * day's rate. Days with no rate for the pair are left out.
     *
     * @param array<string, string> $valueByDay
     * @return array<string, string>
     */
    public function convertSeries(array $valueByDay, string $from, string $to): array
    {
        if ($from === $to) {
            return $valueByDay;
        }

        $converted = [];
        foreach ($valueByDay as $day => $value) {
            $result = $this->convert($value, $from, $to, $day);
            if ($result !== null) {
                $converted[$day] = self::round($result, 4);
            }
        }

        return $converted;
    }

    /** Rounded half up (away from zero) to $scale decimals: truncating would turn 100 into 99.9999 after a there-and-back. */
    private static function round(string $value, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', 16) >= 0 ? bcadd($value, $half, $scale) : bcsub($value, $half, $scale);
    }
}
