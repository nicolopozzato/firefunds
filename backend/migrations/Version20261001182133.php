<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Capital gains tax rate used to estimate the tax owed on unrealized gains.
 */
final class Version20261001182133 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Capital gains tax rate: per user (default 26%) and per instrument (optional)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_instrument ADD capital_gains_tax_rate NUMERIC(7, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD capital_gains_tax_rate NUMERIC(7, 4) DEFAULT \'26.0000\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_instrument DROP capital_gains_tax_rate');
        $this->addSql('ALTER TABLE "user" DROP capital_gains_tax_rate');
    }
}
