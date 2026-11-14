<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A recurring transaction entered by hand can expect the average of its
 * latest occurrences (a bill) instead of a fixed amount.
 */
final class Version20261114190033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recurring amount estimated from the latest occurrences';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_transaction ADD estimate_count SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_transaction DROP estimate_count');
    }
}
