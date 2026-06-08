<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes starter/template sessions that were previously persisted as
 * plan_details rows. These are now shown as non-persisted placeholders for
 * empty plans, so the seeded rows should no longer live in the database.
 *
 * Only the exact starter fingerprints are deleted, and only when the row was
 * never dated, validated or cancelled, to avoid touching real user sessions.
 */
final class Version20260608130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete legacy starter/template sessions from plan_details';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM plan_details
            WHERE sem = 1
              AND session_date IS NULL
              AND is_done = FALSE
              AND is_cancelled = FALSE
              AND (
                (format = '45'' facile' AND session_type = 'EF' AND total_min = 45)
                OR (format = '20''@Z2 >> 10x (30"@Z5 + 30"@Z1) >> 5''@Z1' AND session_type = 'FC' AND total_min = 45)
                OR (format = '15'' échauffement >> 3x (1.5km tempo >> 200m marche) >> 1km récup' AND session_type = 'FL' AND total_min = 45)
                OR (format = '90''@Z2' AND session_type = 'SL' AND total_min = 90)
              )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Deleted seed rows cannot be restored.
        $this->throwIrreversibleMigrationException('Starter session cleanup cannot be reverted.');
    }
}
