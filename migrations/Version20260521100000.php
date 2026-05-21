<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add google_id column to users table for Google OAuth login.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS google_id VARCHAR(128) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_1483A5E9AE3B8FC ON users (google_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS UNIQ_1483A5E9AE3B8FC');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS google_id');
    }
}
