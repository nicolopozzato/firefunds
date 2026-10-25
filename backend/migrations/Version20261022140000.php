<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The conversion of an amount between currencies, in the database, so the sums
 * that add up amounts of different currencies (dashboard, reports, totals)
 * convert each one at its own day's rate in the same query. The same rule as
 * RateTable (a day takes the user's own rate if they entered one, else the
 * shared rate of the day, else the latest before it, else the first; the euro
 * is 1): a test keeps the two equal. With no rate for either currency the
 * amount stays as it is.
 */
final class Version20261022140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SQL functions app_per_euro and app_convert for the rates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE FUNCTION app_per_euro(p_user uuid, p_currency varchar, p_day date) RETURNS numeric
            LANGUAGE sql STABLE AS $$
                SELECT CASE WHEN p_currency = 'EUR' THEN 1 ELSE COALESCE(
                    (SELECT o.rate FROM exchange_rate_override o
                        WHERE o.user_id = p_user AND o.currency = p_currency AND o.date = p_day AND o.deleted_at IS NULL),
                    (SELECT r.rate FROM exchange_rate r WHERE r.currency = p_currency AND r.date <= p_day ORDER BY r.date DESC LIMIT 1),
                    (SELECT r.rate FROM exchange_rate r WHERE r.currency = p_currency ORDER BY r.date ASC LIMIT 1)
                ) END
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION app_convert(p_amount numeric, p_from varchar, p_to varchar, p_day date, p_user uuid) RETURNS numeric
            LANGUAGE sql STABLE AS $$
                SELECT CASE WHEN p_from = p_to THEN p_amount ELSE COALESCE(
                    p_amount * app_per_euro(p_user, p_to, p_day) / NULLIF(app_per_euro(p_user, p_from, p_day), 0),
                    p_amount
                ) END
            $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP FUNCTION app_convert(numeric, varchar, varchar, date, uuid)');
        $this->addSql('DROP FUNCTION app_per_euro(uuid, varchar, date)');
    }
}
