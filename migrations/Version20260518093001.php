<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518093001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create calendar_events table for personal calendar agenda entries.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE calendar_events (id SERIAL NOT NULL, user_id INT NOT NULL, event_date VARCHAR(10) NOT NULL, title VARCHAR(160) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_calendar_events_user_date ON calendar_events (user_id, event_date)');
        $this->addSql('CREATE INDEX IDX_D3F9298EA76ED395 ON calendar_events (user_id)');
        $this->addSql("COMMENT ON COLUMN calendar_events.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN calendar_events.updated_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('ALTER TABLE calendar_events ADD CONSTRAINT FK_D3F9298EA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE calendar_events DROP CONSTRAINT FK_D3F9298EA76ED395');
        $this->addSql('DROP TABLE calendar_events');
    }
}
