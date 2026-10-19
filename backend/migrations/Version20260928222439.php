<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928222439 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add timezone preference to user (existing users: Europe/Rome)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"user\" ADD timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Rome'");
        $this->addSql('ALTER TABLE "user" ALTER COLUMN timezone DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP timezone');
    }
}
