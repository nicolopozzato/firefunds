<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928223220 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create investment_transaction_audit_log table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE investment_transaction_audit_log (id UUID NOT NULL, action VARCHAR(20) NOT NULL, changes JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, investment_transaction_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9B190E5CA32F3BFA8B8E8428 ON investment_transaction_audit_log (investment_transaction_id, created_at)');
        $this->addSql('CREATE INDEX IDX_9B190E5CA32F3BFA ON investment_transaction_audit_log (investment_transaction_id)');
        $this->addSql('CREATE INDEX IDX_9B190E5CA76ED395 ON investment_transaction_audit_log (user_id)');
        $this->addSql('ALTER TABLE investment_transaction_audit_log ADD CONSTRAINT FK_9B190E5CA32F3BFA FOREIGN KEY (investment_transaction_id) REFERENCES investment_transaction (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE investment_transaction_audit_log ADD CONSTRAINT FK_9B190E5CA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_transaction_audit_log DROP CONSTRAINT FK_9B190E5CA32F3BFA');
        $this->addSql('ALTER TABLE investment_transaction_audit_log DROP CONSTRAINT FK_9B190E5CA76ED395');
        $this->addSql('DROP TABLE investment_transaction_audit_log');
    }
}
