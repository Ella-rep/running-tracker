<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial consolidated schema migration including admin tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (id SERIAL NOT NULL, username VARCHAR(64) NOT NULL, email VARCHAR(180) DEFAULT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reset_password_token_hash VARCHAR(64) DEFAULT NULL, reset_password_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, dashboard_projections_visible BOOLEAN NOT NULL DEFAULT false, dashboard_plan_progress_visible BOOLEAN NOT NULL DEFAULT false, dashboard_training_load_visible BOOLEAN NOT NULL DEFAULT false, dashboard_monthly_load_visible BOOLEAN NOT NULL DEFAULT true, dashboard_coherence_visible BOOLEAN NOT NULL DEFAULT false, dashboard_ef_bpm_visible BOOLEAN NOT NULL DEFAULT false, google_id VARCHAR(128) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9F85E0677 ON users (username)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9AE3B8FC ON users (google_id)');
        $this->addSql("COMMENT ON COLUMN users.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN users.reset_password_expires_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('CREATE TABLE plans (id SERIAL NOT NULL, user_id INT NOT NULL, name VARCHAR(64) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_356798D1A76ED395 ON plans (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_plans_user_name ON plans (user_id, name)');

        $this->addSql('CREATE TABLE plan_details (id SERIAL NOT NULL, user_id INT NOT NULL, plan_id INT NOT NULL, position INT NOT NULL, sem INT DEFAULT NULL, session_date DATE DEFAULT NULL, format TEXT NOT NULL, session_type VARCHAR(16) DEFAULT NULL, pe VARCHAR(10) DEFAULT NULL, total_min INT DEFAULT NULL, is_optional BOOLEAN NOT NULL, is_done BOOLEAN NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_CC994382A76ED395 ON plan_details (user_id)');
        $this->addSql('CREATE INDEX IDX_CC994382E899029B ON plan_details (plan_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_plan_details_user_plan_pos ON plan_details (user_id, plan_id, position)');

        $this->addSql('CREATE TABLE plan_progress (id SERIAL NOT NULL, user_id INT NOT NULL, plan_key VARCHAR(32) NOT NULL, session_index INT NOT NULL, done BOOLEAN NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_2C64C53CA76ED395 ON plan_progress (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2C64C53CA76ED395867110F45B0EDFD7 ON plan_progress (user_id, plan_key, session_index)');

        $this->addSql('CREATE TABLE races (id SERIAL NOT NULL, user_id INT NOT NULL, name VARCHAR(128) NOT NULL, date VARCHAR(10) NOT NULL, distance VARCHAR(16) DEFAULT NULL, objective VARCHAR(12) DEFAULT NULL, result VARCHAR(12) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_5DBD1EC9A76ED395 ON races (user_id)');
        $this->addSql("COMMENT ON COLUMN races.created_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('CREATE TABLE run_logs (id SERIAL NOT NULL, user_id INT NOT NULL, planned_session_id INT DEFAULT NULL, date VARCHAR(10) NOT NULL, km DOUBLE PRECISION DEFAULT NULL, duration VARCHAR(12) DEFAULT NULL, allure VARCHAR(8) DEFAULT NULL, gap VARCHAR(8) DEFAULT NULL, dplus INT DEFAULT NULL, bpm INT DEFAULT NULL, run_type VARCHAR(16) DEFAULT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_6C719978A76ED395 ON run_logs (user_id)');
        $this->addSql('CREATE INDEX IDX_RUN_LOGS_PLANNED_SESSION ON run_logs (planned_session_id)');
        $this->addSql("COMMENT ON COLUMN run_logs.created_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('CREATE TABLE calendar_events (id SERIAL NOT NULL, user_id INT NOT NULL, event_date VARCHAR(10) NOT NULL, title VARCHAR(160) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_D3F9298EA76ED395 ON calendar_events (user_id)');
        $this->addSql('CREATE INDEX idx_calendar_events_user_date ON calendar_events (user_id, event_date)');
        $this->addSql("COMMENT ON COLUMN calendar_events.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN calendar_events.updated_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('CREATE TABLE admin_audit_log (id SERIAL NOT NULL, admin_user_id INT DEFAULT NULL, admin_identifier VARCHAR(64) NOT NULL, target_user_id INT DEFAULT NULL, action VARCHAR(64) NOT NULL, details JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_ADMIN_AUDIT_LOG_CREATED_AT ON admin_audit_log (created_at)');
        $this->addSql('CREATE INDEX IDX_ADMIN_AUDIT_LOG_TARGET_USER ON admin_audit_log (target_user_id)');
        $this->addSql("COMMENT ON COLUMN admin_audit_log.created_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('CREATE TABLE admin_announcement (id SERIAL NOT NULL, message TEXT NOT NULL, level VARCHAR(16) NOT NULL, is_active BOOLEAN NOT NULL DEFAULT TRUE, starts_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_by_admin_id INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_ADMIN_ANNOUNCEMENT_ACTIVE ON admin_announcement (is_active, starts_at, ends_at)');
        $this->addSql('CREATE INDEX IDX_ADMIN_ANNOUNCEMENT_UPDATED ON admin_announcement (updated_at)');
        $this->addSql("COMMENT ON COLUMN admin_announcement.starts_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN admin_announcement.ends_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN admin_announcement.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN admin_announcement.updated_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('ALTER TABLE plans ADD CONSTRAINT FK_356798D1A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE plan_details ADD CONSTRAINT FK_CC994382A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE plan_details ADD CONSTRAINT FK_CC994382E899029B FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE plan_progress ADD CONSTRAINT FK_2C64C53CA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE races ADD CONSTRAINT FK_5DBD1EC9A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE run_logs ADD CONSTRAINT FK_6C719978A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE run_logs ADD CONSTRAINT FK_RUN_LOGS_PLANNED_SESSION FOREIGN KEY (planned_session_id) REFERENCES plan_details (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE calendar_events ADD CONSTRAINT FK_D3F9298EA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE plans DROP CONSTRAINT FK_356798D1A76ED395');
        $this->addSql('ALTER TABLE plan_details DROP CONSTRAINT FK_CC994382A76ED395');
        $this->addSql('ALTER TABLE plan_details DROP CONSTRAINT FK_CC994382E899029B');
        $this->addSql('ALTER TABLE plan_progress DROP CONSTRAINT FK_2C64C53CA76ED395');
        $this->addSql('ALTER TABLE races DROP CONSTRAINT FK_5DBD1EC9A76ED395');
        $this->addSql('ALTER TABLE run_logs DROP CONSTRAINT FK_6C719978A76ED395');
        $this->addSql('ALTER TABLE run_logs DROP CONSTRAINT FK_RUN_LOGS_PLANNED_SESSION');
        $this->addSql('ALTER TABLE calendar_events DROP CONSTRAINT FK_D3F9298EA76ED395');
        $this->addSql('DROP TABLE admin_announcement');
        $this->addSql('DROP TABLE admin_audit_log');
        $this->addSql('DROP TABLE calendar_events');
        $this->addSql('DROP TABLE run_logs');
        $this->addSql('DROP TABLE races');
        $this->addSql('DROP TABLE plan_progress');
        $this->addSql('DROP TABLE plan_details');
        $this->addSql('DROP TABLE plans');
        $this->addSql('DROP TABLE users');
    }
}

