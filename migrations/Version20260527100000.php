<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add course_name to run logs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run_logs ADD course_name VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run_logs DROP course_name');
    }
}
