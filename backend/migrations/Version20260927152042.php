<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927152042 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add investment_instrument table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE investment_instrument (id UUID NOT NULL, name VARCHAR(255) NOT NULL, ticker VARCHAR(20) DEFAULT NULL, isin VARCHAR(12) DEFAULT NULL, type VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_25C7674CA76ED395 ON investment_instrument (user_id)');
        $this->addSql('ALTER TABLE investment_instrument ADD CONSTRAINT FK_25C7674CA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_instrument DROP CONSTRAINT FK_25C7674CA76ED395');
        $this->addSql('DROP TABLE investment_instrument');
    }
}
