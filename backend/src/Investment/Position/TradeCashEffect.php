<?php

namespace App\Investment\Position;

/**
 * What a trade does to its broker account's cash, written once for the SQL
 * that sums it and the PHP that does the same sum over trade rows: a BUY pays
 * its amount plus fees and taxes, a SELL receives its amount minus them. Every
 * balance, history, total and "money put in" figure goes through here, so they
 * can't drift apart.
 */
final class TradeCashEffect
{
    /**
     * SQL: the trade's total as a positive number (what a list shows).
     * Columns amount, fees, taxes and type of the investment_transaction
     * table aliased $alias.
     */
    public static function totalSql(string $alias = 'it'): string
    {
        return "CASE WHEN {$alias}.type = 'buy' THEN {$alias}.amount + {$alias}.fees + {$alias}.taxes ELSE {$alias}.amount - {$alias}.fees - {$alias}.taxes END";
    }

    /** SQL: the change to the account's cash, negative for a BUY. */
    public static function signedSql(string $alias = 'it'): string
    {
        return 'CASE WHEN '.$alias.".type = 'buy' THEN -(".self::totalSql($alias).') ELSE '.self::totalSql($alias).' END';
    }

    /**
     * PHP: the trade's total as a positive number.
     *
     * @param string $type 'buy' or 'sell'
     * @param string $costs fees plus taxes
     */
    public static function total(string $type, string $amount, string $costs): string
    {
        return $type === 'buy' ? bcadd($amount, $costs, 4) : bcsub($amount, $costs, 4);
    }

    /** PHP: the change to the account's cash, negative for a BUY. */
    public static function signed(string $type, string $amount, string $costs): string
    {
        $total = self::total($type, $amount, $costs);

        return $type === 'buy' ? bcsub('0', $total, 4) : $total;
    }

    /** PHP: the money a trade put into the instrument, negative for a SELL (what came back). */
    public static function contribution(string $type, string $amount, string $costs): string
    {
        return bcsub('0', self::signed($type, $amount, $costs), 4);
    }
}
