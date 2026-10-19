<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928215604 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE investment_transaction (id UUID NOT NULL, type VARCHAR(10) NOT NULL, quantity NUMERIC(20, 6) NOT NULL, unit_price NUMERIC(19, 4) NOT NULL, fees NUMERIC(19, 4) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, account_id UUID NOT NULL, instrument_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F526DE0DA76ED395 ON investment_transaction (user_id)');
        $this->addSql('CREATE INDEX IDX_F526DE0D9B6B5FBACF11D9C ON investment_transaction (account_id, instrument_id)');
        $this->addSql('CREATE INDEX IDX_F526DE0D9B6B5FBA ON investment_transaction (account_id)');
        $this->addSql('CREATE INDEX IDX_F526DE0DCF11D9C ON investment_transaction (instrument_id)');
        $this->addSql('ALTER TABLE investment_transaction ADD CONSTRAINT FK_F526DE0DA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE investment_transaction ADD CONSTRAINT FK_F526DE0D9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE investment_transaction ADD CONSTRAINT FK_F526DE0DCF11D9C FOREIGN KEY (instrument_id) REFERENCES investment_instrument (id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE investment_transaction DROP CONSTRAINT FK_F526DE0DA76ED395');
        $this->addSql('ALTER TABLE investment_transaction DROP CONSTRAINT FK_F526DE0D9B6B5FBA');
        $this->addSql('ALTER TABLE investment_transaction DROP CONSTRAINT FK_F526DE0DCF11D9C');
        $this->addSql('DROP TABLE investment_transaction');
    }
}
