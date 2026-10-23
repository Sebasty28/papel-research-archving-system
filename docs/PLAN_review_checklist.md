# Plan: a review checklist that fits the paper

Status: **implemented** on 2026-10-23 and not yet committed. Written as a plan before any code
(approved as it stands); section 0 records where the build departs from it. Read `CLAUDE.md`
first; the traps it lists apply here, in particular "A copy of the database can be behind the
code".

---

## 0. Outcome (read this first)

Built as planned. One new nullable column (`paper_checklist.items_json`), one shared editor,
no change to what the Coordinator or the public can do.

**Where the build differs from the plan, or adds to it**

- **The Feasibility Study list comes from the studies on file**, not an outline: the folder
  supplied holds finished BSIE manuscripts. The two whose contents pages read cleanly (Shampease,
  VegiMix) agree: Executive Summary, then the Marketing, Technical, Management, Financial and
  Socio-Economic Aspects, then References and Appendices. The list is those eight. A study that
  also has a conclusion chapter is covered by adding it for that paper.
- **`tests/fixtures.py` now clears `paper_manuscripts` on teardown.** The table has no foreign
  key, so a real submission by a test left rows behind; 44 had piled up from earlier runs and were
  removed.
- **The dropdown is `width: auto`.** Left alone it was stretched to the whole row by other rules on
  the site and pushed "Edit sections" and "Tick all" onto a second line, unlike the original
  heading, which had them on its right.
- **Local MariaDB failed while the column was being added** and the database folder was rebuilt
  from its dump (see the memory note `reference_mariadb_recovery`). Nothing in the feature caused
  it, but the `ALTER` on a table whose pages were already damaged is what exposed it. Every table
  came back with the same number of rows.

**Checked against the running site**: 96 unit cases (`tests/_checklist_unit.php`); 21 HTTP cases
(`tests/m14_review_checklist.py`); 34 browser checks in a real Chrome (dropdown, edit mode with
typed input, the switch bar, the posted fields, the approve window on the desk, a return, the
author's page, a phone width, the dark theme); 30 cases on a copy of the database put back to its
14 October shape, served with mysqli throwing as on PHP 8.1+ (`tests/m13_old_schema.py`), which now
also covers the checklist column. Putting the previous approve handler back made the second-round
case fail with the 500 page, which is the failure staging would have shown.

---

## 1. What was asked

Turn the "IMRaD Sections" heading of the Review Checklist into a dropdown that switches the list
to Feasibility Study, Full Manuscript and others; let the reviewer add, remove or change sections,
or write a new one, **for that paper only**.

Answers given while planning: the lists are IMRaD, Undergraduate Thesis, Capstone Project (the
last two take the old Full Manuscript chapters; "Full Manuscript" goes) and Feasibility Study,
with a Blank list kept; the Feasibility outline will come from the user's PDF; the Coordinator
sees the adviser's list read-only.

## 2. What the code did (findings that shaped the design)

1. **Eleven fixed yes/no columns** in `paper_checklist`, one row per paper (`unique_paper`).
2. Written **only on Approve**, by the adviser's desk handler; Return saves none.
3. Drawn in **two places** (`app/review_paper.php` and the approve window in
   `includes/review_console.php`) and read by the author in `app/student/paper_details.php`.
4. **A latent bug**: the save was a plain INSERT, so approving a paper a second time (returned by
   the Coordinator, revised, sent again) hit the unique key: silent on PHP 8.0, an exception on
   8.1+ (staging), ending in the 500 page with the paper unforwarded.
5. The Coordinator's boxes looked editable, and nothing they changed was kept.
6. Two of the three theses on record were filed as IMRAD, so the starting list has to follow the
   format before the type.

## 3. Design

- **Lists** in code (`paper_checklist_templates()`): `imrad`, `undergrad_thesis`, `capstone`,
  `feasibility`, `blank`.
- **Starting list** (`paper_checklist_default()`): Feasibility Study type gets Feasibility; filed
  as IMRAD only gets IMRaD; Journal and Conference papers get IMRaD; anything else gets the chapters
  list named for its type.
- **Storage**: `paper_checklist.items_json` holds the list as left (template key, edited flag,
  labels, ticks); a row without it is an old row and is read from the eleven columns.
- **Server**: `paper_checklist_clean()` decides what a post may hold (20 sections, 80 characters,
  tags and control characters stripped, repeats dropped, at least one); the old eleven field names
  are still accepted; `paper_checklist_save()` is an upsert; `paper_checklist_load()` returns one
  shape for both kinds of row.
- **Editor**: `includes/review_checklist.php`, used by both places. Dropdown, Edit sections (rename,
  remove, add by typing, Reset), Tick all, a counter, and a "Replace / Keep mine" bar when switching
  lists would throw away edits. Posts `ck_template`, `ck_label[n]`, `ck_ok[n]`.
- **Coordinator and others** read the list as plain rows.
- **Server without the column**: ships with the four things CLAUDE.md asks for (the structure in
  `database/schema.sql`, `scripts/migrations/sql/2026-10-23_checklist_items.sql`, a
  `schema_gaps()` entry, and a tolerant path: three lists, no editing, a notice).

## 4. Files

Changed: `config/core.php`, `app/review_paper.php`, `app/faculty/faculty_review_dashboard.php`,
`includes/review_console.php`, `app/student/paper_details.php`, `includes/paper_record_css.php`,
`database/schema.sql`, `CLAUDE.md`, `tests/m13_old_schema.py`, `tests/fixtures.py`.
New: `includes/review_checklist.php`, `scripts/migrations/run_checklist_items_migration.php`,
`scripts/migrations/sql/2026-10-23_checklist_items.sql`, `tests/m14_review_checklist.py`,
`tests/_checklist_unit.php`.

## 5. Out of scope

Editing the ready-made lists from a screen; reusable lists per adviser; reordering rows; saving the
checklist when a paper is returned; autosaving while the adviser reads; lists of their own for
Journal Article and Conference Paper (they start on IMRaD).
