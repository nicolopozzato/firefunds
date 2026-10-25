<?php

namespace App\Repository;

use App\Entity\ExchangeRate;
use App\Entity\ExchangeRateSource;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExchangeRate>
 */
class ExchangeRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExchangeRate::class);
    }

    /**
     * The currencies somebody needs rates for, with the first day they need
     * them from (null: no data yet). A user needs rates when what they hold or
     * prefer spans two currencies or more (accounts of money, instruments,
     * their preferred one); the euro, the pivot, needs none. With a user: only
     * theirs.
     *
     * @return array<string, ?string> currency => 'Y-m-d'
     */
    public function neededCurrencies(?User $user = null): array
    {
        $userFilter = $user !== null ? 'AND u.id = :user' : '';
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                WITH user_currencies AS (
                    SELECT a.user_id, c.code AS currency
                    FROM account a JOIN currency c ON c.id = a.currency_id
                    WHERE a.deleted_at IS NULL AND a.type = 'asset'
                    UNION
                    SELECT i.user_id, i.currency FROM investment_instrument i WHERE i.deleted_at IS NULL
                    UNION
                    SELECT u.id, u.currency FROM "user" u
                ),
                spanning AS (
                    SELECT uc.user_id FROM user_currencies uc
                    JOIN "user" u ON u.id = uc.user_id
                    WHERE TRUE {$userFilter}
                    GROUP BY uc.user_id HAVING COUNT(DISTINCT uc.currency) >= 2
                ),
                first_day AS (
                    SELECT user_id, MIN(day) AS day FROM (
                        SELECT user_id, CAST(occurred_at AS date) AS day FROM transaction WHERE deleted_at IS NULL
                        UNION ALL
                        SELECT user_id, CAST(occurred_at AS date) FROM investment_transaction WHERE deleted_at IS NULL
                        UNION ALL
                        SELECT user_id, opening_balance_date FROM account WHERE deleted_at IS NULL AND opening_balance_date IS NOT NULL
                    ) days GROUP BY user_id
                )
                SELECT uc.currency, CAST(MIN(fd.day) AS text) AS since
                FROM user_currencies uc
                JOIN spanning s ON s.user_id = uc.user_id
                LEFT JOIN first_day fd ON fd.user_id = uc.user_id
                WHERE uc.currency <> 'EUR'
                GROUP BY uc.currency
                ORDER BY uc.currency
                SQL,
            $user !== null ? ['user' => $user->getId()->toRfc4122()] : [],
        )->fetchAllAssociative();

        return array_column($rows, 'since', 'currency');
    }

    /** The newest day with a rate for the currency, null if none. */
    public function latestDate(string $currency): ?\DateTimeImmutable
    {
        $day = $this->getEntityManager()->getConnection()->fetchOne('SELECT MAX(date) FROM exchange_rate WHERE currency = ?', [$currency]);

        return \is_string($day) ? new \DateTimeImmutable($day) : null;
    }

    /**
     * For each currency, the newest day with a shared rate and the newest
     * with one of the user's own (null where there is none).
     *
     * @param string[] $currencies
     * @return array<string, array{shared: ?string, own: ?string}> currency => 'Y-m-d'
     */
    public function latestDates(User $user, array $currencies): array
    {
        $latest = array_fill_keys($currencies, ['shared' => null, 'own' => null]);
        if ($currencies === []) {
            return $latest;
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                SELECT currency, 'shared' AS kind, CAST(MAX(date) AS text) AS date FROM exchange_rate
                WHERE currency IN (:currencies) GROUP BY currency
                UNION ALL
                SELECT currency, 'own', CAST(MAX(date) AS text) FROM exchange_rate_override
                WHERE user_id = :user AND deleted_at IS NULL AND currency IN (:currencies) GROUP BY currency
                SQL,
            ['user' => $user->getId()->toRfc4122(), 'currencies' => $currencies],
            ['currencies' => ArrayParameterType::STRING],
        )->fetchAllAssociative();
        foreach ($rows as $row) {
            $latest[$row['currency']][$row['kind']] = $row['date'];
        }

        return $latest;
    }

    /**
     * For each currency, its newest shared rate: the day, and the newest day a
     * provider published one (not carried over) with which provider.
     *
     * @param string[] $currencies
     * @return array<string, array{latest: ?string, published: ?string, source: ?string}>
     */
    public function latestShared(array $currencies): array
    {
        $latest = array_fill_keys($currencies, ['latest' => null, 'published' => null, 'source' => null]);
        if ($currencies === []) {
            return $latest;
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                SELECT currency, CAST(MAX(date) AS text) AS latest,
                       CAST(MAX(date) FILTER (WHERE source <> 'filled') AS text) AS published,
                       (ARRAY_AGG(source ORDER BY date DESC) FILTER (WHERE source <> 'filled'))[1] AS source
                FROM exchange_rate WHERE currency IN (:currencies) GROUP BY currency
                SQL,
            ['currencies' => $currencies],
            ['currencies' => ArrayParameterType::STRING],
        )->fetchAllAssociative();
        foreach ($rows as $row) {
            $latest[$row['currency']] = ['latest' => $row['latest'], 'published' => $row['published'], 'source' => $row['source']];
        }

        return $latest;
    }

    /** The oldest day with a rate for the currency, null if none. */
    public function earliestDate(string $currency): ?\DateTimeImmutable
    {
        $day = $this->getEntityManager()->getConnection()->fetchOne('SELECT MIN(date) FROM exchange_rate WHERE currency = ?', [$currency]);

        return \is_string($day) ? new \DateTimeImmutable($day) : null;
    }

    /** The newest rate of the currency before a day: [day, rate], null if none. */
    public function latestBefore(string $currency, \DateTimeImmutable $day): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT CAST(date AS text) AS date, rate FROM exchange_rate WHERE currency = ? AND date < ? ORDER BY date DESC LIMIT 1',
            [$currency, $day->format('Y-m-d')],
        );

        return $row === false ? null : [$row['date'], $row['rate']];
    }

    /**
     * Stores rates, one per day: a day that already has a rate gets the new
     * value and source (the provider's correction, or a real rate replacing a
     * carried-over one).
     *
     * @param array<string, array{0: string, 1: ExchangeRateSource}> $rates 'Y-m-d' => [rate, source]
     */
    public function upsert(string $currency, array $rates): void
    {
        $connection = $this->getEntityManager()->getConnection();
        foreach (array_chunk($rates, 500, true) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $date => [$rate, $source]) {
                $values[] = '(gen_random_uuid(), ?, ?, ?, ?, NOW())';
                array_push($params, $currency, $date, $rate, $source->value);
            }
            $connection->executeStatement(
                'INSERT INTO exchange_rate (id, currency, date, rate, source, created_at) VALUES '.implode(', ', $values)
                .' ON CONFLICT (currency, date) DO UPDATE SET rate = EXCLUDED.rate, source = EXCLUDED.source'
                .' WHERE exchange_rate.rate <> EXCLUDED.rate OR exchange_rate.source <> EXCLUDED.source',
                $params,
            );
        }
    }

    /**
     * What the user sees: for each currency, the rate of every day in
     * [from, to] with the user's own where they entered one, plus the last
     * one before the range (so a day carries over from it). `source` is
     * 'manual' for the user's.
     *
     * @param string[] $currencies
     * @return array<string, array<string, array{rate: string, source: string, original: ?string}>> currency => (Y-m-d => row), oldest first
     */
    public function findEffective(User $user, array $currencies, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        if ($currencies === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                WITH effective AS (
                    SELECT COALESCE(r.currency, o.currency) AS currency,
                           COALESCE(r.date, o.date) AS date,
                           COALESCE(o.rate, r.rate) AS rate,
                           CASE WHEN o.id IS NOT NULL THEN 'manual' ELSE r.source END AS source,
                           r.rate AS original
                    FROM exchange_rate r
                    FULL JOIN (
                        SELECT * FROM exchange_rate_override WHERE user_id = :user AND deleted_at IS NULL
                    ) o ON o.currency = r.currency AND o.date = r.date
                    WHERE COALESCE(r.currency, o.currency) IN (:currencies)
                )
                SELECT currency, CAST(date AS text) AS date, rate, source, original FROM (
                    SELECT * FROM effective WHERE date BETWEEN :from AND :to
                    UNION ALL
                    (
                        SELECT DISTINCT ON (currency) * FROM effective
                        WHERE date < :from
                        ORDER BY currency, date DESC
                    )
                ) shown
                ORDER BY currency, date
                SQL,
            ['user' => $user->getId()->toRfc4122(), 'currencies' => $currencies, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            ['currencies' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $byCurrency = [];
        foreach ($rows as $row) {
            $byCurrency[$row['currency']][$row['date']] = ['rate' => $row['rate'], 'source' => $row['source'], 'original' => $row['original']];
        }

        return $byCurrency;
    }
}
