<?php

namespace App\Entity;

/** Where an exchange rate comes from. */
enum ExchangeRateSource: string
{
    /** The European Central Bank's reference rate for that day. */
    case Ecb = 'ecb';
    /** Yahoo Finance's close, for a currency the ECB doesn't publish. */
    case Yahoo = 'yahoo';
    /** No rate that day (weekend, holiday): the previous one, carried over. */
    case Filled = 'filled';
}
