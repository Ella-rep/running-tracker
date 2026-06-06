<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds meteo_city column to users table for weather city preference persistence.
 */
final class Version20260606120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add meteo_city to users for weather city preference persistence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD meteo_city VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN meteo_city');
    }
}
