<?php

namespace App\Investment\PriceProvider;

use App\Entity\InvestmentInstrument;

interface QuoteCurrencyProviderInterface
{
    /**
     * The ISO 4217 code of the currency the provider quotes the instrument in
     * (a quote in pence is a quote in pounds: the prices come divided by 100),
     * null when it can't tell.
     */
    public function getQuoteCurrency(InvestmentInstrument $instrument): ?string;
}
