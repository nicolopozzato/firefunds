<?php

namespace App\Currency\Provider;

use App\Entity\ExchangeRateSource;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Where exchange rates come from. Rates are always against the euro: `rate`
 * units of the currency for 1 EUR. The providers are tried in priority order
 * (tagged `app.exchange_rate_provider`), each only for what the ones before
 * didn't have.
 */
#[AutoconfigureTag('app.exchange_rate_provider')]
interface ExchangeRateProviderInterface
{
    public function source(): ExchangeRateSource;

    /**
     * The rates this provider has for these currencies on the days in
     * [from, to] (days it publishes, not every calendar day), 8 decimals.
     * Currencies it doesn't know are left out; a failure gives nothing.
     *
     * @param string[] $currencies
     * @return array<string, array<string, string>> currency => (Y-m-d => rate)
     */
    public function getRates(array $currencies, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
