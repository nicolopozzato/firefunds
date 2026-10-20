<?php

namespace App\Entity;

/** Where a day's price comes from. */
enum InvestmentPriceSource: string
{
    /** The provider's close for that day. */
    case Market = 'market';
    /** No close that day (weekend, holiday): the provider's previous close, carried over. */
    case Filled = 'filled';
    /** Entered or corrected by the user: the provider never overwrites it. */
    case Manual = 'manual';
}
