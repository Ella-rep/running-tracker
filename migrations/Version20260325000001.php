<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: users, run_logs, races, plan_checks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (
            id SERIAL NOT NULL,
            username VARCHAR(64) NOT NULL,
            password VARCHAR(255) NOT NULL,
            roles JSON NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_username ON users (username)');
        $this->addSql('COMMENT ON COLUMN users.created_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE run_logs (
            id SERIAL NOT NULL,
            user_id INT NOT NULL,
            date VARCHAR(10) NOT NULL,
            km DOUBLE PRECISION DEFAULT NULL,
            duration VARCHAR(12) DEFAULT NULL,
            allure VARCHAR(8) DEFAULT NULL,
            gap VARCHAR(8) DEFAULT NULL,
            dplus INT DEFAULT NULL,
            bpm INT DEFAULT NULL,
            run_type VARCHAR(16) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_run_logs_user ON run_logs (user_id)');
        $this->addSql('CREATE INDEX idx_run_logs_date ON run_logs (date)');
        $this->addSql('COMMENT ON COLUMN run_logs.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE run_logs ADD CONSTRAINT fk_run_logs_user
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE races (
            id SERIAL NOT NULL,
            user_id INT NOT NULL,
            name VARCHAR(128) NOT NULL,
            date VARCHAR(10) NOT NULL,
            distance VARCHAR(16) DEFAULT NULL,
            objective VARCHAR(12) DEFAULT NULL,
            result VARCHAR(12) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_races_user ON races (user_id)');
        $this->addSql('CREATE INDEX idx_races_date ON races (date)');
        $this->addSql('COMMENT ON COLUMN races.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE races ADD CONSTRAINT fk_races_user
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE plan_checks (
            id SERIAL NOT NULL,
            user_id INT NOT NULL,
            plan_key VARCHAR(32) NOT NULL,
            session_index INT NOT NULL,
            done BOOLEAN NOT NULL DEFAULT FALSE,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_plan_checks ON plan_checks (user_id, plan_key, session_index)');
        $this->addSql('CREATE INDEX idx_plan_checks_user ON plan_checks (user_id)');
        $this->addSql('ALTER TABLE plan_checks ADD CONSTRAINT fk_plan_checks_user
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run_logs DROP CONSTRAINT fk_run_logs_user');
        $this->addSql('ALTER TABLE races DROP CONSTRAINT fk_races_user');
        $this->addSql('ALTER TABLE plan_checks DROP CONSTRAINT fk_plan_checks_user');
        $this->addSql('DROP TABLE plan_checks');
        $this->addSql('DROP TABLE run_logs');
        $this->addSql('DROP TABLE races');
        $this->addSql('DROP TABLE users');
    }
}
