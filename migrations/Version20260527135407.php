<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260527135407 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_admin_announcement_active');
        $this->addSql('DROP INDEX idx_admin_announcement_updated');
        $this->addSql('DROP INDEX idx_admin_audit_log_created_at');
        $this->addSql('DROP INDEX idx_admin_audit_log_target_user');
        $this->addSql('ALTER INDEX idx_d3f9298ea76ed395 RENAME TO IDX_F9E14F16A76ED395');
        $this->addSql('ALTER INDEX idx_run_logs_planned_session RENAME TO IDX_6C71997866D76C02');
        $this->addSql('ALTER INDEX uniq_1483a5e9ae3b8fc RENAME TO UNIQ_1483A5E976F5C865');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER INDEX idx_f9e14f16a76ed395 RENAME TO idx_d3f9298ea76ed395');
        $this->addSql('ALTER INDEX uniq_1483a5e976f5c865 RENAME TO uniq_1483a5e9ae3b8fc');
        $this->addSql('CREATE INDEX idx_admin_audit_log_created_at ON admin_audit_log (created_at)');
        $this->addSql('CREATE INDEX idx_admin_audit_log_target_user ON admin_audit_log (target_user_id)');
        $this->addSql('ALTER INDEX idx_6c71997866d76c02 RENAME TO idx_run_logs_planned_session');
        $this->addSql('CREATE INDEX idx_admin_announcement_active ON admin_announcement (is_active, starts_at, ends_at)');
        $this->addSql('CREATE INDEX idx_admin_announcement_updated ON admin_announcement (updated_at)');
    }
}
