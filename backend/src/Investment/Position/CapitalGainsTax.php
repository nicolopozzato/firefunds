<?php

namespace App\Investment\Position;

/**
 * Capital gains tax owed if a position were sold today: an estimate.
 */
final class CapitalGainsTax
{
    /** Tax on a gain at a percentage rate; nothing on a loss. */
    public static function on(string $gain, string $ratePercent): string
    {
        if (bccomp($gain, '0', 10) <= 0) {
            return '0.0000';
        }

        return bcdiv(bcmul($gain, $ratePercent, 10), '100', 4);
    }
}
