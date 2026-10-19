<?php

namespace App\Repository;

use App\Core\Pagination\PaginationParams;
use App\Core\Pagination\Paginator;
use App\Core\Search\SearchQuery;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * The order the user gave the accounts, everywhere they are listed: the
     * position they were dragged to, then the not yet ordered ones oldest
     * first (Postgres sorts NULL positions last).
     */
    private const array ORDER = ['position' => 'ASC', 'createdAt' => 'ASC', 'id' => 'ASC'];

    /**
     * @return Account[]
     */
    public function findByUserAndType(User $user, AccountType $type): array
    {
        return $this->findBy(['user' => $user, 'type' => $type], self::ORDER);
    }

    /**
     * @return array{0: Account[], 1: int}
     */
    public function findByUserAndTypePaginated(
        User $user,
        AccountType $type,
        PaginationParams $params,
        ?bool $active = null,
        ?AssetAccountKind $kind = null,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->andWhere('a.type = :type')
            ->setParameter('user', $user)
            ->setParameter('type', $type);
        foreach (self::ORDER as $field => $direction) {
            $qb->addOrderBy('a.'.$field, $direction);
        }

        if ($active !== null) {
            $qb->andWhere('a.isActive = :active')->setParameter('active', $active);
        }
        if ($kind !== null) {
            $qb->andWhere('a.assetKind = :kind')->setParameter('kind', $kind);
        }

        return Paginator::paginate($qb, $params);
    }

    /**
     * The user's accounts whose name matches the search, active ones first,
     * then by name: the shortcuts under the search box.
     *
     * @return array<array{id: string, name: string, type: string}>
     */
    public function searchByName(User $user, SearchQuery $search, int $limit): array
    {
        [$condition, $params] = $search->toSql('unaccent(name)', 'account');

        return $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                SELECT CAST(id AS text) AS id, name, type FROM account
                WHERE user_id = :userId AND deleted_at IS NULL AND {$condition}
                ORDER BY is_active DESC, name
                LIMIT :limit
                SQL,
            $params + ['userId' => $user->getId()->toRfc4122(), 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        )->fetchAllAssociative();
    }

    public function findOneByUserAndId(User $user, string $id): ?Account
    {
        return $this->findOneBy(['user' => $user, 'id' => $id]);
    }

    /**
     * Current balance per account: opening balance + money received (as
     * destination) - money sent (as source), across all transactions, plus the
     * net cash effect of any BUY/SELL investment transactions on that account
     * (these never produce a `transaction` row, so their cash impact has to be
     * folded in here to keep an investment account's free cash balance correct).
     *
     * @param Account[] $accounts
     * @return array<string, string> account id (string) => balance
     */
    public function getCurrentBalances(array $accounts): array
    {
        if ($accounts === []) {
            return [];
        }

        $ids = array_map(static fn (Account $account) => $account->getId(), $accounts);

        $connection = $this->getEntityManager()->getConnection();
        $placeholders = implode(',', array_fill(0, \count($ids), '?'));
        $params = array_map(static fn ($id) => $id->toRfc4122(), $ids);

        $sql = <<<SQL
            SELECT
                a.id AS account_id,
                CAST(
                    COALESCE(a.opening_balance, 0)
                        + COALESCE((SELECT SUM(t.amount) FROM transaction t WHERE t.destination_account_id = a.id AND t.deleted_at IS NULL), 0)
                        - COALESCE((SELECT SUM(t.amount) FROM transaction t WHERE t.source_account_id = a.id AND t.deleted_at IS NULL), 0)
                        + COALESCE((
                            SELECT SUM(
                                CASE
                                    WHEN it.type = 'buy' THEN -(it.amount + it.fees + it.taxes)
                                    WHEN it.type = 'sell' THEN (it.amount - it.fees - it.taxes)
                                    ELSE 0
                                END
                            )
                            FROM investment_transaction it
                            WHERE it.account_id = a.id AND it.deleted_at IS NULL
                        ), 0)
                    AS numeric(19,4)
                ) AS balance
            FROM account a
            WHERE a.id IN ({$placeholders})
            SQL;

        $rows = $connection->executeQuery($sql, $params)->fetchAllAssociative();

        $balances = [];
        foreach ($rows as $row) {
            $balances[$row['account_id']] = $row['balance'];
        }

        return $balances;
    }

    /**
     * Most recent activity per account: a money transaction with the account as
     * either source or destination, or an investment BUY/SELL on it (a broker
     * account's trades live in `investment_transaction`, not `transaction`).
     *
     * @param Account[] $accounts
     * @return array<string, string> account id (string) => ISO 8601 datetime
     */
    public function getLastActivityDates(array $accounts): array
    {
        if ($accounts === []) {
            return [];
        }

        $ids = array_map(static fn (Account $account) => $account->getId(), $accounts);

        $connection = $this->getEntityManager()->getConnection();
        $placeholders = implode(',', array_fill(0, \count($ids), '?'));
        $params = array_map(static fn ($id) => $id->toRfc4122(), $ids);

        $sql = <<<SQL
            SELECT account_id, MAX(occurred_at) AS last_activity_at
            FROM (
                SELECT source_account_id AS account_id, occurred_at FROM transaction WHERE source_account_id IN ({$placeholders}) AND deleted_at IS NULL
                UNION ALL
                SELECT destination_account_id, occurred_at FROM transaction WHERE destination_account_id IN ({$placeholders}) AND deleted_at IS NULL
                UNION ALL
                SELECT account_id, occurred_at FROM investment_transaction WHERE account_id IN ({$placeholders}) AND deleted_at IS NULL
            ) activity
            GROUP BY account_id
            SQL;
        $params = [...$params, ...$params, ...$params];

        $rows = $connection->executeQuery($sql, $params)->fetchAllAssociative();

        $dates = [];
        foreach ($rows as $row) {
            $dates[$row['account_id']] = $row['last_activity_at'];
        }

        return $dates;
    }

    /**
     * Balance at the end of each day in [from, to], carrying the opening balance
     * forward and applying the net of that day's transactions.
     *
     * @return array<array{date: string, balance: string}>
     */
    public function getDailyBalanceHistory(Account $account, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $history = $this->getDailyBalanceHistories([$account], $from, $to)[$account->getId()->toRfc4122()] ?? [];

        return array_map(
            static fn (string $date, string $balance) => ['date' => $date, 'balance' => $balance],
            array_keys($history),
            $history
        );
    }

    /**
     * getDailyBalanceHistory() for several accounts of the same user in one
     * query. The free cash of an investment account: what it holds is valued
     * apart (InvestmentTransactionRepository, PositionHistory).
     *
     * `occurred_at` is stored as a UTC instant; each one is converted into the
     * owner's own timezone before being truncated to a calendar day, so a
     * transaction made at (say) 00:20 local time lands in "today" rather than
     * "yesterday" just because its UTC instant falls on the previous UTC date.
     *
     * The opening balance is there from its date on (as in Firefly III), so an
     * account opened during the range adds nothing before; one without a date
     * is there from the very beginning.
     *
     * @param Account[] $accounts
     * @return array<string, array<string, string>> account id => (Y-m-d => balance), oldest first
     */
    public function getDailyBalanceHistories(array $accounts, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        if ($accounts === []) {
            return [];
        }

        $sql = <<<SQL
            WITH days AS (
                SELECT CAST(d AS date) AS day FROM generate_series(CAST(:from AS date), CAST(:to AS date), INTERVAL '1 day') d
            ),
            movements AS (
                SELECT t.destination_account_id AS account_id, t.occurred_at, t.amount AS net
                FROM transaction t
                WHERE t.destination_account_id IN (:ids) AND t.deleted_at IS NULL
                UNION ALL
                SELECT t.source_account_id, t.occurred_at, -t.amount
                FROM transaction t
                WHERE t.source_account_id IN (:ids) AND t.deleted_at IS NULL
                UNION ALL
                SELECT
                    it.account_id,
                    it.occurred_at,
                    CASE
                        WHEN it.type = 'buy' THEN -(it.amount + it.fees + it.taxes)
                        WHEN it.type = 'sell' THEN (it.amount - it.fees - it.taxes)
                        ELSE 0
                    END
                FROM investment_transaction it
                WHERE it.account_id IN (:ids) AND it.deleted_at IS NULL
            ),
            daily_net AS MATERIALIZED (
                SELECT account_id, day, SUM(net) AS net FROM (
                    SELECT account_id, CAST(occurred_at AT TIME ZONE 'UTC' AT TIME ZONE :timezone AS date) AS day, net
                    FROM movements
                    UNION ALL
                    -- A dated opening balance arrives on its date (already a calendar day): before it, it isn't there.
                    SELECT id, opening_balance_date, opening_balance
                    FROM account
                    WHERE id IN (:ids) AND opening_balance_date IS NOT NULL AND opening_balance <> 0
                ) by_day
                WHERE day <= CAST(:to AS date)
                GROUP BY account_id, day
            ),
            -- A join, not a subquery per account: Postgres would run that once per output row.
            -- An opening balance without a date counts from the very beginning.
            opening AS (
                SELECT
                    a.id AS account_id,
                    CASE WHEN a.opening_balance_date IS NULL THEN COALESCE(a.opening_balance, 0) ELSE 0 END
                        + COALESCE(before.net, 0) AS balance
                FROM account a
                LEFT JOIN (
                    SELECT account_id, SUM(net) AS net FROM daily_net WHERE day < CAST(:from AS date) GROUP BY account_id
                ) before ON before.account_id = a.id
                WHERE a.id IN (:ids)
            )
            SELECT
                opening.account_id,
                days.day::text AS date,
                CAST(opening.balance + COALESCE(SUM(daily_net.net) OVER (PARTITION BY opening.account_id ORDER BY days.day), 0) AS numeric(19,4)) AS balance
            FROM opening
            CROSS JOIN days
            LEFT JOIN daily_net ON daily_net.account_id = opening.account_id AND daily_net.day = days.day
            ORDER BY opening.account_id, days.day
            SQL;

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [
                'ids' => array_map(static fn (Account $account) => $account->getId()->toRfc4122(), $accounts),
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'timezone' => $accounts[0]->getUser()->getTimezone(),
            ],
            ['ids' => ArrayParameterType::STRING]
        )->fetchAllAssociative();

        $histories = [];
        foreach ($rows as $row) {
            $histories[$row['account_id']][$row['date']] = $row['balance'];
        }

        return $histories;
    }
}
