<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Investment transactions get their own amount (what the broker charged for
 * the units), instead of always deriving it from quantity x unit price.
 */
final class Version20260930221732 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add investment_transaction.amount, filled with quantity x unit price for existing rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_transaction ADD amount NUMERIC(19, 4) DEFAULT NULL');
        // Soft-deleted rows too: they keep what they were worth if ever restored.
        $this->addSql('UPDATE investment_transaction SET amount = ROUND(quantity * unit_price, 4)');
        $this->addSql('ALTER TABLE investment_transaction ALTER amount SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_transaction DROP amount');
    }
}
