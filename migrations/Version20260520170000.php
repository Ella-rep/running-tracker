<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional session_type column on plan_details for planned session classification (EF/FC/FL/T/Race).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan_details ADD COLUMN IF NOT EXISTS session_type VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan_details DROP COLUMN IF EXISTS session_type');
    }
}
