# Plan: one paper, two manuscript files (IMRAD and Full Manuscript)

Status: **implemented** on 2026-10-21 and not yet committed. It was written the
same day as a plan for the session that did the coding; section 0 records what the
owner decided and where the build departs from it, and the rest is the plan as
written. Read `CLAUDE.md` first; every trap it lists applies here.

---

## 0. Outcome (read this first)

Built and checked against the running site with real Google Drive (the scenarios
in section 6, plus a returned, withdrawn and resubmitted chain, an archive and
restore, and a paper filed before the table existed). The throwaway scripts that
drove those checks were not added to the repository.

**Decisions taken**

| # | Decision |
|---|---|
| D1 | The primary file is the **Full Manuscript**. |
| D2 | Extract with AI reads the **IMRAD** file when both are attached, and says which one it read. |
| D3 | Anyone who may open the manuscript gets **both** files. A student still goes through the librarian request, and one grant covers both. |
| D4 | **No "keep the saved file".** A draft holds no PDF, on the server or on Drive; the files stay in the browser and are picked again on resume. This is stricter than the plan, which kept saving the draft's PDF under `uploads/drafts/`. |
| D5 | Phase 0 done: `supporting_documents.document_type` is repaired. |
| D6 | The review desk's "Open paper" now opens PAPEL's own viewer, in a new tab. |

**Where the build differs from the plan**

- There is no `original_name` column. It was for the draft hint, which D4 removed;
  the "Continue your draft?" prompt and the Step 4 summary read the file names from
  the browser.
- `save_draft` does not touch PDFs at all, not only the second one. The page also
  stops sending them with the draft.
- The helpers are `paper_manuscript_entries()` and `paper_manuscript_rows()` (one
  query for a whole page of papers), not `paper_manuscript_files()`. The viewer
  names its tab `IMRAD · Title`, and only when the paper has two files.
- The review and author pages also list the extra, named optional documents, as
  tiles that are not required.
- `supporting_doc_label()` keeps its `''` fallback. The plan said to drop it once
  no `''` rows remain, but that is only true on a server that has run the repair.
- A paper that was **withdrawn** (not returned) keeps its Drive files. Filing it
  again now deletes the ones it replaced (`paper_drive_file_ids()` in
  `config/gdrive_config.php`); before, they stayed on Drive for good.
- A form left open in a tab from before the change still posts `research_pdf`. It is
  accepted for a paper ticked one way only.
- The combined-size check uses `max_post_bytes()` and `max_post_label()` in
  `config/core.php`, both for the page's own check and for the note under the boxes.
- All-or-nothing is one database transaction plus clean-up of the Drive and staging
  files that request made, rather than the step-by-step undo sketched in Phase 4.
- Left as it was: a submission that fails after its files reached Drive deletes the
  files but not the empty folder named after the title. The next attempt reuses it.
- After the first build, on the owner's request, each PDF box on Step 2 became one
  thin solid-lined box that holds both the drop target and the "No PDF selected"
  state, instead of a dashed box beside a second bordered row. Once a file is
  chosen the prompt and the size limit are hidden and the box shows only the file's
  name over its size, with a tick in place of the upload icon. That name and size
  sit above the invisible file input as the switch for the preview panel (the rest
  of the box still opens the file browser to replace the file), and the tooltip
  says so.

To bring another server up to date, run
`scripts/migrations/run_supporting_doc_type_repair.php` and
`scripts/migrations/run_paper_manuscripts_migration.php`. Until the second one has
run, pages fall back to the paper's own columns and a paper ticked both ways is
refused with a message.

---

## 1. The goal

A student can tick **IMRAD**, **Full Manuscript**, or both in Step 1 of the
upload page. The help text there already promises: *"Tick both if you are
handing it in both ways, and you will be asked for two files in the next step,
one of each."* Today that promise is not kept: Step 2 has one PDF field, and the
paper record has room for one file.

After this work:

- A student is asked for exactly one PDF per format they ticked, and cannot
  submit until each is attached.
- Both files are stored, reviewed, returned, published, archived, restored and
  deleted together, as one paper. A paper in both formats is **one paper with
  two files**, never two papers (`config/core.php`, `manuscript_types()` already
  says so).
- Everyone who can open a paper's manuscript today can open either file, in
  PAPEL's own viewer, from every page that shows the manuscript now.
- Papers already in the database keep working without being touched by hand.

## 2. Decisions to confirm before coding

Each has a recommendation. The rest of the plan assumes the recommendation; the
answers actually given are in section 0 (D4 and D6 went further than recommended).

| # | Question | Recommendation |
|---|---|---|
| D1 | When both files exist, which one is the paper's **primary** file (the one older code, the AI summary and single-file contexts keep using)? | **Full Manuscript.** It is the complete record. |
| D2 | Which file does **Extract with AI** read on the upload page when both are attached? | **IMRAD.** The form's section editors are IMRAD-shaped (abstract, introduction, methodology, results, conclusion, references). |
| D3 | On the public page, does a reader who may open the manuscript (staff, or a student with a librarian's grant) get **both** files? | **Yes, both.** A grant is per paper, so it covers both. |
| D4 | Resuming a saved draft: re-pick the PDFs (today's behaviour), or offer "keep the file saved last time"? | **Re-pick, as today.** Show the names saved last time as a hint. A "keep it" option is a separate enhancement. |
| D5 | Fix the existing `supporting_documents.document_type` ENUM bug (Phase 0) in the same piece of work? | **Yes, as its own first commit.** |
| D6 | The review desk's "Open paper" opens Google Drive in a new tab, while every other page uses PAPEL's viewer. Move the desk onto the viewer while touching it? | **Optional.** Only if it stays small; otherwise note it and leave it. |

## 3. How it worked before (what the plan builds on)

Verified against the code and the live database on 2026-10-21.

**Where the file is stored**
- `research_papers.file_path`, `gdrive_file_id`, `file_size`: one file per paper.
  `papers_archive` has the same three columns.
- `research_papers.manuscript_type` is a `varchar(100)` holding the ticked names
  joined by `", "` (for example `"IMRAD, Full Manuscript"`), built by
  `manuscript_types_posted()` in `config/core.php`.
- There is also an older `paper_format enum('IMRAD','Manuscript')` column. Nothing
  new writes it.
- Supporting documents live in `supporting_documents` (one row per file, keyed by
  `paper_id`, **no foreign key**, so rows outlive archiving, which is intended).
- Live data right now: 4 papers (3 approved IMRAD, 1 Full Manuscript draft),
  `supporting_documents` empty.

**Upload page** (`app/student/student_upload_ai.php`, line numbers approximate)
- `extract_ai` handler (~L73) reads `$_FILES['research_pdf']`.
- `save_draft` handler (~L297) stores an attached PDF under
  `app/student/uploads/drafts/<user_id>/`, writes `file_path` on the draft row,
  and unlinks the file it replaces, but only inside that student's draft folder.
- Draft payload for resuming (~L462) carries one `file`.
- `upload_paper` handler (~L489): validates `research_pdf`, stages it locally,
  uploads to Drive with `upload_paper_to_gdrive()` (folder
  `PAPEL - DOCUMENTS / program / year / type / title`, file name `"<title>.pdf"`),
  inserts the row (a 25-parameter `bind_param`), deletes the local staging copy,
  then merges into the student's draft row if it came from one (~L730), then
  handles supporting documents, then notifies the adviser.
- Step 2 markup (~L3561): one `<input type="file" id="pdfFile" name="research_pdf">`
  with `#pdf-indicator`.
- JS: `papelPdfPreview` (~L6323) is the in-page preview, tied to `#pdfFile`;
  `updatePdfIndicator()` (~L6761); `goToStep()` (~L6801) gates Step 2 on a PDF;
  the extraction call (~L7066) appends `research_pdf`; the Step 4 summary shows
  the file name.

**Serving a file**
- Every file opens in PAPEL's viewer (`includes/pdf_dock.php`), from tiles
  `<a class="pd-file" data-pdf-src="...">`. The address comes from
  `paper_file_src('paper'|'doc', id)` and is served by `app/paper_file.php`
  (`?paper=` for the manuscript, `?doc=` for a supporting document), which checks
  `paper_file_viewable()` and streams from Drive, falling back to disk.
- The panel's "new tab" button copies the query string onto
  `app/student/pdf_viewer.php`, so that page must accept the same queries.

**Pages that show the manuscript**
- `app/review_paper.php` (adviser, Coordinator) and
  `app/student/paper_details.php` (the author): each builds an `$expected` list
  with a single `'manuscript'` entry, then the supporting documents.
- `includes/review_console.php` (the review desk, shared by four roles): the card's
  "Open paper" link via `rc_open_link()` (a Drive link), supporting-document chips,
  and the approve/return dialog, which hides the chapter checklist only when
  `data-format` is exactly `IMRAD`. That already works for both formats.
- `archive/view_paper.php` (public page): the Manuscript card unfolds to open one
  hidden `pd-file` link; the librarian request flow checks "is there a file" with
  `paper_file_url()`; the AI summary is extracted from `gdrive_file_id`.
- `analytics/paper_analysis.php`: one "open" button from `gdrive_file_id`.
- Readers of `file_path` / `gdrive_file_id` that only need "the paper's file":
  `archive/home.php`, `app/models/PaperRepository.php`,
  `app/models/ArchiveRepository.php` (copies the columns on archive/restore).

**Lifecycle**
- Returning a paper (adviser in `app/faculty/faculty_review_dashboard.php`,
  Coordinator via `PaperService::declinePaper()`) sets it back to `draft` and calls
  `purge_paper_drive_files()` (`config/gdrive_config.php`), which deletes the
  manuscript and supporting documents from Drive and nulls their `gdrive_file_id`.
  The student must upload again to resubmit.
- `app/student/student_draft_delete.php` deletes a draft's row, its supporting
  document rows and their files.
- `reset_system_data.php` wipes data for a fresh start.

**Found on the way (Phase 0):** `supporting_documents.document_type` is
`enum('ethics_clearance','consent_form','data_collection','other')` in the live
database. The upload handler writes `copyright_doc`, and named "other" documents
as their own name, so both are stored as an **empty string** (the silent ENUM
trap in `CLAUDE.md`). `supporting_doc_label()` papers over it by reading `''` as
the copyright document.

## 4. Design

### 4.1 Data: a `paper_manuscripts` table

One row per file per paper:

| column | type | notes |
|---|---|---|
| `manuscript_id` | `INT AUTO_INCREMENT PRIMARY KEY` | |
| `paper_id` | `INT NOT NULL` | indexed; **no foreign key**, same as `supporting_documents`, so rows survive archiving |
| `format` | `VARCHAR(20) NOT NULL` | `'imrad'` or `'full'`. Deliberately **not an ENUM**: a wrong value must fail loudly in PHP validation, not be stored as `''` |
| `file_path` | `VARCHAR(500) NOT NULL DEFAULT ''` | relative to `app/student/`, same convention as today |
| `gdrive_file_id` | `VARCHAR(255) NULL` | |
| `file_size` | `BIGINT NULL` | |
| `original_name` | `VARCHAR(255) NULL` | the name the student picked, for the draft hint and the Step 4 summary |
| `uploaded_at` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` | |
| | `UNIQUE KEY (paper_id, format)` | one IMRAD and one full file per paper, at most |

**Why a table, not two more column sets on `research_papers`:** extra columns
would also have to go on `papers_archive` and into `ArchiveRepository`'s column
list and its `bind_param` type strings (a trap that has already lost a paper's
file link once, see the comment at `ArchiveRepository.php` ~L98). A table keyed
by `paper_id` survives archive and restore untouched, as `supporting_documents`
already does. Storing the second file as a supporting document was also rejected:
supporting documents are never shown to published-paper readers
(`paper_file_viewable(..., $supporting = true)`), and that table has the broken
ENUM.

**The mirror rule:** `research_papers.file_path / gdrive_file_id / file_size`
always hold the **primary** manuscript (D1: full if present, else IMRAD). This
keeps every older reader in section 3 working with no change, including the AI
summary, archive/restore, and `?paper=` links already out there. Every write path
below must update the mirror in the same request.

### 4.2 Shared helpers in `config/core.php`

Put these next to `manuscript_types()` so every page asks the same thing (house
style: fix the shared piece, not each page):

- `manuscript_format_key(string $name): ?string`: `'IMRAD'` to `'imrad'`,
  `'Full Manuscript'` to `'full'`, anything else `null`.
- `manuscript_format_label(string $key): string`: `'imrad'` to `"IMRAD"`,
  `'full'` to `"Full Manuscript"`.
- `paper_declared_formats(?string $manuscriptType): array`: the keys the paper
  says it was handed in as, in a fixed order (`['imrad','full']` order). An empty
  or unrecognised value (older papers) returns `[]`.
- `paper_primary_format(array $formats): ?string`: `'full'` if present, else
  `'imrad'` (D1).
- `paper_manuscript_files(int $paperId, ?string $manuscriptType, array $legacy): array`:
  the files to show, as `[['key' => 'manuscript_imrad', 'format' => 'imrad',
  'label' => 'Manuscript (IMRAD)', 'src' => <paper_file_src or null>,
  'required' => true], ...]`. Reads `paper_manuscripts`; if there are **no rows**
  (a paper from before the migration that the backfill missed), falls back to one
  entry built from `$legacy` (the row's `file_path` / `gdrive_file_id`) with
  `src = paper_file_src('paper', $paperId)` and the label `"Manuscript"`. One
  helper, used by section 4.5's pages.
- `paper_file_src()`: add a third kind, `'manuscript'`, giving
  `app/paper_file.php?manuscript=<manuscript_id>`.

### 4.3 Migration: `scripts/migrations/run_paper_manuscripts_migration.php`

Plain PHP, idempotent, safe to run twice, like the others in that folder:

1. `CREATE TABLE IF NOT EXISTS paper_manuscripts (...)`.
2. Backfill from `research_papers` **and** `papers_archive` for every row with a
   non-empty `file_path` or `gdrive_file_id` and no `paper_manuscripts` row yet:
   the format is the single recognised name in `manuscript_type`; if it holds both
   or neither, fall back to `paper_format` (`'Manuscript'` to `'full'`, else
   `'imrad'`). Copy `file_path`, `gdrive_file_id`, `file_size`.
3. Print what it did (counts per format), so a second run visibly does nothing.

Phase 0 has its own migration (see Phase 0).

### 4.4 Serving: `app/paper_file.php` and `app/student/pdf_viewer.php`

- `paper_file.php` accepts `?manuscript=<manuscript_id>`: read the row, take its
  `paper_id`, load the paper from `research_papers`, else from `papers_archive`
  (exactly as `?paper=` does now), check
  `paper_file_viewable($u, $paper, false)` (a manuscript, **not** a supporting
  document), then stream the row's Drive file, falling back to disk. Keep
  `?paper=` and `?doc=` exactly as they are: `?paper=` serves the primary.
- `pdf_viewer.php` accepts the same `?manuscript=` and names the tab
  `"<title>: IMRAD"` / `"<title>: Full Manuscript"`. It checks access only to name
  the tab; `paper_file.php` still guards the bytes.
- `includes/pdf_dock.php` needs no change: it passes the query string through.
  Confirm the "new tab" button works with the new query.

### 4.5 Every page that shows the manuscript

- `app/review_paper.php` and `app/student/paper_details.php`: replace the single
  `'manuscript'` entry in `$expected` with the entries from
  `paper_manuscript_files()`. Each declared format is **required**, so a missing
  one counts in `$missingRequired` and shows as missing, the way a missing
  supporting document does now. Supporting documents are unchanged.
- `includes/review_console.php`: the card shows one open link per manuscript
  ("Open IMRAD", "Open full manuscript"), or a single "Open paper" when there is
  one file. Fetch the rows for the whole page in one query, the way supporting
  documents are fetched (~L424), never one query per card. The approve/return
  checklist needs no change (D6 is optional).
- `archive/view_paper.php`: in the Manuscript card, for a reader who may open the
  file, one file there keeps today's behaviour (unfold opens it). Two files show
  two visible tiles inside the card, and unfolding does not auto-open either. The
  card's foot script (~L860, `ms.querySelector('a.pd-file[data-pdf-src]')`) must
  handle both cases. The request flow's "is there a file" check becomes "is there
  at least one manuscript". The wording becomes plural only when there are two
  ("the manuscript files"). A grant covers both (D3).
- `analytics/paper_analysis.php`: one open button per file.
- No change needed in: `archive/home.php`, `PaperRepository`,
  `ArchiveRepository`, `app/librarian/manuscript_requests.php` (links to the
  public page), `browse_card.php`, the approval path (`PaperService` approve
  methods). They read the mirrored primary columns or nothing file-related.

### 4.6 Lifecycle

- **Return (decline):** `purge_paper_drive_files()` also deletes every
  `paper_manuscripts.gdrive_file_id` for the paper from Drive and nulls it. Both
  return paths call this one function, so it is the only change.
- **Resubmission after a return:** the submit handler already demands new files,
  so it will demand one per declared format. Nothing extra.
- **Archive and restore:** rows are keyed by `paper_id` and untouched. Verify that
  `?manuscript=` still opens on an archived paper (the `papers_archive` fallback in
  4.4).
- **Draft delete** (`student_draft_delete.php`): delete the draft's
  `paper_manuscripts` rows and their files under the student's draft folder (same
  "only inside `uploads/drafts/<uid>/`" guard it uses now).
- **System reset** (`reset_system_data.php`): clear `paper_manuscripts` too.
- **`CLAUDE.md`, Database section:** add `paper_manuscripts` to the sentence that
  lists tables left behind when a paper is deleted, and note the mirror rule.

## 5. Work plan, in commit-sized phases

Each phase ends with its own verification (section 6). Do them in order; each
leaves the site working.

### Phase 0: repair `supporting_documents.document_type` (recommended, D5)
- Migration `run_supporting_doc_type_repair.php`: widen the column to `VARCHAR(50)`
  (or add `copyright_doc` to the ENUM; VARCHAR is preferred, for the reason in 4.1).
  Then set `document_type = 'copyright_doc'` where it is `''`.
- Named "other" documents: store `document_type = 'other'` and keep the name the
  student typed. The table has no column for it yet, so add `document_name VARCHAR(255) NULL`.
  `supporting_doc_label()` then shows `document_name` for `'other'`.
- Remove the "empty type means copyright" workaround from `supporting_doc_label()`
  once no `''` rows remain.

### Phase 1: data layer
- Write the migration (4.3) and run it on the dev box.
- Add the helpers (4.2).
- No visible change yet. Check that existing papers still open everywhere.

### Phase 2: serving
- `paper_file.php` and `pdf_viewer.php` (4.4).
- Check: `?manuscript=<id>` opens in the viewer and in a new tab, and is refused
  without the viewer header, to a guest, and to another student.

### Phase 3: reading pages
- `review_paper.php`, `paper_details.php`, `review_console.php`, `view_paper.php`,
  `paper_analysis.php` (4.5), all through `paper_manuscript_files()`.
- With only migrated single-file papers, every page should look exactly as before.
  Then put a second row on a test paper by hand (section 6 rules) to see two tiles.

### Phase 4: the upload page (`student_upload_ai.php`)
*Markup and CSS*
- Step 2 gets one drop zone per ticked format, each with its own label
  ("IMRAD PDF", "Full Manuscript PDF"), file input, indicator and preview control.
  Use the input names `pdf_imrad` and `pdf_full`; flat names keep `$_FILES` simple.
  Only the zones for ticked formats are shown and `required`.
- Retitle Step 2 "Upload PDFs" when two are needed.
- No `style="..."` attributes (they are dropped by the CSP): put everything in the
  page's nonce'd `<style>` block, using the colour tokens.

*Script*
- Ticking or unticking in Step 1 shows or hides the matching zone. Unticking a
  format that already has a file clears that file and says so with the site's
  own notice, not `alert()`.
- `goToStep()` will not leave Step 2 until every shown zone has a valid PDF.
- `papelPdfPreview` and `updatePdfIndicator()` are rewritten to work on any of the
  zones (pass the input in), so both files can be previewed and popped out.
- Extract with AI sends the IMRAD file if attached, otherwise the one attached
  file (D2), and says which file it read.
- Step 4 summary lists one line per file.
- Before submitting, add the file sizes together and compare them with the
  smaller of `post_max_size` and the per-file limit. Show a plain message when
  they are too big together. Two 30 MB files each pass the 40 MB per-file limit
  on the dev box, but the request is still refused. Expose the post limit from PHP
  the way `max_upload_label()` is exposed now.

*Submit handler (`upload_paper`)*
- For each declared format: the file must be present and pass
  `validate_pdf_upload()`. A file for a format that was not ticked is refused with a
  clear message, never silently stored.
- Upload each to Drive into the same paper folder, named
  `"<title> (IMRAD).pdf"` and `"<title> (Full Manuscript).pdf"`. A single-format
  paper keeps today's `"<title>.pdf"`.
- If the second Drive upload fails after the first succeeded, delete the first with
  `delete_from_gdrive()`, remove the staging copies, and fail the whole submission.
  A paper must never be half-submitted.
- Insert the paper row with the primary file mirrored into
  `file_path / gdrive_file_id / file_size` (4.1), then one `paper_manuscripts` row
  per file. **Count the `bind_param` type string** if the main insert changes.
- Draft merge (~L730): after the paper row moves onto the draft's `paper_id`,
  delete the draft's old `paper_manuscripts` rows (and their draft-folder files)
  and re-point the new rows from the temporary id to the draft id.
- Delete the staging copies once Drive has them, as now.

*Draft save (`save_draft`)*
- Accept `pdf_imrad` / `pdf_full`. For each one sent, store it in the draft folder
  and upsert that format's `paper_manuscripts` row (`UNIQUE (paper_id, format)`),
  unlinking the file it replaces (draft folder only). For a format no longer ticked,
  delete its row and its draft-folder file. Keep the mirror.
- Resume payload: `files: {imrad: <original_name>, full: <original_name>}` so
  Step 2 can show "Saved last time: X.pdf, choose it again" (D4).
- `CLAUDE.md`'s draft trap still applies: the device copy must keep carrying the
  draft's `paper_id` after a save.

*Corpus*
- Add one line to the assistant's system prompt (`app/student/student_assistant.php`,
  "File Requirements"): if both formats are ticked, two PDFs are required, one of
  each.

### Phase 5: lifecycle
- `purge_paper_drive_files()`, `student_draft_delete.php`, `reset_system_data.php`
  (4.6).

### Phase 6: documentation
- `CLAUDE.md` (4.6).
- The field guide: `scripts/field_guide_content.py` mentions manuscript types.
  Update it and regenerate `docs/field_guide.html` / `field_guide_snapshot.json`
  the way they are normally built.

## 6. Verification on the dev box

Follow `CLAUDE.md` "Testing" and the memory notes:

- **XAMPP:** check whether Apache and MariaDB are already running before starting
  anything (`Get-Process httpd,mysqld`, ports 80 and 3306). Never start a second
  MariaDB: that has corrupted the privilege tables once. **Never stop either
  service afterwards.**
- **Chrome:** headless only with `--user-data-dir=<scratch folder>`; stop it by its
  own PID, never by image name; never run `chrome.exe --version`.
- **Test data:** `AUTOTEST` accounts with `@example.com` addresses (see
  `tests/fixtures.py`), never a real student. Submitting notifies the adviser and
  **sends real email**, so use the AUTOTEST adviser.
- **Drive is real:** every test submission uploads real files to Google Drive.
  Delete them afterwards (returning the paper purges them; or call
  `purge_paper_drive_files()`), then delete the test rows, including
  `paper_manuscripts`.
- The upload page's "continue your draft?" dialog and its `beforeunload` prompt
  can block a scripted browser; see the memory note on CDP verification.

Scenarios, each checked in the browser, not by reading code:

1. IMRAD only: one zone, submit, one Drive file, one `paper_manuscripts` row,
   mirror filled, one tile on the review and author pages.
2. Full Manuscript only: the same, with the full label.
3. Both: two zones; cannot leave Step 2 with one file; submit; two Drive files in
   the same folder with the two names; two rows; mirror = full; two tiles that each
   open the right file in the panel and in a new tab; two links on the desk card;
   both checklist groups in the approve dialog.
4. Both, with the second Drive upload made to fail: no paper row, no
   `paper_manuscripts` row, and the first Drive file deleted.
5. Draft with both files: save, leave, resume via the dashboard; the hint names
   both files; re-pick and submit; the paper keeps the draft's id; no stray files
   left in `uploads/drafts/<uid>/`.
6. Untick a format after choosing its file: the zone and file are cleared, and the
   submission carries one file.
7. Return by the adviser: both Drive files deleted, ids nulled; resubmitting asks
   for both again.
8. Approve through to published: staff see both files on the public page; a
   student sees the request button; after a librarian grant, both files; after
   expiry, neither.
9. Archive, then restore: both files still open.
10. A paper from before the migration: still opens through the legacy fallback and
    `?paper=`.
11. Two files together over the post limit: a clear message before anything is
    sent.
12. `paper_file.php?manuscript=<id>` typed into the address bar: refused. Another
    student's manuscript: refused.

## 7. Out of scope (note, do not do)

- The older `app/student/student_upload.php` (only referenced by the navbar's
  active-page matching): leave it alone, or ask about retiring it separately.
- Filtering or counting papers by format in analytics.
- Moving the review desk onto PAPEL's viewer, unless D6 says yes.
- Letting a resumed draft reuse its saved PDF without re-picking (D4).

## 8. House rules that apply to every line of this

- Every page: `require_once config/core.php` then `require_role([...])`;
  `csrf_field()` in forms and `csrf_verify()` in POST handlers; prepared
  statements; `e()` on output.
- Inline `<style>`/`<script>` need `nonce="<?= csp_nonce() ?>"`; no `style="..."`.
- Colours only from the tokens (`--maroon`, `--ink`, `--border`, ...), never hex.
- Never `alert()` or `confirm()`: use the site's dialogs.
- No new ENUM values without checking the live column; prefer VARCHAR plus
  validation.
- Count `bind_param` type strings. PHP 8.0: no first-class callable syntax.
- Comments say why, in British spelling. No em dashes anywhere (code, comments,
  UI text, docs).
