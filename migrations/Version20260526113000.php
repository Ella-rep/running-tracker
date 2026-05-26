<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260526113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-plan dashboard tracking flag.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plans ADD dashboard_tracked BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql("UPDATE plans SET dashboard_tracked = FALSE WHERE LOWER(TRIM(name)) = 'starter'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plans DROP dashboard_tracked');
    }
}
