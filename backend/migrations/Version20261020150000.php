<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Other" changes to an asset account's balance on a day, besides its opening
 * balance (App\Entity\AccountAdjustment): neither income nor expenses.
 *
 * The opening balances an account deletion carried over until now, as a
 * deposit from nobody (or a withdrawal to nobody), become adjustments of the
 * account they landed on, and those transactions are marked deleted (with an
 * audit log entry). They are recognised by what the deletion wrote: one side
 * empty, named after a deleted account of the same user, for the amount of
 * its opening balance, on its opening balance date if it had one.
 */
final class Version20261020150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account adjustments: other changes to an account balance; carried-over opening balances become adjustments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account_adjustment (id UUID NOT NULL, date DATE NOT NULL, amount NUMERIC(19, 4) NOT NULL, description VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, account_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_75628AB29B6B5FBA ON account_adjustment (account_id)');
        $this->addSql('ALTER TABLE account_adjustment ADD CONSTRAINT FK_75628AB29B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE carried_opening_balance AS
            SELECT DISTINCT ON (t.id)
                t.id,
                t.user_id,
                COALESCE(t.destination_account_id, t.source_account_id) AS account_id,
                CAST(t.occurred_at AS date) AS day,
                CASE WHEN t.destination_account_id IS NULL THEN -t.amount ELSE t.amount END AS amount,
                t.description
            FROM transaction t
            JOIN account a ON a.user_id = t.user_id AND a.deleted_at IS NOT NULL AND a.name = t.description
            WHERE t.deleted_at IS NULL
                AND (t.source_account_id IS NULL) <> (t.destination_account_id IS NULL)
                AND t.amount = abs(a.opening_balance)
                AND (a.opening_balance_date IS NULL OR CAST(t.occurred_at AS date) = a.opening_balance_date)
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO account_adjustment (id, account_id, date, amount, description, created_at, updated_at)
            SELECT gen_random_uuid(), account_id, day, amount, left(description, 255), now() AT TIME ZONE 'UTC', now() AT TIME ZONE 'UTC'
            FROM carried_opening_balance
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO transaction_audit_log (id, action, changes, created_at, transaction_id, user_id)
            SELECT gen_random_uuid(), 'deleted', NULL, now() AT TIME ZONE 'UTC', id, user_id
            FROM carried_opening_balance
            SQL);
        $this->addSql("UPDATE transaction SET deleted_at = now() AT TIME ZONE 'UTC' WHERE id IN (SELECT id FROM carried_opening_balance)");
        $this->addSql('DROP TABLE carried_opening_balance');
    }

    public function down(Schema $schema): void
    {
        // The carried-over opening balances stay deleted transactions: the adjustments go.
        $this->addSql('ALTER TABLE account_adjustment DROP CONSTRAINT FK_75628AB29B6B5FBA');
        $this->addSql('DROP TABLE account_adjustment');
    }
}
