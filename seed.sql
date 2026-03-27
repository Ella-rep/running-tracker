-- =============================================================
--  Running Tracker — Script d'initialisation des données
--  Compatible PostgreSQL 14+
--
--  Usage :
--    docker exec -i runtracker_db psql -U runner runtracker < seed.sql
--  ou depuis le conteneur app :
--    docker exec -i runtracker_db psql \
--      -U "${POSTGRES_USER}" "${POSTGRES_DB}" < seed.sql
--
--  Le script crée un utilisateur "demo" (mot de passe : demo1234)
--  et insère toutes les données initiales liées à ce compte.
--  Si l'utilisateur demo existe déjà, le script ne fait rien.
-- =============================================================

BEGIN;

-- -------------------------------------------------------------
-- 1. Utilisateur demo
--    Mot de passe "demo1234" hashé avec bcrypt (coût 12)
--    Pour changer le mot de passe, régénérer le hash avec :
--    php bin/console security:hash-password
-- -------------------------------------------------------------
INSERT INTO users (username, password, roles, created_at)
VALUES (
    'demo',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.',  -- demo1234
    '["ROLE_USER"]',
    NOW()
)
ON CONFLICT (username) DO NOTHING;

-- Récupérer l'id de l'utilisateur demo pour les FK
DO $$
DECLARE
    v_user_id INTEGER;
BEGIN

SELECT id INTO v_user_id FROM users WHERE username = 'demo';

IF v_user_id IS NULL THEN
    RAISE NOTICE 'Utilisateur demo non trouvé, seed annulé.';
    RETURN;
END IF;

RAISE NOTICE 'Insertion des données pour user_id = %', v_user_id;

-- -------------------------------------------------------------
-- 2. Journal de course (25 sorties — janv. à mars 2026)
-- -------------------------------------------------------------
INSERT INTO run_logs (user_id, date, km, duration, allure, gap, dplus, bpm, run_type, notes, created_at) VALUES

-- Janvier 2026
(v_user_id, '2026-01-06',  3.00, '00:26:47', '08:55', NULL, NULL, NULL, NULL,   NULL,              NOW()),
(v_user_id, '2026-01-12',  3.06, '00:29:14', '09:33', NULL, NULL, NULL, NULL,   NULL,              NOW()),
(v_user_id, '2026-01-13',  3.64, '00:33:27', '09:11', NULL, NULL, NULL, NULL,   NULL,              NOW()),
(v_user_id, '2026-01-16',  3.87, '00:34:06', '08:49', NULL, NULL, NULL, NULL,   NULL,              NOW()),
(v_user_id, '2026-01-17',  6.09, '00:53:19', '08:45', NULL, NULL, NULL, NULL,   NULL,              NOW()),
(v_user_id, '2026-01-19',  4.84, '00:43:15', '08:56', NULL, NULL, NULL, NULL,   NULL,              NOW()),
(v_user_id, '2026-01-21',  4.20, '00:39:37', '09:26', NULL, NULL, NULL, NULL,   NULL,              NOW()),
(v_user_id, '2026-01-24',  6.08, '00:52:51', '08:42', NULL, NULL, NULL, NULL,   NULL,              NOW()),
(v_user_id, '2026-01-26',  4.85, '00:42:49', '08:50', NULL, NULL, NULL, NULL,   NULL,              NOW()),
(v_user_id, '2026-01-28',  5.03, '00:42:48', '08:31', NULL, NULL, NULL, NULL,   NULL,              NOW()),
(v_user_id, '2026-01-31',  6.03, '00:49:49', '08:16', NULL, NULL, NULL, NULL,   NULL,              NOW()),

-- Février 2026
(v_user_id, '2026-02-02',  5.45, '00:45:05', '08:16', NULL, NULL, NULL, NULL,   NULL,              NOW()),
(v_user_id, '2026-02-05',  6.01, '00:54:07', '09:00', NULL, NULL, NULL, NULL,   NULL,              NOW()),
(v_user_id, '2026-02-07',  6.68, '00:54:11', '08:07', NULL, NULL, NULL, NULL,   NULL,              NOW()),
(v_user_id, '2026-02-14',  5.11, '00:45:05', '08:49', NULL, NULL, 146,  'EF',   NULL,              NOW()),
(v_user_id, '2026-02-27',  4.20, '00:35:04', '08:21', NULL, NULL, NULL, NULL,   NULL,              NOW()),

-- Mars 2026
(v_user_id, '2026-03-01',  5.17, '00:44:24', '08:35', NULL, NULL, NULL, NULL,   NULL,              NOW()),
(v_user_id, '2026-03-04',  6.05, '00:52:24', '08:40', NULL, NULL, NULL, NULL,   NULL,              NOW()),
(v_user_id, '2026-03-08',  5.23, '00:38:50', '07:26', NULL, NULL, NULL, 'Race', 'La Putéolienne',  NOW()),
(v_user_id, '2026-03-11',  5.22, '00:45:14', '08:40', NULL, NULL, 154,  'EF',   NULL,              NOW()),
(v_user_id, '2026-03-13',  6.18, '00:52:39', '08:31', NULL, NULL, NULL, 'FC',   NULL,              NOW()),
(v_user_id, '2026-03-15',  8.59, '01:10:07', '08:10', NULL, NULL, NULL, 'FL',   NULL,              NOW()),
(v_user_id, '2026-03-18',  4.42, '00:40:14', '09:06', NULL, NULL, 151,  'EF',   NULL,              NOW()),
(v_user_id, '2026-03-22', 10.19, '01:28:37', '08:42', NULL, NULL, NULL, 'Race', 'Eco Trail',       NOW()),
(v_user_id, '2026-03-24',  4.37, '00:40:03', '09:10', NULL, NULL, 147,  'EF',   NULL,              NOW());

-- -------------------------------------------------------------
-- 3. Calendrier des courses (saison 2026)
-- -------------------------------------------------------------
INSERT INTO races (user_id, name, date, distance, objective, result, created_at) VALUES

(v_user_id, 'La Putéolienne',        '2026-03-08', '5km',  '00:40:00', '00:38:50', NOW()),
(v_user_id, 'Ecotrail',              '2026-03-22', '10km', '01:30:00', '01:28:37', NOW()),
(v_user_id, 'Sine qua non',          '2026-03-28', '10km', NULL,       NULL,        NOW()),
(v_user_id, 'Unicef Boulogne',       '2026-05-10', '10km', NULL,       NULL,        NOW()),
(v_user_id, 'Adidas Paris',          '2026-06-07', '10km', NULL,       NULL,        NOW()),
(v_user_id, 'La course des princesses', '2026-06-28', '8km', NULL,     NULL,        NOW()),
(v_user_id, 'La Parisienne',         '2026-09-13', '10km', NULL,       NULL,        NOW()),
(v_user_id, '20km de Paris',         '2026-10-11', '20km', NULL,       NULL,        NOW());

-- -------------------------------------------------------------
-- 4. Coches des plans d'entraînement
--    Seule la première séance Tempo (index 0) est cochée
--    conformément aux données initiales (done: true)
-- -------------------------------------------------------------
INSERT INTO plan_checks (user_id, plan_key, session_index, done) VALUES
(v_user_id, 'tempoDone', 0, true);

RAISE NOTICE 'Seed terminé avec succès pour user "demo".';

END $$;

COMMIT;

-- =============================================================
--  Vérification rapide
-- =============================================================
SELECT
    (SELECT COUNT(*) FROM run_logs  r JOIN users u ON u.id = r.user_id WHERE u.username = 'demo') AS sorties,
    (SELECT COUNT(*) FROM races     r JOIN users u ON u.id = r.user_id WHERE u.username = 'demo') AS courses,
    (SELECT COUNT(*) FROM plan_checks p JOIN users u ON u.id = p.user_id WHERE u.username = 'demo') AS coches;
