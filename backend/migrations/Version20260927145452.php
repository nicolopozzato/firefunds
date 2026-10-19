<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927145452 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add asset_kind to account, backfilling existing asset accounts as checking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD asset_kind VARCHAR(20) DEFAULT NULL');
        $this->addSql("UPDATE account SET asset_kind = 'checking' WHERE type = 'asset'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP asset_kind');
    }
}
