<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exchange rates (shared, against the euro) and the user\'s own corrections';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE exchange_rate (id UUID NOT NULL, currency VARCHAR(3) NOT NULL, date DATE NOT NULL, rate NUMERIC(18, 8) NOT NULL, source VARCHAR(10) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_exchange_rate_day ON exchange_rate (currency, date)');
        $this->addSql('CREATE TABLE exchange_rate_override (id UUID NOT NULL, currency VARCHAR(3) NOT NULL, date DATE NOT NULL, rate NUMERIC(18, 8) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_exchange_rate_override_day_active ON exchange_rate_override (user_id, currency, date) WHERE (deleted_at IS NULL)');
        $this->addSql('CREATE INDEX IDX_EAA8CEE7A76ED395 ON exchange_rate_override (user_id)');
        $this->addSql('ALTER TABLE exchange_rate_override ADD CONSTRAINT FK_EAA8CEE7A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exchange_rate_override DROP CONSTRAINT FK_EAA8CEE7A76ED395');
        $this->addSql('DROP TABLE exchange_rate');
        $this->addSql('DROP TABLE exchange_rate_override');
    }
}
