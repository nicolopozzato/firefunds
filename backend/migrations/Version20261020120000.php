<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Transactions keep the date and time as written where they happened, plus
 * that place's timezone (App\Entity\OccursLocally), instead of a UTC instant
 * converted back on every read. The UTC values become clock times in their
 * owner's timezone, so each transaction stays on the day it showed before.
 */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Transactions: occurred_at as local clock time, plus a timezone column';
    }

    public function up(Schema $schema): void
    {
        foreach (['transaction', 'investment_transaction'] as $table) {
            $this->addSql("ALTER TABLE {$table} ADD timezone VARCHAR(64) DEFAULT NULL");
            $this->addSql(<<<SQL
                UPDATE {$table} t
                SET occurred_at = (t.occurred_at AT TIME ZONE 'UTC') AT TIME ZONE u.timezone,
                    timezone = u.timezone
                FROM "user" u
                WHERE u.id = t.user_id
                SQL);
            $this->addSql("ALTER TABLE {$table} ALTER timezone SET NOT NULL");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['transaction', 'investment_transaction'] as $table) {
            $this->addSql("UPDATE {$table} SET occurred_at = (occurred_at AT TIME ZONE timezone) AT TIME ZONE 'UTC'");
            $this->addSql("ALTER TABLE {$table} DROP timezone");
        }
    }
}
