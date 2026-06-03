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
--  Le script crée un utilisateur "elodie" (mot de passe : elodie1234)
--  et insère toutes les données initiales liées à ce compte.
--  Si l'utilisateur elodie existe déjà, le script ne fait rien.
-- =============================================================

BEGIN;

-- Récupérer l'id de l'utilisateur elodie pour les FK
DO $$
DECLARE
    v_user_id INTEGER;
    v_plan_id INTEGER;
BEGIN

SELECT id INTO v_user_id FROM users WHERE email = 'hellojito@gmail.com';
SELECT id INTO v_plan_id FROM plans WHERE user_id = v_user_id;

IF v_user_id IS NULL THEN
    RAISE NOTICE 'Utilisateur elodie non trouvé, seed annulé.';
    RETURN;
END IF;

RAISE NOTICE 'Insertion des données pour user_id = %', v_user_id;
--
-- PostgreSQL database dump
--


-- -------------------------------------------------------------
-- 2. Journal de course (25 sorties — janv. à mars 2026)
-- -------------------------------------------------------------
INSERT INTO run_logs (user_id, date, km, duration, allure, gap, dplus, bpm, run_type, notes, created_at) VALUES

-- Janvier 2026
(v_user_id, '2026-01-06',  3.00, '00:26:47', '08:55', NULL, NULL, NULL, NULL,   NULL,              NOW()),
--
-- Data for Name: plan_details; Type: TABLE DATA; Schema: public; Owner: runner
--

INSERT INTO public.plan_details (user_id, plan_id, "position", sem, session_date, format, session_type, pe, total_min, is_optional, is_done) 
VALUES (v_user_id, v_plan_id, 27, 9, '2026-05-31', '80''@Z2', 'SL', '4/10', 80, false, true),
(v_user_id, v_plan_id, 28, 10, '2026-06-01', '40''@Z2', 'EF', '3/10', 40, false, true),
(v_user_id, v_plan_id, 1, 1, NULL, '45'' facile', 'EF', '3/10', 45, false, false),
(v_user_id, v_plan_id, 2, 1, NULL, '20''@Z2 >> 10x (30"@Z5 + 30"@Z1) >> 5''@Z1', 'FC', '4/10', 45, false, false),
(v_user_id, v_plan_id, 3, 1, NULL, '15'' échauffement >> 3x (1.5km tempo >> 200m marche) >> 1km récup', 'FL', '4/10', 45, true, false),
(v_user_id, v_plan_id, 4, 1, NULL, '90''@Z2', 'SL', '4/10', 90, false, false),
(v_user_id, v_plan_id, 5, 1, NULL, '25''@Z2 >>2x (10''@Z4 + 3''@Z1) >> 5''@Z1', 'FL', '5/10', 56, false, true),
(v_user_id, v_plan_id, 6, 1, NULL, '25''@Z2', 'EF', '3/10', 25, false, true),
(v_user_id, v_plan_id, 7, 3, '2026-04-13', '40''@Z2', 'EF', '3/10', 40, false, true),
(v_user_id, v_plan_id, 8, 3, '2026-04-15', '5km harmonie mutuelle', 'Race', '6/10', 40, false, true),
(v_user_id, v_plan_id, 9, 3, '2026-04-18', '60''@Z2', 'SL', '4/10', 60, false, true),
(v_user_id, v_plan_id, 10, 4, '2026-04-20', '40''@Z2', 'EF', '3/10', 40, false, true),
(v_user_id, v_plan_id, 11, 4, '2026-04-22', '25''@Z2 >>4x (3''@Z4 + 1''30@Z1)>>10''@Z1', 'FL', '5/10', 53, false, true),
(v_user_id, v_plan_id, 12, 4, '2026-04-24', '65''@Z2', 'SL', '4/10', 65, false, true),
(v_user_id, v_plan_id, 13, 4, '2026-04-26', '40''@Z2', 'EF', '3/10', 40, false, true),
(v_user_id, v_plan_id, 14, 5, '2026-04-28', '25''@Z2 >>10x (1''@Z4 + 1''@Z1)>>10''@Z1', 'FC', '3/10', 55, false, true),
(v_user_id, v_plan_id, 15, 5, '2026-05-01', '65''@Z2', 'SL', '4/10', 65, false, true),
(v_user_id, v_plan_id, 16, 6, '2026-05-04', '40''@Z2', 'EF', '3/10', 40, false, true),
(v_user_id, v_plan_id, 17, 6, '2026-05-05', '35''@Z2 >>6x (1''@Z5 + 1''30@Z1)>>10''@Z1', 'FC', '3/10', 60, false, true),
(v_user_id, v_plan_id, 18, 6, '2026-05-10', '10km unicef boulogne', 'Race', '6/10', 75, false, true),
(v_user_id, v_plan_id, 19, 7, '2026-05-11', '40''@Z2', 'EF', '3/10', 40, false, true),
(v_user_id, v_plan_id, 20, 7, '2026-05-12', '25''@Z2 >> 4x (5''@Z4 + 2''@Z1) >> 5''@Z1', 'FL', '3/10', 40, false, true),
(v_user_id, v_plan_id, 21, 7, '2026-05-16', '75''@Z2', 'SL', '4/10', 75, false, true),
(v_user_id, v_plan_id, 22, 8, '2026-05-18', '40''@Z2', 'EF', '3/10', 40, false, true),
(v_user_id, v_plan_id, 23, 8, '2026-05-19', '25''@Z2 >> 6x (2''@Z5 + 1''@Z1) >> 5''@Z1', 'FC', '3/10', 40, false, true),
(v_user_id, v_plan_id, 24, 8, '2026-05-23', '80''@Z2', 'SL', '4/10', 80, false, true),
(v_user_id, v_plan_id, 25, 9, '2026-05-28', '40''@Z2', 'EF', '3/10', 40, false, true),
(v_user_id, v_plan_id, 26, 9, '2026-05-25', '25''@Z2 >> 3x (7''@Z4 + 2''@Z1) >> 5''@Z1', 'FL', '3/10', 50, false, true),
(v_user_id, v_plan_id, 30, 10, '2026-06-07', '10km adidas Paris', 'Race', '6/10', NULL, false, false),
(v_user_id, v_plan_id, 31, 11, '2026-06-08', '35''@Z2', 'EF', '3/10', 40, false, false),
(v_user_id, v_plan_id, 32, 11, '2026-06-10', '25''@Z2 >> 10x (30''''@Z5 + 30''''@Z1) >> 5''@Z1', 'FC', '5/10', 40, false, false),
(v_user_id, v_plan_id, 33, 11, '2026-06-13', '1h35@Z2', 'SL', '4/10', 95, false, false);


--
-- Data for Name: plan_progress; Type: TABLE DATA; Schema: public; Owner: runner
--

INSERT INTO public.plan_progress (user_id, plan_key, session_index, done) VALUES 
(v_user_id, '37', 0, true),
(v_user_id, '37', 1, true),
(v_user_id, '37', 2, true),
(v_user_id, '37', 3, true),
(v_user_id, '37', 4, true),
(v_user_id, '37', 5, true),
(v_user_id, '37', 6, true),
(v_user_id, '37', 7, true),
(v_user_id, '37', 8, true),
(v_user_id, '37', 9, true),
(v_user_id, '37', 10, true),
(v_user_id, '37', 11, true),
(v_user_id, '37', 12, true),
(v_user_id, '37', 13, true),
(v_user_id, '37', 14, true),
(v_user_id, '37', 15, true),
(v_user_id, '37', 16, true),
(v_user_id, '37', 17, true),
(v_user_id, '37', 18, true),
(v_user_id, '37', 19, true),
(v_user_id, '37', 20, true),
(v_user_id, '37', 21, true),
(v_user_id, '37', 22, true),
(v_user_id, '37', 23, true),
(v_user_id, '37', 24, true),
(v_user_id, '37', 25, true),
(v_user_id, '37', 26, true),
(v_user_id, '37', 27, true),
(v_user_id, '37', 28, true);


--
-- Data for Name: races; Type: TABLE DATA; Schema: public; Owner: runner
--

INSERT INTO public.races (user_id, name, date, distance, objective, result, created_at) VALUES 
(v_user_id, 'La Putéolienne', '2026-03-08', '5km', '00:40:00', '00:38:50', '2026-05-22 15:50:41'),
(v_user_id, 'Ecotrail', '2026-03-22', '10km', '01:30:00', '01:28:37', '2026-05-22 15:50:41'),
(v_user_id, 'Sine qua non', '2026-03-28', '10km', '01:24:00', '01:20:56', '2026-05-22 15:50:41'),
(v_user_id, '5km Harmonie Mutuelle', '2026-04-15', '5km', '00:39:00', '00:38:46', '2026-05-22 15:50:41'),
(v_user_id, 'Unicef Boulogne', '2026-05-10', '10km', '01:20:00', '01:19:03', '2026-05-22 15:50:41'),
(v_user_id, 'Adidas Paris', '2026-06-07', '10km', '01:20:00', NULL, '2026-05-22 15:50:41'),
(v_user_id, 'La course des princesses', '2026-06-28', '8km', NULL, NULL, '2026-05-22 15:50:41'),
(v_user_id, 'Gravelanza', '2026-06-13', '7km', '01:00:00', NULL, '2026-05-22 15:50:41'),
(v_user_id, '5km Marathon Relais', '2026-06-21', '5km', NULL, NULL, '2026-05-22 15:50:41'),
(v_user_id, 'La Parisienne', '2026-09-13', '10km', NULL, NULL, '2026-05-22 15:50:41'),
(v_user_id, '20km', '2026-10-11', '20km', NULL, NULL, '2026-05-22 15:50:41');


--
-- Data for Name: run_logs; Type: TABLE DATA; Schema: public; Owner: runner
--

INSERT INTO public.run_logs (user_id, planned_session_id, date, km, duration, allure, gap, dplus, bpm, run_type, notes, created_at, course_name, perceived_effort) VALUES 
(v_user_id, NULL, '2026-01-06', 3, '00:26:47', '08:55', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-01-12', 3.06, '00:29:14', '09:33', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-01-13', 3.64, '00:33:27', '09:11', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-01-16', 3.87, '00:34:06', '08:49', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-01-17', 6.09, '00:53:19', '08:45', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-01-19', 4.84, '00:43:15', '08:56', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-01-21', 4.2, '00:39:37', '09:26', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-01-24', 6.08, '00:52:51', '08:42', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-01-26', 4.85, '00:42:49', '08:50', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-01-28', 5.03, '00:42:48', '08:31', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-01-31', 6.03, '00:49:49', '08:16', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-02-02', 5.45, '00:45:05', '08:16', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-02-05', 6.01, '00:54:07', '09:00', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-02-07', 6.68, '00:54:11', '08:07', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-02-14', 5.11, '00:45:05', '08:49', NULL, NULL, 146, 'EF', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-02-27', 4.2, '00:35:04', '08:21', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-03-01', 5.17, '00:44:24', '08:35', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-03-04', 6.05, '00:52:24', '08:40', NULL, NULL, NULL, NULL, NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-03-11', 5.22, '00:45:14', '08:40', NULL, NULL, 154, 'EF', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-03-13', 6.18, '00:52:39', '08:31', NULL, NULL, NULL, 'FC', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-03-15', 8.59, '01:10:07', '08:10', NULL, NULL, NULL, 'FL', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-03-18', 4.42, '00:40:14', '09:06', NULL, NULL, 151, 'EF', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-03-24', 4.37, '00:40:03', '09:10', NULL, NULL, 147, 'EF', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-04-22', 6.16, '00:53:04', '08:37', NULL, NULL, 156, 'FL', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, 1919, '2026-05-25', 6.12, '0:52:50', '08:38', NULL, NULL, 153, 'FL', NULL, '2026-05-26 07:39:37', NULL, 'difficile'),
(v_user_id, 1917, '2026-05-23', 10.58, '1:35:15', '09:00', NULL, NULL, 162, 'SL', NULL, '2026-05-26 07:40:00', NULL, NULL),
(v_user_id, 1916, '2026-05-19', 5.86, '0:50:36', '08:38', NULL, NULL, 151, 'FC', NULL, '2026-05-26 07:40:30', NULL, NULL),
(v_user_id, 1915, '2026-05-18', 5.21, '0:48:14', '09:15', NULL, NULL, 146, 'EF', NULL, '2026-05-26 07:41:12', NULL, NULL),
(v_user_id, 1914, '2026-05-16', 9.04, '1:22:46', '09:09', NULL, NULL, 151, 'SL', NULL, '2026-05-26 07:41:38', NULL, NULL),
(v_user_id, 1913, '2026-05-12', 7.32, '1:03:02', '08:37', NULL, NULL, 154, 'FL', NULL, '2026-05-26 07:42:05', NULL, NULL),
(v_user_id, 1912, '2026-05-11', 5.18, '00:48:21', '09:20', NULL, NULL, 140, 'EF', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, 1911, '2026-05-10', 10.17, '01:30:03', '08:51', NULL, NULL, 168, 'Race', '10km Unicef', '2026-05-22 15:50:41', '10km Unicef', 'difficile'),
(v_user_id, 1910, '2026-05-05', 6.8, '1:00:00', '08:49', NULL, NULL, 146, 'FC', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, 1909, '2026-05-04', 5.29, '0:50:15', '09:30', NULL, NULL, 142, 'EF', NULL, '2026-05-26 10:46:58', NULL, NULL),
(v_user_id, 1908, '2026-05-01', 10.35, '01:30:55', '08:47', NULL, NULL, 146, 'SL', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, 1907, '2026-04-28', 6.55, '00:55:35', '08:29', NULL, NULL, 147, 'FC', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, 1906, '2026-04-26', 4.97, '00:45:08', '09:05', NULL, NULL, 156, 'EF', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, 1905, '2026-04-24', 7.53, '01:10:19', '09:20', NULL, NULL, 157, 'SL', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, 1903, '2026-04-20', 4.43, '00:45:08', '10:11', NULL, NULL, 156, 'EF', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, 1902, '2026-04-18', 8.03, '01:07:55', '08:27', NULL, NULL, 157, 'SL', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, NULL, '2026-03-28', 10, '01:20:56', '08:06', NULL, NULL, NULL, 'Race', 'Sine Qua Non', '2026-05-22 15:50:41', 'Sine Qua Non', 'moderee'),
(v_user_id, 1901, '2026-04-15', 5, '00:38:46', '07:45', NULL, NULL, 163, 'Race', '5km Harmonie Mutuelle', '2026-05-22 15:50:41', '5km Harmonie mutuelle', 'maximum'),
(v_user_id, 1900, '2026-04-13', 4.38, '00:40:10', '09:10', NULL, NULL, 148, 'EF', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, 1899, '2026-04-11', 8.07, '01:11:56', '08:55', NULL, NULL, 155, 'EF', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, 1898, '2026-04-07', 5.34, '00:46:08', '08:38', NULL, NULL, 154, 'FL', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, 1897, '2026-04-06', 4.88, '00:46:18', '09:29', NULL, NULL, 143, 'EF', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, 1896, '2026-04-04', 6.39, '01:00:03', '09:24', NULL, NULL, 145, 'SL', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, 1895, '2026-04-01', 4.12, '00:40:00', '09:43', NULL, NULL, 149, 'FC', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, 1894, '2026-03-30', 2.88, '00:25:00', '08:41', NULL, NULL, 147, 'EF', NULL, '2026-05-22 15:50:41', NULL, NULL),
(v_user_id, 1920, '2026-05-31', 8.26, '1:16:02', '09:12', NULL, NULL, 149, 'SL', NULL, '2026-06-01 06:55:45', NULL, 'difficile'),
(v_user_id, 1918, '2026-05-28', 4.3, '0:40:13', '09:21', NULL, NULL, 148, 'EF', NULL, '2026-05-28 06:53:08', NULL, 'difficile'),
(v_user_id, 1921, '2026-06-01', 4.2, '40:18', '09:36', NULL, NULL, 143, 'EF', NULL, '2026-06-01 12:14:02', NULL, 'facile'),
(v_user_id, 1922, '2026-06-03', 5.44, '46:56', '08:38', '08:37', 1, 150, 'FL', NULL, '2026-06-03 13:53:15', NULL, 'difficile'),
(v_user_id, NULL, '2026-03-22', 10.19, '01:28:37', '08:42', NULL, NULL, NULL, 'Race', 'Eco Trail', '2026-05-22 15:50:41', 'Eco Trail', 'difficile'),
(v_user_id, NULL, '2026-03-08', 5.23, '00:38:50', '07:26', NULL, NULL, NULL, 'Race', 'La Putéolienne', '2026-05-22 15:50:41', 'La Putéolienne', 'difficile');





