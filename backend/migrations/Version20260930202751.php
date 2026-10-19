<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260930202751 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Taxes withheld on an investment trade (capital gains on a SELL, Tobin tax on a BUY), separate from broker fees';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_transaction ADD taxes NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_transaction DROP taxes');
    }
}
