<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recurring transactions: their history, and planned changes of the amount.
 */
final class Version20261028214834 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recurring transactions: history and planned amount changes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE recurring_transaction_audit_log (id UUID NOT NULL, action VARCHAR(20) NOT NULL, changes JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, recurring_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8F8D39C0B149C95E8B8E8428 ON recurring_transaction_audit_log (recurring_id, created_at)');
        $this->addSql('CREATE INDEX IDX_8F8D39C0B149C95E ON recurring_transaction_audit_log (recurring_id)');
        $this->addSql('CREATE INDEX IDX_8F8D39C0A76ED395 ON recurring_transaction_audit_log (user_id)');
        $this->addSql('ALTER TABLE recurring_transaction_audit_log ADD CONSTRAINT FK_8F8D39C0B149C95E FOREIGN KEY (recurring_id) REFERENCES recurring_transaction (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recurring_transaction_audit_log ADD CONSTRAINT FK_8F8D39C0A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recurring_transaction ADD amount_changes JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_transaction_audit_log DROP CONSTRAINT FK_8F8D39C0B149C95E');
        $this->addSql('ALTER TABLE recurring_transaction_audit_log DROP CONSTRAINT FK_8F8D39C0A76ED395');
        $this->addSql('DROP TABLE recurring_transaction_audit_log');
        $this->addSql('ALTER TABLE recurring_transaction DROP amount_changes');
    }
}
