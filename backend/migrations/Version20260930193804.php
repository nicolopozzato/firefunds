<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260930193804 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Soft delete for accounts, instruments, categories, tags and currencies; currency code unique among active ones only';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE category ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('DROP INDEX uniq_6956883f77153098');
        $this->addSql('ALTER TABLE currency ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_currency_code_active ON currency (code) WHERE (deleted_at IS NULL)');
        $this->addSql('ALTER TABLE investment_instrument ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE tag ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP deleted_at');
        $this->addSql('ALTER TABLE category DROP deleted_at');
        $this->addSql('DROP INDEX uniq_currency_code_active');
        $this->addSql('ALTER TABLE currency DROP deleted_at');
        $this->addSql('CREATE UNIQUE INDEX uniq_6956883f77153098 ON currency (code)');
        $this->addSql('ALTER TABLE investment_instrument DROP deleted_at');
        $this->addSql('ALTER TABLE tag DROP deleted_at');
    }
}
