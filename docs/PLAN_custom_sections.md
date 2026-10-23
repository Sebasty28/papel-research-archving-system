# Plan: sections of the author's own in "Which sections will you submit?"

Status: **implemented** on 2026-10-23 and not yet committed. Written as a plan before any code
(approved as it stands); section 0 records where the build departs from it. Read `CLAUDE.md`
first, in particular "Which written sections a paper was filed with" and "Sections of the
author's own"; the traps they list apply here.

---

## 0. Outcome (read this first)

Built as planned, with **no migration, no new column and no change to `ArchiveRepository`**:
everything rides inside `research_papers.imrad_content`, so a copy of the site that is behind
the code (staging) needs nothing run before it can take this.

**Where the build differs from the plan, or adds to it**

- **Keys run to `custom_24`, not `custom_12`.** Twelve sections is still the most. A section taken
  out while it holds text is kept, struck through, with an Undo, until the answer is given, and
  keeps its key meanwhile. Replacing a whole list of written-in sections with a ready-made one
  needed keys for the new rows as well, and with only twelve the last chapter was dropped without a
  word (found by the browser test).
- **A name equal to a standard section is dropped only when that section is being submitted.**
  The plan said "never equal to a standard name". A thesis has to put References after its
  chapters, and a standard References box would put it before Chapter 1, so a section of their own
  called References is allowed when the standard one is left out.
- **Choosing a ready-made list unticks the standard sections** (the Abstract stays). The plan only
  said it fills the rows. A thesis student who clicked "Undergraduate Thesis" and still had the six
  ticked would have been asked for Introduction, Methodology and the chapters. "Abstract only" and
  "All sections" leave the rows alone, so nothing typed is thrown away by a stray click.
- **At the limit only the Add button is disabled, not the name box.** A disabled input gives up
  focus, so the next Enter landed on the page and answered the whole question. Found by the browser
  test; Enter in the box now explains the limit.
- **A box made after the page loads is wired to the browser-copy autosave by `addEditor()`.** The
  six are wired at load, and a contenteditable raises no input event on the form, so typing in a
  new box saved nothing. Found when the test for "choosing does not autosave" turned out to be
  looking at its own simulated clicks.
- **`paper_sections_for_display()` counts a section holding only a picture or a table as written**,
  for the six as well. The three readers used to test the plain text alone, which hid a section made
  of one photograph although submit had accepted it.
- **The dialog got `max-height` and a body that scrolls**, and the editor title carries the whole
  name as a tooltip (a long name is cut short on the bar).
- **Names are escaped where markup is built** (`escHtml()`): the Step 4 explanation and the
  extraction notice are handed to the page as HTML, and until now only held fixed text.

**Checked against the running site**: 126 unit cases (`tests/_scope_unit.php`, 51 of them new);
74 HTTP cases (`tests/m10_section_scope.py`, SC-45 to SC-74 are new; four real submissions to Google
Drive, all removed afterwards); 74 + 12 checks in a real Chrome (the dialog and boxes at 1300px, the
dialog and boxes at 390px, the dark theme, the browser copy coming back, escaping, no autosave on
choosing) and the 30 of the earlier dialog script still passing; m11 (15), m14 (24) and
`_checklist_unit.php` (100) unchanged. The browser scripts are not in the repository.

---

## 1. What was asked

"In the selection of the which section will you submit. Can you add a Custom section. Wherein they
can type or select based on Full Manuscript, Undergraduate Thesis or Feasibility Study."

## 2. What the code did (findings that shaped the design)

1. Six fixed section keys (`paper_section_labels()`), stored as one JSON object in `imrad_content`;
   the author's choice is the reserved key `_scope`.
2. Every reader loops over those six keys and ignores anything else: `review_paper.php`,
   `paper_details.php` and `archive/view_paper.php`, each with the same two loops (cards, contents
   list). Data kept in the JSON is invisible until each is taught about it.
3. The upload page draws one editor per key in PHP and registers them in `papelDoc` when it loads;
   nothing could add one afterwards.
4. The dialog (`papelChoose`) has one caller, answers an array of keys, and shares one box with one
   answer slot.
5. Draft save, draft restore and submit all loop over the six keys; the browser copy stores every
   named field and `apply()` puts values back by name before it fills the editors.

## 3. Design

- **Additive.** The six keep their boxes; a student's own sections come after them in the order they
  set. The Abstract stays always included (cards, search and the AI read the `abstract` column).
- **Ready-made lists** (`paper_custom_presets()`): Full Manuscript and Undergraduate Thesis are the
  same chapters (Chapter 1 to 5, References), Feasibility Study is the eight sections of the BSIE
  studies on file. They are the lists the adviser's checklist already uses, so a study is asked for
  the sections it is reviewed against. Every row is then a text box: rename, remove, add by typing.
- **Limits**: 12 sections, 80 characters a name, no repeats, enforced on the server
  (`paper_custom_list()`); the dialog shows the same figures.
- **Required**: every one of their sections, like a ticked standard one ("Please write the
  Executive Summary section.").
- **Storage**: `imrad_content._custom = [{key, label, html}]`. No `_custom` means none.
- **Server** (`config/core.php`): `paper_custom_key_valid()`, `paper_custom_presets()`,
  `paper_custom_list()`, `paper_custom_clean()`, `paper_custom_sections()`,
  `paper_section_html_is_empty()`, `paper_sections_for_display()`; `paper_scope_info()` gains kind
  `custom` and a `custom` list, and `paper_scope_notice()` counts what was provided.
- **Upload page**: the editor is a closure (`$renderEditor`) used for the six and, once more, inside
  an inert `<template>`; `papelDoc.addEditor / removeEditor / relabel / orderCustom`; the hidden
  `custom_sections` JSON field; `setCustomList()`, `restoreCustomSections()`, `parseCustom()`.
- **Dialog**: a "Sections of your own" block under the six (count, "Start from" lists, numbered rows
  with remove, an Add box), a "Custom" quick choice, answer `{keys, custom}` only when asked with
  `opts.custom`.
- **Readers**: take `$sectionLabels` and `$sections` from `paper_sections_for_display()`; the desk,
  the dashboard and the notifications follow through `paper_scope_info()`.

## 4. Files

Changed: `config/core.php`, `app/student/student_upload_ai.php`, `app/review_paper.php`,
`app/student/paper_details.php`, `archive/view_paper.php`, `CLAUDE.md`, `tests/_scope_unit.php`,
`tests/_scope_probe.php`, `tests/m10_section_scope.py`.
New: this file.
Not changed: `ArchiveRepository`, `database/schema.sql`, the migrations, `schema_gaps()`.

## 5. Out of scope

Reordering their sections; keeping a list for reuse on the next paper; letting the Abstract be
optional; a Capstone Project list (one line in `paper_custom_presets()` when it is wanted); a
reviewer-side change (the adviser's checklist is separate and already editable).
