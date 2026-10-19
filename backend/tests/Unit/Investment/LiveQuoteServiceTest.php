<?php

namespace App\Tests\Unit\Investment;

use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\User;
use App\Investment\PriceProvider\LiveQuote;
use App\Investment\PriceProvider\MarketState;
use App\Investment\PriceProvider\PriceProviderInterface;
use App\Investment\Service\LiveQuoteService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * An open instrument page polls every POLL_SECONDS; the provider is asked at
 * most once per CACHE_SECONDS per symbol, whoever asks.
 */
class LiveQuoteServiceTest extends TestCase
{
    public function testTheCacheExpiresBeforeTheNextPoll(): void
    {
        $this->assertLessThan(LiveQuoteService::POLL_SECONDS, LiveQuoteService::CACHE_SECONDS);
        $this->assertGreaterThanOrEqual(LiveQuoteService::POLL_SECONDS / 2, LiveQuoteService::CACHE_SECONDS);
    }

    public function testOneProviderCallPerSymbolWhileCached(): void
    {
        $provider = new class implements PriceProviderInterface {
            public int $calls = 0;

            public function getClosingPrices(InvestmentInstrument $instrument, \DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                return [];
            }

            public function getLiveQuote(InvestmentInstrument $instrument): LiveQuote
            {
                ++$this->calls;

                return new LiveQuote('10.0000', MarketState::Open);
            }
        };
        $service = new LiveQuoteService($provider, new ArrayAdapter());

        $first = $service->quote($this->instrument('VWCE.MI'));
        $second = $service->quote($this->instrument('VWCE.MI'));
        $service->quote($this->instrument('SWDA.MI'));

        $this->assertSame(2, $provider->calls, 'once per symbol, even for another user\'s instrument');
        $this->assertEquals($first->fetchedAt, $second->fetchedAt, 'the cached quote keeps when it was fetched');
    }

    private function instrument(string $symbol): InvestmentInstrument
    {
        $instrument = new InvestmentInstrument(new User(), 'ETF', InvestmentInstrumentType::Etf);
        $instrument->setYahooTicker($symbol);

        return $instrument;
    }
}
