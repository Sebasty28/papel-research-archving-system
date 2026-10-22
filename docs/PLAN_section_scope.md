# Plan: choosing which written sections a paper is submitted with

Status: **implemented** on 2026-10-22 and not yet committed. It was written as a
plan before any code; section 0 records where the build departs from it, and the
rest is the plan as written. Read `CLAUDE.md` first; the traps it lists apply here.

---

## 0. Outcome (read this first)

Built as planned, with no migration and no change to `ArchiveRepository`. Checked
against the running site: 75 unit cases, 44 HTTP cases (three real submissions to
Google Drive, all removed afterwards), and four browser scripts (30 + 19 + 13 + 9
checks) covering the dialog, drafts, the step-indicator skip, a full submit from
the page, and every surface in the approval chain, with screenshots.

**Where the build differs from the plan**

- **`setSectionScope()` does not autosave.** The plan said the choice would be
  "kept with the rest of the work". Writing a browser copy on the choice alone
  turned out to raise "Continue where you left off?" for an empty form, because the
  form's defaults already count as content (the browser test shows it). The
  autosave on typing, the leave-page guard and "Save as draft" already carry the
  field, so nothing is lost by leaving it out.
- **The submit message was fixed on the way.** `Please write the X section.` was a
  plain `Exception`, which the handler replaces with a generic line; it is now a
  `UserFacingException` and reaches the student (HTTP 422).
- **The adviser checklist was left alone**, as planned, and the notices say why a
  gap is not a fault: they are about the typed sections, not the PDF.
- **`tests/m10_section_scope.py` is standalone, not in `run_all.py`.** That run feeds
  the progress tracker, keyed `ID-nnn`, and it cannot start in any case: reCAPTCHA
  now refuses a scripted sign-in, and the Coordinator's password has changed since
  the suite was written. The new module mints its sessions and says so.
  `harness.post_files` now accepts a list value and sends it as a repeated field.

**Known limits**

- A chip is not shown on the public listing, by design (section 10).
- On the review desks' compact rows view the meta line fades at its right edge, so
  the chip is placed first to stay visible; on a partial paper that pushes the
  "waiting" badge a little further out than it was.

---

## 1. What was asked

When a student (or any uploader) reaches **Step 2** of the upload page, a small
rectangular dialog, in the same style as the site's other questions, asks which
written sections they will submit: the **Abstract only**, or everything from
**Introduction to References**, or any combination. Whatever they choose has to
be remembered and shown in the **approval workflow**, so a reviewer knows a
missing section was the author's choice and not an omission.

## 2. What the code does today (findings that shape the design)

1. **Six sections, all required.** `paper_section_labels()` gives abstract,
   introduction, methodology, results_discussion, conclusion, references. The
   page demands every one (`papelDoc.firstEmpty()`), and so does the submit
   handler (`Please write the X section.`).
2. **They are stored as one JSON object** in `research_papers.imrad_content`,
   keyed by section. The abstract is also copied, as plain text, into
   `research_papers.abstract`, which the cards, the search and the AI read.
3. **Reviewers check the PDF; the repository shows the typed sections.** The
   adviser checklist (`paper_checklist`) asks about chapters and IMRaD parts of
   the *manuscript file*. So choosing fewer typed sections does not change what
   a reviewer verifies. It changes what gets **published**, and that is what a
   reviewer needs to be told when they approve.
4. **Archive and restore copy an explicit column list**
   (`ArchiveRepository::ARCHIVED_COLUMNS`). `imrad_content` is in it. Any new
   column would also have to be added to `papers_archive`, to that list and to
   its bind-type string, and a server missing it would fail on archiving.
5. **Every reader loops over the known section keys** and indexes into the
   decoded JSON (`review_paper.php`, `paper_details.php`, `archive/view_paper.php`),
   so an extra key in that object is ignored by all of them.
6. **An existing dialog contradicts this feature.** `SECTIONS_NOTE` says "That is
   why all six are required", and the Step 2 notice says "Every section is
   required". Both must change.
7. **One shared dialog element** (`#papelDialog`) serves `papelAlert`,
   `papelPrompt` and `papelConfirm`, with a single resolver. Two dialogs open at
   once would orphan the first one's promise.
8. Resuming a draft (`apply()`) always ends by returning the student to Step 1,
   and restores every named form field, so a hidden input round-trips through
   both the browser copy and the server draft without extra code.

## 3. Decisions

| # | Decision | Why |
|---|---|---|
| D1 | The choice is stored **inside `imrad_content`** under a reserved key, `_scope`: a list of section keys. | No schema change, so nothing to run on InfinityFree staging or production, and archive, restore and drafts carry it with no edits (findings 4 and 5). |
| D2 | **The Abstract is always included.** | The abstract column feeds every card, the search and the AI. A paper without one cannot be listed. |
| D3 | The dialog opens **once, on arriving at Step 2, when no choice is recorded.** A **Change** button reopens it at any time. A resumed draft keeps its choice. | Asking on every visit to Step 2 would nag; never asking again would trap a wrong answer. |
| D4 | The dialog **starts with every section ticked.** Escape or a click outside keeps what is ticked. | That is today's behaviour, so doing nothing loses nothing. |
| D5 | **The server is the authority.** Submit requires only the chosen sections and stores the others empty, whatever the browser posts. A draft keeps everything typed, so narrowing and widening again loses no text. | A browser can be wrong, stale, or hand-made. |
| D6 | A paper with **no `_scope`** (filed before this) is **complete**, and nothing new is shown for it. | We cannot know what an older paper's author intended, and must not invent a claim about it. |
| D7 | A **complete** paper gets no chip; a **partial** one gets "Abstract only" or "n of 6 sections". | Flag the exception, not the rule. |
| D8 | The dialog is built **into the existing shared dialog** (`papelChoose`), with the same shell, head, body and footer, widened slightly. | It is the same look as "Continue where you left off?" by construction. |

## 4. Data and helpers (`config/core.php`)

```
PAPER_SCOPE_KEY = '_scope'
section_scope_parse($raw): ?array     null = nothing valid chosen; else canonical order, abstract forced in
section_scope_resolve($raw): array    parse, or every section (an older page that posts nothing)
paper_section_scope($imrad): ?array   read it back from stored JSON; null = unrecorded (legacy)
paper_scope_info($imrad): array       kind (full|abstract|partial|legacy), keys, provided, omitted, short, text
paper_scope_chip(array $info): string markup for a partial paper, empty otherwise
paper_scope_notice(array $info, $audience): string   one sentence for reviewer, author or reader
```

## 5. The upload page

1. A hidden `section_scope` input in the form (comma-separated keys). Because it
   is a named field, `collect()`, `apply()`, `saveToServer()` and the server
   draft payload all carry it.
2. `papelChoose()` in the shared dialog: a lead sentence, two quick buttons
   (**Abstract only**, **All sections**), six checkboxes (Abstract ticked and
   locked), a live line saying what will be on the page, and **Continue**.
3. `goToStep(2)` asks for the choice when none is recorded. A step-indicator
   click that skips ahead is handled, and the dialog waits if another is open.
4. A scope bar at the top of Step 2: "Sections you are submitting: ...
   [Change]".
5. Sections outside the choice are hidden (`.is-out-of-scope`) and skipped by
   validation. The Step 2 notice, the extraction message, the explanation
   dialog and the Step 4 summary all follow the choice.
6. `hasContent()` ignores the new field, so choosing a scope alone does not
   make an empty form look like unsaved work.

## 6. Where the choice shows in the approval workflow

| Surface | What a reader sees |
|---|---|
| Review desk card (Adviser, Coordinator, Head of Academic Programs, Director: one shared card) | A chip beside the type and date: "Abstract only" or "3 of 6 sections" |
| `review_paper.php` | A notice above the written sections naming what was provided and what was left out by choice, and the same fact in the **Approve** lead text, so the Coordinator knows what "publish" will show |
| Adviser notification on submission; Coordinator notification on forward | One extra sentence in the message and the email |
| Student dashboard card and `paper_details.php` | The chip, and "You chose to submit ..." |
| `archive/view_paper.php` (public page) | "The author chose to publish only ..." so a reader is not left wondering |

## 7. Files touched

`config/core.php`, `app/student/student_upload_ai.php`,
`includes/review_console.php`, `includes/console_shell.php`,
`includes/paper_record_css.php`, `app/student/student_dashboard.php`,
`app/review_paper.php`, `app/student/paper_details.php`,
`archive/view_paper.php`, `app/faculty/faculty_review_dashboard.php`,
`CLAUDE.md`, `tests/m10_section_scope.py`, `tests/_scope_unit.php`.
No migration. No change to `ArchiveRepository`.

## 8. Risks and how each is covered

- **Two dialogs at once.** The shared dialog has one resolver. The new one
  waits for any open dialog to close, and every other opener clears the choose
  mode first.
- **`hasContent()` and "Continue where you left off?".** The new field is
  excluded from the content test so it cannot create the prompt on its own.
- **A tampered or stale scope.** Parsed against the known keys, abstract forced
  in, unknown keys dropped, empty means "all" on submit and "not chosen" on a
  draft.
- **Section text stranded when narrowing.** Drafts keep it; submit stores it
  empty. The Step 4 summary says what will be filed.
- **The old message never reached the student.** `Please write the X section.`
  was thrown as a plain `Exception`, which the handler replaces with a generic
  line. It becomes a `UserFacingException` while this validation is edited.
- **No em dashes anywhere** (a saved preference), and no `style="..."`
  attributes (the CSP drops them).

## 9. Test plan

1. **Unit** (`tests/_scope_unit.php`): parse, resolve, info and chip for null,
   garbage, legacy JSON, abstract only, partial, full, tampered keys, a missing
   abstract, duplicates and ordering.
2. **HTTP** (`tests/m10_section_scope.py`, through the suite's AUTOTEST
   fixtures): a draft saved with and without a scope; a tampered scope; a
   submit with the abstract only passes the sections check, and one with a
   chosen section blank is refused with a readable message; a submit stores
   `_scope` and blanks the rest; a resubmit from a draft keeps it; archive and
   restore keep it; legacy papers show nothing new; the review page, desk card,
   student pages and public page show the indication; notifications carry it.
3. **Browser** (headless Chrome over CDP, own profile): the dialog appears on
   arriving at Step 2 and not again; presets and checkboxes; Abstract locked;
   Escape keeps the choice; editors hidden; Change reopens; a draft saves,
   reloads and resumes with the choice and no second prompt; the stepper skip;
   a screenshot of the dialog beside the existing one.
4. **End to end with real Drive**, AUTOTEST accounts only (mail goes to
   `@example.com`), cleaned up afterwards including the Drive files and folders.
5. **Regression**: the existing suite modules that touch submission and review.

## 10. Out of scope

A database column or a filter for "abstract only" papers; a chip on the public
listing (that query is the hot path and does not load the sections); changing
the adviser checklist, which concerns the PDF.
