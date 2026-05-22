<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create admin_audit_log table for admin actions on users.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS admin_audit_log (id SERIAL NOT NULL, admin_user_id INT DEFAULT NULL, admin_identifier VARCHAR(64) NOT NULL, target_user_id INT DEFAULT NULL, action VARCHAR(64) NOT NULL, details JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_ADMIN_AUDIT_LOG_CREATED_AT ON admin_audit_log (created_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_ADMIN_AUDIT_LOG_TARGET_USER ON admin_audit_log (target_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS admin_audit_log');
    }
}
