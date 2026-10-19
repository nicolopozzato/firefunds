<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927160152 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add yahoo_ticker to investment_instrument';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_instrument ADD yahoo_ticker VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investment_instrument DROP yahoo_ticker');
    }
}
