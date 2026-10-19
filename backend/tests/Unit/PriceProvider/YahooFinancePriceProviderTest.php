<?php

namespace App\Tests\Unit\PriceProvider;

use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\User;
use App\Investment\PriceProvider\YahooFinancePriceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

class YahooFinancePriceProviderTest extends TestCase
{
    public function testTradingDayWithoutDailyCloseFallsBackToLastHourlyClose(): void
    {
        // Mirrors VWCE.MI around 2026-09-29: the daily bar exists but has a
        // null close, while that day's hourly bars are there.
        $daily = $this->chart([
            ['2026-09-25 09:00', 169.77],
            ['2026-09-28 09:00', 169.14],
            ['2026-09-29 09:00', null],
        ]);
        $hourly = $this->chart([
            ['2026-09-29 09:00', 169.56],
            ['2026-09-29 16:00', 169.68],
            ['2026-09-29 17:00', 169.27],
        ]);

        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requests, $daily, $hourly) {
            $requests[] = $url;

            return str_contains($url, 'interval=1h') ? $hourly : $daily;
        });

        $prices = (new YahooFinancePriceProvider($client, new NullLogger()))
            ->getClosingPrices($this->instrument(), new \DateTimeImmutable('2026-09-25'), new \DateTimeImmutable('2026-09-29'));

        $this->assertSame([
            '2026-09-25' => '169.77',
            '2026-09-28' => '169.14',
            '2026-09-29' => '169.27',
        ], $prices);
        $this->assertCount(2, $requests);
    }

    public function testNoIntradayRequestWhenEveryDailyCloseIsPresent(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function () use (&$requests) {
            ++$requests;

            return $this->chart([['2026-09-25 09:00', 169.77], ['2026-09-28 09:00', 169.14]]);
        });

        $prices = (new YahooFinancePriceProvider($client, new NullLogger()))
            ->getClosingPrices($this->instrument(), new \DateTimeImmutable('2026-09-25'), new \DateTimeImmutable('2026-09-28'));

        $this->assertSame(['2026-09-25' => '169.77', '2026-09-28' => '169.14'], $prices);
        $this->assertSame(1, $requests);
    }

    public function testBarsAreDatedInTheExchangeTimezone(): void
    {
        // Sydney's 10:00 AEST open is 00:00 UTC; in AEDT (from October)
        // it is the previous day in UTC.
        $client = new MockHttpClient($this->chart([['2026-10-06 10:00', 100.0]], 'Australia/Sydney'));

        $prices = (new YahooFinancePriceProvider($client, new NullLogger()))
            ->getClosingPrices($this->instrument(), new \DateTimeImmutable('2026-10-06'), new \DateTimeImmutable('2026-10-06'));

        $this->assertSame(['2026-10-06' => '100'], $prices);
    }

    /**
     * @param list<array{string, ?float}> $bars local 'Y-m-d H:i' => close
     */
    private function chart(array $bars, string $timezone = 'Europe/Rome'): JsonMockResponse
    {
        $tz = new \DateTimeZone($timezone);

        return new JsonMockResponse(['chart' => ['result' => [[
            'meta' => ['exchangeTimezoneName' => $timezone],
            'timestamp' => array_map(static fn (array $bar) => (new \DateTimeImmutable($bar[0], $tz))->getTimestamp(), $bars),
            'indicators' => ['quote' => [['close' => array_column($bars, 1)]]],
        ]]]]);
    }

    private function instrument(): InvestmentInstrument
    {
        $instrument = new InvestmentInstrument(new User(), 'Test ETF', InvestmentInstrumentType::Etf);
        $instrument->setYahooTicker('VWCE.MI');

        return $instrument;
    }
}
