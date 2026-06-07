<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds dnf_status and dnf_comment columns to races table for DNS/DNF support.
 */
final class Version20260607100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dnf_status and dnf_comment to races for DNS/DNF support';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE races ADD dnf_status VARCHAR(3) DEFAULT NULL");
        $this->addSql("ALTER TABLE races ADD dnf_comment VARCHAR(255) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE races DROP COLUMN dnf_status');
        $this->addSql('ALTER TABLE races DROP COLUMN dnf_comment');
    }
}
