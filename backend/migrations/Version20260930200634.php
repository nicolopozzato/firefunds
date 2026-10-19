<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930200634 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow an "external" side on money transactions (null source or destination, never both)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction ALTER source_account_id DROP NOT NULL');
        $this->addSql('ALTER TABLE transaction ALTER destination_account_id DROP NOT NULL');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT transaction_has_an_account CHECK (source_account_id IS NOT NULL OR destination_account_id IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction DROP CONSTRAINT transaction_has_an_account');
        $this->addSql('ALTER TABLE transaction ALTER source_account_id SET NOT NULL');
        $this->addSql('ALTER TABLE transaction ALTER destination_account_id SET NOT NULL');
    }
}
