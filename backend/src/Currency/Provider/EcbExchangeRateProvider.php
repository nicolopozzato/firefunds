<?php

namespace App\Currency\Provider;

use App\Entity\ExchangeRateSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The European Central Bank's euro foreign exchange reference rates: free,
 * official, about thirty currencies, a rate per working day (around 16:00
 * CET), history back to 1999. The last 90 days come as a small file, the whole
 * history as a large one (read as a stream, once).
 */
#[AsTaggedItem(index: 'ecb', priority: 20)]
class EcbExchangeRateProvider implements ExchangeRateProviderInterface
{
    private const RECENT = 'eurofxref-hist-90d.xml';
    private const FULL = 'eurofxref-hist.xml';
    /** The recent file has a little less than 90 days: stay clear of its edge. */
    private const RECENT_DAYS = 80;

    public function __construct(
        /** Scoped in framework.yaml: the ECB's statistics site. */
        private readonly HttpClientInterface $ecbClient,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function source(): ExchangeRateSource
    {
        return ExchangeRateSource::Ecb;
    }

    public function getRates(array $currencies, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $recentFrom = $this->clock->now()->modify('-'.self::RECENT_DAYS.' days')->setTime(0, 0);

        try {
            $xml = $this->ecbClient->request('GET', $from >= $recentFrom ? self::RECENT : self::FULL)->getContent();
        } catch (\Throwable $e) {
            $this->logger->warning('ECB exchange rates fetch failed: {error}', ['error' => $e->getMessage()]);

            return [];
        }

        return $this->parse($xml, $currencies, $from->format('Y-m-d'), $to->format('Y-m-d'));
    }

    /**
     * @param string[] $currencies
     * @return array<string, array<string, string>>
     */
    private function parse(string $xml, array $currencies, string $from, string $to): array
    {
        $wanted = array_flip($currencies);
        $reader = new \XMLReader();
        if (!$reader->XML($xml, null, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING)) {
            return [];
        }

        $rates = [];
        $day = null;
        while (@$reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'Cube') {
                continue;
            }
            $time = $reader->getAttribute('time');
            if ($time !== null) {
                $day = $time;
                continue;
            }
            $currency = $reader->getAttribute('currency');
            $rate = $reader->getAttribute('rate');
            if ($day === null || $currency === null || $rate === null || $day < $from || $day > $to || !isset($wanted[$currency]) || !is_numeric($rate)) {
                continue;
            }
            $rates[$currency][$day] = bcadd($rate, '0', 8);
        }
        $reader->close();

        foreach ($rates as &$byDay) {
            ksort($byDay);
        }

        return $rates;
    }
}
