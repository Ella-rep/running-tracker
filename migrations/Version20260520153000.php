<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional planned_session_id relation on run_logs to link a run log with a planned plan session.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run_logs ADD COLUMN IF NOT EXISTS planned_session_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_RUN_LOGS_PLANNED_SESSION ON run_logs (planned_session_id)');
        $this->addSql('ALTER TABLE run_logs ADD CONSTRAINT FK_RUN_LOGS_PLANNED_SESSION FOREIGN KEY (planned_session_id) REFERENCES plan_details (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run_logs DROP CONSTRAINT IF EXISTS FK_RUN_LOGS_PLANNED_SESSION');
        $this->addSql('DROP INDEX IF EXISTS IDX_RUN_LOGS_PLANNED_SESSION');
        $this->addSql('ALTER TABLE run_logs DROP COLUMN IF EXISTS planned_session_id');
    }
}
