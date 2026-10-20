<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prices say where they come from (App\Entity\InvestmentPriceSource): the
 * provider's close, a close carried over to a day without one, or the user's
 * own; and a price the user entered can be deleted (softly), so the one-per-
 * day rule only holds among the active ones.
 *
 * Existing prices: a value the user corrected becomes manual, keeping the
 * provider's in original_price; a weekend day of an instrument that doesn't
 * trade on weekends was carried over; everything else is taken as a close
 * (a holiday stays so: no trade happens on one, which is all it affects).
 */
final class Version20261020212422 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Investment prices: source (market, filled, manual), soft delete, one active price per day';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_a50b142dcf11d9caa9e377a');
        $this->addSql('ALTER TABLE investment_price ADD source VARCHAR(10) DEFAULT \'market\' NOT NULL');
        $this->addSql('ALTER TABLE investment_price ADD original_source VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE investment_price ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE investment_price ALTER original_price DROP NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_investment_price_day_active ON investment_price (instrument_id, date) WHERE (deleted_at IS NULL)');

        $this->addSql(<<<'SQL'
            UPDATE investment_price p
            SET source = 'filled'
            FROM investment_instrument i
            WHERE i.id = p.instrument_id AND i.type <> 'cryptocurrency' AND EXTRACT(ISODOW FROM p.date) IN (6, 7)
            SQL);
        $this->addSql('UPDATE investment_price SET original_source = source');
        $this->addSql("UPDATE investment_price SET source = 'manual' WHERE price <> original_price");
    }

    public function down(Schema $schema): void
    {
        // Prices only the user entered have no provider value to go back to: they go.
        $this->addSql('DELETE FROM investment_price WHERE original_price IS NULL OR deleted_at IS NOT NULL');
        $this->addSql('DROP INDEX uniq_investment_price_day_active');
        $this->addSql('ALTER TABLE investment_price DROP source');
        $this->addSql('ALTER TABLE investment_price DROP original_source');
        $this->addSql('ALTER TABLE investment_price DROP deleted_at');
        $this->addSql('ALTER TABLE investment_price ALTER original_price SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_a50b142dcf11d9caa9e377a ON investment_price (instrument_id, date)');
    }
}
