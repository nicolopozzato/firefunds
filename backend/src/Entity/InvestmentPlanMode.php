<?php

namespace App\Entity;

/**
 * How a recurring purchase (a PAC) buys: a fixed amount in fractional units,
 * a fixed amount in whole units only (what is left over stays in the
 * account), or always the same number of units (the amount follows the price).
 */
enum InvestmentPlanMode: string
{
    case Amount = 'amount';
    case WholeUnits = 'whole_units';
    case Units = 'units';
}
