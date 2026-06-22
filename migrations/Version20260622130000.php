<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace dashboard_gamification_visible with rpg_mode on users table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS dashboard_gamification_visible');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS rpg_mode BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS rpg_mode');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_gamification_visible BOOLEAN NOT NULL DEFAULT FALSE');
    }
}
