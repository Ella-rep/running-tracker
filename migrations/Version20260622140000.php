<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_id (nullable) to quest — personal quests per user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest ADD COLUMN IF NOT EXISTS user_id INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_quest_user ON quest(user_id)');
        // Delete the hardcoded seed quests (they are replaced by user-created ones)
        $this->addSql("DELETE FROM quest_progress WHERE quest_id IN (SELECT id FROM quest WHERE user_id IS NULL)");
        $this->addSql("DELETE FROM quest WHERE user_id IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest DROP COLUMN IF EXISTS user_id');
    }
}
