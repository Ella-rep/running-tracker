<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mode column to plans table (dated|ordered)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE plans ADD COLUMN mode VARCHAR(10) NOT NULL DEFAULT 'dated'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plans DROP COLUMN mode');
    }
}
