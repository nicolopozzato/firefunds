<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930170434 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add number_format preference to user (existing users keep the Italian format)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"user\" ADD number_format VARCHAR(10) NOT NULL DEFAULT 'it-IT'");
        $this->addSql('ALTER TABLE "user" ALTER COLUMN number_format DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP number_format');
    }
}
