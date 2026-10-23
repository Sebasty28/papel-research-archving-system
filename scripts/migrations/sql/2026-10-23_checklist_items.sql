-- PAPEL: room for a review checklist that can be edited (paper_checklist.items_json).
--
-- For a copy of the site with no shell to run scripts/migrations/*.php from (the staging
-- host has phpMyAdmin and nothing else). It does what run_checklist_items_migration.php
-- does, in plain SQL.
--
-- HOW TO RUN IT
--   1. phpMyAdmin, then click this site's database in the left-hand list.
--   2. The SQL tab. Paste the whole of this file. Press Go.
--   3. It checks first and skips itself if the column is already there, so it is safe to
--      run twice. The last result says which it did.
--   4. Open /app/student/upload_diagnostics.php while signed in as staff. Under
--      "Database" it should read "No gaps".
--
-- WHAT IT DOES
--   Adds paper_checklist.items_json, where the Research Adviser's checklist is kept as the
--   adviser left it: the list chosen, the sections (added, removed or renamed for that one
--   paper) and which were ticked. Checklists already recorded keep their eleven yes/no
--   columns and are read as before. Until this is run the site still works: the checklist is
--   limited to the IMRaD, Undergraduate Thesis and Capstone Project lists as they stand.
--
-- Nothing here deletes or changes a row.


SET @has_items := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'paper_checklist'
                      AND COLUMN_NAME  = 'items_json');
SET @stmt := IF(@has_items = 0,
    'ALTER TABLE paper_checklist ADD COLUMN items_json TEXT NULL AFTER full_references',
    'SELECT ''skipped: items_json already exists'' AS result');
PREPARE step FROM @stmt;
EXECUTE step;
DEALLOCATE PREPARE step;


-- Result: what this run did. -------------------------------------------------------
-- This has to be the last statement and it must read no table. phpMyAdmin looks at the
-- last statement of a script and, if it reads from a database, switches to that database
-- before running ANY of it, so every DATABASE() above would point at the wrong place.
SELECT CONCAT('paper_checklist.items_json: ', IF(@has_items = 0, 'added', 'already there')) AS result;
