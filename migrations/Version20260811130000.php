<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add plans.is_archived (read-only archived plans)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plans ADD COLUMN is_archived BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plans DROP COLUMN is_archived');
    }
}
