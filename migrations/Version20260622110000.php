<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create gamification tables: gear, quest, quest_progress, athlete_stats';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE gear (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                name VARCHAR(120) NOT NULL,
                skill_type VARCHAR(30) NOT NULL DEFAULT 'speed',
                modifier INTEGER NOT NULL DEFAULT 0,
                active BOOLEAN NOT NULL DEFAULT TRUE,
                retired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
            )
        ");

        $this->addSql("
            CREATE TABLE quest (
                id SERIAL PRIMARY KEY,
                type VARCHAR(30) NOT NULL DEFAULT 'side',
                title VARCHAR(180) NOT NULL,
                subtitle VARCHAR(200) DEFAULT NULL,
                condition_type VARCHAR(40) NOT NULL DEFAULT 'distance_km',
                condition_value DOUBLE PRECISION NOT NULL DEFAULT 0,
                xp_reward INTEGER NOT NULL DEFAULT 100,
                active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
            )
        ");

        $this->addSql("
            CREATE TABLE quest_progress (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                quest_id INTEGER NOT NULL REFERENCES quest(id) ON DELETE CASCADE,
                progress_current DOUBLE PRECISION NOT NULL DEFAULT 0,
                completed BOOLEAN NOT NULL DEFAULT FALSE,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_user_quest UNIQUE (user_id, quest_id)
            )
        ");

        $this->addSql("
            CREATE TABLE athlete_stats (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
                xp_total INTEGER NOT NULL DEFAULT 0,
                skill_speed INTEGER NOT NULL DEFAULT 10,
                skill_endurance INTEGER NOT NULL DEFAULT 10,
                skill_recovery INTEGER NOT NULL DEFAULT 10,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
            )
        ");

        // Quêtes par défaut
        $this->addSql("
            INSERT INTO quest (type, title, subtitle, condition_type, condition_value, xp_reward) VALUES
            ('main',   'Finir le 20km avant octobre',       'Allure cible : 8:30/km',           'distance_km',  20.0,  500),
            ('main',   'Améliorer ton allure de 30s/km',    'Allure de référence : derniers logs','pace_per_km',  30.0,  300),
            ('side',   'Courir 5km sans pause',             'Distance max atteinte',             'distance_km',   5.0,  120),
            ('side',   'Enchaîner 3 sorties en 7 jours',   'Régularité récompensée',            'streak_days',   3.0,   80),
            ('side',   'Atteindre 50km cumulés',            'Total km cumulé',                   'total_km',     50.0,  200),
            ('legend', 'Semi des Halflings · Hobbiton NZ', 'L''aventure commence…',             'distance_km',  21.1, 5000)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS quest_progress');
        $this->addSql('DROP TABLE IF EXISTS athlete_stats');
        $this->addSql('DROP TABLE IF EXISTS gear');
        $this->addSql('DROP TABLE IF EXISTS quest');
    }
}
