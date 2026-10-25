<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * When each user last used the app (App\Auth\EventListener\LastSeenListener).
 */
final class Version20261025210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User: last_seen_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP last_seen_at');
    }
}
