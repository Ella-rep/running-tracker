<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create admin_announcement table for global admin communication banner.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS admin_announcement (id SERIAL NOT NULL, message TEXT NOT NULL, level VARCHAR(16) NOT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE, starts_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_by_admin_id INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_ADMIN_ANNOUNCEMENT_ACTIVE ON admin_announcement (is_active, starts_at, ends_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_ADMIN_ANNOUNCEMENT_UPDATED ON admin_announcement (updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS admin_announcement');
    }
}
