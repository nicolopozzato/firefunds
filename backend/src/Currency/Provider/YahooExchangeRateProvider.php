<?php

namespace App\Currency\Provider;

use App\Entity\ExchangeRateSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Yahoo Finance's daily close of the pair (EURUSD=X: how many dollars one euro
 * buys), for a currency the ECB doesn't publish. Unofficial, like its prices.
 */
#[AsTaggedItem(index: 'yahoo', priority: 10)]
class YahooExchangeRateProvider implements ExchangeRateProviderInterface
{
    public function __construct(
        /** Scoped in framework.yaml: Yahoo's chart endpoint, as for the prices. */
        private readonly HttpClientInterface $yahooClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function source(): ExchangeRateSource
    {
        return ExchangeRateSource::Yahoo;
    }

    public function getRates(array $currencies, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rates = [];
        foreach ($currencies as $currency) {
            $byDay = $this->forCurrency($currency, $from, $to);
            if ($byDay !== []) {
                $rates[$currency] = $byDay;
            }
        }

        return $rates;
    }

    /** @return array<string, string> */
    private function forCurrency(string $currency, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $symbol = 'EUR'.$currency.'=X';

        try {
            $data = $this->yahooClient->request('GET', rawurlencode($symbol), ['query' => [
                'period1' => $from->modify('-1 day')->getTimestamp(),
                'period2' => $to->modify('+1 day')->getTimestamp(),
                'interval' => '1d',
            ]])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Yahoo Finance rate fetch failed for {symbol}: {error}', ['symbol' => $symbol, 'error' => $e->getMessage()]);

            return [];
        }

        $result = $data['chart']['result'][0] ?? null;
        $closes = $result['indicators']['quote'][0]['close'] ?? [];
        $rates = [];
        foreach ($result['timestamp'] ?? [] as $index => $timestamp) {
            $close = $closes[$index] ?? null;
            $day = gmdate('Y-m-d', $timestamp);
            if (is_numeric($close) && (float) $close > 0 && $day >= $from->format('Y-m-d') && $day <= $to->format('Y-m-d')) {
                $rates[$day] = number_format((float) $close, 8, '.', '');
            }
        }
        ksort($rates);

        return $rates;
    }
}
