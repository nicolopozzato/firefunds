<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Push notifications: the users' devices, their choices, and which
 * notifications were pushed already.
 */
final class Version20261028223422 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Push notifications: devices, preferences, pushed notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE push_subscription (id UUID NOT NULL, endpoint TEXT NOT NULL, public_key VARCHAR(255) NOT NULL, auth_token VARCHAR(255) NOT NULL, label VARCHAR(120) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_562830F3C4420F7B ON push_subscription (endpoint)');
        $this->addSql('CREATE INDEX IDX_562830F3A76ED395 ON push_subscription (user_id)');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_562830F3A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD pushed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD push_reminders BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD push_system BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD push_summary_time VARCHAR(5) DEFAULT \'09:00\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD push_summary_sent_on DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE push_subscription DROP CONSTRAINT FK_562830F3A76ED395');
        $this->addSql('DROP TABLE push_subscription');
        $this->addSql('ALTER TABLE notification DROP pushed_at');
        $this->addSql('ALTER TABLE "user" DROP push_reminders');
        $this->addSql('ALTER TABLE "user" DROP push_system');
        $this->addSql('ALTER TABLE "user" DROP push_summary_time');
        $this->addSql('ALTER TABLE "user" DROP push_summary_sent_on');
    }
}
