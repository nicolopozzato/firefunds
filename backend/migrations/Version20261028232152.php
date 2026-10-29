<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Personal API tokens, drafts (what comes from outside, to review), and
 * whether new drafts are pushed.
 */
final class Version20261028232152 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'API tokens, drafts, draft push preference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_token (id UUID NOT NULL, name VARCHAR(100) NOT NULL, scope VARCHAR(20) NOT NULL, token_hash VARCHAR(64) NOT NULL, hint VARCHAR(12) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7BA2F5EBB3BC57DA ON api_token (token_hash)');
        $this->addSql('CREATE INDEX IDX_7BA2F5EBA76ED395 ON api_token (user_id)');
        $this->addSql('CREATE TABLE draft (id UUID NOT NULL, source VARCHAR(20) NOT NULL, source_name VARCHAR(120) DEFAULT NULL, description TEXT DEFAULT NULL, amount NUMERIC(19, 4) NOT NULL, direction VARCHAR(3) DEFAULT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, timezone VARCHAR(64) NOT NULL, notes TEXT DEFAULT NULL, raw_text TEXT DEFAULT NULL, status VARCHAR(12) NOT NULL, transaction_ids JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, source_account_id UUID DEFAULT NULL, destination_account_id UUID DEFAULT NULL, possible_duplicate_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_467C9694A76ED3957B00651C ON draft (user_id, status)');
        $this->addSql('CREATE INDEX IDX_467C9694A76ED395 ON draft (user_id)');
        $this->addSql('CREATE INDEX IDX_467C9694E7DF2E9E ON draft (source_account_id)');
        $this->addSql('CREATE INDEX IDX_467C9694C652C408 ON draft (destination_account_id)');
        $this->addSql('CREATE INDEX IDX_467C9694881EF712 ON draft (possible_duplicate_id)');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT FK_7BA2F5EBA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE draft ADD CONSTRAINT FK_467C9694A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE draft ADD CONSTRAINT FK_467C9694E7DF2E9E FOREIGN KEY (source_account_id) REFERENCES account (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE draft ADD CONSTRAINT FK_467C9694C652C408 FOREIGN KEY (destination_account_id) REFERENCES account (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE draft ADD CONSTRAINT FK_467C9694881EF712 FOREIGN KEY (possible_duplicate_id) REFERENCES transaction (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE "user" ADD push_drafts BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token DROP CONSTRAINT FK_7BA2F5EBA76ED395');
        $this->addSql('ALTER TABLE draft DROP CONSTRAINT FK_467C9694A76ED395');
        $this->addSql('ALTER TABLE draft DROP CONSTRAINT FK_467C9694E7DF2E9E');
        $this->addSql('ALTER TABLE draft DROP CONSTRAINT FK_467C9694C652C408');
        $this->addSql('ALTER TABLE draft DROP CONSTRAINT FK_467C9694881EF712');
        $this->addSql('DROP TABLE api_token');
        $this->addSql('DROP TABLE draft');
        $this->addSql('ALTER TABLE "user" DROP push_drafts');
    }
}
