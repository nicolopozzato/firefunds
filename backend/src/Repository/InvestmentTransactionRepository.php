<?php

namespace App\Repository;

use App\Core\Pagination\PaginationParams;
use App\Core\Pagination\Paginator;
use App\Entity\Account;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\User;
use App\Investment\Position\AverageCostPosition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvestmentTransaction>
 */
class InvestmentTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvestmentTransaction::class);
    }

    /**
     * @return array{0: InvestmentTransaction[], 1: int}
     */
    public function findByUserPaginated(
        User $user,
        PaginationParams $params,
        ?Account $account = null,
        ?InvestmentInstrument $instrument = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        $qb = $this->createQueryBuilder('it')
            ->andWhere('it.user = :user')
            ->setParameter('user', $user)
            ->orderBy('it.occurredAt', 'DESC')
            ->addOrderBy('it.id', 'DESC');

        if ($account !== null) {
            $qb->andWhere('it.account = :account')->setParameter('account', $account);
        }

        if ($instrument !== null) {
            $qb->andWhere('it.instrument = :instrument')->setParameter('instrument', $instrument);
        }

        if ($from !== null) {
            $qb->andWhere('it.occurredAt >= :from')->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('it.occurredAt <= :to')->setParameter('to', $to);
        }

        return Paginator::paginate($qb, $params);
    }

    private const array TOTAL_GROUPS = [
        'instrumentType' => ['i.type', 'i.type'],
        'instrument' => ['i.id', 'i.name'],
        'account' => ['a.id', 'a.name'],
    ];

    /**
     * Sum of the trades' totals (what the list shows: a BUY with its costs, a
     * SELL net of them), grouped by instrument type, instrument or account,
     * for the same period as the list. Biggest first.
     *
     * @param 'instrumentType'|'instrument'|'account' $groupBy
     * @return array<array{id: string, name: string, total: string}>
     */
    public function sumTotalsBy(string $groupBy, User $user, ?\DateTimeInterface $from, ?\DateTimeInterface $to): array
    {
        [$idColumn, $nameColumn] = self::TOTAL_GROUPS[$groupBy];

        $dateFilter = '';
        $params = ['userId' => $user->getId()->toRfc4122()];
        if ($from !== null) {
            $dateFilter .= ' AND it.occurred_at >= :from';
            $params['from'] = $from->format('Y-m-d H:i:s');
        }
        if ($to !== null) {
            $dateFilter .= ' AND it.occurred_at <= :to';
            $params['to'] = $to->format('Y-m-d H:i:s');
        }

        $sql = <<<SQL
            SELECT
                CAST({$idColumn} AS text) AS id,
                {$nameColumn} AS name,
                CAST(SUM(
                    CASE WHEN it.type = 'buy' THEN it.amount + it.fees + it.taxes ELSE it.amount - it.fees - it.taxes END
                ) AS numeric(19,4)) AS total
            FROM investment_transaction it
            JOIN investment_instrument i ON i.id = it.instrument_id
            JOIN account a ON a.id = it.account_id
            WHERE it.user_id = :userId AND it.deleted_at IS NULL {$dateFilter}
            GROUP BY {$idColumn}, {$nameColumn}
            ORDER BY total DESC
            SQL;

        return $this->getEntityManager()->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();
    }

    /**
     * These trades with their account (and its currency) and instrument
     * loaded in the same query.
     *
     * @param string[] $ids
     * @return InvestmentTransaction[]
     */
    public function findWithRelationsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('it')
            ->addSelect('a', 'cur', 'i')
            ->join('it.account', 'a')
            ->leftJoin('a.currency', 'cur')
            ->join('it.instrument', 'i')
            ->andWhere('it.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndId(User $user, string $id): ?InvestmentTransaction
    {
        return $this->findOneBy(['user' => $user, 'id' => $id]);
    }

    /**
     * Every open position (instrument still held) on an account: quantity, cost
     * basis and average cost, folded from the ordered BUY/SELL history using the
     * running average-cost method (see AverageCostPosition). `openedOn` is the
     * day (in the owner's timezone) the current holding was opened, i.e. the BUY
     * that last took the quantity up from zero.
     *
     * @return array<array{instrumentId: string, instrumentName: string, instrumentTicker: ?string, quantity: string, costBasis: string, averageCost: string, openedOn: ?string, taxRate: ?string}>
     */
    public function getPositions(Account $account): array
    {
        return $this->positionsFromTrades($this->findTradeRows($account));
    }

    /**
     * When the instrument was first traded (any account, deleted trades
     * excluded), or null if never.
     */
    public function findFirstTradeTime(InvestmentInstrument $instrument): ?\DateTimeImmutable
    {
        $first = $this->createQueryBuilder('it')
            ->select('MIN(it.occurredAt)')
            ->andWhere('it.instrument = :instrument')
            ->setParameter('instrument', $instrument)
            ->getQuery()
            ->getSingleScalarResult();

        return $first !== null ? new \DateTimeImmutable($first, new \DateTimeZone('UTC')) : null;
    }

    /**
     * Every (non-deleted) BUY/SELL on this account, oldest first per
     * instrument, with the day it happened in the owner's timezone.
     *
     * @return array<array{account_id: string, instrument_id: string, instrument_name: string, instrument_ticker: ?string, type: string, quantity: string, amount: string, costs: string, day: string}>
     */
    public function findTradeRows(Account $account): array
    {
        return $this->findTradeRowsForAccounts([$account]);
    }

    /**
     * Same as findTradeRows() for several accounts in one query, ordered by
     * account first (e.g. the whole accounts list page at once).
     *
     * @param Account[] $accounts
     * @return array<array{account_id: string, instrument_id: string, instrument_name: string, instrument_ticker: ?string, type: string, quantity: string, amount: string, costs: string, day: string}>
     */
    public function findTradeRowsForAccounts(array $accounts): array
    {
        if ($accounts === []) {
            return [];
        }

        return $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                SELECT it.account_id, it.instrument_id, i.name AS instrument_name, i.ticker AS instrument_ticker,
                       i.capital_gains_tax_rate AS instrument_tax_rate,
                       it.type, it.quantity, it.amount, it.fees + it.taxes AS costs,
                       CAST(it.occurred_at AT TIME ZONE 'UTC' AT TIME ZONE u.timezone AS date) AS day
                FROM investment_transaction it
                JOIN investment_instrument i ON i.id = it.instrument_id
                JOIN "user" u ON u.id = it.user_id
                WHERE it.account_id IN (:accountIds) AND it.deleted_at IS NULL
                ORDER BY it.account_id, it.instrument_id, it.occurred_at, it.id
                SQL,
            ['accountIds' => array_map(static fn (Account $account) => $account->getId()->toRfc4122(), $accounts)],
            ['accountIds' => ArrayParameterType::STRING]
        )->fetchAllAssociative();
    }

    /**
     * The open positions resulting from trade rows as returned by findTradeRows().
     *
     * @param array<array{instrument_id: string, instrument_name: string, instrument_ticker: ?string, type: string, quantity: string, amount: string, costs: string, day: string}> $rows
     * @return array<array{instrumentId: string, instrumentName: string, instrumentTicker: ?string, quantity: string, costBasis: string, averageCost: string, openedOn: ?string, taxRate: ?string}>
     */
    public function positionsFromTrades(array $rows): array
    {
        $byInstrument = [];
        foreach ($rows as $row) {
            $byInstrument[$row['instrument_id']]['name'] ??= $row['instrument_name'];
            $byInstrument[$row['instrument_id']]['ticker'] ??= $row['instrument_ticker'];
            $byInstrument[$row['instrument_id']]['taxRate'] ??= $row['instrument_tax_rate'] ?? null;
            $byInstrument[$row['instrument_id']]['rows'][] = $row;
        }

        $positions = [];
        foreach ($byInstrument as $instrumentId => $data) {
            ['quantity' => $quantity, 'costBasis' => $costBasis, 'openedOn' => $openedOn] = $this->fold($data['rows']);

            if (bccomp($quantity, '0', 10) === 0) {
                continue;
            }

            $positions[] = [
                'instrumentId' => $instrumentId,
                'instrumentName' => $data['name'],
                'instrumentTicker' => $data['ticker'],
                'quantity' => bcadd($quantity, '0', 6),
                'costBasis' => bcadd($costBasis, '0', 4),
                'averageCost' => bcdiv($costBasis, $quantity, 4),
                'openedOn' => $openedOn,
                // The instrument's own capital gains tax rate, null = the user's.
                'taxRate' => $data['taxRate'] ?? null,
            ];
        }

        return $positions;
    }

    /**
     * Market value of everything held on this account at the end of each day in
     * [from, to]: for each instrument, the running quantity held as of that day
     * (cumulative BUYs minus SELLs up to and including that day, trades before
     * `from` included) times the
     * instrument's closing price on or before that day (its price history is
     * itself forward-filled through weekends/holidays, so this only falls back
     * further for a day before the instrument's first recorded price).
     *
     * Each transaction's UTC instant is converted into the account owner's own
     * timezone before being truncated to a calendar day (mirroring
     * AccountRepository::getDailyBalanceHistory), so a purchase made late at
     * night local time isn't attributed to the previous UTC day.
     *
     * @return array<array{date: string, value: string}>
     */
    public function getDailyInvestedValueHistory(Account $account, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $id = $account->getId()->toRfc4122();
        $timezone = $account->getUser()->getTimezone();

        $sql = <<<SQL
            WITH RECURSIVE days AS (
                SELECT CAST(:from AS date) AS day
                UNION ALL
                SELECT (day + INTERVAL '1 day')::date FROM days WHERE day < CAST(:to AS date)
            ),
            instruments AS (
                SELECT DISTINCT instrument_id FROM investment_transaction WHERE account_id = :id AND deleted_at IS NULL
            ),
            quantity_changes AS (
                SELECT instrument_id, day, SUM(delta) AS delta FROM (
                    SELECT
                        instrument_id,
                        CAST(occurred_at AT TIME ZONE 'UTC' AT TIME ZONE :timezone AS date) AS day,
                        CASE WHEN type = 'buy' THEN quantity ELSE -quantity END AS delta
                    FROM investment_transaction
                    WHERE account_id = :id AND deleted_at IS NULL
                ) raw_changes
                GROUP BY instrument_id, day
            ),
            -- What was already held when the range starts: trades before it count too.
            opening_quantity AS (
                SELECT instrument_id, SUM(delta) AS quantity
                FROM quantity_changes
                WHERE day < CAST(:from AS date)
                GROUP BY instrument_id
            ),
            daily_quantity AS (
                SELECT
                    i.instrument_id,
                    d.day,
                    COALESCE(oq.quantity, 0) + COALESCE(SUM(qc.delta) OVER (
                        PARTITION BY i.instrument_id ORDER BY d.day
                        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                    ), 0) AS quantity
                FROM instruments i
                CROSS JOIN days d
                LEFT JOIN opening_quantity oq ON oq.instrument_id = i.instrument_id
                LEFT JOIN quantity_changes qc ON qc.instrument_id = i.instrument_id AND qc.day = d.day
            ),
            daily_price AS (
                SELECT
                    dq.instrument_id,
                    dq.day,
                    dq.quantity,
                    (
                        SELECT ip.price FROM investment_price ip
                        WHERE ip.instrument_id = dq.instrument_id AND ip.date <= dq.day
                        ORDER BY ip.date DESC
                        LIMIT 1
                    ) AS price
                FROM daily_quantity dq
            )
            SELECT
                day::text AS date,
                CAST(COALESCE(SUM(quantity * COALESCE(price, 0)), 0) AS numeric(19,4)) AS value
            FROM daily_price
            GROUP BY day
            ORDER BY day
            SQL;

        return $connection->executeQuery($sql, [
            'id' => $id,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'timezone' => $timezone,
        ])->fetchAllAssociative();
    }

    /**
     * @param array<array{type: string, quantity: string, amount: string, costs: string, day?: string}> $rows ordered oldest first
     * @return array{quantity: string, costBasis: string, openedOn: ?string}
     */
    private function fold(array $rows): array
    {
        $position = new AverageCostPosition();
        $openedOn = null;

        foreach ($rows as $row) {
            if ($row['type'] === InvestmentTransactionType::Buy->value && $position->isClosed()) {
                $openedOn = $row['day'] ?? null;
            }

            $position->apply($row['type'], $row['quantity'], $row['amount'], $row['costs']);
        }

        return ['quantity' => $position->quantity(), 'costBasis' => $position->costBasis(), 'openedOn' => $openedOn];
    }
}
