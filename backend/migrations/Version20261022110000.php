<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the quote currency to instruments (existing ones: the euro, as they were treated)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE investment_instrument ADD currency VARCHAR(3) NOT NULL DEFAULT 'EUR'");
        $this->addSql('ALTER TABLE investment_instrument ALTER COLUMN currency DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_instrument DROP currency');
    }
}
