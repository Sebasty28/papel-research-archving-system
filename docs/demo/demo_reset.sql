-- PAPEL: put the demo papers back to their starting states.
--
-- For the capstone defence. Run it between practice runs, or on the morning, so
-- the whole demo can be given again from the top.
--
--     phpMyAdmin, SQL tab, with the site's database SELECTED on the left,
--     paste the whole file, Go.
--
-- ===========================================================================
-- WHAT IT TOUCHES, AND WHAT IT WILL NOT
-- ===========================================================================
-- Every statement is scoped to papers whose title begins with 'DEMO '. A real
-- student's paper is never read, moved or deleted by this file. If you have not
-- named your demo papers that way, this file does nothing at all, which is the
-- safe way round.
--
-- It CANNOT create a paper. A paper needs a real PDF on Google Drive, and SQL
-- cannot put one there. Papers are made by uploading them; see 1.5 of the
-- runbook. This only moves papers you already made back to where they started.
--
-- Safe to run twice. It ends with a line saying what it did.
--
-- The last statement reads no table on purpose: phpMyAdmin looks at the last
-- statement of a pasted script and switches database before running any of it,
-- so a script ending in a SELECT from a table can make every DATABASE() above
-- it answer for the wrong database.
-- ===========================================================================


-- ---------------------------------------------------------------------------
-- Step 1: every DEMO paper loses its review history.
--
-- The tracker on a card is drawn from approval_workflow, so without this a
-- paper put back to 'draft' still shows the approvals it collected on the last
-- run, and the panel sees a draft that has somehow been approved twice.
-- ---------------------------------------------------------------------------
DELETE w FROM approval_workflow w
  JOIN research_papers p ON p.paper_id = w.paper_id
 WHERE p.title LIKE 'DEMO %';
SET @workflows := ROW_COUNT();

-- The adviser's ticked checklist goes with it, for the same reason.
DELETE c FROM paper_checklist c
  JOIN research_papers p ON p.paper_id = c.paper_id
 WHERE p.title LIKE 'DEMO %';
SET @checklists := ROW_COUNT();


-- ---------------------------------------------------------------------------
-- Step 2: each DEMO paper goes back to the state its workflow needs.
--
-- Named one at a time rather than by a rule, so that a paper you have added
-- yourself is left exactly as it is.
-- ---------------------------------------------------------------------------
UPDATE research_papers SET current_status = 'draft'
 WHERE title = 'DEMO Draft Paper';
SET @s1 := ROW_COUNT();

UPDATE research_papers SET current_status = 'pending_faculty', upload_date = NOW()
 WHERE title = 'DEMO Waiting Adviser';
SET @s2 := ROW_COUNT();

-- upload_date is set to now on purpose: the withdraw window at the adviser
-- stage is the first 24 hours, and Workflow 5 is that window. A paper reset
-- yesterday would show no Withdraw button today.

UPDATE research_papers SET current_status = 'pending_admin'
 WHERE title = 'DEMO Waiting Coordinator';
SET @s3 := ROW_COUNT();

UPDATE research_papers SET current_status = 'draft'
 WHERE title = 'DEMO Returned Paper';
SET @s4 := ROW_COUNT();

UPDATE research_papers SET current_status = 'approved'
 WHERE title = 'DEMO Published Paper';
SET @s5 := ROW_COUNT();


-- ---------------------------------------------------------------------------
-- Step 3: 'DEMO Returned Paper' needs to look returned, not merely unfinished.
--
-- A returned paper is a draft WITH a review decision against it: that is what
-- puts it in the Needs Revision tab with feedback, rather than in Drafts. So
-- the row deleted in step 1 is written again, by the adviser who owns the
-- student. Skipped silently if no such paper or no adviser is found.
-- ---------------------------------------------------------------------------
SET @paper := (SELECT paper_id FROM research_papers WHERE title = 'DEMO Returned Paper' LIMIT 1);
SET @adviser := (SELECT user_id FROM users WHERE user_role = 'faculty' AND is_active = 1
                 ORDER BY user_id LIMIT 1);

INSERT INTO approval_workflow (paper_id, reviewer_id, review_level, status, feedback, reviewed_at, submitted_at)
SELECT @paper, @adviser, 'faculty', 'declined',
       'Please expand the Methodology section and correct the citation style in Chapter 2.',
       NOW(), NOW()
 WHERE @paper IS NOT NULL AND @adviser IS NOT NULL;
SET @returned := ROW_COUNT();


-- ---------------------------------------------------------------------------
-- Step 4: clear the manuscript requests made against DEMO papers.
--
-- A student may hold three at once, and a paper with one still open cannot be
-- asked about again, so Workflow 11 would have nothing to show on a second run.
-- Guarded: the table is not on every copy of the site.
-- ---------------------------------------------------------------------------
SET @has_requests := (
  SELECT COUNT(*) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manuscript_requests'
);
SET @sql := IF(@has_requests > 0,
  'DELETE r FROM manuscript_requests r JOIN research_papers p ON p.paper_id = r.paper_id
     WHERE p.title LIKE ''DEMO %''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @requests := IF(@has_requests > 0, 'cleared', 'no manuscript_requests table on this copy');


-- ---------------------------------------------------------------------------
-- Step 5: un-save the DEMO papers, so the bookmark can be pressed again.
-- ---------------------------------------------------------------------------
DELETE f FROM paper_favorites f
  JOIN research_papers p ON p.paper_id = f.paper_id
 WHERE p.title LIKE 'DEMO %';
SET @saved := ROW_COUNT();


-- ---------------------------------------------------------------------------
-- What it did. Reads no table, for the reason at the top.
--
-- The figures count rows it CHANGED, so run it straight after a demo and they
-- read 1; run it twice and they read 0, because the papers are already where
-- they should be. Both are correct. A row of zeros on the FIRST run after a
-- demo is the one to look at: it means no paper matched, so check the titles
-- begin with 'DEMO ' exactly, capitals included.
-- ---------------------------------------------------------------------------
SELECT CONCAT(
  'Cleared ', @workflows, ' review decision(s) and ', @checklists, ' checklist(s). ',
  'Reset: draft=', @s1, ', with adviser=', @s2, ', with coordinator=', @s3,
  ', returned=', @s4, ', published=', @s5, '. ',
  'Returned-paper feedback written: ', @returned, '. ',
  'Manuscript requests: ', @requests, '. ',
  'Saved papers cleared: ', @saved, '.'
) AS result;
