-- PAPEL: "Thesis" is the Undergraduate Thesis.
--
-- "thesis" is the retired paper-type code for what the upload form now calls an
-- Undergraduate Thesis ('undergrad_thesis'). Papers filed before the rename still
-- carry the old code, so every filter offered "Thesis" beside "Undergraduate
-- Thesis" and the analytics charts drew the same kind of paper as two slices.
-- The owner asked for them to be one category (20 November 2026).
--
-- Reading an old row keeps working either way: paper_type_aliases() in
-- config/core.php reads "thesis" as Undergraduate Thesis in every label, filter
-- and chart, which is the tolerant path for a copy of the site this has not been
-- run on. This renames what is on file, in the live papers and in the archive.
-- Nothing writes the old code back: the upload form cannot choose it.
--
-- For a server with no shell: phpMyAdmin, SQL tab, with the site's database
-- SELECTED in the list on the left, paste the whole file, Go.
--
-- Safe to run twice: the second run finds nothing to rename and says 0.
--
-- The last statement reads no table on purpose. phpMyAdmin looks at the last
-- statement of a pasted script and switches to whatever database it names
-- BEFORE running any of it. See "phpMyAdmin looks at the last statement of a
-- pasted script" in CLAUDE.md.
--
-- PHP twin, for a server with a shell:
--     php scripts/migrations/run_thesis_paper_type_migration.php

-- ---------------------------------------------------------------------------
-- Step 1: live papers
-- ---------------------------------------------------------------------------
UPDATE research_papers
   SET paper_type = 'undergrad_thesis'
 WHERE LOWER(TRIM(paper_type)) = 'thesis';
SET @live := ROW_COUNT();

-- ---------------------------------------------------------------------------
-- Step 2: the archive, which is meant to outlive its papers and keeps their type
-- ---------------------------------------------------------------------------
UPDATE papers_archive
   SET paper_type = 'undergrad_thesis'
 WHERE LOWER(TRIM(paper_type)) = 'thesis';
SET @archived := ROW_COUNT();

-- ---------------------------------------------------------------------------
-- What it did. Reads no table, for the reason at the top.
-- ---------------------------------------------------------------------------
SELECT CONCAT(
  'Step 1: renamed ', @live, ' live paper(s) from Thesis to Undergraduate Thesis.  ',
  'Step 2: renamed ', @archived, ' archived paper(s).'
) AS result;
