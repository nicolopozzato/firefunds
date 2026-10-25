<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the preferred currency to user (existing users get the euro)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"user\" ADD currency VARCHAR(3) NOT NULL DEFAULT 'EUR'");
        $this->addSql('ALTER TABLE "user" ALTER COLUMN currency DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP currency');
    }
}
