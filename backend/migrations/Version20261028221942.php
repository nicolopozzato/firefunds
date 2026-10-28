<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications (the bell), and how many days a recurring transaction the
 * user enters waits before reminding them.
 */
final class Version20261028221942 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications; recurring reminder days';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification (id UUID NOT NULL, type VARCHAR(40) NOT NULL, dedupe_key VARCHAR(120) NOT NULL, params JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, dismissed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_BF5476CAA76ED3952770E87 ON notification (user_id, resolved_at)');
        $this->addSql('CREATE INDEX IDX_BF5476CAA76ED395 ON notification (user_id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recurring_transaction ADD reminder_days INT DEFAULT 3 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAA76ED395');
        $this->addSql('DROP TABLE notification');
        $this->addSql('ALTER TABLE recurring_transaction DROP reminder_days');
    }
}
