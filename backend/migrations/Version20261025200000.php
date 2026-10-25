<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Users an administrator turned off (disabled_at), and a password set by an
 * administrator to be changed at the next login (must_change_password).
 */
final class Version20261025200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User: disabled_at, must_change_password';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD disabled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD must_change_password BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP disabled_at');
        $this->addSql('ALTER TABLE "user" DROP must_change_password');
    }
}
