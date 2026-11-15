<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * File imports as batches: what each recorded points to it, so a whole
 * import can be undone.
 */
final class Version20261115143930 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import batches';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE import_batch (id UUID NOT NULL, file_name VARCHAR(255) NOT NULL, transaction_count INT NOT NULL, adjustment_count INT NOT NULL, created_account_ids JSON NOT NULL, created_category_ids JSON NOT NULL, created_tag_ids JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, undone_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_155EFD1AA76ED3958B8E8428 ON import_batch (user_id, created_at)');
        $this->addSql('CREATE INDEX IDX_155EFD1AA76ED395 ON import_batch (user_id)');
        $this->addSql('ALTER TABLE import_batch ADD CONSTRAINT FK_155EFD1AA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE account_adjustment ADD import_batch_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE account_adjustment ADD CONSTRAINT FK_75628AB25A310080 FOREIGN KEY (import_batch_id) REFERENCES import_batch (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_75628AB25A310080 ON account_adjustment (import_batch_id)');
        $this->addSql('ALTER TABLE transaction ADD import_batch_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D15A310080 FOREIGN KEY (import_batch_id) REFERENCES import_batch (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_723705D15A310080 ON transaction (import_batch_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_batch DROP CONSTRAINT FK_155EFD1AA76ED395');
        $this->addSql('DROP TABLE import_batch');
        $this->addSql('ALTER TABLE account_adjustment DROP CONSTRAINT FK_75628AB25A310080');
        $this->addSql('DROP INDEX IDX_75628AB25A310080');
        $this->addSql('ALTER TABLE account_adjustment DROP import_batch_id');
        $this->addSql('ALTER TABLE transaction DROP CONSTRAINT FK_723705D15A310080');
        $this->addSql('DROP INDEX IDX_723705D15A310080');
        $this->addSql('ALTER TABLE transaction DROP import_batch_id');
    }
}
