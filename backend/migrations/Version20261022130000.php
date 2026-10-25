<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Transfers between currencies: the amount that arrived on the destination account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction ADD destination_amount NUMERIC(19, 4) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction DROP destination_amount');
    }
}
