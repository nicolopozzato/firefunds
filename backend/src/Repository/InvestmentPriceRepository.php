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

    public function findLatestForInstrument(InvestmentInstrument $instrument): ?InvestmentPrice
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.instrument = :instrument')
            ->setParameter('instrument', $instrument)
            ->orderBy('p.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findEarliestForInstrument(InvestmentInstrument $instrument): ?InvestmentPrice
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.instrument = :instrument')
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
     * Most recent recorded price per instrument.
     *
     * @param string[] $instrumentIds
     * @return array<string, string> instrument id => price
     */
    public function findLatestPrices(array $instrumentIds): array
    {
        if ($instrumentIds === []) {
            return [];
        }

        return $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                SELECT DISTINCT ON (instrument_id) instrument_id, price
                FROM investment_price
                WHERE instrument_id IN (:ids)
                ORDER BY instrument_id, date DESC
                SQL,
            ['ids' => $instrumentIds],
            ['ids' => ArrayParameterType::STRING]
        )->fetchAllKeyValue();
    }

    /**
     * Daily prices of several instruments in [from, to], plus each one's last
     * price before `from` (so a chart can carry a price into the range's first
     * days), in a single query. Only what the range needs, however long the
     * stored history is.
     *
     * @param string[] $instrumentIds
     * @return array<string, array<string, string>> instrument id => (Y-m-d => price), oldest first
     */
    public function findDailyPricesForRange(array $instrumentIds, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        if ($instrumentIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                SELECT instrument_id, date, price FROM (
                    SELECT instrument_id, date, price
                    FROM investment_price
                    WHERE instrument_id IN (:ids) AND date BETWEEN :from AND :to
                    UNION ALL
                    (
                        SELECT DISTINCT ON (instrument_id) instrument_id, date, price
                        FROM investment_price
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
