<?php

namespace App\Transaction;

use App\Entity\AccountType;
use Doctrine\ORM\QueryBuilder;

/**
 * What kind a money transaction is, decided by its two sides, written once for
 * every query that classifies them (lists, totals, the reports' flows):
 *
 * - withdrawal: from an asset account to an expense account or to nobody;
 * - deposit: from a revenue account or from nobody to an asset account;
 * - transfer: between two asset accounts.
 *
 * The reports further split transfers by whether they move money into or out
 * of an investment account (`invested`, `divested`). The frontend has the same
 * rule in `lib/transaction-sides.ts`.
 */
final class TransactionKind
{
    public const WITHDRAWAL = 'withdrawal';
    public const DEPOSIT = 'deposit';
    public const TRANSFER = 'transfer';

    /**
     * SQL condition for a kind, over the transaction and its two sides
     * (LEFT JOINed: either may be missing).
     */
    public static function sql(string $kind, string $t = 't', string $src = 'src', string $dst = 'dst'): string
    {
        return match ($kind) {
            self::WITHDRAWAL => "{$src}.type = 'asset' AND ({$dst}.type = 'expense' OR {$t}.destination_account_id IS NULL)",
            self::DEPOSIT => "({$src}.type = 'revenue' OR {$t}.source_account_id IS NULL) AND {$dst}.type = 'asset'",
            self::TRANSFER => "{$src}.type = 'asset' AND {$dst}.type = 'asset'",
            default => throw new \InvalidArgumentException('Invalid kind filter.'),
        };
    }

    /** SQL expression: 'withdrawal', 'deposit' or 'transfer' (null for a pair that is none of them). */
    public static function sqlCase(string $t = 't', string $src = 'src', string $dst = 'dst'): string
    {
        return 'CASE WHEN '.self::sql(self::TRANSFER, $t, $src, $dst)." THEN 'transfer'"
            .' WHEN '.self::sql(self::WITHDRAWAL, $t, $src, $dst)." THEN 'withdrawal'"
            .' WHEN '.self::sql(self::DEPOSIT, $t, $src, $dst)." THEN 'deposit' END";
    }

    /**
     * SQL expression for the reports' flows: 'expense', 'income', 'invested'
     * (a transfer from a non-investment asset account to an investment one),
     * 'divested' (and back), null for any other transfer.
     */
    public static function sqlFlowCase(string $t = 't', string $src = 'src', string $dst = 'dst'): string
    {
        $transfer = self::sql(self::TRANSFER, $t, $src, $dst);

        return 'CASE'
            .' WHEN '.self::sql(self::WITHDRAWAL, $t, $src, $dst)." THEN 'expense'"
            .' WHEN '.self::sql(self::DEPOSIT, $t, $src, $dst)." THEN 'income'"
            ." WHEN {$transfer} AND {$src}.asset_kind IS DISTINCT FROM 'investment' AND {$dst}.asset_kind = 'investment' THEN 'invested'"
            ." WHEN {$transfer} AND {$src}.asset_kind = 'investment' AND {$dst}.asset_kind IS DISTINCT FROM 'investment' THEN 'divested'"
            .' END';
    }

    /**
     * DQL: keeps only that kind (or everything for 'all') on a query over
     * `t` with `src` and `dst` left-joined.
     */
    public static function applyDql(QueryBuilder $qb, string $kind): void
    {
        match ($kind) {
            // 'cash': every money transaction, whatever its kind (the ORM list only has those).
            'all', 'cash' => null,
            // An external destination is a withdrawal, an external source a deposit.
            self::WITHDRAWAL => $qb->andWhere('src.type = :kindSourceType')
                ->andWhere('dst.type = :kindDestinationType OR t.destinationAccount IS NULL')
                ->setParameter('kindSourceType', AccountType::Asset)
                ->setParameter('kindDestinationType', AccountType::Expense),
            self::DEPOSIT => $qb->andWhere('src.type = :kindSourceType OR t.sourceAccount IS NULL')
                ->andWhere('dst.type = :kindDestinationType')
                ->setParameter('kindSourceType', AccountType::Revenue)
                ->setParameter('kindDestinationType', AccountType::Asset),
            self::TRANSFER => $qb->andWhere('src.type = :kindSourceType')
                ->andWhere('dst.type = :kindDestinationType')
                ->setParameter('kindSourceType', AccountType::Asset)
                ->setParameter('kindDestinationType', AccountType::Asset),
            default => throw new \InvalidArgumentException('Invalid kind filter.'),
        };
    }
}
