<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The order the user drags the accounts into (null until the first reorder).
 */
final class Version20261003102335 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account: position (nullable)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD position INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP position');
    }
}
