<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Which asset accounts the dashboard shows (a user preference, every account by default).
 */
final class Version20261002204802 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account: show_on_dashboard (default true)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD show_on_dashboard BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP show_on_dashboard');
    }
}
