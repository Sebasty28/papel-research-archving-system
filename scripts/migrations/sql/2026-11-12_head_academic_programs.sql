-- PAPEL: the head's position is the "Head of Academic Programs".
--
-- The position was listed in staff_positions() as "Head of Academic Affairs"
-- while every page around it said "Head of Academic Programs": the Director's
-- console offered the old name in its Position list and translated it to the
-- new one as it drew its tabs. There is now one name. Rows made before this
-- still carry the old one in users.title (and in secondary_position where a
-- Director handed it out as a second post), and the position is stored as
-- written, so they have to be renamed or those pages say the wrong thing.
--
-- Reading an old row keeps working either way: staff_position_aliases() in
-- config/core.php resolves the old name, which is the tolerant path for a copy
-- of the site this has not been run on.
--
-- For a server with no shell: phpMyAdmin, SQL tab, with the site's database
-- SELECTED in the list on the left, paste the whole file, Go.
--
-- Safe to run twice: the second run finds nothing to rename and says 0.
--
-- The last statement reads no table on purpose. phpMyAdmin looks at the last
-- statement of a pasted script and switches to whatever database it names
-- BEFORE running any of it, so a script ending in a SELECT from
-- information_schema makes every DATABASE() above it answer
-- 'information_schema' and the whole thing does nothing. See "phpMyAdmin looks
-- at the last statement of a pasted script" in CLAUDE.md.

-- ---------------------------------------------------------------------------
-- Step 1: the primary post, users.title
-- ---------------------------------------------------------------------------
UPDATE users
   SET title = 'Head of Academic Programs'
 WHERE title = 'Head of Academic Affairs';
SET @titles := ROW_COUNT();

-- ---------------------------------------------------------------------------
-- Step 2: the second post, users.secondary_position.
--
-- Guarded, because a copy of the site that has not run
-- 2026-11-12_secondary_position.sql has no such column and a bare UPDATE
-- naming it would stop the script here.
-- ---------------------------------------------------------------------------
SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'users'
     AND COLUMN_NAME = 'secondary_position'
);
SET @sql := IF(@col > 0,
  'UPDATE users SET secondary_position = ''Head of Academic Programs''
    WHERE secondary_position = ''Head of Academic Affairs''',
  'SELECT 1');
-- ROW_COUNT() is read before DEALLOCATE, which resets it: reading it after
-- reported 0 for an UPDATE that had in fact renamed a row.
PREPARE s FROM @sql; EXECUTE s;
SET @seconds := IF(@col > 0, ROW_COUNT(), 0);
DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- What it did. Reads no table, for the reason at the top.
-- ---------------------------------------------------------------------------
SELECT CONCAT(
  'Step 1: renamed ', @titles, ' stored title(s) to Head of Academic Programs.  ',
  'Step 2: ', IF(@col > 0,
    CONCAT('renamed ', @seconds, ' second post(s).'),
    'users.secondary_position is not on this copy; nothing to rename.')
) AS result;
