<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The search ignores accents ("caffe" finds "Caffè"): Postgres' `unaccent`.
 */
final class Version20261003180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'unaccent extension, for the search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP EXTENSION IF EXISTS unaccent');
    }
}
