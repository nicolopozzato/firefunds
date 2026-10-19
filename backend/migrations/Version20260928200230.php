<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928200230 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE transaction_audit_log (id UUID NOT NULL, action VARCHAR(20) NOT NULL, changes JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, transaction_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B5C65BB62FC0CB0F8B8E8428 ON transaction_audit_log (transaction_id, created_at)');
        $this->addSql('CREATE INDEX IDX_B5C65BB62FC0CB0F ON transaction_audit_log (transaction_id)');
        $this->addSql('CREATE INDEX IDX_B5C65BB6A76ED395 ON transaction_audit_log (user_id)');
        $this->addSql('ALTER TABLE transaction_audit_log ADD CONSTRAINT FK_B5C65BB62FC0CB0F FOREIGN KEY (transaction_id) REFERENCES transaction (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE transaction_audit_log ADD CONSTRAINT FK_B5C65BB6A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transaction_audit_log DROP CONSTRAINT FK_B5C65BB62FC0CB0F');
        $this->addSql('ALTER TABLE transaction_audit_log DROP CONSTRAINT FK_B5C65BB6A76ED395');
        $this->addSql('DROP TABLE transaction_audit_log');
    }
}
