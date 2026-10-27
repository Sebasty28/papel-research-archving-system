-- PAPEL: somewhere for manuscript requests to live (manuscript_requests), and a kind of
-- notification for them.
--
-- For a copy of the site with no shell to run scripts/migrations/*.php from (the staging
-- host has phpMyAdmin and nothing else). It does what run_manuscript_requests_migration.php
-- does, in plain SQL.
--
-- WHY
--   The table was added by that migration and never made it into database/schema.sql, so a
--   copy built from that file (staging, on 14 October) has no such table. The public paper
--   page asks about a student's grant on every visit, and on PHP 8.1+ a query on a missing
--   table ends the request: every student who opened a paper in the public repository got
--   "Something Went Wrong". The code now copes without the table (no requests, and a note
--   saying they are not open yet); this file is what turns requests on.
--
-- HOW TO RUN IT
--   1. phpMyAdmin, then click this site's database in the left-hand list.
--   2. The SQL tab. Paste the whole of this file. Press Go.
--   3. Each step checks first and skips itself if it is already done, so it is safe to run
--      twice. The last result says what it did.
--   4. Open /app/student/upload_diagnostics.php while signed in as staff. Under "Database"
--      it should no longer list manuscript_requests or notification_manuscript.
--
-- Nothing here deletes or changes a row.


-- 1. The table. ------------------------------------------------------------------------
SET @has_table := (SELECT COUNT(*) FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'manuscript_requests');
SET @stmt := IF(@has_table = 0,
    'CREATE TABLE manuscript_requests (
        request_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
        paper_id        INT(11)      NOT NULL,
        student_user_id INT(11)      NOT NULL,
        status          ENUM(''pending'',''granted'',''denied'') NOT NULL DEFAULT ''pending'',
        duration_hours  TINYINT UNSIGNED NULL,
        granted_by      INT(11)      NULL,
        granted_at      DATETIME     NULL,
        expires_at      DATETIME     NULL,
        denied_by       INT(11)      NULL,
        denied_at       DATETIME     NULL,
        created_at      DATETIME     NOT NULL,
        PRIMARY KEY (request_id),
        KEY idx_student (student_user_id),
        KEY idx_paper (paper_id),
        KEY idx_status_expires (status, expires_at),
        KEY idx_created (created_at)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'SELECT ''skipped: manuscript_requests already exists'' AS result');
PREPARE step FROM @stmt;
EXECUTE step;
DEALLOCATE PREPARE step;


-- 2. 'manuscript' as a kind of notification. --------------------------------------------
-- An ENUM stores an unknown value as an empty string without a word (see CLAUDE.md), so
-- without this a notice about a request would lose its kind.
SET @has_kind := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME   = 'notifications'
                     AND COLUMN_NAME  = 'notification_type'
                     AND COLUMN_TYPE LIKE '%''manuscript''%');
SET @stmt := IF(@has_kind = 0,
    'ALTER TABLE notifications MODIFY notification_type
        ENUM(''submission'',''approval'',''decline'',''reminder'',''comment'',''security'',''support'',''account'',''manuscript'') NOT NULL',
    'SELECT ''skipped: notification_type already allows manuscript'' AS result');
PREPARE step FROM @stmt;
EXECUTE step;
DEALLOCATE PREPARE step;


-- Result: what this run did. -------------------------------------------------------
-- This has to be the last statement and it must read no table. phpMyAdmin looks at the
-- last statement of a script and, if it reads from a database, switches to that database
-- before running ANY of it, so every DATABASE() above would point at the wrong place.
SELECT CONCAT('manuscript_requests: ', IF(@has_table = 0, 'created', 'already there'),
              ' | notification_type manuscript: ', IF(@has_kind = 0, 'added', 'already there')) AS result;
