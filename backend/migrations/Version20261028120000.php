<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An asset account remembers the latest day its balance was checked against
 * the bank's, and that balance.
 */
final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account: latest reconciliation day and balance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD reconciled_on DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE account ADD reconciled_balance NUMERIC(19, 4) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP reconciled_on');
        $this->addSql('ALTER TABLE account DROP reconciled_balance');
    }
}
