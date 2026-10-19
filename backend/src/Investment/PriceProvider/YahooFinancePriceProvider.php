<?php

namespace App\Investment\PriceProvider;

use App\Entity\InvestmentInstrument;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches daily closing prices from Yahoo Finance's public (unofficial) chart
 * endpoint. No API key required, but the endpoint is not officially supported
 * and may change without notice - kept behind PriceProviderInterface so it can
 * be swapped for an official/paid provider later without touching callers.
 */
class YahooFinancePriceProvider implements PriceProviderInterface
{
    private const BASE_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getClosingPrices(InvestmentInstrument $instrument, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $symbol = $this->symbolFor($instrument);

        if ($symbol === null) {
            return [];
        }

        // Yahoo's range end is exclusive-ish around market close, pad by a day either side.
        $period1 = $from->modify('-1 day')->getTimestamp();
        $period2 = $to->modify('+1 day')->getTimestamp();

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.rawurlencode($symbol), [
                'query' => [
                    'period1' => $period1,
                    'period2' => $period2,
                    'interval' => '1d',
                    'events' => 'history',
                ],
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0',
                ],
            ]);

            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Yahoo Finance price fetch failed for {symbol}: {error}', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $result = $data['chart']['result'][0] ?? null;

        if ($result === null) {
            return [];
        }

        $timezone = $this->exchangeTimezone($result['meta'] ?? []);
        $timestamps = $result['timestamp'] ?? [];
        $closes = $result['indicators']['quote'][0]['close'] ?? [];

        $prices = [];
        $missingDates = [];
        foreach ($timestamps as $index => $timestamp) {
            $date = $this->localDate($timestamp, $timezone);
            $close = $closes[$index] ?? null;

            if ($close === null) {
                // A real holiday has no bar at all; a bar with a null close
                // is a trading day Yahoo simply has no daily close for.
                $missingDates[] = $date;
                continue;
            }

            $prices[$date] = (string) round((float) $close, 4);
        }

        $prices += $this->lastIntradayCloses($symbol, $missingDates, $timezone);
        ksort($prices);

        return $prices;
    }

    public function getLiveQuote(InvestmentInstrument $instrument): LiveQuote
    {
        $symbol = $this->symbolFor($instrument);

        if ($symbol === null) {
            return new LiveQuote(null, MarketState::Unknown);
        }

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.rawurlencode($symbol), [
                'query' => [
                    'range' => '1d',
                    'interval' => '1d',
                ],
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0',
                ],
            ]);

            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Yahoo Finance live quote fetch failed for {symbol}: {error}', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return new LiveQuote(null, MarketState::Unknown);
        }

        $meta = $data['chart']['result'][0]['meta'] ?? null;

        if ($meta === null) {
            return new LiveQuote(null, MarketState::Unknown);
        }

        $price = $meta['regularMarketPrice'] ?? null;

        return new LiveQuote(
            price: $price !== null ? (string) round((float) $price, 4) : null,
            marketState: $this->marketStateFrom($meta),
        );
    }

    /**
     * Fallback for trading days whose daily bar has no close: Yahoo sometimes
     * leaves a hole in the daily series (occasionally for good) while still
     * having that day's hourly bars. The last hourly close is the last trade
     * of the session, usually a few cents off the official closing auction;
     * since recent days are re-fetched on every run, the official close still
     * replaces it once Yahoo publishes it. Today is skipped, as its session
     * may still be open.
     *
     * @param string[] $dates 'Y-m-d' in the exchange's timezone
     *
     * @return array<string, string>
     */
    private function lastIntradayCloses(string $symbol, array $dates, \DateTimeZone $timezone): array
    {
        $today = (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');
        $dates = array_values(array_filter($dates, static fn (string $date) => $date < $today));

        if ($dates === []) {
            return [];
        }

        sort($dates);

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.rawurlencode($symbol), [
                'query' => [
                    'period1' => (new \DateTimeImmutable($dates[0], $timezone))->getTimestamp(),
                    'period2' => (new \DateTimeImmutable(end($dates), $timezone))->modify('+1 day')->getTimestamp(),
                    'interval' => '1h',
                ],
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0',
                ],
            ]);

            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Yahoo Finance intraday fallback failed for {symbol}: {error}', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $result = $data['chart']['result'][0] ?? null;
        $timestamps = $result['timestamp'] ?? [];
        $closes = $result['indicators']['quote'][0]['close'] ?? [];

        $wanted = array_flip($dates);
        $prices = [];
        foreach ($timestamps as $index => $timestamp) {
            $date = $this->localDate($timestamp, $timezone);
            $close = $closes[$index] ?? null;

            // Bars come in ascending order, so the last one per day wins.
            if ($close !== null && isset($wanted[$date])) {
                $prices[$date] = (string) round((float) $close, 4);
            }
        }

        if ($prices !== []) {
            $this->logger->info('Yahoo Finance daily close missing for {symbol}, used last hourly close for {dates}', [
                'symbol' => $symbol,
                'dates' => implode(', ', array_keys($prices)),
            ]);
        }

        return $prices;
    }

    /**
     * Bars are dated in the exchange's own timezone, not UTC: a session
     * opening before midnight UTC (e.g. Tokyo, Sydney) would otherwise land
     * on the previous day.
     *
     * @param array<string, mixed> $meta
     */
    private function exchangeTimezone(array $meta): \DateTimeZone
    {
        try {
            return new \DateTimeZone($meta['exchangeTimezoneName'] ?? 'UTC');
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }

    private function localDate(int $timestamp, \DateTimeZone $timezone): string
    {
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone($timezone)->format('Y-m-d');
    }

    /**
     * Yahoo's chart endpoint has no explicit "is the market open" flag, but
     * `meta.currentTradingPeriod` gives today's pre/regular/post session
     * windows, so the state can be derived by checking which one "now" falls
     * into.
     *
     * @param array<string, mixed> $meta
     */
    private function marketStateFrom(array $meta): MarketState
    {
        $sessions = $meta['currentTradingPeriod'] ?? null;

        if ($sessions === null) {
            return MarketState::Unknown;
        }

        $now = time();

        if ($this->isWithinSession($sessions['regular'] ?? null, $now)) {
            return MarketState::Open;
        }

        if ($this->isWithinSession($sessions['pre'] ?? null, $now)) {
            return MarketState::PreMarket;
        }

        if ($this->isWithinSession($sessions['post'] ?? null, $now)) {
            return MarketState::PostMarket;
        }

        return MarketState::Closed;
    }

    /**
     * @param ?array{start: int, end: int} $session
     */
    private function isWithinSession(?array $session, int $now): bool
    {
        return $session !== null && $now >= $session['start'] && $now < $session['end'];
    }

    /**
     * The symbol to query Yahoo Finance with: the explicit Yahoo ticker
     * (including any exchange suffix, e.g. "VWCE.DE") if set, falling back
     * to the plain display ticker for symbols that need no suffix (e.g. "AAPL").
     */
    private function symbolFor(InvestmentInstrument $instrument): ?string
    {
        $symbol = $instrument->getYahooTicker() ?: $instrument->getTicker();

        return $symbol !== null && $symbol !== '' ? $symbol : null;
    }
}
