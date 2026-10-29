<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Import presets: how a bank's CSV file reads, saved per user.
 */
final class Version20261028233725 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import presets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE import_preset (id UUID NOT NULL, name VARCHAR(100) NOT NULL, settings JSON NOT NULL, headers JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, account_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_506E5E2AA76ED395 ON import_preset (user_id)');
        $this->addSql('CREATE INDEX IDX_506E5E2A9B6B5FBA ON import_preset (account_id)');
        $this->addSql('ALTER TABLE import_preset ADD CONSTRAINT FK_506E5E2AA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE import_preset ADD CONSTRAINT FK_506E5E2A9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_preset DROP CONSTRAINT FK_506E5E2AA76ED395');
        $this->addSql('ALTER TABLE import_preset DROP CONSTRAINT FK_506E5E2A9B6B5FBA');
        $this->addSql('DROP TABLE import_preset');
    }
}
