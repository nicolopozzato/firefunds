<?php

namespace App\Repository;

use App\Core\Pagination\PaginationParams;
use App\Core\Pagination\Paginator;
use App\Core\Search\SearchQuery;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Category;
use App\Entity\RecurringTransaction;
use App\Entity\Tag;
use App\Entity\Transaction;
use App\Entity\User;
use App\Investment\Position\TradeCashEffect;
use App\Transaction\TransactionKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * @param Category|'none'|null $category only this category, only transactions without one, or any
     * @return array{0: Transaction[], 1: int}
     */
    public function findByUserPaginated(
        User $user,
        string $kind,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
        PaginationParams $params,
        ?Account $account = null,
        Category|string|null $category = null,
        ?Tag $tag = null,
        ?RecurringTransaction $recurring = null,
    ): array {
        $qb = $this->createBaseQuery($user, $from, $to)
            ->leftJoin('t.recurring', 'rec')
            ->addSelect('rec');
        TransactionKind::applyDql($qb, $kind);

        if ($account !== null) {
            $qb->andWhere('t.sourceAccount = :account OR t.destinationAccount = :account')
                ->setParameter('account', $account);
        }

        if ($category instanceof Category) {
            $qb->andWhere('t.category = :category')->setParameter('category', $category);
        } elseif ($category === 'none') {
            $qb->andWhere('t.category IS NULL');
        }

        if ($tag !== null) {
            $qb->andWhere(':tag MEMBER OF t.tags')->setParameter('tag', $tag);
        }

        if ($recurring !== null) {
            $qb->andWhere('t.recurring = :recurring')->setParameter('recurring', $recurring);
        }

        return Paginator::paginate($qb, $params);
    }

    /**
     * Page of ids across both `transaction` and `investment_transaction`, ordered
     * by date, for the "all transactions" list and the search: the two are
     * separate entities, so a single ORM query can't paginate them together.
     * This runs the ordering and paging directly in SQL against a UNION of both
     * tables' (id, occurred_at) pairs, and the caller hydrates each half by id
     * afterwards.
     *
     * @param ?string $moneyKind 'withdrawal', 'deposit' or 'transfer' to keep only that kind of money transaction
     * @return array{0: array{0: string, 1: string}[], 1: int} rows of [id, source ('money'|'investment')], and the total count
     */
    public function findAllKindIdsPaginated(
        User $user,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
        PaginationParams $params,
        ?Account $account = null,
        bool $includeMoney = true,
        bool $includeInvestment = true,
        ?string $moneyKind = null,
        ?SearchQuery $search = null,
    ): array {
        [$union, $queryParams] = $this->activityUnion(
            "t.id, t.occurred_at, 'money' AS source",
            "it.id, it.occurred_at, 'investment' AS source",
            $user, $from, $to, $account, $includeMoney, $includeInvestment, $moneyKind, $search,
        );
        if ($union === null) {
            return [[], 0];
        }

        $connection = $this->getEntityManager()->getConnection();

        $total = (int) $connection->executeQuery("SELECT COUNT(*) FROM ({$union}) combined", $queryParams)->fetchOne();

        $rows = $connection->executeQuery(
            <<<SQL
                SELECT id, occurred_at, source FROM ({$union}) combined
                ORDER BY occurred_at DESC, id DESC
                LIMIT :limit OFFSET :offset
                SQL,
            $queryParams + ['limit' => $params->pageSize, 'offset' => $params->offset()],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]
        )->fetchAllAssociative();

        $ids = array_map(static fn (array $row) => [$row['id'], $row['source']], $rows);

        return [$ids, $total];
    }

    /**
     * What the transactions findAllKindIdsPaginated() would list (same
     * filters) add up to, per kind: withdrawals, deposits and transfers, and
     * investment BUYs and SELLs with their total cash effect (as listed: a BUY
     * with its costs, a SELL net of them). Kinds with nothing listed are 0.
     *
     * @return array{withdrawal: string, deposit: string, transfer: string, investmentBuy: string, investmentSell: string}
     */
    public function sumAllKindsByKind(
        User $user,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
        ?Account $account = null,
        bool $includeMoney = true,
        bool $includeInvestment = true,
        ?string $moneyKind = null,
        ?SearchQuery $search = null,
    ): array {
        $totals = ['withdrawal' => '0', 'deposit' => '0', 'transfer' => '0', 'investmentBuy' => '0', 'investmentSell' => '0'];

        // Each amount at its day's rate, in the currency of the account whose list it is, else the user's.
        $accountCurrency = $account !== null ? $this->accountCurrency($account) : null;
        $convertTo = ':convertTo, CAST(%s AS date), :convertUser';
        [$union, $queryParams] = $this->activityUnion(
            TransactionKind::sqlCase().' AS kind, app_convert(t.amount, t.currency, '.\sprintf($convertTo, 't.occurred_at').') AS amount',
            "CASE WHEN it.type = 'buy' THEN 'investmentBuy' ELSE 'investmentSell' END AS kind,
             app_convert(".TradeCashEffect::totalSql('it').', (SELECT c.code FROM account ia JOIN currency c ON c.id = ia.currency_id WHERE ia.id = it.account_id), '.\sprintf($convertTo, 'it.occurred_at').') AS amount',
            $user, $from, $to, $account, $includeMoney, $includeInvestment, $moneyKind, $search,
        );
        if ($union === null) {
            return $totals;
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT kind, CAST(SUM(amount) AS numeric(19,4)) AS total FROM ({$union}) combined GROUP BY kind",
            $queryParams + ['convertTo' => $accountCurrency ?? $user->getCurrency(), 'convertUser' => $user->getId()->toRfc4122()],
        )->fetchAllKeyValue();

        // A pair that is none of the three kinds (it can't be stored) has no row to show.
        return array_merge($totals, array_intersect_key($rows, $totals));
    }

    /**
     * The UNION behind findAllKindIdsPaginated() and sumAllKindsByKind():
     * the user's money transactions (aliases t, src, dst) and investment
     * BUY/SELLs (it, a, i) matching the filters, each selecting the given
     * columns. Null when both halves are left out.
     *
     * The search looks, for a money transaction, in its description, notes,
     * accounts, category and tags; for a BUY/SELL in its description, notes,
     * account and instrument (name, ticker, ISIN). The fields are joined with
     * a separator no one types, so a phrase can't match across two of them.
     *
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    private function activityUnion(
        string $moneyColumns,
        string $investmentColumns,
        User $user,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
        ?Account $account,
        bool $includeMoney,
        bool $includeInvestment,
        ?string $moneyKind,
        ?SearchQuery $search,
    ): array {
        $queryParams = ['userId' => $user->getId()->toRfc4122()];
        if ($account !== null) {
            $queryParams['accountId'] = $account->getId()->toRfc4122();
        }
        if ($from !== null) {
            $queryParams['from'] = $from->format('Y-m-d H:i:s.u');
        }
        if ($to !== null) {
            $queryParams['to'] = $to->format('Y-m-d H:i:s.u');
        }

        $dateFilter = static function (string $alias) use ($from, $to): string {
            return ($from !== null ? " AND {$alias}.occurred_at >= :from" : '')
                .($to !== null ? " AND {$alias}.occurred_at <= :to" : '');
        };

        $parts = [];

        if ($includeMoney) {
            $kindFilter = $moneyKind === null ? '' : 'AND '.TransactionKind::sql($moneyKind);
            $accountFilter = $account !== null ? 'AND (t.source_account_id = :accountId OR t.destination_account_id = :accountId)' : '';
            $searchJoin = '';
            $searchFilter = '';
            if ($search !== null) {
                $searchJoin = <<<SQL
                    LEFT JOIN category c ON c.id = t.category_id
                    CROSS JOIN LATERAL (SELECT unaccent(concat_ws(chr(31),
                        t.description, t.notes, src.name, dst.name, c.name,
                        (SELECT string_agg(tag.name, chr(31)) FROM transaction_tag tt
                            JOIN tag ON tag.id = tt.tag_id AND tag.deleted_at IS NULL
                            WHERE tt.transaction_id = t.id)
                    )) AS text) searched
                    SQL;
                [$condition, $searchParams] = $search->toSql('searched.text', 'money');
                $searchFilter = "AND {$condition}";
                $queryParams += $searchParams;
            }

            $parts[] = <<<SQL
                SELECT {$moneyColumns} FROM transaction t
                LEFT JOIN account src ON src.id = t.source_account_id
                LEFT JOIN account dst ON dst.id = t.destination_account_id
                {$searchJoin}
                WHERE t.user_id = :userId AND t.deleted_at IS NULL
                    {$dateFilter('t')} {$accountFilter} {$kindFilter} {$searchFilter}
                SQL;
        }

        if ($includeInvestment) {
            $accountFilter = $account !== null ? 'AND it.account_id = :accountId' : '';
            $searchJoin = '';
            $searchFilter = '';
            if ($search !== null) {
                $searchJoin = <<<SQL
                    JOIN account a ON a.id = it.account_id
                    JOIN investment_instrument i ON i.id = it.instrument_id
                    CROSS JOIN LATERAL (SELECT unaccent(concat_ws(chr(31),
                        it.description, it.notes, a.name, i.name, i.ticker, i.isin
                    )) AS text) searched
                    SQL;
                [$condition, $searchParams] = $search->toSql('searched.text', 'investment');
                $searchFilter = "AND {$condition}";
                $queryParams += $searchParams;
            }

            $parts[] = <<<SQL
                SELECT {$investmentColumns} FROM investment_transaction it
                {$searchJoin}
                WHERE it.user_id = :userId AND it.deleted_at IS NULL
                    {$dateFilter('it')} {$accountFilter} {$searchFilter}
                SQL;
        }

        return [$parts === [] ? null : implode("\nUNION ALL\n", $parts), $queryParams];
    }

    /**
     * The latest `limit` transactions of each account in [from, to], money
     * transactions on either side and investment BUY/SELLs alike, in one
     * query; the caller hydrates them by id (as findAllKindIdsPaginated()).
     * A transfer between two of the accounts counts for both.
     *
     * @param Account[] $accounts
     * @return array<string, array{0: string, 1: string}[]> account id => rows of [id, source ('money'|'investment')], newest first
     */
    public function findLatestIdsPerAccount(array $accounts, \DateTimeInterface $from, \DateTimeInterface $to, int $limit): array
    {
        if ($accounts === []) {
            return [];
        }

        $sql = <<<SQL
            SELECT account_id, id, source FROM (
                SELECT account_id, id, source,
                       ROW_NUMBER() OVER (PARTITION BY account_id ORDER BY occurred_at DESC, id DESC) AS position
                FROM (
                    SELECT source_account_id AS account_id, id, occurred_at, 'money' AS source FROM transaction
                    WHERE source_account_id IN (:ids) AND deleted_at IS NULL AND occurred_at BETWEEN :from AND :to
                    UNION ALL
                    SELECT destination_account_id, id, occurred_at, 'money' FROM transaction
                    WHERE destination_account_id IN (:ids) AND deleted_at IS NULL AND occurred_at BETWEEN :from AND :to
                    UNION ALL
                    SELECT account_id, id, occurred_at, 'investment' FROM investment_transaction
                    WHERE account_id IN (:ids) AND deleted_at IS NULL AND occurred_at BETWEEN :from AND :to
                ) activity
            ) ranked
            WHERE position <= :limit
            ORDER BY account_id, position
            SQL;

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [
                'ids' => array_map(static fn (Account $account) => $account->getId()->toRfc4122(), $accounts),
                'from' => $from->format('Y-m-d H:i:s.u'),
                'to' => $to->format('Y-m-d H:i:s.u'),
                'limit' => $limit,
            ],
            ['ids' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER]
        )->fetchAllAssociative();

        $idsByAccount = [];
        foreach ($rows as $row) {
            $idsByAccount[$row['account_id']][] = [$row['id'], $row['source']];
        }

        return $idsByAccount;
    }

    /**
     * Every transaction on this account (either side) from `from` through
     * `to`: what a file's rows are checked against for duplicates.
     *
     * @return list<Transaction>
     */
    public function findOnAccountBetween(Account $account, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('s', 'd')
            ->leftJoin('t.sourceAccount', 's')
            ->leftJoin('t.destinationAccount', 'd')
            ->andWhere('t.sourceAccount = :account OR t.destinationAccount = :account')
            ->andWhere('t.occurredAt >= :from AND t.occurredAt < :to')
            ->setParameter('account', $account)
            ->setParameter('from', new \DateTimeImmutable($from->format('Y-m-d')))
            ->setParameter('to', new \DateTimeImmutable($to->modify('+1 day')->format('Y-m-d')))
            ->orderBy('t.occurredAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * An identical transaction already recorded: the same amount to the
     * cent, within a day of `day`, on `account` when known, the nearest
     * first. What an incoming one (a draft, the API) is checked against.
     */
    public function findIdentical(User $user, ?Account $account, string $amount, \DateTimeImmutable $day): ?Transaction
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.amount = :amount')
            ->andWhere('t.occurredAt >= :from AND t.occurredAt < :to')
            ->setParameter('user', $user)
            ->setParameter('amount', bcadd($amount, '0', 4))
            ->setParameter('from', new \DateTimeImmutable($day->modify('-1 day')->format('Y-m-d')))
            ->setParameter('to', new \DateTimeImmutable($day->modify('+2 days')->format('Y-m-d')));
        if ($account !== null) {
            $qb->andWhere('t.sourceAccount = :account OR t.destinationAccount = :account')->setParameter('account', $account);
        }
        $candidates = $qb->getQuery()->getResult();
        $target = $day->format('Y-m-d');
        usort($candidates, static fn (Transaction $a, Transaction $b) => abs(strtotime($a->getOccurredAt()->format('Y-m-d')) - strtotime($target)) <=> abs(strtotime($b->getOccurredAt()->format('Y-m-d')) - strtotime($target)));

        return $candidates[0] ?? null;
    }

    /**
     * Transactions that could be an occurrence of a recurring one: between
     * the same two sides (none for none), from `from` through `to`, not part
     * of a recurrence yet. Any amount: a bill changes month by month.
     *
     * @return list<Transaction>
     */
    public function findRecurringCandidates(User $user, ?Account $source, ?Account $destination, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.recurring IS NULL')
            ->andWhere('t.occurredAt >= :from AND t.occurredAt < :to')
            ->setParameter('user', $user)
            ->setParameter('from', new \DateTimeImmutable($from->format('Y-m-d')))
            ->setParameter('to', new \DateTimeImmutable($to->modify('+1 day')->format('Y-m-d')))
            ->orderBy('t.occurredAt', 'ASC');
        foreach (['sourceAccount' => $source, 'destinationAccount' => $destination] as $side => $account) {
            if ($account === null) {
                $qb->andWhere("t.{$side} IS NULL");
            } else {
                $qb->andWhere("t.{$side} = :{$side}")->setParameter($side, $account);
            }
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Transactions on this account, not part of a recurrence, from `from`
     * through `to`: what the user may link to a recurring one by hand.
     *
     * @return list<Transaction>
     */
    public function findLinkable(User $user, Account $account, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.recurring IS NULL')
            ->andWhere('t.sourceAccount = :account OR t.destinationAccount = :account')
            ->andWhere('t.occurredAt >= :from AND t.occurredAt < :to')
            ->setParameter('user', $user)
            ->setParameter('account', $account)
            ->setParameter('from', new \DateTimeImmutable($from->format('Y-m-d')))
            ->setParameter('to', new \DateTimeImmutable($to->modify('+1 day')->format('Y-m-d')))
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
    }

    /**
     * These transactions with everything their response shows (accounts,
     * category, tags) loaded in the same query.
     *
     * @param string[] $ids
     * @return Transaction[]
     */
    public function findWithRelationsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->addSelect('src', 'dst', 'c', 'tag', 'rec')
            ->leftJoin('t.recurring', 'rec')
            ->leftJoin('t.sourceAccount', 'src')
            ->leftJoin('t.destinationAccount', 'dst')
            ->leftJoin('t.category', 'c')
            ->leftJoin('t.tags', 'tag')
            ->andWhere('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndId(User $user, string $id): ?Transaction
    {
        // Not a UUID is not anything of theirs (and would fail the conversion).
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['user' => $user, 'id' => $id]);
    }

    /**
     * Every (non-deleted) transaction with this account on either side.
     *
     * @return Transaction[]
     */
    public function findByAccount(Account $account): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.sourceAccount = :account OR t.destinationAccount = :account')
            ->setParameter('account', $account)
            ->orderBy('t.occurredAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByAccount(Account $account): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.sourceAccount = :account OR t.destinationAccount = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Transaction[]
     */
    public function findByCategory(Category $category): array
    {
        return $this->findBy(['category' => $category]);
    }

    public function countByCategory(Category $category): int
    {
        return $this->count(['category' => $category]);
    }

    /**
     * @return Transaction[]
     */
    public function findByTag(Tag $tag): array
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.tags', 'tag')
            ->andWhere('tag = :tag')
            ->setParameter('tag', $tag)
            ->getQuery()
            ->getResult();
    }

    public function countByTag(Tag $tag): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->innerJoin('t.tags', 'tag')
            ->andWhere('tag = :tag')
            ->setParameter('tag', $tag)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The first and last day the user has anything on: money transactions,
     * trades, and the opening balances and other changes (AccountAdjustment)
     * of their accounts (deleted ones left out). Bounds the "All" timeframe. Both null when there is nothing yet.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} days (midnight)
     */
    public function findDateRange(User $user): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            <<<SQL
                SELECT MIN(day) AS earliest, MAX(day) AS latest FROM (
                    SELECT CAST(occurred_at AS date) AS day FROM transaction WHERE user_id = :user AND deleted_at IS NULL
                    UNION ALL
                    SELECT CAST(occurred_at AS date) FROM investment_transaction WHERE user_id = :user AND deleted_at IS NULL
                    UNION ALL
                    SELECT opening_balance_date FROM account
                    WHERE user_id = :user AND deleted_at IS NULL AND opening_balance_date IS NOT NULL
                    UNION ALL
                    SELECT aa.date FROM account_adjustment aa
                    JOIN account a ON a.id = aa.account_id AND a.user_id = :user AND a.deleted_at IS NULL
                    WHERE aa.deleted_at IS NULL
                ) days
                SQL,
            ['user' => $user->getId()->toRfc4122()],
        );

        if ($row === false || $row['earliest'] === null) {
            return [null, null];
        }

        return [new \DateTimeImmutable($row['earliest']), new \DateTimeImmutable($row['latest'])];
    }

    /**
     * What the reports add up, per flow, category and calendar month (as
     * written in `occurred_at`) over [from, to]. Flows:
     * - `income`: a deposit (from a revenue account or from nobody to an asset account);
     * - `expense`: a withdrawal (from an asset account to an expense account or to nobody);
     * - `invested` / `divested`: a transfer from a non-investment asset account
     *   to an investment one, and back. Not an expense: the money is still
     *   there, as another asset.
     * Other transfers move money around and are left out. The same rules as
     * the dashboard's figures (TransactionKind), so the two always agree.
     *
     * @return array<array{flow: string, category_id: ?string, category_name: ?string, month: string, total: string}>
     */
    public function sumFlowsByCategoryAndMonth(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $flow = TransactionKind::sqlFlowCase();
        $sql = <<<SQL
            SELECT flow, category_id, category_name, month, CAST(SUM(amount) AS numeric(19,4)) AS total
            FROM (
                SELECT
                    {$flow} AS flow,
                    t.category_id,
                    c.name AS category_name,
                    to_char(t.occurred_at, 'YYYY-MM') AS month,
                    app_convert(t.amount, t.currency, :currency, CAST(t.occurred_at AS date), :user) AS amount
                FROM transaction t
                LEFT JOIN account src ON src.id = t.source_account_id
                LEFT JOIN account dst ON dst.id = t.destination_account_id
                LEFT JOIN category c ON c.id = t.category_id
                WHERE t.user_id = :user AND t.deleted_at IS NULL AND t.occurred_at BETWEEN :from AND :to
            ) flows
            WHERE flow IS NOT NULL
            GROUP BY flow, category_id, category_name, month
            ORDER BY month
            SQL;

        return $this->getEntityManager()->getConnection()->executeQuery($sql, [
            'user' => $user->getId()->toRfc4122(),
            'currency' => $user->getCurrency(),
            'from' => $from->format('Y-m-d H:i:s.u'),
            'to' => $to->format('Y-m-d H:i:s.u'),
        ])->fetchAllAssociative();
    }

    /**
     * Month by month, what was spent and received with one category or one
     * tag over a range, in the user's currency (each amount at its day's
     * rate): the chart of a category's or a tag's page. Transfers are neither.
     *
     * @return list<array{month: string, expense: string, income: string}>
     */
    public function sumFlowsByMonthFor(User $user, \DateTimeInterface $from, \DateTimeInterface $to, Category|Tag $of): array
    {
        $flow = TransactionKind::sqlFlowCase();
        $which = $of instanceof Category
            ? 't.category_id = :of'
            : 'EXISTS (SELECT 1 FROM transaction_tag tt WHERE tt.transaction_id = t.id AND tt.tag_id = :of)';
        $sql = <<<SQL
            SELECT month,
                CAST(COALESCE(SUM(amount) FILTER (WHERE flow = 'expense'), 0) AS numeric(19,4)) AS expense,
                CAST(COALESCE(SUM(amount) FILTER (WHERE flow = 'income'), 0) AS numeric(19,4)) AS income
            FROM (
                SELECT
                    {$flow} AS flow,
                    to_char(t.occurred_at, 'YYYY-MM') AS month,
                    app_convert(t.amount, t.currency, :currency, CAST(t.occurred_at AS date), :user) AS amount
                FROM transaction t
                LEFT JOIN account src ON src.id = t.source_account_id
                LEFT JOIN account dst ON dst.id = t.destination_account_id
                WHERE t.user_id = :user AND t.deleted_at IS NULL AND t.occurred_at BETWEEN :from AND :to AND {$which}
            ) flows
            WHERE flow IN ('expense', 'income')
            GROUP BY month
            ORDER BY month
            SQL;

        return $this->getEntityManager()->getConnection()->executeQuery($sql, [
            'user' => $user->getId()->toRfc4122(),
            'currency' => $user->getCurrency(),
            'from' => $from->format('Y-m-d H:i:s.u'),
            'to' => $to->format('Y-m-d H:i:s.u'),
            'of' => $of->getId()->toRfc4122(),
        ])->fetchAllAssociative();
    }

    /**
     * What to offer while a new transaction's description is typed: the
     * latest transaction of each description containing the text (ignoring
     * case and accents), those starting with it first, the most recent next.
     *
     * @return list<Transaction>
     */
    public function suggestionsByDescription(User $user, string $text, int $limit = 6): array
    {
        $escaped = addcslashes(mb_strtolower(trim($text)), '%_\\');
        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                SELECT id FROM (
                    SELECT DISTINCT ON (lower(t.description)) t.id, t.occurred_at, unaccent(lower(t.description)) AS folded
                    FROM transaction t
                    WHERE t.user_id = :user AND t.deleted_at IS NULL AND t.description IS NOT NULL
                        AND unaccent(lower(t.description)) LIKE '%' || unaccent(:text) || '%'
                    ORDER BY lower(t.description), t.occurred_at DESC
                ) latest
                ORDER BY folded LIKE unaccent(:text) || '%' DESC, occurred_at DESC
                LIMIT :limit
                SQL,
            ['user' => $user->getId()->toRfc4122(), 'text' => $escaped, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        )->fetchFirstColumn();

        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->findBy(['id' => $ids]) as $transaction) {
            $byId[$transaction->getId()->toRfc4122()] = $transaction;
        }

        return array_values(array_filter(array_map(static fn (string $id) => $byId[$id] ?? null, $ids)));
    }

    /**
     * The day of the latest transaction each of these categories or tags is
     * set on, in one query for a whole list page.
     *
     * @param list<Category>|list<Tag> $items
     *
     * @return array<string, string> id => YYYY-MM-DD
     */
    public function lastTransactionDays(User $user, array $items, bool $tags = false): array
    {
        if ($items === []) {
            return [];
        }
        $sql = $tags
            ? 'SELECT tt.tag_id AS id, MAX(t.occurred_at) AS last FROM transaction t JOIN transaction_tag tt ON tt.transaction_id = t.id
               WHERE t.user_id = :user AND t.deleted_at IS NULL AND tt.tag_id IN (:ids) GROUP BY tt.tag_id'
            : 'SELECT t.category_id AS id, MAX(t.occurred_at) AS last FROM transaction t
               WHERE t.user_id = :user AND t.deleted_at IS NULL AND t.category_id IN (:ids) GROUP BY t.category_id';

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            ['user' => $user->getId()->toRfc4122(), 'ids' => array_map(static fn ($item) => $item->getId()->toRfc4122(), $items)],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $days = [];
        foreach ($rows as $row) {
            $days[$row['id']] = substr((string) $row['last'], 0, 10);
        }

        return $days;
    }

    /**
     * SUM of the amounts in the currency the user reads them in (their own,
     * or the one given), each amount at its day's rate: what to select where
     * transactions of accounts in different currencies are added up.
     */
    private function convertedSum(QueryBuilder $qb, User $user, ?string $currency = null): string
    {
        $qb->setParameter('convertTo', $currency ?? $user->getCurrency())
            ->setParameter('convertUser', $user->getId()->toRfc4122());

        return 'SUM(CONVERTED(t.amount, t.currency, t.occurredAt, :convertTo, :convertUser))';
    }

    /**
     * Sum of transaction amounts grouped by category, for the same filters as the list endpoints.
     *
     * @return array<array{id: ?string, name: ?string, total: string}>
     */
    public function sumByCategory(User $user, string $kind, ?\DateTimeInterface $from, ?\DateTimeInterface $to): array
    {
        $qb = $this->createBaseQuery($user, $from, $to);
        $qb->select('IDENTITY(t.category) AS id', 'c.name AS name', $this->convertedSum($qb, $user).' AS total')
            ->leftJoin('t.category', 'c')
            ->groupBy('t.category, c.name')
            ->orderBy('total', 'DESC');

        TransactionKind::applyDql($qb, $kind);

        return $qb->getQuery()->getResult();
    }

    /**
     * Sum of transaction amounts grouped by source account, for the same filters as the list endpoints.
     *
     * @return array<array{id: string, name: string, total: string}>
     */
    public function sumBySourceAccount(User $user, string $kind, ?\DateTimeInterface $from, ?\DateTimeInterface $to): array
    {
        $qb = $this->createBaseQuery($user, $from, $to);
        $qb->select('IDENTITY(t.sourceAccount) AS id', 'src.name AS name', $this->convertedSum($qb, $user).' AS total')
            ->groupBy('t.sourceAccount, src.name')
            ->orderBy('total', 'DESC');

        TransactionKind::applyDql($qb, $kind);

        return $qb->getQuery()->getResult();
    }

    /**
     * Sum of transaction amounts grouped by destination account, for the same filters as the list endpoints.
     *
     * @return array<array{id: string, name: string, total: string}>
     */
    public function sumByDestinationAccount(User $user, string $kind, ?\DateTimeInterface $from, ?\DateTimeInterface $to): array
    {
        $qb = $this->createBaseQuery($user, $from, $to);
        $qb->select('IDENTITY(t.destinationAccount) AS id', 'dst.name AS name', $this->convertedSum($qb, $user).' AS total')
            ->groupBy('t.destinationAccount, dst.name')
            ->orderBy('total', 'DESC');

        TransactionKind::applyDql($qb, $kind);

        return $qb->getQuery()->getResult();
    }

    /**
     * Sum of this account's outgoing (withdrawal) transactions grouped by category.
     *
     * For an asset account: money it sent to an expense account. For an expense
     * account itself: every transaction received (it can only ever be a destination
     * for withdrawals). Revenue accounts have no expenses, so this is empty for them.
     *
     * @return array<array{id: ?string, name: ?string, total: string}>
     */
    public function sumByCategoryForAccountExpenses(
        \App\Entity\Account $account,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
    ): array {
        if ($account->getType() === AccountType::Revenue) {
            return [];
        }

        $qb = $this->createAccountBaseQuery($account, $from, $to);
        $qb->select('IDENTITY(t.category) AS id', 'c.name AS name', $this->convertedSum($qb, $account->getUser(), $this->accountCurrency($account)).' AS total')
            ->leftJoin('t.category', 'c')
            ->groupBy('t.category, c.name')
            ->orderBy('total', 'DESC');

        if ($account->getType() === AccountType::Expense) {
            $qb->andWhere('t.destinationAccount = :account');
        } else {
            $qb->andWhere('t.sourceAccount = :account');
            TransactionKind::applyDql($qb, TransactionKind::WITHDRAWAL);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Sum of this account's incoming (deposit) transactions grouped by category.
     *
     * For an asset account: money it received from a revenue account. For a revenue
     * account itself: every transaction it generated (it can only ever be a source
     * for deposits). Expense accounts have no income, so this is empty for them.
     *
     * @return array<array{id: ?string, name: ?string, total: string}>
     */
    public function sumByCategoryForAccountIncome(
        \App\Entity\Account $account,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
    ): array {
        if ($account->getType() === AccountType::Expense) {
            return [];
        }

        $qb = $this->createAccountBaseQuery($account, $from, $to);
        $qb->select('IDENTITY(t.category) AS id', 'c.name AS name', $this->convertedSum($qb, $account->getUser(), $this->accountCurrency($account)).' AS total')
            ->leftJoin('t.category', 'c')
            ->groupBy('t.category, c.name')
            ->orderBy('total', 'DESC');

        if ($account->getType() === AccountType::Revenue) {
            $qb->andWhere('t.sourceAccount = :account');
        } else {
            $qb->andWhere('t.destinationAccount = :account');
            TransactionKind::applyDql($qb, TransactionKind::DEPOSIT);
        }

        return $qb->getQuery()->getResult();
    }

    /** An asset account's amounts are in its own currency; an expense or revenue account collects from several: the user's. */
    private function accountCurrency(\App\Entity\Account $account): ?string
    {
        return $account->getType() === AccountType::Asset ? $account->getCurrency()?->getCode() : null;
    }

    private function createAccountBaseQuery(
        \App\Entity\Account $account,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('t')
            // Left joins: either side may be null ("external").
            ->leftJoin('t.sourceAccount', 'src')
            ->leftJoin('t.destinationAccount', 'dst')
            ->andWhere('t.user = :user')
            ->setParameter('user', $account->getUser())
            ->setParameter('account', $account)
            ->orderBy('t.occurredAt', 'DESC')
            ->addOrderBy('t.id', 'DESC');

        if ($from !== null) {
            $qb->andWhere('t.occurredAt >= :from')->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('t.occurredAt <= :to')->setParameter('to', $to);
        }

        return $qb;
    }

    private function createBaseQuery(User $user, ?\DateTimeInterface $from, ?\DateTimeInterface $to): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            // Left joins: either side may be null ("external").
            ->leftJoin('t.sourceAccount', 'src')
            ->leftJoin('t.destinationAccount', 'dst')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.occurredAt', 'DESC')
            ->addOrderBy('t.id', 'DESC');

        if ($from !== null) {
            $qb->andWhere('t.occurredAt >= :from')->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('t.occurredAt <= :to')->setParameter('to', $to);
        }

        return $qb;
    }
}
