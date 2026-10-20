<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Accounts get a history of their changes (App\Entity\AccountAuditLog), as
 * transactions have. Accounts already there start it empty.
 */
final class Version20261020152612 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account audit log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account_audit_log (id UUID NOT NULL, action VARCHAR(20) NOT NULL, changes JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, account_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D49B18AE9B6B5FBA8B8E8428 ON account_audit_log (account_id, created_at)');
        $this->addSql('CREATE INDEX IDX_D49B18AE9B6B5FBA ON account_audit_log (account_id)');
        $this->addSql('CREATE INDEX IDX_D49B18AEA76ED395 ON account_audit_log (user_id)');
        $this->addSql('ALTER TABLE account_audit_log ADD CONSTRAINT FK_D49B18AE9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE account_audit_log ADD CONSTRAINT FK_D49B18AEA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_audit_log DROP CONSTRAINT FK_D49B18AE9B6B5FBA');
        $this->addSql('ALTER TABLE account_audit_log DROP CONSTRAINT FK_D49B18AEA76ED395');
        $this->addSql('DROP TABLE account_audit_log');
    }
}
