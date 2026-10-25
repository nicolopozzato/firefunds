<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The scheduled tasks' run log (App\Task\TaskRunner): when each run started
 * and ended, what started it, how it went, what it reports.
 */
final class Version20261025183429 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table scheduled_task_run';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE scheduled_task_run (id UUID NOT NULL, task VARCHAR(40) NOT NULL, triggered_by VARCHAR(10) NOT NULL, status VARCHAR(10) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, details JSON NOT NULL, error TEXT DEFAULT NULL, idle BOOLEAN DEFAULT false NOT NULL, repeats INT DEFAULT 1 NOT NULL, first_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_scheduled_task_run_task ON scheduled_task_run (task, started_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scheduled_task_run');
    }
}
