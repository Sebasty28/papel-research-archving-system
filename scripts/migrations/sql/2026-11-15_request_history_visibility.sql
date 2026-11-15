-- Clear a student's My Library History without deleting the librarian's record.
-- Safe to run again. Select the site's database in phpMyAdmin before pasting.
SET @has_column := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'manuscript_requests'
     AND COLUMN_NAME = 'student_hidden_at'
);
SET @migration_sql := IF(@has_column = 0,
  'ALTER TABLE manuscript_requests ADD COLUMN student_hidden_at DATETIME NULL DEFAULT NULL AFTER created_at',
  'SELECT 1');
PREPARE migration_step FROM @migration_sql;
EXECUTE migration_step;
DEALLOCATE PREPARE migration_step;
SELECT IF(@has_column = 0, 'added student_hidden_at', 'student_hidden_at was already there') AS result;
