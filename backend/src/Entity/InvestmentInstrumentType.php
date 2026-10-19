<?php

namespace App\Entity;

enum InvestmentInstrumentType: string
{
    case Etf = 'etf';
    case Stock = 'stock';
    case Bond = 'bond';
    case Fund = 'fund';
    case Cryptocurrency = 'cryptocurrency';
    case Other = 'other';
}
