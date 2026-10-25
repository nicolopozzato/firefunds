<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The user's preferences for appearance, start page and period, default
 * account and the dashboard's layout.
 */
final class Version20261025222539 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User: colour scheme, density, start page and period, default account, dashboard layout';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD color_scheme VARCHAR(10) DEFAULT \'blue\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD density VARCHAR(12) DEFAULT \'comfortable\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD start_page VARCHAR(20) DEFAULT \'dashboard\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD start_period VARCHAR(20) DEFAULT \'last\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD dashboard_sections JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD dashboard_trend_view VARCHAR(12) DEFAULT \'accounts\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD dashboard_investment_unit VARCHAR(8) DEFAULT \'percent\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD default_account_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT FK_8D93D649D3480BD9 FOREIGN KEY (default_account_id) REFERENCES account (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_8D93D649D3480BD9 ON "user" (default_account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT FK_8D93D649D3480BD9');
        $this->addSql('DROP INDEX IDX_8D93D649D3480BD9');
        $this->addSql('ALTER TABLE "user" DROP color_scheme');
        $this->addSql('ALTER TABLE "user" DROP density');
        $this->addSql('ALTER TABLE "user" DROP start_page');
        $this->addSql('ALTER TABLE "user" DROP start_period');
        $this->addSql('ALTER TABLE "user" DROP dashboard_sections');
        $this->addSql('ALTER TABLE "user" DROP dashboard_trend_view');
        $this->addSql('ALTER TABLE "user" DROP dashboard_investment_unit');
        $this->addSql('ALTER TABLE "user" DROP default_account_id');
    }
}
