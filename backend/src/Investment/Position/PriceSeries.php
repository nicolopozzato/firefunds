<?php

namespace App\Investment\Position;

/**
 * Looking a price up in a series of daily prices (Y-m-d => price, oldest
 * first, as InvestmentPriceRepository returns them: already carried over
 * weekends and holidays, so this only reaches further back for a day before
 * the first quote).
 */
final class PriceSeries
{
    /** The last price recorded on or before $day, null if there is none yet. */
    public static function onOrBefore(array $priceByDate, string $day): ?string
    {
        $price = null;
        foreach ($priceByDate as $date => $value) {
            if ($date > $day) {
                break;
            }
            $price = $value;
        }

        return $price;
    }

    /** The last price recorded before $day (not on it). */
    public static function before(array $priceByDate, string $day): ?string
    {
        $price = null;
        foreach ($priceByDate as $date => $value) {
            if ($date >= $day) {
                break;
            }
            $price = $value;
        }

        return $price;
    }
}
