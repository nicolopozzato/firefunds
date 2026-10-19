<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927154148 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add investment_price table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE investment_price (id UUID NOT NULL, date DATE NOT NULL, price NUMERIC(19, 4) NOT NULL, original_price NUMERIC(19, 4) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, instrument_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A50B142DCF11D9CAA9E377A ON investment_price (instrument_id, date)');
        $this->addSql('CREATE INDEX IDX_A50B142DCF11D9C ON investment_price (instrument_id)');
        $this->addSql('ALTER TABLE investment_price ADD CONSTRAINT FK_A50B142DCF11D9C FOREIGN KEY (instrument_id) REFERENCES investment_instrument (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_price DROP CONSTRAINT FK_A50B142DCF11D9C');
        $this->addSql('DROP TABLE investment_price');
    }
}
