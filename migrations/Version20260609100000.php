<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260609100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create health_pauses table for health pause mode feature';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE health_pauses (
                id SERIAL NOT NULL,
                user_id INT NOT NULL,
                started_at VARCHAR(10) NOT NULL,
                type VARCHAR(32) DEFAULT NULL,
                estimated_days INT DEFAULT NULL,
                resumed_at VARCHAR(10) DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_health_pause_user ON health_pauses (user_id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE health_pauses
                ADD CONSTRAINT fk_health_pauses_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
                NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE health_pauses DROP CONSTRAINT fk_health_pauses_user');
        $this->addSql('DROP TABLE health_pauses');
    }
}
