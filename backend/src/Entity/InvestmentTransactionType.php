<?php

namespace App\Entity;

enum InvestmentTransactionType: string
{
    case Buy = 'buy';
    case Sell = 'sell';
}
