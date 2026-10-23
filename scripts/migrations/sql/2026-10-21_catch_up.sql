-- PAPEL: bring a copy of the database up to the schema of 21 October 2026.
--
-- For a copy of the site that has no shell to run scripts/migrations/*.php from:
-- the staging host (InfinityFree) has phpMyAdmin and nothing else. This file does
-- what run_supporting_doc_type_repair.php and run_paper_manuscripts_migration.php
-- do, in plain SQL.
--
-- HOW TO RUN IT
--   1. phpMyAdmin, then click this site's database in the left-hand list.
--   2. The SQL tab. Paste the whole of this file. Press Go.
--   3. Every step checks first and skips itself if its work is already done, so it
--      is safe to run twice. A step that did nothing says why in its result.
--   4. Open /app/student/upload_diagnostics.php while signed in as staff. Under
--      "Database" it should read "No gaps".
--
-- WHAT IT DOES
--   1. supporting_documents.document_type: ENUM of four values -> VARCHAR(50).
--      The ENUM had no room for 'copyright_doc', and a value outside an ENUM is
--      refused on a strict server or stored as an empty string on a lenient one.
--   2. supporting_documents.document_name: added. The name a student gives an
--      optional document. Without it the faculty desk and the paper pages fail
--      with "Unknown column 'document_name'", which on staging is the 500 page
--      that appears when an adviser signs in and has a paper waiting.
--   3. Rows already written are read back: a document staged as
--      "<paper id>_copyright_doc_<file>.pdf" is the copyright document. A row
--      whose type came out empty is repaired the same way the script does it.
--   4. paper_manuscripts: the table that lets one paper carry two manuscript files
--      (IMRAD and Full Manuscript), and one row for each paper that already has a
--      file. The archived papers are filed too when papers_archive has the columns
--      that needs, and skipped, with a line saying so, when it does not.
--
-- Nothing here deletes data.


-- 1. document_type: ENUM -> VARCHAR(50) -------------------------------------------
SET @is_enum := (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'supporting_documents'
                    AND COLUMN_NAME  = 'document_type'
                    AND DATA_TYPE    = 'enum');
SET @stmt := IF(@is_enum > 0,
    'ALTER TABLE supporting_documents MODIFY document_type VARCHAR(50) NOT NULL DEFAULT ''other''',
    'SELECT ''1. skipped: document_type is not an ENUM any more'' AS result');
PREPARE step FROM @stmt;
EXECUTE step;
DEALLOCATE PREPARE step;


-- 2. document_name ----------------------------------------------------------------
SET @has_name := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME   = 'supporting_documents'
                     AND COLUMN_NAME  = 'document_name');
SET @stmt := IF(@has_name = 0,
    'ALTER TABLE supporting_documents ADD COLUMN document_name VARCHAR(255) NULL AFTER document_type',
    'SELECT ''2. skipped: document_name already exists'' AS result');
PREPARE step FROM @stmt;
EXECUTE step;
DEALLOCATE PREPARE step;


-- 3. Read the rows already written back into their type ----------------------------
-- Only an empty type, or 'other' with no name, can be one of the two kinds the old
-- column could not hold. The last part of the stored path is the file, whether the
-- path is relative or a whole address.
UPDATE supporting_documents
   SET document_type = 'copyright_doc'
 WHERE (document_type = ''
        OR (document_type = 'other' AND (document_name IS NULL OR document_name = '')))
   AND SUBSTRING_INDEX(file_path, '/', -1) REGEXP '^[0-9]+_copyright_doc_';

-- Staged by the current uploader and not the copyright document: an optional one.
UPDATE supporting_documents
   SET document_type = 'other'
 WHERE document_type = ''
   AND SUBSTRING_INDEX(file_path, '/', -1) REGEXP '^[0-9]+_';

-- Written by the older upload page, which left only the copyright document blank.
UPDATE supporting_documents
   SET document_type = 'copyright_doc'
 WHERE document_type = '';


-- 4a. paper_manuscripts -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS paper_manuscripts (
    manuscript_id  INT(11)      NOT NULL AUTO_INCREMENT,
    paper_id       INT(11)      NOT NULL,
    format         VARCHAR(20)  NOT NULL,
    file_path      VARCHAR(500) NOT NULL DEFAULT '',
    gdrive_file_id VARCHAR(255) DEFAULT NULL,
    file_size      BIGINT(20)   DEFAULT NULL,
    uploaded_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (manuscript_id),
    UNIQUE KEY uq_paper_format (paper_id, format)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- 4b. Each paper's existing file, filed under the format it stands for. A single
-- recognised name in manuscript_type says it outright; two names, or none, fall
-- back to the paper_format column. Drafts are left out: the PDF a draft once kept
-- in uploads/drafts was never read back and is not a manuscript.
INSERT IGNORE INTO paper_manuscripts (paper_id, format, file_path, gdrive_file_id, file_size)
SELECT paper_id,
       CASE
         WHEN manuscript_type = 'Full Manuscript' THEN 'full'
         WHEN manuscript_type = 'IMRAD'           THEN 'imrad'
         WHEN paper_format    = 'Manuscript'      THEN 'full'
         ELSE 'imrad'
       END,
       COALESCE(file_path, ''), NULLIF(gdrive_file_id, ''), file_size
  FROM research_papers p
 WHERE (COALESCE(file_path, '') <> '' OR COALESCE(gdrive_file_id, '') <> '')
   AND NOT (COALESCE(current_status, '') = 'draft' AND file_path LIKE 'uploads/drafts/%')
   AND NOT EXISTS (SELECT 1 FROM paper_manuscripts m WHERE m.paper_id = p.paper_id);

-- The same for the archive, when it has every column that reads.
SET @arch_ok := (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'papers_archive'
                    AND COLUMN_NAME IN ('manuscript_type', 'paper_format', 'current_status',
                                        'file_path', 'gdrive_file_id', 'file_size'));
SET @stmt := IF(@arch_ok = 6,
    'INSERT IGNORE INTO paper_manuscripts (paper_id, format, file_path, gdrive_file_id, file_size)
     SELECT paper_id,
            CASE
              WHEN manuscript_type = ''Full Manuscript'' THEN ''full''
              WHEN manuscript_type = ''IMRAD''           THEN ''imrad''
              WHEN paper_format    = ''Manuscript''      THEN ''full''
              ELSE ''imrad''
            END,
            COALESCE(file_path, ''''), NULLIF(gdrive_file_id, ''''), file_size
       FROM papers_archive p
      WHERE (COALESCE(file_path, '''') <> '''' OR COALESCE(gdrive_file_id, '''') <> '''')
        AND NOT (COALESCE(current_status, '''') = ''draft'' AND file_path LIKE ''uploads/drafts/%'')
        AND NOT EXISTS (SELECT 1 FROM paper_manuscripts m WHERE m.paper_id = p.paper_id)',
    'SELECT ''4b. skipped: papers_archive is missing a column this needs'' AS result');
PREPARE step FROM @stmt;
EXECUTE step;
DEALLOCATE PREPARE step;


-- Result: what this run did. -------------------------------------------------------
-- This has to be the last statement and it must read no table. phpMyAdmin looks at
-- the last statement of a script and, if it reads from a database, switches to that
-- database before running ANY of it. A closing SELECT from information_schema made
-- every DATABASE() above point at information_schema, so step 1 skipped itself and
-- step 2 was refused with "#1044 Access denied ... to database 'information_schema'".
SELECT CONCAT('1. document_type: ', IF(@is_enum > 0, 'changed to VARCHAR(50)', 'already done')) AS step_1,
       CONCAT('2. document_name: ', IF(@has_name = 0, 'added', 'already there')) AS step_2,
       CONCAT('4b. archive: ', IF(@arch_ok = 6, 'checked', 'skipped, papers_archive lacks a column')) AS step_4b;
