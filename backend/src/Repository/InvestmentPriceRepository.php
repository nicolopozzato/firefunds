<?php

namespace App\Repository;

use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentPrice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvestmentPrice>
 */
class InvestmentPriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvestmentPrice::class);
    }

    /**
     * @return InvestmentPrice[]
     */
    public function findByInstrumentInRange(
        InvestmentInstrument $instrument,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.instrument = :instrument')
            ->setParameter('instrument', $instrument)
            ->orderBy('p.date', 'ASC');

        if ($from !== null) {
            $qb->andWhere('p.date >= :from')->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('p.date <= :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * The provider's history only (what the fetch fills and refreshes): a day
     * only the user entered isn't part of it.
     */
    public function findLatestForInstrument(InvestmentInstrument $instrument): ?InvestmentPrice
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.instrument = :instrument')
            ->andWhere('p.originalPrice IS NOT NULL')
            ->setParameter('instrument', $instrument)
            ->orderBy('p.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** The latest price recorded for the instrument, the user's included. */
    public function findLatestRecorded(InvestmentInstrument $instrument): ?InvestmentPrice
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.instrument = :instrument')
            ->setParameter('instrument', $instrument)
            ->orderBy('p.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** The provider's history only, as findLatestForInstrument(). */
    public function findEarliestForInstrument(InvestmentInstrument $instrument): ?InvestmentPrice
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.instrument = :instrument')
            ->andWhere('p.originalPrice IS NOT NULL')
            ->setParameter('instrument', $instrument)
            ->orderBy('p.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByInstrumentAndDate(InvestmentInstrument $instrument, \DateTimeImmutable $date): ?InvestmentPrice
    {
        return $this->findOneBy(['instrument' => $instrument, 'date' => $date]);
    }

    /**
     * The prices an instrument is valued at, one per day at most, as a SQL
     * subquery (instrument_id, date, price) for every query that values
     * holdings, so they all follow the same rule:
     * 1. the price recorded for the day (the provider's close, or the user's);
     * 2. else, on a day with a trade, that trade's unit price (the last of the
     *    day): what was paid is what it was worth, e.g. for an instrument the
     *    provider doesn't quote. A price carried over from the day before
     *    (weekend, holiday) doesn't count as recorded here;
     * 3. else (the callers' job) the latest of the days before.
     */
    public const EFFECTIVE_PRICES = <<<SQL
        SELECT p.instrument_id, p.date, p.price
        FROM investment_price p
        WHERE p.deleted_at IS NULL
            AND NOT (p.source = 'filled' AND EXISTS (
                SELECT 1 FROM investment_transaction t
                WHERE t.instrument_id = p.instrument_id AND t.deleted_at IS NULL AND CAST(t.occurred_at AS date) = p.date
            ))
        UNION ALL
        (
            SELECT DISTINCT ON (it.instrument_id, CAST(it.occurred_at AS date))
                it.instrument_id, CAST(it.occurred_at AS date), it.unit_price
            FROM investment_transaction it
            WHERE it.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM investment_price rp
                    WHERE rp.instrument_id = it.instrument_id AND rp.date = CAST(it.occurred_at AS date)
                        AND rp.deleted_at IS NULL AND rp.source <> 'filled'
                )
            ORDER BY it.instrument_id, CAST(it.occurred_at AS date), it.occurred_at DESC, it.id DESC
        )
        SQL;

    /**
     * Most recent price per instrument (EFFECTIVE_PRICES).
     *
     * @param string[] $instrumentIds
     * @return array<string, string> instrument id => price
     */
    public function findLatestPrices(array $instrumentIds): array
    {
        if ($instrumentIds === []) {
            return [];
        }

        $prices = self::EFFECTIVE_PRICES;

        return $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                SELECT DISTINCT ON (instrument_id) instrument_id, price
                FROM ({$prices}) prices
                WHERE instrument_id IN (:ids)
                ORDER BY instrument_id, date DESC
                SQL,
            ['ids' => $instrumentIds],
            ['ids' => ArrayParameterType::STRING]
        )->fetchAllKeyValue();
    }

    /**
     * Latest price per instrument on or before a day (EFFECTIVE_PRICES).
     *
     * @param string[] $instrumentIds
     * @return array<string, string> instrument id => price
     */
    public function findPricesOn(array $instrumentIds, \DateTimeInterface $day): array
    {
        if ($instrumentIds === []) {
            return [];
        }

        $prices = self::EFFECTIVE_PRICES;

        return $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                SELECT DISTINCT ON (instrument_id) instrument_id, price
                FROM ({$prices}) prices
                WHERE instrument_id IN (:ids) AND date <= :day
                ORDER BY instrument_id, date DESC
                SQL,
            ['ids' => $instrumentIds, 'day' => $day->format('Y-m-d')],
            ['ids' => ArrayParameterType::STRING]
        )->fetchAllKeyValue();
    }

    /**
     * Daily prices (EFFECTIVE_PRICES) of several instruments in [from, to],
     * plus each one's last price before `from` (so a chart can carry a price
     * into the range's first days), in a single query. Only what the range
     * needs, however long the stored history is.
     *
     * @param string[] $instrumentIds
     * @return array<string, array<string, string>> instrument id => (Y-m-d => price), oldest first
     */
    public function findDailyPricesForRange(array $instrumentIds, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        if ($instrumentIds === []) {
            return [];
        }

        $prices = self::EFFECTIVE_PRICES;

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                WITH effective AS (SELECT * FROM ({$prices}) all_prices WHERE instrument_id IN (:ids))
                SELECT instrument_id, date, price FROM (
                    SELECT instrument_id, date, price
                    FROM effective
                    WHERE instrument_id IN (:ids) AND date BETWEEN :from AND :to
                    UNION ALL
                    (
                        SELECT DISTINCT ON (instrument_id) instrument_id, date, price
                        FROM effective
                        WHERE instrument_id IN (:ids) AND date < :from
                        ORDER BY instrument_id, date DESC
                    )
                ) prices
                ORDER BY instrument_id, date
                SQL,
            ['ids' => $instrumentIds, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            ['ids' => ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $byInstrument = [];
        foreach ($rows as $row) {
            $byInstrument[$row['instrument_id']][$row['date']] = $row['price'];
        }

        return $byInstrument;
    }
}
