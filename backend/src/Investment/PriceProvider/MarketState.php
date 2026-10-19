<?php

namespace App\Investment\PriceProvider;

enum MarketState: string
{
    case Open = 'open';
    case PreMarket = 'pre_market';
    case PostMarket = 'post_market';
    case Closed = 'closed';
    /** The provider didn't have enough information to tell (e.g. unknown ticker). */
    case Unknown = 'unknown';
}
