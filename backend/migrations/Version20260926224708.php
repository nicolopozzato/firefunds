<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926224708 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create transaction and transaction_tag tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE transaction (id UUID NOT NULL, description TEXT DEFAULT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, amount NUMERIC(19, 4) NOT NULL, currency VARCHAR(3) NOT NULL, foreign_amount NUMERIC(19, 4) DEFAULT NULL, foreign_currency VARCHAR(3) DEFAULT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, source_account_id UUID NOT NULL, destination_account_id UUID NOT NULL, category_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_723705D1A76ED395 ON transaction (user_id)');
        $this->addSql('CREATE INDEX IDX_723705D1A76ED39587C03D1B ON transaction (user_id, occurred_at)');
        $this->addSql('CREATE INDEX IDX_723705D1E7DF2E9E ON transaction (source_account_id)');
        $this->addSql('CREATE INDEX IDX_723705D1C652C408 ON transaction (destination_account_id)');
        $this->addSql('CREATE INDEX IDX_723705D112469DE2 ON transaction (category_id)');
        $this->addSql('CREATE TABLE transaction_tag (transaction_id UUID NOT NULL, tag_id UUID NOT NULL, PRIMARY KEY (transaction_id, tag_id))');
        $this->addSql('CREATE INDEX IDX_F8CD024A2FC0CB0F ON transaction_tag (transaction_id)');
        $this->addSql('CREATE INDEX IDX_F8CD024ABAD26311 ON transaction_tag (tag_id)');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D1A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D1E7DF2E9E FOREIGN KEY (source_account_id) REFERENCES account (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D1C652C408 FOREIGN KEY (destination_account_id) REFERENCES account (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D112469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE transaction_tag ADD CONSTRAINT FK_F8CD024A2FC0CB0F FOREIGN KEY (transaction_id) REFERENCES transaction (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transaction_tag ADD CONSTRAINT FK_F8CD024ABAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction DROP CONSTRAINT FK_723705D1A76ED395');
        $this->addSql('ALTER TABLE transaction DROP CONSTRAINT FK_723705D1E7DF2E9E');
        $this->addSql('ALTER TABLE transaction DROP CONSTRAINT FK_723705D1C652C408');
        $this->addSql('ALTER TABLE transaction DROP CONSTRAINT FK_723705D112469DE2');
        $this->addSql('ALTER TABLE transaction_tag DROP CONSTRAINT FK_F8CD024A2FC0CB0F');
        $this->addSql('ALTER TABLE transaction_tag DROP CONSTRAINT FK_F8CD024ABAD26311');
        $this->addSql('DROP TABLE transaction');
        $this->addSql('DROP TABLE transaction_tag');
    }
}
