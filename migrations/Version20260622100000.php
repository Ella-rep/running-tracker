<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dashboard_gamification_visible column to user table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"user\" ADD dashboard_gamification_visible BOOLEAN NOT NULL DEFAULT FALSE");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"user\" DROP COLUMN dashboard_gamification_visible");
    }
}
