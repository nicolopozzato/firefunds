<?php

namespace App\Investment\Service;

use App\Entity\InvestmentInstrument;
use App\Investment\PriceProvider\LiveQuote;
use App\Investment\PriceProvider\PriceProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The live quote of an instrument, asked of the provider at most once per
 * CACHE_SECONDS per symbol, whoever asks: an open instrument page polls every
 * POLL_SECONDS (sent to the frontend with the quote), and every user holding
 * the same symbol shares the answer, so Yahoo sees one request per symbol
 * however many pages are open.
 */
final readonly class LiveQuoteService
{
    public const int POLL_SECONDS = 30;

    /** About three quarters of the poll: each poll finds the previous quote expired and gets a fresh one. */
    public const int CACHE_SECONDS = 22;

    public function __construct(
        private PriceProviderInterface $priceProvider,
        private CacheInterface $cache,
    ) {
    }

    public function quote(InvestmentInstrument $instrument): LiveQuote
    {
        $symbol = $instrument->getQuoteSymbol();
        if ($symbol === null) {
            return $this->priceProvider->getLiveQuote($instrument);
        }

        return $this->cache->get('live_quote_'.hash('xxh128', $symbol), function (ItemInterface $item) use ($instrument): LiveQuote {
            $item->expiresAfter(self::CACHE_SECONDS);

            return $this->priceProvider->getLiveQuote($instrument);
        });
    }
}
