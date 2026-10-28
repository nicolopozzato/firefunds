<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recurring transactions (a template, when it falls, what happens then), and
 * the link from each transaction to the recurrence that created it.
 */
final class Version20261028210314 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recurring transactions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE recurring_transaction (id UUID NOT NULL, description TEXT NOT NULL, amount NUMERIC(19, 4) NOT NULL, destination_amount NUMERIC(19, 4) DEFAULT NULL, notes TEXT DEFAULT NULL, start_date DATE NOT NULL, unit VARCHAR(10) NOT NULL, every_n INT DEFAULT 1 NOT NULL, month_day VARCHAR(20) DEFAULT \'day\' NOT NULL, weekend_shift VARCHAR(10) DEFAULT \'none\' NOT NULL, months JSON DEFAULT NULL, end_date DATE DEFAULT NULL, occurrence_count INT DEFAULT NULL, time VARCHAR(5) NOT NULL, timezone VARCHAR(64) NOT NULL, mode VARCHAR(10) DEFAULT \'auto\' NOT NULL, paused_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, handled_through DATE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, source_account_id UUID DEFAULT NULL, destination_account_id UUID DEFAULT NULL, category_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D3509AA6A76ED395 ON recurring_transaction (user_id)');
        $this->addSql('CREATE INDEX IDX_D3509AA6E7DF2E9E ON recurring_transaction (source_account_id)');
        $this->addSql('CREATE INDEX IDX_D3509AA6C652C408 ON recurring_transaction (destination_account_id)');
        $this->addSql('CREATE INDEX IDX_D3509AA612469DE2 ON recurring_transaction (category_id)');
        $this->addSql('CREATE TABLE recurring_transaction_tag (recurring_transaction_id UUID NOT NULL, tag_id UUID NOT NULL, PRIMARY KEY (recurring_transaction_id, tag_id))');
        $this->addSql('CREATE INDEX IDX_73070BBC5B71D755 ON recurring_transaction_tag (recurring_transaction_id)');
        $this->addSql('CREATE INDEX IDX_73070BBCBAD26311 ON recurring_transaction_tag (tag_id)');
        $this->addSql('ALTER TABLE recurring_transaction ADD CONSTRAINT FK_D3509AA6A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recurring_transaction ADD CONSTRAINT FK_D3509AA6E7DF2E9E FOREIGN KEY (source_account_id) REFERENCES account (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recurring_transaction ADD CONSTRAINT FK_D3509AA6C652C408 FOREIGN KEY (destination_account_id) REFERENCES account (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recurring_transaction ADD CONSTRAINT FK_D3509AA612469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recurring_transaction_tag ADD CONSTRAINT FK_73070BBC5B71D755 FOREIGN KEY (recurring_transaction_id) REFERENCES recurring_transaction (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recurring_transaction_tag ADD CONSTRAINT FK_73070BBCBAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transaction ADD recurring_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D1B149C95E FOREIGN KEY (recurring_id) REFERENCES recurring_transaction (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_723705D1B149C95E ON transaction (recurring_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_transaction DROP CONSTRAINT FK_D3509AA6A76ED395');
        $this->addSql('ALTER TABLE recurring_transaction DROP CONSTRAINT FK_D3509AA6E7DF2E9E');
        $this->addSql('ALTER TABLE recurring_transaction DROP CONSTRAINT FK_D3509AA6C652C408');
        $this->addSql('ALTER TABLE recurring_transaction DROP CONSTRAINT FK_D3509AA612469DE2');
        $this->addSql('ALTER TABLE recurring_transaction_tag DROP CONSTRAINT FK_73070BBC5B71D755');
        $this->addSql('ALTER TABLE recurring_transaction_tag DROP CONSTRAINT FK_73070BBCBAD26311');
        $this->addSql('DROP TABLE recurring_transaction');
        $this->addSql('DROP TABLE recurring_transaction_tag');
        $this->addSql('ALTER TABLE transaction DROP CONSTRAINT FK_723705D1B149C95E');
        $this->addSql('DROP INDEX IDX_723705D1B149C95E');
        $this->addSql('ALTER TABLE transaction DROP recurring_id');
    }
}
