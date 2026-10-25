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
use Symfony\Component\HttpClient\Response\MockResponse;

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
            '2026-09-25' => '169.7700',
            '2026-09-28' => '169.1400',
            '2026-09-29' => '169.2700',
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

        $this->assertSame(['2026-09-25' => '169.7700', '2026-09-28' => '169.1400'], $prices);
        $this->assertSame(1, $requests);
    }

    public function testBarsAreDatedInTheExchangeTimezone(): void
    {
        // Sydney's 10:00 AEST open is 00:00 UTC; in AEDT (from October)
        // it is the previous day in UTC.
        $client = new MockHttpClient($this->chart([['2026-10-06 10:00', 100.0]], 'Australia/Sydney'));

        $prices = (new YahooFinancePriceProvider($client, new NullLogger()))
            ->getClosingPrices($this->instrument(), new \DateTimeImmutable('2026-10-06'), new \DateTimeImmutable('2026-10-06'));

        $this->assertSame(['2026-10-06' => '100.0000'], $prices);
    }

    /**
     * What the database couldn't store is left out, as a day without a close:
     * prices that are zero once rounded, too large, or not numbers.
     */
    public function testPricesTheColumnCannotHoldAreLeftOut(): void
    {
        $daily = $this->chart([
            ['2026-10-05 17:30', 0.00001],
            ['2026-10-06 17:30', 1.0e16],
            ['2026-10-07 17:30', 12.345678],
        ]);
        $client = new MockHttpClient(static fn () => $daily, 'https://query1.finance.yahoo.com/v8/finance/chart/');

        $prices = (new YahooFinancePriceProvider($client, new NullLogger()))
            ->getClosingPrices($this->instrument(), new \DateTimeImmutable('2026-10-07'), new \DateTimeImmutable('2026-10-07'));

        $this->assertSame(['2026-10-07' => '12.3457'], $prices);
    }

    public function testAnOversizedAnswerIsDropped(): void
    {
        $client = new MockHttpClient(
            static fn () => new MockResponse(str_repeat(' ', 6 * 1024 * 1024).'{}'),
            'https://query1.finance.yahoo.com/v8/finance/chart/',
        );

        $quote = (new YahooFinancePriceProvider($client, new NullLogger()))->getLiveQuote($this->instrument());

        $this->assertNull($quote->price);
    }

    public function testTheQuoteCurrencyIsWhatYahooSays(): void
    {
        $provider = new YahooFinancePriceProvider(new MockHttpClient($this->chart([['2026-09-25 09:00', 100.0]], 'America/New_York', 'USD')), new NullLogger());

        $this->assertSame('USD', $provider->getQuoteCurrency($this->instrument()));
    }

    public function testAQuoteInPenceIsAQuoteInPoundsWithPricesDividedByAHundred(): void
    {
        $client = new MockHttpClient(fn () => $this->chart([['2026-09-25 09:00', 1250.0], ['2026-09-28 09:00', 1262.5]], 'Europe/London', 'GBp'));
        $provider = new YahooFinancePriceProvider($client, new NullLogger());

        $this->assertSame('GBP', $provider->getQuoteCurrency($this->instrument()));
        $this->assertSame(
            ['2026-09-25' => '12.5000', '2026-09-28' => '12.6250'],
            $provider->getClosingPrices($this->instrument(), new \DateTimeImmutable('2026-09-25'), new \DateTimeImmutable('2026-09-28')),
        );
    }

    public function testNoCurrencyWhenYahooDoesNotSayOrFails(): void
    {
        $unknown = new YahooFinancePriceProvider(new MockHttpClient($this->chart([['2026-09-25 09:00', 100.0]])), new NullLogger());
        $failing = new YahooFinancePriceProvider(new MockHttpClient(new MockResponse('', ['http_code' => 500])), new NullLogger());
        $noSymbol = new YahooFinancePriceProvider(new MockHttpClient($this->chart([])), new NullLogger());

        $this->assertNull($unknown->getQuoteCurrency($this->instrument()));
        $this->assertNull($failing->getQuoteCurrency($this->instrument()));
        $this->assertNull($noSymbol->getQuoteCurrency(new InvestmentInstrument(new User(), 'No symbol', InvestmentInstrumentType::Other)));
    }

    /**
     * @param list<array{string, ?float}> $bars local 'Y-m-d H:i' => close
     */
    private function chart(array $bars, string $timezone = 'Europe/Rome', ?string $currency = null): JsonMockResponse
    {
        $tz = new \DateTimeZone($timezone);

        return new JsonMockResponse(['chart' => ['result' => [[
            'meta' => ['exchangeTimezoneName' => $timezone] + ($currency !== null ? ['currency' => $currency] : []),
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
