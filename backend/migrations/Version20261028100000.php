<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The colour scheme is no longer a preference: the app has one accent colour.
 */
final class Version20261028100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User: drop the colour scheme';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP color_scheme');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD color_scheme VARCHAR(10) DEFAULT \'blue\' NOT NULL');
    }
}
