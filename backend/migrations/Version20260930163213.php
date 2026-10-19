<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930163213 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Soft delete for money and investment transactions (deleted_at)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_transaction ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_transaction DROP deleted_at');
        $this->addSql('ALTER TABLE transaction DROP deleted_at');
    }
}
