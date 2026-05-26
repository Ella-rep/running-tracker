<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add perceived_effort to run logs to keep notes and effort separate.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run_logs ADD perceived_effort VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run_logs DROP perceived_effort');
    }
}
