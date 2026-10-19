<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Refresh tokens name their user by id instead of email (User::getUserIdentifier),
 * so an address that changes hands no longer carries a session with it. The
 * tokens already issued are rewritten rather than dropped: nobody is signed out.
 */
final class Version20261020090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refresh tokens: username is the user id, not the email';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE refresh_tokens r SET username = u.id::text FROM "user" u WHERE r.username = u.email');
        // A token naming no current email can't be used anyway.
        $this->addSql("DELETE FROM refresh_tokens WHERE username !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE refresh_tokens r SET username = u.email FROM "user" u WHERE r.username = u.id::text');
    }
}
