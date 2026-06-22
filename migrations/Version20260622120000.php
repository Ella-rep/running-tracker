<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create rpg_event table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE rpg_event (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                race_id INTEGER DEFAULT NULL REFERENCES races(id) ON DELETE SET NULL,
                type VARCHAR(30) NOT NULL DEFAULT 'random',
                severity VARCHAR(20) NOT NULL DEFAULT 'info',
                title VARCHAR(180) NOT NULL,
                description TEXT DEFAULT NULL,
                icon VARCHAR(10) DEFAULT NULL,
                xp_delta INTEGER NOT NULL DEFAULT 0,
                acknowledged BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
            )
        ");

        $this->addSql('CREATE INDEX idx_rpg_event_user ON rpg_event(user_id)');
        $this->addSql('CREATE INDEX idx_rpg_event_pending ON rpg_event(user_id, acknowledged)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS rpg_event');
    }
}
