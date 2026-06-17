<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add plans.is_completed (manual completion) and users.dashboard_race_avg_visible (race-avg widget)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plans ADD COLUMN is_completed BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE users ADD COLUMN dashboard_race_avg_visible BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plans DROP COLUMN is_completed');
        $this->addSql('ALTER TABLE users DROP COLUMN dashboard_race_avg_visible');
    }
}
