<?php

namespace App\Repository;

use App\Core\Pagination\PaginationParams;
use App\Core\Pagination\Paginator;
use App\Entity\Account;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentTransaction;
use App\Entity\User;
use App\Investment\Position\AverageCostPosition;
use App\Investment\Position\TradeCashEffect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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
     * for the same period as the list, in the user's currency. Biggest first.
     *
     * @param 'instrumentType'|'instrument'|'account' $groupBy
     * @return array<array{id: string, name: string, total: string}>
     */
    public function sumTotalsBy(string $groupBy, User $user, ?\DateTimeInterface $from, ?\DateTimeInterface $to): array
    {
        [$idColumn, $nameColumn] = self::TOTAL_GROUPS[$groupBy];

        $dateFilter = '';
        // Each trade's total at its day's rate, in the user's currency (accounts can be in different ones).
        $params = ['userId' => $user->getId()->toRfc4122(), 'currency' => $user->getCurrency()];
        if ($from !== null) {
            $dateFilter .= ' AND it.occurred_at >= :from';
            $params['from'] = $from->format('Y-m-d H:i:s.u');
        }
        if ($to !== null) {
            $dateFilter .= ' AND it.occurred_at <= :to';
            $params['to'] = $to->format('Y-m-d H:i:s.u');
        }

        $total = TradeCashEffect::totalSql('it');
        $sql = <<<SQL
            SELECT
                CAST({$idColumn} AS text) AS id,
                {$nameColumn} AS name,
                CAST(SUM(
                    app_convert({$total}, ac.code, :currency, CAST(it.occurred_at AS date), :userId)
                ) AS numeric(19,4)) AS total
            FROM investment_transaction it
            JOIN investment_instrument i ON i.id = it.instrument_id
            JOIN account a ON a.id = it.account_id
            LEFT JOIN currency ac ON ac.id = a.currency_id
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
        // Not a UUID is not anything of theirs (and would fail the conversion).
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['user' => $user, 'id' => $id]);
    }

    /**
     * Every open position (instrument still held) on an account: quantity, cost
     * basis and average cost, folded from the ordered BUY/SELL history using the
     * running average-cost method (see AverageCostPosition). `openedOn` is the
     * day (as written where it happened) the current holding was opened, i.e. the trade
     * that last took the quantity away from zero (or across it).
     *
     * @return array<array{instrumentId: string, instrumentName: string, instrumentTicker: ?string, quantity: string, costBasis: string, averageCost: string, openedOn: ?string, taxRate: ?string, quoteCurrency: ?string, accountCurrency: ?string}>
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

        // The clock time where it happened: its date is the day of the trade.
        return $first !== null ? new \DateTimeImmutable($first) : null;
    }

    /**
     * Every (non-deleted) BUY/SELL on this account, oldest first per
     * instrument, with the day it happened (as written where it happened).
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
     * @return array<array{account_id: string, instrument_id: string, instrument_name: string, instrument_ticker: ?string, instrument_currency: string, account_currency: ?string, type: string, quantity: string, amount: string, costs: string, day: string}>
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
                       i.currency AS instrument_currency, ac.code AS account_currency,
                       it.type, it.quantity, it.amount, it.fees + it.taxes AS costs,
                       CAST(it.occurred_at AS date) AS day
                FROM investment_transaction it
                JOIN investment_instrument i ON i.id = it.instrument_id
                JOIN account a ON a.id = it.account_id
                LEFT JOIN currency ac ON ac.id = a.currency_id
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
            $byInstrument[$row['instrument_id']]['quoteCurrency'] ??= $row['instrument_currency'] ?? null;
            $byInstrument[$row['instrument_id']]['accountCurrency'] ??= $row['account_currency'] ?? null;
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
                // What the instrument is quoted in, and the account's own currency (its amounts, cost included, are in it).
                'quoteCurrency' => $data['quoteCurrency'] ?? null,
                'accountCurrency' => $data['accountCurrency'] ?? null,
            ];
        }

        return $positions;
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
            $before = bccomp($position->quantity(), '0', 10);
            $position->apply($row['type'], $row['quantity'], $row['amount'], $row['costs']);
            $after = bccomp($position->quantity(), '0', 10);

            // The position as it stands began on the trade that opened it, or turned it to the other side.
            if ($after === 0) {
                $openedOn = null;
            } elseif ($after !== $before) {
                $openedOn = $row['day'] ?? null;
            }
        }

        return ['quantity' => $position->quantity(), 'costBasis' => $position->costBasis(), 'openedOn' => $openedOn];
    }
}
