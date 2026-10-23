# PAPEL — notes for Claude Code

Context for anyone (human or AI) picking this project up. Read this before
changing anything; several of the conventions below are not guessable from the
code, and a few are traps that have already cost time once.

---

## What this is

A research archiving system for PUP Biñan. Students submit papers, a Research
Adviser reviews them, the Research Coordinator approves and publishes them, and
a public repository makes the approved work readable.

**PHP 8.0.30 · MariaDB · XAMPP · vanilla JS · Chart.js.** No framework, no build
step. Files are served straight from `C:/xampp/htdocs/capstone`.

---

## The approval chain

```
Student uploads  →  Research Adviser  →  Research Coordinator  →  Published
                         (faculty)            (admin, level 1)
```

**Approval ends at the Research Coordinator.** The Head of Academic Programs and
the Director *read* what comes out; they do not approve. This was deliberately
shortened — do not add a fourth approval step without asking.

Returning a paper sets its status back to `draft` and attaches the reviewer's
feedback. So `draft` means either "never submitted" *or* "returned" — see the
`SUBMITTED` rule below.

### Roles

| Role in `users.user_role` | Who | Home page |
|---|---|---|
| `student` | Student | `app/student/student_dashboard.php` |
| `faculty` | Research Adviser | `app/faculty/faculty_review_dashboard.php` |
| `admin` + `admin_level=1` | Research Coordinator | `app/admin/admin_review_dashboard.php` |
| `admin` + `admin_level=2` | Head of Academic Programs | `app/faculty/head_review_dashboard.php` |
| `head_academic` | Head of Academic Programs | same page as above |
| `super_admin` | Director | `app/admin/super_admin_review_dashboard.php` |
| `librarian` | Librarian | `app/librarian/librarian_manage_guests.php` |

**Two roles do the HAP job** — `head_academic`, and `admin` at level 2. Both land
on the same desk. They have not been consolidated; be careful when writing a
role check that you cover both.

---

## Traps that have already bitten

### The `<style nonce="<?= ... ?>">` slicing trap

When finding the end of an opening tag in a PHP template, the first `>` is the
one inside `<?=`, **not** the end of the tag. Search for `?>">` instead.

### `.modal-backdrop` was a name collision

The site's login slide-in used a class literally called `.modal-backdrop` — the
same class Bootstrap generates. Every Bootstrap modal inherited its
`z-index: 1100` and rendered *underneath* its own veil. It is now
`.login-backdrop`. Do not reintroduce a class that Bootstrap also generates.

### Fixed panels sit behind the bars, not over them

The PDF panel (`includes/pdf_dock.php`) and Corpus (`#chat-widget`, upload page
only) are `position: fixed`, and the panel's top and bottom are set from script
on every scroll. The browser scrolls on its own thread and that script runs a
frame or two behind it, so on a quick scroll a fixed edge is briefly where a bar
has already arrived. With the panel in front of the bars, that showed as it
lying over the breadcrumb strip and the footer. The order, front to back, is:

| z-index | What |
|---|---|
| 903 | navbar while a panel is docked (900 otherwise) |
| 902 | footer (also always on the upload page), and the breadcrumb strip while a panel is docked below it |
| 901 | Corpus |
| 900 | the PDF panel |
| 880 | scroll-to-top button, the docked chat |
| 1 | the breadcrumb strip, its own level, while the PDF panel is docked right over it |

**The PDF panel sits over the breadcrumb strip when it can.** Docked to the right
with the breadcrumb links clear of it, it starts right under the navbar, over the
strip's right-hand end (`pdf-over-strip` on `<body>`, decided by `decideOver()` in
`pdf_dock.php` whenever a width could have changed). Its top is then the navbar's
own edge, which never moves, so there is nothing for a scroll to leave behind.
Docked to the left (the links are at that end), or too narrow to leave them clear,
it starts below the strip, follows it up as it scrolls away, and the strip is in
front of it, as the table above says. The docked chat is always the second kind.

The pinned panel has **no border and no background of its own**: its header and
stage cover it completely, and either one shows as a pale hairline against the
maroon strip (the 1px `--border` always; a white ground wherever the inner edge
falls between two device pixels, which happens at 110% to 175% zoom in some window
widths). The in-page card keeps both. Check such an edge by reading pixels across
it at several zoom levels, not by eye at 100%.

**A CSS variable written on `<html>` is a page-wide restyle.** Custom properties
inherit, so changing one on the root makes the browser re-check every element on
the page, including the PDF panel's text layer (thousands of spans for a
thesis). The panels' edges were written that way on every step of a scroll: 50
to 100ms a time with a thesis open, 2 seconds of style recalculation over one
scripted scroll, and the panels trailed the bars by several hundred
milliseconds (headers sliding under the strip, gaps above the footer). The
PDF panel's edges are now plain `top`/`bottom` written on the panel itself
(`placeTop()` in `pdf_dock.php`), and the chat's `--chat-lift` and
`--chat-dock-top` live on the chat and are declared non-inheriting
(`@property`). Anything that changes on every scroll step goes the same way,
never on `:root`. Test it with a long PDF open: a one-line test PDF has no text
layer to restyle and hides the problem entirely.

Put a new fixed element on that ladder instead of reaching for a bigger number.
Corpus follows the footer's visible height (`--chat-lift`, set by
`watchChatLift()`): floating, he is lifted by it, so he rests above the footer
rather than being buried by it on a page short enough to show it all the time;
docked, the panel's bottom edge is that figure, so it ends at the footer's top.
The footer runs the full width in front of a docked chat, as the navbar and the
strip do, and as it already did for the PDF panel.

Anything that must sit flush under the navbar reads `--pdf-dock-top`, the bar's
measured bottom edge that `pdf_dock.php` keeps on `<html>`, and never a number.
The expanded section used `60px` against a bar of 37px, so 23px of the
breadcrumb strip showed above it as a gap; on a phone the bar is 61px, and a
`top: 0` override left the box's own toolbar underneath it.

### One wheel event is not one notch

`assests/js/papel-pdf-view.js` zooms on Ctrl + wheel, in the full-window viewer and in the
panel alike. It used to take a whole step for every wheel *event*, and a touchpad or a
free-spinning wheel sends dozens a second: a one-second flick went from 100% to the 25%
floor in 35ms, and a gentle pinch from 100% to 500% in 200ms. The movement is now gathered
(a mouse notch is 100, a step needs 40) and two steps are never closer than `WHEEL_GAP`
(140ms), so a touchpad and a mouse zoom at the same pace as the buttons, about seven steps
a second at most. Both share `STEPS`, the browser's own ladder (25, 33, 50, 67, 75, 80, 90,
100, 110, 125, 150 ...); it is finer than the old 25/50/75/100 on purpose, so do not go back
to jumps of a quarter or a half of the page.

Test it with real wheel events on a fixed clock: `Input.dispatchMouseEvent` with
`modifiers: 2`, sent **without waiting for each reply**. Calling `zoomIn()` in a loop skips
the wheel code altogether, and a driver that waits for every event lets a slow redraw pass
for a slow hand. A paper's PDF comes from Drive, which is not always to hand: `pdf_viewer.php`
with no `?paper=` takes a local file by `postMessage({type: 'papel-pdf-file', file:
<ArrayBuffer>, name})`, the way the upload page hands it one, which is enough to test with.

### `style="..."` attributes are dropped

`style-src` in the CSP has no `'unsafe-inline'`, and that blocks style
*attributes* in markup just as it blocks an unnonced `<style>` element. A div
carrying `style="display:flex;margin-bottom:.75rem"` computes as `display:block`
with `margin-bottom:0px`. It fails silently, so it reads as a layout bug.

Anything positional goes in a `<style nonce="<?= csp_nonce() ?>">` block or the
shared stylesheet. Setting `el.style.x` from JavaScript is fine, since the CSSOM
is not covered by the policy — which is why some of this looks like it works.

About 59 of these attributes are still scattered through the app. Most are
harmless (duplicating a rule the stylesheet already has) but none of them do
anything.

### An invalid ENUM value is silently thrown away

MariaDB runs here without `STRICT_TRANS_TABLES`. Writing a value the ENUM does
not list stores an **empty string** and the insert still reports success; the
only sign is a warning nobody reads.

`notifications.notification_type` accepts `submission`, `approval`, `decline`,
`reminder`, `comment`, `manuscript`, `security`, `support`, `account`. The
approval path passed `progress` for months, so every "your paper is published"
notice was stored with no kind and drew the generic bell in the notification
centre instead of the approval tick. Repaired by
`scripts/migrations/run_notification_type_repair.php`.

Check the column's ENUM before inventing a value for it.

`supporting_documents.document_type` went wrong the other way round: the ENUM
did not list `copyright_doc`, and a named optional document was written under
its own name, so both were stored as `''` and `supporting_doc_label()` quietly
read `''` as the copyright document. It is now a `VARCHAR(50)` beside a
`document_name` column (`run_supporting_doc_type_repair.php`). The `''` fallback
in `supporting_doc_label()` stays for a server that has not run it yet. A new
"kind" column should be a `VARCHAR` checked in PHP, as `paper_manuscripts.format`
is, so a wrong value fails loudly instead of vanishing.

### A draft lives in two places, and both need the id

`student_upload_ai.php` keeps the student's work twice: a row in
`research_papers` with `current_status='draft'`, and an autosaved copy in the
browser's `localStorage`. The device copy carries the row's `paper_id` in its
`draftId` field, and that field is the only thing that makes the next save an
UPDATE rather than an INSERT.

So anything that writes a draft to the server has to write the id back into the
device copy too. Updating only the page's own `draftId` variable left a copy
tagged 0 behind: the student saved, left the page, came back, answered
"Continue", and the same paper was written a second time as a new draft.

A draft holds **no PDF**, on the server or on Drive. The files stay in the
browser: coming back to a draft, the student attaches them again, and the
"Continue your draft?" prompt only lists the names they had used. That is
deliberate (nothing is stored for work nobody has handed in), so do not add a
"keep the saved file" path without asking. `save_draft` takes no `$_FILES`.

### "Upload failed. Please try again." hid its own cause

That line is the submit handler's catch-all: **every** exception that is not a
`UserFacingException` becomes it, and the real cause goes only to the server log,
against a reference. On staging (InfinityFree) that log is not somewhere anyone
testing can look, so a failure read as unexplained, and the page also prefixed its
own "Upload failed:" and dropped the reference.

- A message a person can act on is a `UserFacingException` (keywords, dates, the
  sections, Drive not connected). A plain `Exception` is a fault, and says nothing.
- `safe_error_message()` adds `detail` (the class, the message, where, and the
  wrapped error inside it) on **any copy of the site that is not production**, never
  on production and never for a `UserFacingException`. Handlers send the whole array
  (`['success' => false] + $safe`), and `papelFailureText()` puts message, reference
  and cause in the dialog. Do not go back to picking out two keys by hand.
- **Nothing after the commit may report a failed upload.** The paper is filed by
  then; the tail (old Drive files, the adviser's notification) sits in its own
  `try`. It used to fall into the handler's catch and answer "Upload failed" for a
  paper that was in fact filed, which invites filing it twice.
- Drive is asked about first (`is_gdrive_connected()`), before anything is stored. A
  copy of the site has its own database and its own Drive token, written once by
  hand, and nothing sets it up when the code is deployed.
- PHP 8.1+ makes mysqli **throw** on a failed query; 8.0 (here) stays silent and
  returns false. Staging runs 8.4, so a statement that fails quietly here can end a
  request there.
- **The 500 page and the JSON error say the cause too, off production.** An uncaught
  exception used to end as a styled page with a reference and nothing else, so a failure
  on staging could only be read from a server log nobody testing can open.
  `show_safe_error()` now takes the cause as a third argument, built by
  `error_detail_for()` (an exception) or `error_detail_plain()` (a fatal caught at
  shutdown); both return null on production and for a `UserFacingException`.
  `pages/error_page.php` shows it in a "Cause" box and the JSON branch adds `detail`.

**The first real cause, on staging, was the storage folder.** Drive was connected, and
every paper still failed: the cause the dialog finally showed was Drive answering
`404 File not found` to the folder ID in `system_settings.gdrive_parent_folder_id`, a
folder in a Google account the connected one cannot see. The connection and the folder
are two separate settings and fail separately.

- `gdrive_check_folder()` asks Drive whether the connected account can add files to a
  folder (`ok`, `unset`, `not_found`, `not_folder`, `trashed`, `read_only`, `unknown`).
  `unknown` means Drive could not be asked and is **not** a verdict; only the four
  definite faults (`gdrive_folder_is_unusable()`) stop a submission.
- The Storage Folder page (`app/admin/gdrive_settings.php`, **Director only**) checks a
  folder before saving it and refuses with the reason; it also shows whether the current
  one is usable, since "connected" says nothing about that. The Coordinator can connect
  Drive (`pages/gdrive_callback.php`) but cannot change the folder.
- A submission checks the folder first and tells the student in words, without the ID.

**Setting up a copy of the site** (staging, production) needs three things by hand, none
of which a deploy does: the database, the Drive connection (Reconnect on the Storage
Folder page, signed in as the Google account that owns or can edit the folder), and the
storage folder, which is **different on each copy** and must be one that connected
account can edit. Open the folder in that account's Drive to be sure before pasting it.
A deploy leaves `.env` and `config/gdrive_token.json` alone (see
`.github/workflows/deploy-staging.yml`), so they have to exist on the server already.

### A copy of the database can be behind the code

Staging was built from a database export on 14 October. Two schema changes came a
week later (`supporting_documents.document_type` became a `VARCHAR` and gained
`document_name`; `paper_manuscripts` was created), and staging has **no shell to run a
migration script from**. `scripts/**` is not deployed either (`deploy-staging.yml`), and
it must stay that way: several of the older scripts have no web guard. So its database
stayed as it was, five queries named `document_name`, and the Research Adviser's desk
ended in the "Something Went Wrong" page for any adviser with a paper waiting. Nothing
showed it here, where the migration had run. The first sign was a faculty login that
"went to an error".

A query that names a column or table a migration added breaks on every copy that has
not run it. So a migration ships with four things, not one:

- **The structure** in `database/schema.sql`, so a copy built from now on is right.
- **The SQL**, in `scripts/migrations/sql/`, for phpMyAdmin (the only tool such a host
  gives you). Each step checks `information_schema` first and skips itself, so it is
  safe to run twice, and the file ends with a line saying what it did.
- **A gap check** in `schema_gaps()` (`config/core.php`): what is missing, what that
  does to the site, and the statement that closes it. Staff see it under "Database" on
  `app/student/upload_diagnostics.php`, which reads "No gaps" once the SQL has run.
- **A tolerant path** until every copy is known to be caught up. Ask
  `supporting_documents_have_name()` or use `supporting_doc_name_sql()` before naming
  the column in a SELECT, and `supporting_doc_type_for_storage()` before writing a kind
  the old ENUM cannot hold (it files the document as `other` and logs why). Remove the
  tolerance only when nobody is behind.

**phpMyAdmin looks at the last statement of a pasted script.** If that statement reads
from a database (`FROM information_schema.COLUMNS`), it switches to that database before
running *any* of the script, so every `DATABASE()` above it answered `information_schema`,
step 1 skipped itself, and step 2 was refused with `#1044 Access denied ... to database
'information_schema'`. The mysql client does not do this, which is why it passed there.
End such a script with a statement that reads no table (the catch-up file's closing
`SELECT CONCAT(...)` of session variables). Test a pasted script in phpMyAdmin itself, not
only with `mysql < file.sql`.

### `bind_param` type-string length

`'sssisssi'` must have exactly one character per parameter. A mismatch fails at
runtime, not at lint. Count them.

### PHP 8.0, not 8.1+

No first-class callable syntax (`trim(...)`). Use `'trim'` or a closure.

### Heredoc indentation

PHP 7.3+ strips the closing marker's indentation from every line. If the closing
`HTML;` is indented but a body line is not, it is a parse error.

### Windows long paths

Some upload paths exceed 260 characters and git warns "Filename too long".
`git config --global core.longpaths true` if it becomes a problem.

### Headless Chrome closes the real browser — two separate ways

`chrome.exe --headless=new` with no `--user-data-dir` opens the *default*
profile — the same one the human's everyday Chrome window is signed into.
Chrome allows only one process per profile; a second process (even headless)
takes the lock by closing the window that already held it. Every verification
run under **Testing** below must pass its own `--user-data-dir` pointing at a
throwaway folder (the scratchpad is fine), or it will force-close whatever the
human has open, every time.

Cleaning up afterwards with `taskkill /F /IM chrome.exe /T` is a second,
unconditional way to cause the same symptom — it matches every `chrome.exe`
process on the machine by image name, including the human's real windows,
*regardless* of `--user-data-dir`. Track the PID of a launch you backgrounded
and kill only that PID, or let a one-shot `--dump-dom`/`--screenshot` process
exit on its own.

---

## Shared includes — use these, do not re-invent

Everything visual lives in `includes/`. A page that hand-rolls its own header or
its own palette is a bug, not a style choice.

| Include | What it gives you |
|---|---|
| `site_head.php` | Design tokens, fonts, `.wrap`, `.crumb-bar`, `.btn-sm-maroon`, `.btn-sm-outline`, Material Symbols |
| `site_header.php` | Navbar, avatar menu, notification bell, login modal |
| `site_footer.php` | Footer, Bootstrap JS, notification dropdown script |
| `console_shell.php` | Dashboard card layout (`.layout`, `.paper-card`, progress tracker) |
| `review_console.php` | The whole review desk. Four roles share it — configure with `$RC` |
| `manage_page.php` | The `mgmt-*` stylesheet for the three management consoles |
| `manage_console.php` | Bootstrap-class re-skin for management pages |
| `password_generator.php` | The Generate button on the create forms — builds `RayverReyes-056` from the name and ID already typed |
| `action_dialogs.php` | Site-styled confirm on any `[data-confirm]` element |
| `manage_save_confirm.php` | "Save these changes?" with a field-by-field diff |
| `theme.php` | Palette + light/dark, applied pre-paint |
| `scroll_jump.php` | Scroll to top/bottom button |

**Never use `alert()` or `confirm()`.** Put `data-confirm="..."` on the button
and `action_dialogs.php` handles it.

**One loading indicator.** The bar along the top of the window
(`loading_bar.php`, pulled in by `site_footer.php`) reports every fetch on the
site. Drive it with `window.papelLoading.start()` and `.done()`;
`papelLoading.work.*` adds the logo pill for a long workflow. A page that
paints a progress bar of its own ends up with two of them, in two places: the
student dashboard drew one across the top of its results column, which on that
page is above the search box, so it read as a stray rule floating in the gap.

The upload page's overlay is the one place the logo is not a pill: it plays the
same GIF inside its own card and does not call `papelLoading.work.*`, since
asking for both put the animation on screen twice. The GIF is not transparent
(it carries an off-white ground), so it sits on a plate of that colour, and the
dark theme's `img { filter: brightness(.92) }` has to be switched off for it
(`#id img { filter: none }`, the id being for specificity) or the picture comes
out darker than its plate.

### Styling

Use the CSS custom properties — `--maroon`, `--dark-maroon`, `--soft-maroon`,
`--cream`, `--ink`, `--grey`, `--border`, `--white`, `--font-head`,
`--font-body`. Never hardcode a hex colour. The theme system swaps these at
runtime via `data-mode` / `data-color` on `<html>`, so a hardcoded colour breaks
dark mode silently.

Chart.js colours must be **read from the tokens at draw time** and rebuilt when
the theme changes — see `analytics/analytics_dashboard.php` for the pattern.

---

## Security conventions

Every page: `require_once config/core.php` then `require_role([...])`.

- **CSRF** — `csrf_field()` in every form, `csrf_verify()` at the top of every
  POST handler.
- **CSP nonces** — every inline `<style>`/`<script>` needs
  `nonce="<?= csp_nonce() ?>"` or it will not run.
- **Prepared statements** everywhere. The one place SQL is interpolated is a
  whitelisted sort column in the analytics page — copy that pattern if you need
  dynamic ordering.
- **Escape output** with `e()`.

### Never commit

`.env`, `client_secret*.json`, anything under `**/uploads/`, `vendor/`, `*.log`.
The `.gitignore` covers these; check `git status --short` before committing
anyway. A committed secret must be **revoked**, not just deleted — it stays in
the history.

---

## Login

Sign-in is **ID + password**. No usernames, no birthdate, no emailed one-time
code (all three were removed; the code step was deleted outright in October
2026, so there is no commented-out block to revive).

The lookup matches `username`, `email`, `student_id` **or** `faculty_id` —
students carry their ID in `student_id`, staff in `faculty_id`, and which column
depends on who created the account. All four are checked.

`admin_level` is stored in the session at login. Do not reintroduce a
`$u['admin_level'] ?? 1` that reads from a session which never had it — that bug
gave a HAP the Coordinator's navbar for months.

Staff, librarians and the Director all sign in on the **faculty** tab. Only
`selected_role` values `student`, `faculty` and `guest` exist.

---

## Email

Every message goes through `send_email()` in `config/core.php`, which wraps the
body in `email_layout()`: the maroon PUP letterhead, the PAPEL band, the
sign-off and the footer. **The subject is also printed as the heading inside
the letter**, so changing one changes both.

Bodies are built from `email_para()`, `email_details()` (the label/value table,
with IDs and passwords in monospace) and `email_action()` (the link). Never
concatenate raw HTML; `email_plain()` derives the plain-text copy from the same
markup and only understands those blocks.

`create_notification()` posts the in-app notice **and** emails it. Its subject
comes from a map keyed on the notification type; pass the fifth argument when
one type means two different things (an approval is "forwarded" to one reader
and "published" to another, a manuscript notice is a request to a librarian and
an answer to a student).

**Mail actually sends from this machine.** Anything that creates a notification
reaches a real inbox, so test against `@example.com` addresses, which are
reserved and cannot receive mail. Seeding a paper on a real student's account
and approving it will email that student.

---

## Business rules worth knowing

### Student account expiry

An account's life comes from the section: `1-x` → 5 years, `2-x` → 4, `3-x` → 3,
`4-x` → 2, `Ladderized` → 2. Counted in academic years from the A.Y. on the
account, expiring 31 July. See `student_account_years()` and
`student_expiry_date()` in `config/core.php`.

Recomputed on every create and edit, so **moving a student up a year is how you
renew them**. An expired account cannot sign in.

### What counts as a submission

Analytics counts only work actually handed in:

```sql
current_status <> 'draft' OR EXISTS (SELECT 1 FROM approval_workflow ...)
```

The second half matters: a returned paper is back in `draft` but *was*
submitted. Drafts nobody has been asked to look at are reported separately.

### One paper, one or two manuscript files

A student ticks IMRAD, Full Manuscript or both in Step 1, and
`research_papers.manuscript_type` records it (`IMRAD`, `Full Manuscript`,
`IMRAD, Full Manuscript`). A paper handed in both ways is **one paper with two
files**, never two papers.

The files live in `paper_manuscripts`, one row each (`paper_id`, `format` =
`imrad` or `full`, `file_path`, `gdrive_file_id`, `file_size`). There is no
foreign key, so the rows stay put when a paper is archived or restored.

**The mirror rule.** `research_papers.file_path / gdrive_file_id / file_size`
always describe the *primary* file: the Full Manuscript when there are two,
otherwise the only one. Older readers depend on it (`archive/home.php`, the
repositories, archive and restore, the AI summary, `?paper=` links already in
circulation), so anything that writes a paper's file writes the mirror in the
same request.

Ask `config/core.php`, do not parse `manuscript_type` by hand:
`paper_declared_formats()`, `paper_primary_format()`,
`manuscript_format_label()`, `paper_manuscript_entries()` (what a page lists:
label, viewer address, whether it is required) and `paper_manuscript_rows()`
(one query for a whole page of papers). One file is labelled "Manuscript"; two
are "Manuscript (IMRAD)" and "Manuscript (Full Manuscript)". A paper with no
row (filed before the table existed) falls back to its own columns through
`?paper=`.

Each file is served by `app/paper_file.php?manuscript=<id>` and opened in
PAPEL's viewer, which now includes the review desk (it used to link to Drive).
Whoever may open one may open both, and a student's librarian grant covers both.

Submitting is all-or-nothing: every file goes to Drive first, then one database
transaction writes the paper, the draft merge, the manuscript rows and the
supporting documents. If anything fails the transaction rolls back and the Drive
files and staging copies that request made are deleted. Returning a paper
deletes **both** Drive files (`purge_paper_drive_files()`), so the student
attaches both again; withdrawing keeps them, and resubmitting deletes the ones
it replaced. Extract with AI reads the IMRAD file when both are attached, since
the form's editors are IMRAD-shaped.

### Which written sections a paper was filed with

A paper has six written sections (`paper_section_labels()`): the Abstract and five
more. On arriving at Step 2 the author chooses which to submit: the Abstract only,
or any combination (`askSectionScope()` in `student_upload_ai.php`, in the shared
dialog, with a Change button to ask again). The Abstract is always included,
because the cards, the search and the AI read the `abstract` column.

The choice is stored **inside `imrad_content`**, under the reserved key `_scope`
(a list of section keys), and **not in a column**. `ArchiveRepository` copies a
fixed column list and `imrad_content` is on it, so archive and restore carry the
choice with no change at all. A column would have to be added to `papers_archive`,
to that list and to its bind types, and a server missing any one of them fails on
archiving. Do not add one without reading `ArchiveRepository` first.

- Ask `config/core.php`, do not parse the JSON by hand: `section_scope_parse()`,
  `section_scope_resolve()`, `paper_section_scope()`, `paper_scope_info()`,
  `paper_scope_chip()`, `paper_scope_notice()`.
- **No `_scope` means complete**, and nothing is shown or claimed for the paper:
  it was filed before the choice existed, or it is a draft saved before one was
  made. Do not read it as a statement of what the author meant.
- **The server decides.** Submit requires only the chosen sections and stores the
  others empty whatever was posted. A draft keeps everything typed, so narrowing
  the choice and widening it again loses no text.
- **Reviewers check the PDF; the repository shows the typed sections.** The adviser
  checklist is about the manuscript file and is unchanged. What the choice changes
  is what gets *published*, so that is what the notices say.
- It shows in the approval chain: a chip on the review desks (one shared card, so
  all four roles) and on the student dashboard; a notice on `review_paper.php` and
  in the Approve confirmation; the author's `paper_details.php`; the public paper
  page; and the adviser's and Coordinator's notifications.
- **Choosing must not autosave.** A bare upload form already counts as content (its
  defaults), so saving on the choice alone wrote a device copy and raised
  "Continue where you left off?" for someone who had only answered the question.
  The autosave on typing, the leave-page guard and "Save as draft" all carry the
  field already.

### Sections of the author's own

A Feasibility Study has an Executive Summary and five Aspects, a thesis has chapters, and
boxes named Methodology and Results and Discussion help neither. In the same question as above
the author can add **sections of their own name** (up to 12), typed or started from a ready-made
list: Full Manuscript, Undergraduate Thesis (the same chapters) or Feasibility Study
(`paper_custom_presets()`, which reads the lists the adviser's checklist uses, so a study is
asked for the sections it is reviewed against). They sit **beside** the six, after them, not
instead of them; the "Custom" quick choice leaves only the Abstract ticked and goes to the block.
Each gets a box on Step 2 and is required like a ticked standard section. Plan:
`docs/PLAN_custom_sections.md`.

- **Stored inside `imrad_content` again**, under `_custom`: `[{"key","label","html"}]`, keys
  `custom_1` to `custom_24` (more keys than sections: one taken out while it holds text is kept,
  struck through, until the answer is given, and keeps its key meanwhile). No column and no
  migration, so a copy of the site that is behind needs nothing run, and `ArchiveRepository` carries
  it whole. No `_custom` means none, so every paper filed before this reads as it did.
- **Ask `config/core.php`**: `paper_custom_list()` and `paper_custom_clean()` are what a post may
  hold (names cleaned the checklist's way, repeats dropped, 12 at most, a bad key dropped, never an
  error but for a body left empty), `paper_custom_sections()` is what is stored,
  `paper_sections_for_display()` is what a page shows: the six, then theirs. The three readers
  (`review_paper.php`, `paper_details.php`, `archive/view_paper.php`) take `$sectionLabels` and
  `$sections` from it; **a new reader must, not loop over `paper_section_labels()`**, which is
  blind to them. It also counts a section holding only a picture or a table as written, as submit does.
- **A name equal to a standard section that is being submitted is dropped** (that box already covers
  it). One equal to a section left out stays: that is how a thesis puts References after its chapters
  and not before them. Choosing a ready-made list therefore unticks the standard sections.
- **Kind `custom` in `paper_scope_info()`**: their own sections *and* standard ones left out. Chip
  "Custom: N sections", and the notices count what was provided ("4 written sections") instead of
  "3 of 6". Their own on top of all six is `full`, and nothing is flagged.
- **On the page**: the editor is drawn once (`$renderEditor` in `student_upload_ai.php`) and once more as
  an inert `<template>`; `papelDoc.addEditor()` copies it. Their names travel in the hidden
  `custom_sections` field (JSON), each body in the field named by its key (`custom_1`). `apply()`
  rebuilds the boxes **before** it sets their text, or there is nothing to put it in.
- **Traps already met while building it.** `papelChoose` answers `{keys, custom}` only when it is
  given `opts.custom`; with none it answers the array it always did. A *disabled* input gives up
  focus, and the next Enter then lands on the page and answers the whole question, so at the limit
  only the Add button is disabled. A box made after load is not wired to the browser-copy autosave
  (a contenteditable raises no input event on the form), so `addEditor()` wires its own surface.
  `hasContent()` ignores `custom_sections`, as it does `section_scope`: choosing must not autosave.
- **A name is a person's typing.** It is written with `textContent` and escaped with `escHtml()`
  wherever markup is built (the Step 4 explanation, the extraction notice). The dialog's body scrolls
  inside the box, so twelve rows fit a short screen.

### The review checklist

What the Research Adviser confirms while reading a paper. Its heading is a dropdown of
ready-made lists (`paper_checklist_templates()` in `config/core.php`): IMRaD Sections,
Undergraduate Thesis Sections and Capstone Project Sections (Chapter 1 to 5 and References: the
old "Full Manuscript" list, under the name of what is being reviewed), Feasibility Study
Sections (Executive Summary, the Marketing, Technical, Management, Financial and
Socio-Economic Aspects, References, Appendices: the chapters of the BSIE studies on file), and a Blank list. **Edit sections** lets the adviser add, remove or rename sections
**for that one paper**; only a code change edits the ready-made lists.

- **Stored as the adviser left it**, in `paper_checklist.items_json`
  (`{"v":1,"template":"...","edited":true,"items":[{"label":"...","ok":true}]}`), so changing a
  list in code never rewrites what a student was shown. A row with no `items_json` was written by
  the old form and is read from its eleven columns, its chapters headed by the paper's type and
  never "Full Manuscript". Ask `config/core.php`, do not parse by hand: `paper_checklist_load()`,
  `paper_checklist_clean()` (the authority on what a post may hold: 20 sections, 80 characters,
  tags stripped, repeats dropped, at least one), `paper_checklist_save()`,
  `paper_checklist_default()`.
- **The starting list follows the file format first, then the type**: filed as IMRAD only gives
  IMRaD, a Feasibility Study gets its own, anything else the chapters list named for its type
  (two of the three theses on record were filed as IMRAD, so the type alone would be wrong). It is
  only a starting point.
- **One editor, in two places**: `includes/review_checklist.php`, used by `app/review_paper.php`
  and by the approve window on the adviser's desk (`includes/review_console.php`). It posts
  `ck_template`, `ck_label[n]` and `ck_ok[n]`; the old eleven field names are still accepted.
- **Only the adviser edits, and only on Approve.** The Coordinator reads the adviser's list (their
  boxes used to look editable and nothing they changed was kept). Return saves no checklist.
- **The save is an upsert.** It was a plain INSERT against `unique_paper`, so a paper the
  Coordinator returned and the adviser approved again kept its old ticks here (the failure was
  silent on PHP 8.0) and threw on PHP 8.1+, which ended in the 500 page with the paper unforwarded.
- **A server without the column** (`paper_checklist_json_ready()`; see "A copy of the database can
  be behind the code") still works: three lists, no editing, a notice saying why, and the old
  columns as before.

### Programmes

`programs_map()` and `program_code()` in `config/core.php`. Full name in the
database, short code (`BSIT`) in tables and chips.

---

## Database

The data is not in the repository. The **structure** is: `database/schema.sql` (tables
only, no data) is what a new copy of the site imports (phpMyAdmin, pick the database,
Import; or `php scripts/setup.php`), and staging was built from it on 14 October. After a
migration, export the structure again so a fresh copy comes out right. A copy that was
built *before* the migration has to be brought up to date by hand: a migration script in
`scripts/migrations/`, or, where there is no shell, the SQL in `scripts/migrations/sql/`.

Migrations are plain PHP, safe to run twice, and idempotent — they check whether
the column exists first. Run them from the CLI:

```bash
php scripts/migrations/run_student_cohort_migration.php
```

Recent ones: `run_student_cohort_migration.php` (academic_year, section),
`run_account_expiry_migration.php` (expires_on),
`run_notification_type_repair.php` (notices that lost their kind),
`run_drop_otp_column.php` (removed `users.is_otp_exempt`; already applied),
`run_supporting_doc_type_repair.php` (`supporting_documents.document_type` from
ENUM to `VARCHAR`, plus `document_name`),
`run_paper_manuscripts_migration.php` (creates `paper_manuscripts` and backfills
one row for every paper that already has a file),
`run_checklist_items_migration.php` (adds `paper_checklist.items_json`, which the editable review
checklist is stored in).

Run the last two on any other server before it takes this code. Until
`paper_manuscripts` exists the pages fall back to the paper's own columns and
the upload page refuses a paper ticked both ways (and says so), so nothing
breaks, but nothing can be handed in twice either.

A server with no shell (staging) cannot run those. `scripts/migrations/sql/2026-10-21_catch_up.sql`
does the last two in plain SQL for phpMyAdmin: SQL tab, with the site's database selected,
paste, Go. It is safe to run twice, repairs the rows already written, and closes with a
line per step saying what it did. It was checked against the PHP migrations (same tables,
same rows) and by pasting it into phpMyAdmin itself. See "A copy of the database can be
behind the code" under Traps before adding the next one.
`scripts/migrations/sql/2026-10-23_checklist_items.sql` does the checklist column
(`run_checklist_items_migration.php`, `paper_checklist.items_json`) the same way.

Papers deleted from `research_papers` leave rows behind in `approval_workflow`,
`paper_checklist`, `supporting_documents` and `paper_manuscripts`. `papers_archive` is *meant* to
outlive its papers — never treat its rows as orphans.

---

## Testing

There is no test framework. Changes are verified against the running app:

1. Sign in over HTTP with a real account and fetch the page.
2. For behaviour, inject a probe script into the fetched HTML and run it in
   headless Chrome (`--headless=new --dump-dom`), writing results into
   `document.title`. Always pass `--user-data-dir=<scratch folder>` — see
   the trap above; without it, this closes the human's actual browser.
3. For layout, take a screenshot and measure with `getBoundingClientRect()`.

**Verify against the running site, not by reading code.** Several bugs in this
project's history looked correct in source and failed in the browser — a form
closed early by a stray `</div>`, a dialog painted under its own backdrop, a
`\b` regex that never matched at end-of-string.

Seed realistic data, assert, then clean up. Never leave test rows behind.

**Scripted sign-in no longer works.** reCAPTCHA is configured, and it exists so a
script cannot use the sign-in form ("Please confirm you are not a robot"). Write a
session file for the account instead (see "Signed-in page without signing in" in
the verification notes), which is what signing in does. The older suite in
`tests/` (`run_all.py`) signs in by form as the Research Coordinator, whose
password has also changed since it was written, so it cannot start; and its
`full_submission` helper predates the two-manuscript upload (it posts
`manuscript_type` as one word and the file as `research_pdf`). `tests/m10_section_scope.py`
is standalone: it creates the AUTOTEST accounts through the app's own forms using a
minted Coordinator session, submits for real (four Drive uploads), and removes the
Drive files and any empty AUTOTEST folder before it removes the accounts. It takes
step names to run a part (`python m10_section_scope.py legacy`; `custom_cheap` and
`custom_surfaces` cover sections of the author's own with no upload at all).
`tests/m11_submit_failures.py` is its companion for what a failed submission says: it
sets Google Drive aside (token, in the database and in the file) and renames the
notifications table for one request, to produce real failures, and puts both back in a
`finally`. If a run is killed, check `system_settings` for a `gdrive_system_token__aside`
key and `config/` for `gdrive_token.json.aside`, and that a `notifications` table exists.
`tests/m12_storage_folder.py` covers the storage folder: it points the real local
setting at the exact ID staging could not use, briefly, and puts the row back (value,
user and time) in a `finally`, with a copy kept in the system temp folder
(`papel_folder_setting.json`) that the next run restores from if one was killed. Drive is
only ever read. The test client's timeout is 180 seconds because a real submission is a
dozen sequential Drive calls and a slow connection makes that over a minute.

`tests/m13_old_schema.py` is the companion for a database that is behind the code. It
copies the live database into a scratch one (`papel_m13`), puts it back to its 14 October
shape, and serves the same code from a second PHP server (`php -S 127.0.0.1:8093`) pointed
at the copy with mysqli throwing as it does from PHP 8.1. It then fills the adviser's desk,
files papers with the copyright document and a named extra one (three real Drive uploads in
all, removed afterwards), runs the catch-up SQL, and files again. The live database is never
written to. `tests/_old_schema_prepend.php` is what points that server, and the CLI probes,
at the scratch database: `auto_prepend_file` is **not** run for `php -r`, so a probe has to
be a script file. If a run is killed, drop `papel_m13` and stop the `php.exe` on port 8093
by its PID.

`tests/m14_review_checklist.py` covers the review checklist against the running site, with no
Drive: the unit cases in `tests/_checklist_unit.php`, what the review page offers each reader,
approving with each list and with edited ones, the old form's fields, refused and hostile lists,
a second round of approval, the desk's approve window, and the Coordinator's read-only view.
The dropdown, edit mode and posted fields were driven in a real Chrome (typing with
`Input.insertText` and Enter); that script is not in the repository. `m13` also covers the
checklist column.

### When there is no running app (cloud and web sessions)

Claude Code on the web, scheduled cloud agents and `/code-review ultra` all run
against a plain checkout of this repository on Linux. There is no XAMPP, no
MariaDB, no `.env`, no `uploads/` and no browser, so **none of the three steps
above can be carried out there**. A session that tries to sign in over HTTP or
launch headless Chrome is burning its turn on something that cannot work.

Still worth doing without the app:

- Read and change code, and say exactly what changed.
- Sweep the whole tree for a pattern: the dead `style="..."` attributes, a role
  check that covers `head_academic` but misses `admin` at level 2, a
  `bind_param` type string whose length does not match its arguments.
- Rewrite documentation, including the stale files under **Known gaps**.
- Review a diff.

Not possible there, and not to be claimed:

- **Confirming a fix works.** Say "changed, not verified in the browser" and
  leave the check to a session on the dev box. Several bugs here looked correct
  in source and failed in the browser, and that history does not stop applying
  just because the browser is missing.
- **Anything that reads the database**: a column's ENUM, a row count, whether a
  migration has run. The schema is not in the repository. Ask instead of
  guessing, and recall that an invalid ENUM value is accepted silently.
- **Running a migration** from `scripts/migrations/`.

The Windows paths in this file (`C:/xampp/htdocs/capstone`, `C:/xampp/tmp`)
describe the dev box, not the cloud checkout.

---

## House style

- Comments explain **why**, not what. If a line is unusual, say what would go
  wrong without it.
- British spelling in prose; American in code identifiers where the codebase
  already uses it.
- Match surrounding code density and idiom.
- Prefer fixing the shared include over patching one page — that is how the
  three management consoles ended up consistent.

---

## Known gaps

- **Staging's database is behind the code until both files in `scripts/migrations/sql/`
  (`2026-10-21_catch_up.sql` and `2026-10-23_checklist_items.sql`) have been run there.** The adviser's
  checklist is limited to three fixed lists with no editing until the second one is. The pages work around the gaps (the adviser's desk no longer fails),
  but the copyright document is filed as "Other Document", a student's name for an extra
  document is lost, and a paper cannot be handed in as both IMRAD and Full Manuscript. Open
  `app/student/upload_diagnostics.php` as staff: "Database" reads "No gaps" once it is done.
  Delete this entry then.
- **`time_to_approval` is only written from now on.** `PaperService` stamps it
  on the Coordinator's approval, so the tile fills for papers approved since
  that was added. Papers approved before it have no figure and never will.
- **`CSRF_KEY` is still the placeholder** in `.env`. Changing it logs everyone
  out once.
- **The Director's password is unrecoverable.** Testing Director-only pages
  means minting a session file directly in `C:/xampp/tmp` (26-character id from
  the `0-9a-v` alphabet — PHP rejects anything else).
- **17 files in `docs/`**, several stale. `ADMIN_L2_DASHBOARD.md` in particular
  describes routing that has since changed.
