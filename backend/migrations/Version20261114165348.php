<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recurring purchases (PAC): a recurring transaction with an instrument, how
 * it buys and its fee; each trade keeps a link to the PAC it came from.
 */
final class Version20261114165348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recurring purchases (PAC)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_transaction ADD recurring_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE investment_transaction ADD CONSTRAINT FK_F526DE0DB149C95E FOREIGN KEY (recurring_id) REFERENCES recurring_transaction (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_F526DE0DB149C95E ON investment_transaction (recurring_id)');
        $this->addSql('ALTER TABLE recurring_transaction ADD plan_mode VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE recurring_transaction ADD quantity NUMERIC(20, 6) DEFAULT NULL');
        $this->addSql('ALTER TABLE recurring_transaction ADD fees NUMERIC(19, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE recurring_transaction ADD instrument_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE recurring_transaction ADD CONSTRAINT FK_D3509AA6CF11D9C FOREIGN KEY (instrument_id) REFERENCES investment_instrument (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_D3509AA6CF11D9C ON recurring_transaction (instrument_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_transaction DROP CONSTRAINT FK_F526DE0DB149C95E');
        $this->addSql('DROP INDEX IDX_F526DE0DB149C95E');
        $this->addSql('ALTER TABLE investment_transaction DROP recurring_id');
        $this->addSql('ALTER TABLE recurring_transaction DROP CONSTRAINT FK_D3509AA6CF11D9C');
        $this->addSql('DROP INDEX IDX_D3509AA6CF11D9C');
        $this->addSql('ALTER TABLE recurring_transaction DROP plan_mode');
        $this->addSql('ALTER TABLE recurring_transaction DROP quantity');
        $this->addSql('ALTER TABLE recurring_transaction DROP fees');
        $this->addSql('ALTER TABLE recurring_transaction DROP instrument_id');
    }
}
