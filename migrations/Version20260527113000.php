<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add title column to admin_announcement with default fallback.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE admin_announcement ADD title VARCHAR(120) DEFAULT 'Annonce' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_announcement DROP title');
    }
}
