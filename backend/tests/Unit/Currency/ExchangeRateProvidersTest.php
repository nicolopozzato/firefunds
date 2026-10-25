<?php

namespace App\Tests\Unit\Currency;

use App\Currency\Provider\EcbExchangeRateProvider;
use App\Currency\Provider\YahooExchangeRateProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

class ExchangeRateProvidersTest extends TestCase
{
    private const ECB_XML = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
          <Cube>
            <Cube time="2026-10-21"><Cube currency="USD" rate="1.1234"/><Cube currency="GBP" rate="0.83825"/><Cube currency="JPY" rate="177.36"/></Cube>
            <Cube time="2026-10-20"><Cube currency="USD" rate="1.1200"/><Cube currency="GBP" rate="0.84"/></Cube>
            <Cube time="2026-10-16"><Cube currency="USD" rate="1.1100"/></Cube>
          </Cube>
        </gesmes:Envelope>
        XML;

    public function testTheEcbRatesAreReadForTheCurrenciesAndDaysAsked(): void
    {
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested) {
            $requested[] = $url;

            return new MockResponse(self::ECB_XML);
        });
        $provider = new EcbExchangeRateProvider($client, new NullLogger(), new MockClock('2026-10-22 10:00:00'));

        $rates = $provider->getRates(['USD', 'GBP', 'XYZ'], new \DateTimeImmutable('2026-10-17'), new \DateTimeImmutable('2026-10-21'));

        $this->assertSame([
            'USD' => ['2026-10-20' => '1.12000000', '2026-10-21' => '1.12340000'],
            'GBP' => ['2026-10-20' => '0.84000000', '2026-10-21' => '0.83825000'],
        ], $rates);
        $this->assertStringEndsWith('eurofxref-hist-90d.xml', $requested[0], 'a recent range: the small file');
    }

    public function testAnOldRangeAsksForTheWholeHistory(): void
    {
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested) {
            $requested[] = $url;

            return new MockResponse(self::ECB_XML);
        });

        (new EcbExchangeRateProvider($client, new NullLogger(), new MockClock('2026-10-22 10:00:00')))
            ->getRates(['USD'], new \DateTimeImmutable('2024-01-01'), new \DateTimeImmutable('2026-10-21'));

        $this->assertStringEndsWith('eurofxref-hist.xml', $requested[0]);
    }

    public function testEcbFailureGivesNothing(): void
    {
        $provider = new EcbExchangeRateProvider(new MockHttpClient(new MockResponse('', ['http_code' => 503])), new NullLogger(), new MockClock('2026-10-22'));

        $this->assertSame([], $provider->getRates(['USD'], new \DateTimeImmutable('2026-10-20'), new \DateTimeImmutable('2026-10-21')));
    }

    public function testYahooGivesTheClosesOfThePair(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(['chart' => ['result' => [[
            'timestamp' => [strtotime('2026-10-20 00:00 UTC'), strtotime('2026-10-21 00:00 UTC'), strtotime('2026-10-22 00:00 UTC')],
            'indicators' => ['quote' => [['close' => [3.85, null, 3.9]]]],
        ]]]]));

        $rates = (new YahooExchangeRateProvider($client, new NullLogger()))
            ->getRates(['ILS'], new \DateTimeImmutable('2026-10-20'), new \DateTimeImmutable('2026-10-21'));

        $this->assertSame(['ILS' => ['2026-10-20' => '3.85000000']], $rates, 'no null close, nothing beyond the range');
    }
}
