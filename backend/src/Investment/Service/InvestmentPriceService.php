<?php

namespace App\Investment\Service;

use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentPrice;
use App\Entity\InvestmentPriceSource;
use App\Investment\PriceProvider\PriceProviderInterface;
use App\Repository\InvestmentInstrumentRepository;
use App\Repository\InvestmentPriceRepository;
use App\Repository\InvestmentTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

class InvestmentPriceService
{
    /**
     * How many trailing days (ending at the fetch's upper bound) are re-checked
     * against the provider on every fetch, even when already recorded. The
     * provider's most recent daily bar often has no close yet for several
     * hours after the session ends, so that day gets forward-filled from the
     * previous close; without this window the stale value would stick forever.
     */
    private const REFRESH_WINDOW_DAYS = 7;

    public function __construct(
        private readonly InvestmentInstrumentRepository $instrumentRepository,
        private readonly InvestmentPriceRepository $priceRepository,
        private readonly InvestmentTransactionRepository $transactionRepository,
        private readonly PriceProviderInterface $priceProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly PriceCalendar $calendar,
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    /**
     * Backfills every instrument (across all users) up through yesterday,
     * starting from the day after its latest recorded price (or, for an
     * instrument with no price yet, from its very first available market
     * quote). Instruments sharing the same Yahoo symbol are fetched only once.
     * A symbol that fails doesn't stop the others.
     *
     * @return array{symbols: int, empty: string[], failed: array<string, string>} how many symbols, those the
     *                                                                             provider returned nothing for, and the error of each one that failed
     */
    public function updateAllInstruments(): array
    {
        $yesterday = $this->calendar->yesterday();

        $idsBySymbol = [];
        foreach ($this->instrumentRepository->findAll() as $instrument) {
            $symbol = $instrument->getQuoteSymbol();
            if ($symbol !== null) {
                $idsBySymbol[$symbol][] = $instrument->getId();
            }
        }

        $failed = [];
        $empty = [];
        foreach ($idsBySymbol as $symbol => $ids) {
            try {
                // Loaded per symbol: after a failure the EntityManager is a new one.
                if (!$this->backfillGroup($this->instrumentRepository->findBy(['id' => $ids]), $yesterday)) {
                    $empty[] = (string) $symbol;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to update prices for symbol {symbol}: {error}', [
                    'symbol' => $symbol,
                    'error' => $e->getMessage(),
                ]);
                $failed[(string) $symbol] = $e->getMessage();

                // A database error closes the EntityManager, which would fail
                // every symbol after this one: start the next on a fresh one.
                if (!$this->entityManager->isOpen()) {
                    $this->doctrine->resetManager();
                }
                $this->entityManager->clear();
            }
        }

        return ['symbols' => \count($idsBySymbol), 'empty' => $empty, 'failed' => $failed];
    }

    /**
     * Backfills a single, newly created instrument with yesterday's closing
     * price so its chart has data from day one, ahead of the next cron run.
     */
    public function seedInitialPrice(InvestmentInstrument $instrument): void
    {
        $yesterday = $this->calendar->yesterday();

        $this->backfillGroup([$instrument], $yesterday);
    }

    /**
     * Whether this instrument already has a recorded closing price for every
     * day from its first trade through yesterday. Cheap, read-only: no
     * network call.
     */
    public function hasCompletePriceHistoryThroughYesterday(InvestmentInstrument $instrument): bool
    {
        $yesterday = $this->calendar->yesterday();

        return $this->missingDateFor($instrument, $yesterday) === null
            && $this->missingHistoryFrom($instrument) === null;
    }

    /**
     * Best-effort: tries to backfill this instrument's price history through
     * yesterday and reports whether the gap is closed afterwards. Never throws:
     * a failed fetch (network, provider error) is swallowed and simply reported
     * as "still incomplete" so the caller can warn the user instead of failing
     * whatever else it was doing.
     */
    public function ensureCompletePriceHistoryThroughYesterday(InvestmentInstrument $instrument): bool
    {
        // Nothing to fetch: valued at its trades' and the user's own prices.
        if ($instrument->getQuoteSymbol() === null) {
            return true;
        }

        if ($this->hasCompletePriceHistoryThroughYesterday($instrument)) {
            return true;
        }

        try {
            $historyFrom = $this->missingHistoryFrom($instrument);
            if ($historyFrom !== null) {
                // Everything from the first trade on, in one fetch: a trade
                // dated in the past needs the prices of the days since then.
                $this->importRange($instrument, $historyFrom, $this->calendar->yesterday());
            } else {
                $this->seedInitialPrice($instrument);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to backfill prices for instrument {id}: {error}', [
                'id' => (string) $instrument->getId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $this->hasCompletePriceHistoryThroughYesterday($instrument);
    }

    /**
     * Imports every day in [from, to] for one instrument, e.g. a user-requested
     * historical import. A day already recorded is only updated if the
     * provider now reports a different value and the user didn't enter their own.
     */
    public function importRange(InvestmentInstrument $instrument, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $this->backfillGroup([$instrument], $to, $from);
    }

    /**
     * @param InvestmentInstrument[] $instruments  all sharing the same Yahoo symbol
     * @param ?\DateTimeImmutable    $explicitFrom explicit start date (e.g. a user-requested
     *                                             import); when null, each instrument starts
     *                                             the day after its own latest recorded price
     *                                             (or from scratch if it has none yet)
     *
     * @return bool false when the provider returned nothing for the symbol
     */
    private function backfillGroup(array $instruments, \DateTimeImmutable $through, ?\DateTimeImmutable $explicitFrom = null): bool
    {
        $representative = $instruments[0];
        $refreshFrom = $through->modify(\sprintf('-%d days', self::REFRESH_WINDOW_DAYS - 1));

        $wantedFrom = $explicitFrom ?? $this->earliestWantedFrom($instruments, $through) ?? $refreshFrom;
        if ($explicitFrom === null && $refreshFrom < $wantedFrom) {
            $wantedFrom = $refreshFrom;
        }

        // Look back further than $wantedFrom too: the market may have been
        // closed right on that day (weekend/holiday), so an earlier quote is
        // needed to forward-fill from.
        $lookback = $wantedFrom->modify('-7 days');
        $marketPrices = $this->priceProvider->getClosingPrices($representative, $lookback, $through);

        if ($marketPrices === []) {
            return false;
        }

        ksort($marketPrices);
        $firstAvailable = new \DateTimeImmutable(array_key_first($marketPrices));

        foreach ($instruments as $instrument) {
            $hasAnyPrice = $this->priceRepository->findLatestForInstrument($instrument) !== null;
            $from = $explicitFrom ?? $this->missingDateFor($instrument, $through) ?? $refreshFrom;
            if ($explicitFrom === null && $refreshFrom < $from) {
                $from = $refreshFrom;
            }

            if ($from > $through) {
                continue;
            }

            // A brand-new instrument has no lower bound of its own: start from
            // whatever the market's earliest available quote is, so a market
            // closed on $from's default value (yesterday) doesn't get skipped.
            if (!$hasAnyPrice && $explicitFrom === null) {
                $from = $firstAvailable;
            }

            $this->fillInstrumentDays($instrument, $marketPrices, $firstAvailable, $from, $through);
        }

        $this->entityManager->flush();

        return true;
    }

    /**
     * The earliest date any instrument in the group still needs data for, so
     * the shared fetch's lookback window covers every instrument's gap. Null
     * if every instrument is already up to date through $through.
     *
     * @param InvestmentInstrument[] $instruments
     */
    private function earliestWantedFrom(array $instruments, \DateTimeImmutable $through): ?\DateTimeImmutable
    {
        $earliest = null;

        foreach ($instruments as $instrument) {
            $missingFrom = $this->missingDateFor($instrument, $through);

            if ($missingFrom !== null && ($earliest === null || $missingFrom < $earliest)) {
                $earliest = $missingFrom;
            }
        }

        return $earliest;
    }

    /**
     * The day of the instrument's first trade when its price history starts
     * after it (or doesn't exist yet), i.e. from when prices are still
     * missing at the start; null when nothing is missing there.
     */
    private function missingHistoryFrom(InvestmentInstrument $instrument): ?\DateTimeImmutable
    {
        $firstTrade = $this->transactionRepository->findFirstTradeTime($instrument);
        if ($firstTrade === null) {
            return null;
        }

        // The day as written where the trade happened (App\Entity\OccursLocally).
        $firstTradeDay = new \DateTimeImmutable($firstTrade->format('Y-m-d'));
        if ($firstTradeDay > $this->calendar->yesterday()) {
            return null; // traded today: there is no close to have yet
        }

        $earliest = $this->priceRepository->findEarliestForInstrument($instrument);

        return $earliest === null || $earliest->getDate() > $firstTradeDay ? $firstTradeDay : null;
    }

    private function missingDateFor(InvestmentInstrument $instrument, \DateTimeImmutable $through): ?\DateTimeImmutable
    {
        $latest = $this->priceRepository->findLatestForInstrument($instrument);

        if ($latest === null) {
            return $through;
        }

        $nextDay = $latest->getDate()->modify('+1 day');

        return $nextDay <= $through ? $nextDay : null;
    }

    /**
     * Writes one InvestmentPrice per calendar day for this instrument,
     * forward-filling from the last known market price on days the market has
     * none (weekends, holidays) so the chart never has a gap. An always-open
     * market (e.g. crypto) simply never triggers the forward-fill branch,
     * since every day has its own quote. A day already recorded takes the
     * provider's newer value, unless the user entered their own (see
     * InvestmentPrice::refreshFromProvider()).
     *
     * @param array<string, string> $marketPrices day (Y-m-d) => price, sorted ascending
     */
    private function fillInstrumentDays(
        InvestmentInstrument $instrument,
        array $marketPrices,
        \DateTimeImmutable $firstAvailable,
        \DateTimeImmutable $from,
        \DateTimeImmutable $through,
    ): void {
        // Never start before the first quote the market actually has,
        // regardless of what $from asked for (e.g. a user importing "from
        // 2020" for a fund that only started trading in 2022).
        $start = $from > $firstAvailable ? $from : $firstAvailable;

        // Seed the running price with the latest market quote strictly before
        // $start (from the lookback window), so the very first day of the
        // loop can already forward-fill if the market was closed on it.
        $lastKnownPrice = null;
        $startString = $start->format('Y-m-d');
        foreach ($marketPrices as $dateString => $price) {
            if ($dateString >= $startString) {
                break;
            }
            $lastKnownPrice = $price;
        }

        // Every price already recorded in the range, loaded in one query rather
        // than one lookup per day (a multi-year import spans thousands of days).
        $existingByDate = [];
        foreach ($this->priceRepository->findByInstrumentInRange($instrument, $start, $through) as $existingPrice) {
            $existingByDate[$existingPrice->getDate()->format('Y-m-d')] = $existingPrice;
        }

        for ($date = $start; $date <= $through; $date = $date->modify('+1 day')) {
            $dateString = $date->format('Y-m-d');

            $isClose = isset($marketPrices[$dateString]);
            if ($isClose) {
                $lastKnownPrice = $marketPrices[$dateString];
            }

            if ($lastKnownPrice === null) {
                continue;
            }

            $source = $isClose ? InvestmentPriceSource::Market : InvestmentPriceSource::Filled;
            $existing = $existingByDate[$dateString] ?? null;
            if ($existing !== null) {
                $existing->refreshFromProvider($lastKnownPrice, $source);
                continue;
            }

            $this->entityManager->persist(new InvestmentPrice($instrument, $date, $lastKnownPrice, $source));
        }
    }
}
