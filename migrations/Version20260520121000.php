<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dashboard visibility columns to users table with monthly enabled by default.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_kpis_visible BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_projections_visible BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_plan_progress_visible BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_training_load_visible BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_monthly_load_visible BOOLEAN NOT NULL DEFAULT true');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_races_table_visible BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_coherence_visible BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_ef_bpm_visible BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_plan_calendar_visible BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS dashboard_plan_calendar_visible');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS dashboard_ef_bpm_visible');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS dashboard_coherence_visible');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS dashboard_races_table_visible');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS dashboard_monthly_load_visible');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS dashboard_training_load_visible');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS dashboard_plan_progress_visible');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS dashboard_projections_visible');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS dashboard_kpis_visible');
    }
}
