-- PAPEL: a second post the same person holds, and the adviser's new name.
--
-- For a server with no shell: phpMyAdmin, SQL tab, with the site's database
-- SELECTED in the list on the left, paste the whole file, Go.
--
-- Safe to run twice: each step asks information_schema first and skips itself.
--
-- The last statement reads no table on purpose. phpMyAdmin looks at the last
-- statement of a pasted script and switches to whatever database it names
-- BEFORE running any of it, so a script ending in a SELECT from
-- information_schema makes every DATABASE() above it answer
-- 'information_schema' and the whole thing does nothing. See "phpMyAdmin looks
-- at the last statement of a pasted script" in CLAUDE.md.

-- ---------------------------------------------------------------------------
-- Step 1: users.secondary_position
-- ---------------------------------------------------------------------------
SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'users'
     AND COLUMN_NAME = 'secondary_position'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE users
     ADD COLUMN secondary_position VARCHAR(50) NULL DEFAULT NULL
     COMMENT ''A second post the same person holds, by name; see staff_positions()''
     AFTER admin_level',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @step1 := IF(@col = 0, 'added users.secondary_position', 'users.secondary_position was already there');

-- ---------------------------------------------------------------------------
-- Step 2: the adviser is the Subject Adviser now, not the Research Adviser.
-- The name is stored as written, so rows made before the rename still say the
-- old one. Reading them still works (staff_position_aliases() in
-- config/core.php), but every page that prints the title unchanged would say
-- the wrong thing.
-- ---------------------------------------------------------------------------
UPDATE users SET title = 'Subject Adviser' WHERE title = 'Research Adviser';
SET @titles := ROW_COUNT();

SET @col2 := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'users'
     AND COLUMN_NAME = 'secondary_position'
);
SET @sql2 := IF(@col2 > 0,
  'UPDATE users SET secondary_position = ''Subject Adviser'' WHERE secondary_position = ''Research Adviser''',
  'SELECT 1');
PREPARE s2 FROM @sql2; EXECUTE s2; DEALLOCATE PREPARE s2;

-- ---------------------------------------------------------------------------
-- What it did. Reads no table, for the reason at the top.
-- ---------------------------------------------------------------------------
SELECT CONCAT(
  'Step 1: ', @step1, '.  ',
  'Step 2: renamed ', @titles, ' stored title(s) to Subject Adviser.'
) AS result;
