<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds is_cancelled column to plan_details for deliberately skipped sessions.
 */
final class Version20260608120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_cancelled to plan_details';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE plan_details ADD is_cancelled BOOLEAN NOT NULL DEFAULT FALSE");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan_details DROP COLUMN is_cancelled');
    }
}
