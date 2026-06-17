<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store profile picture as BYTEA blob in DB (replaces filesystem photo_filename)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN photo_data BYTEA DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN photo_mime_type VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE users DROP COLUMN photo_filename');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN photo_filename VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users DROP COLUMN photo_data');
        $this->addSql('ALTER TABLE users DROP COLUMN photo_mime_type');
    }
}
