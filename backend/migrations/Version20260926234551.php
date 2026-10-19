<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260926234551 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add locale, theme, and pageSize preferences to user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"user\" ADD locale VARCHAR(5) NOT NULL DEFAULT 'en'");
        $this->addSql("ALTER TABLE \"user\" ADD theme VARCHAR(10) NOT NULL DEFAULT 'system'");
        $this->addSql('ALTER TABLE "user" ADD page_size INT NOT NULL DEFAULT 50');
        $this->addSql('ALTER TABLE "user" ALTER COLUMN locale DROP DEFAULT');
        $this->addSql('ALTER TABLE "user" ALTER COLUMN theme DROP DEFAULT');
        $this->addSql('ALTER TABLE "user" ALTER COLUMN page_size DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" DROP locale');
        $this->addSql('ALTER TABLE "user" DROP theme');
        $this->addSql('ALTER TABLE "user" DROP page_size');
    }
}
