<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520134000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove non-widget dashboard visibility columns (kpis, races table, plan calendar) from users.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS dashboard_kpis_visible');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS dashboard_races_table_visible');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS dashboard_plan_calendar_visible');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_kpis_visible BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_races_table_visible BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_plan_calendar_visible BOOLEAN NOT NULL DEFAULT false');
    }
}
