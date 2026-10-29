<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The phone's notifications: the apps they come from (sources, with their
 * account and reading rules) and each notification as it came.
 */
final class Version20261029000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification sources and bank notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bank_notification (id UUID NOT NULL, title TEXT NOT NULL, text TEXT NOT NULL, posted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, timezone VARCHAR(64) NOT NULL, device_key VARCHAR(255) NOT NULL, status VARCHAR(10) NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, source_id UUID NOT NULL, draft_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7FCC91D1953C1C61FBAAFE2A ON bank_notification (source_id, posted_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7FCC91D1A76ED395606E7130 ON bank_notification (user_id, device_key)');
        $this->addSql('CREATE INDEX IDX_7FCC91D1A76ED395 ON bank_notification (user_id)');
        $this->addSql('CREATE INDEX IDX_7FCC91D1953C1C61 ON bank_notification (source_id)');
        $this->addSql('CREATE INDEX IDX_7FCC91D1E2F3C5D1 ON bank_notification (draft_id)');
        $this->addSql('CREATE TABLE notification_source (id UUID NOT NULL, package_name VARCHAR(255) NOT NULL, app_name VARCHAR(120) NOT NULL, rules JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, account_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9B2D812EA76ED395E56E1BCE ON notification_source (user_id, package_name)');
        $this->addSql('CREATE INDEX IDX_9B2D812EA76ED395 ON notification_source (user_id)');
        $this->addSql('CREATE INDEX IDX_9B2D812E9B6B5FBA ON notification_source (account_id)');
        $this->addSql('ALTER TABLE bank_notification ADD CONSTRAINT FK_7FCC91D1A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bank_notification ADD CONSTRAINT FK_7FCC91D1953C1C61 FOREIGN KEY (source_id) REFERENCES notification_source (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bank_notification ADD CONSTRAINT FK_7FCC91D1E2F3C5D1 FOREIGN KEY (draft_id) REFERENCES draft (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification_source ADD CONSTRAINT FK_9B2D812EA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification_source ADD CONSTRAINT FK_9B2D812E9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_notification DROP CONSTRAINT FK_7FCC91D1A76ED395');
        $this->addSql('ALTER TABLE bank_notification DROP CONSTRAINT FK_7FCC91D1953C1C61');
        $this->addSql('ALTER TABLE bank_notification DROP CONSTRAINT FK_7FCC91D1E2F3C5D1');
        $this->addSql('ALTER TABLE notification_source DROP CONSTRAINT FK_9B2D812EA76ED395');
        $this->addSql('ALTER TABLE notification_source DROP CONSTRAINT FK_9B2D812E9B6B5FBA');
        $this->addSql('DROP TABLE bank_notification');
        $this->addSql('DROP TABLE notification_source');
    }
}
