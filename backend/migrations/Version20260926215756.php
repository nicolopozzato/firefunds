<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260926215756 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create account and currency tables, seed common currencies (EUR enabled)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE account (id UUID NOT NULL, type VARCHAR(20) NOT NULL, name VARCHAR(255) NOT NULL, iban VARCHAR(34) DEFAULT NULL, bic VARCHAR(11) DEFAULT NULL, account_number VARCHAR(64) DEFAULT NULL, notes TEXT DEFAULT NULL, is_active BOOLEAN NOT NULL, opening_balance NUMERIC(19, 4) DEFAULT NULL, opening_balance_date DATE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, currency_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7D3656A4A76ED3958CDE5729 ON account (user_id, type)');
        $this->addSql('CREATE INDEX IDX_7D3656A4A76ED395 ON account (user_id)');
        $this->addSql('CREATE INDEX IDX_7D3656A438248176 ON account (currency_id)');
        $this->addSql('CREATE TABLE currency (id UUID NOT NULL, code VARCHAR(3) NOT NULL, name VARCHAR(100) NOT NULL, symbol VARCHAR(10) NOT NULL, decimal_places INT NOT NULL, is_enabled BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6956883F77153098 ON currency (code)');
        $this->addSql('ALTER TABLE account ADD CONSTRAINT FK_7D3656A4A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE account ADD CONSTRAINT FK_7D3656A438248176 FOREIGN KEY (currency_id) REFERENCES currency (id) NOT DEFERRABLE');

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $currencies = [
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'enabled' => 'true'],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'enabled' => 'false'],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimals' => 2, 'enabled' => 'false'],
            ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'enabled' => 'false'],
        ];
        foreach ($currencies as $currency) {
            $id = Uuid::v7()->toRfc4122();
            $this->addSql(
                "INSERT INTO currency (id, code, name, symbol, decimal_places, is_enabled, created_at, updated_at) "
                . "VALUES ('{$id}', '{$currency['code']}', '{$currency['name']}', '{$currency['symbol']}', {$currency['decimals']}, {$currency['enabled']}, '{$now}', '{$now}')"
            );
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account DROP CONSTRAINT FK_7D3656A4A76ED395');
        $this->addSql('ALTER TABLE account DROP CONSTRAINT FK_7D3656A438248176');
        $this->addSql('DROP TABLE account');
        $this->addSql('DROP TABLE currency');
    }
}
