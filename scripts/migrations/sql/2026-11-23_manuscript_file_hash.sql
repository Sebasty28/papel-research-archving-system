-- PAPEL: a fingerprint for every manuscript PDF, so the same file cannot be
-- filed twice.
--
-- For a server with no shell: phpMyAdmin, SQL tab, with the site's database
-- SELECTED in the list on the left, paste the whole file, Go.
--
-- Safe to run twice: each step asks information_schema first and skips itself.
--
-- Until it has been run, the site files papers exactly as before; it simply has
-- nothing to compare a PDF against, so only the title (and the same file in both
-- manuscript boxes) is checked. Papers filed before this have no fingerprint
-- either. The Storage Folder page fills them in from Google Drive's own checksum
-- as it checks each PDF, and scripts/backfill_manuscript_hashes.php does the same
-- from a shell.
--
-- The last statement reads no table on purpose. phpMyAdmin looks at the last
-- statement of a pasted script and switches to whatever database it names
-- BEFORE running any of it, so a script ending in a SELECT from
-- information_schema makes every DATABASE() above it answer
-- 'information_schema' and the whole thing does nothing. See "phpMyAdmin looks
-- at the last statement of a pasted script" in CLAUDE.md.

-- ---------------------------------------------------------------------------
-- Step 0: is there a table to add it to?
--
-- This one adds a COLUMN to a table an earlier file creates, so it has to ask
-- about the table as well as the column: asking only "has it the column?"
-- answers no on a copy that has not run 2026-10-21_catch_up.sql either, and the
-- ALTER then stops the script with "#1146 - Table ... doesn't exist" instead of
-- skipping itself. That happened on staging on 23 November 2026. A copy still
-- behind is told to run the catch-up first and nothing else here is attempted.
-- ---------------------------------------------------------------------------
SET @tbl := (
  SELECT COUNT(*) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'paper_manuscripts'
);

-- ---------------------------------------------------------------------------
-- Step 1: paper_manuscripts.file_hash
-- ---------------------------------------------------------------------------
SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'paper_manuscripts'
     AND COLUMN_NAME = 'file_hash'
);
SET @sql := IF(@tbl > 0 AND @col = 0,
  'ALTER TABLE paper_manuscripts
     ADD COLUMN file_hash CHAR(64) NULL DEFAULT NULL
     COMMENT ''SHA-256 of the PDF, to refuse the same file filed twice; NULL for a paper filed before it was kept''
     AFTER file_size',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @step1 := IF(@tbl = 0, 'skipped, there is no paper_manuscripts table yet',
             IF(@col = 0, 'added paper_manuscripts.file_hash',
                          'paper_manuscripts.file_hash was already there'));

-- ---------------------------------------------------------------------------
-- Step 2: the index the lookup uses
-- ---------------------------------------------------------------------------
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'paper_manuscripts'
     AND INDEX_NAME = 'idx_file_hash'
);
SET @sql2 := IF(@tbl > 0 AND @idx = 0,
  'ALTER TABLE paper_manuscripts ADD KEY idx_file_hash (file_hash)',
  'SELECT 1');
PREPARE s2 FROM @sql2; EXECUTE s2; DEALLOCATE PREPARE s2;
SET @step2 := IF(@tbl = 0, 'skipped, there is no paper_manuscripts table yet',
             IF(@idx = 0, 'added the index idx_file_hash',
                          'the index idx_file_hash was already there'));

-- ---------------------------------------------------------------------------
-- What it did. Reads no table, for the reason at the top.
-- ---------------------------------------------------------------------------
SELECT CONCAT('Step 1: ', @step1, '.  Step 2: ', @step2, '.',
  IF(@tbl = 0,
     '  NOTHING WAS CHANGED: run scripts/migrations/sql/2026-10-21_catch_up.sql first, which creates paper_manuscripts, then run this file again.',
     '')) AS result;
