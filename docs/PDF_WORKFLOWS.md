# PAPEL PDF Workflows: working brief and defence preparation

**Status: current.** Describes the system as it stands on 23 November 2026.
Written against the code, not from memory. Where this file and `CLAUDE.md`
disagree, `CLAUDE.md` wins; where this file and the running site disagree, the
site wins and this file is wrong.

Companion to the published page at
<https://claude.ai/artifact/3AxJtfFktgBtnBJyDv97XE>, which is the same material
drawn as diagrams for a reader. This file is the long version, with the step
sequences and a question bank.

---

## 0. What this file is for, and how to use it in the Claude project

This is the brief to hand Claude alongside the capstone paper.

**When the paper is added to the project, the job is to reconcile the two.**
The paper describes what the system is meant to do; this file describes what the
code actually does. The valuable output is the list of places where they differ,
because every one of those is a question the panel can ask and the group cannot
answer.

Concretely, once the paper is in the project, ask for:

1. **A mismatch list.** Every claim in the paper about PDF handling, upload,
   storage, access or viewing, set against section 1 to 8 below. Flag anything
   the paper promises that the code does not do, and anything the code does that
   the paper does not mention.
2. **Terminology drift.** The paper and the code must use one name per thing.
   The known traps are listed in section 1.
3. **Figure checks.** Any diagram in the paper that shows a PDF moving should
   match figure-for-figure with section 2 to 7. A diagram with an arrow the code
   does not make is worse than no diagram.
4. **Answer drafting.** Section 11 is a starting bank. Once the paper is in,
   the answers should be rewritten to cite the paper's own chapter and page, so
   a panellist who asks "where is that in your paper" gets a page number.

**What not to ask for.** Do not ask Claude to confirm a behaviour works without
the running site. Several things in this project looked correct in source and
failed in the browser. If a claim matters for the defence, it is checked against
the running site or it is marked unverified.

---

## 1. Scope and vocabulary

This brief covers **every path a PDF takes**, and nothing else. The approval
chain's statuses are in `docs/WORKFLOW_DIAGRAM.md`; the system as a whole is in
`docs/PAPEL_System_Overview.md`.

### The three kinds of PDF

| Kind | What it is | Where it lives | Table |
|---|---|---|---|
| **Manuscript** | The paper itself. One file, or two when filed as both IMRAD and Full Manuscript, or one under a name the student chose. | Google Drive | `paper_manuscripts` |
| **Supporting document** | Ethics clearance, consent form, data collection tool, copyright paper, plus any the student adds and names. | Google Drive | `supporting_documents` |
| **Report** | The exports PAPEL writes itself: analytics, the review desks, the Storage Folder, the Librarian's queue. | Nowhere. Built in memory, sent to the browser. | none |

### Names that must not drift

The paper and the code have to agree on these. Each has been wrong somewhere at
least once.

- **Subject Adviser**, not "Research Adviser". Renamed 12 November 2026.
- **Head of Academic Programs**, not "Head of Academic Affairs".
- **Research Coordinator** is `admin` at `admin_level = 1`. The Head of Academic
  Programs is also `admin`, at level 2. A role check that says `admin` without
  the level is wrong.
- **Manuscript** is the paper's own PDF. A supporting document is never called a
  manuscript.
- The approval chain **ends at the Research Coordinator**. The Head and the
  Director read what comes out; they do not approve. A paper describing a four
  or five stage chain describes a system that was deliberately shortened.

---

## 2. The architecture in one page

Three parties, and the bytes only ever pass between them.

```
  Reader's browser              PAPEL server                Google Drive
  ----------------              ------------                ------------
  pdf.js, served                checks who is asking,       one storage folder,
  from PAPEL                    then passes bytes on        every PDF in it
        |                              |                          |
        |------ asks for a file ------>|                          |
        |                              |------ asks Drive ------->|
        |                              |<----- PDF bytes ---------|
        |<----- PDF bytes -------------|                          |
        |                              |
        |                       (nothing written
        |                        to PAPEL's disk)
```

**PAPEL's server stores no PDF.** There is no `uploads/` directory holding
papers in production. Files stream from Drive at the moment they are asked for.

Consequences worth knowing, because they are all questions:

- There is nothing on the web server to back up, and nothing there to leak.
- Hosting stays cheap: free hosting caps disk hard, and the papers are the only
  thing that would have grown without bound.
- **Drive is a hard dependency.** If Drive is unreachable, no PDF opens. The
  records, the search, the review desks and the dashboards all keep working,
  because they are database only.
- Each copy of the site (development, staging, production) has **its own Drive
  folder and its own connected account**. A check on one proves nothing about
  another.

### The files that do the work

| File | Job |
|---|---|
| `app/student/student_upload_ai.php` | The upload form and its submit handler. |
| `config/gdrive_config.php` | Everything that talks to Drive: upload, stream, trash, folder checks. |
| `app/paper_file.php` | The only route by which a stored PDF reaches a browser. |
| `includes/pdf_dock.php` | The viewer panel on the three paper pages. |
| `app/student/pdf_viewer.php` | The full-window viewer. |
| `assests/js/papel-pdf-view.js` | The renderer: pages, zoom, watermark, cover. |
| `assests/js/pdfjs/` | A vendored copy of pdf.js 6.5.438 (`pdf.min.mjs`, `pdf.worker.min.mjs`). ES modules: there is no UMD build from v4 onwards. |
| `includes/pdf_writer.php` | PAPEL writing PDFs, not reading them. |
| `config/gdrive_inventory.php` | Which account owns which file, and can PAPEL read it. |

---

## 3. Workflow A: submitting a paper

**Actor:** student (or a Librarian digitising an older paper).
**Entry:** `app/student/student_upload_ai.php`, Step 4, Submit.

### The sequence, in order

1. **CSRF token verified**, role checked. A POST with a stale token is refused
   before anything else.
2. **Added supporting document rows are validated first**, at the very top of
   the handler, before a single file is moved. A row with a file and no name, a
   name and no file, two rows under one name, or more than six of them is
   refused in words. This happens first on purpose: a bad row answered after a
   minute of uploading is a minute wasted.
3. **Each PDF is validated** by `validate_pdf_upload()`:
   - the upload itself succeeded,
   - size is above zero and at or under `max_upload_bytes()`,
   - and `finfo` reports the real MIME type as `application/pdf`. The extension
     is not trusted.
4. **Drive is asked whether it is connected** (`is_gdrive_connected()`). If not,
   the submission stops here with a message a person can act on.
5. **The storage folder is checked** (`gdrive_check_folder()`), which asks Drive
   whether the connected account can actually add files to it. It can answer
   `ok`, `unset`, `not_found`, `not_folder`, `trashed`, `read_only` or
   `unknown`. Only the four definite faults stop a submission; `unknown` means
   Drive could not be asked and is not a verdict.
6. **Files are moved out of PHP's temp area** into a staging path.
7. **Each file is uploaded to Drive.** `upload_paper_to_gdrive()` creates or
   finds a nested folder path and puts the file at the end of it:

   ```
   PAPEL - DOCUMENTS / <programme> / <year> / <Paper type> / <paper title> / file.pdf
   ```

   Supporting documents go one level deeper, under `Supporting Documents/`.
   Each file is created with `viewersCanCopyContent: false`, and its owner
   metadata is recorded in `gdrive_file_inventory` from the same create
   response, so ownership is captured without a second API call.
8. **One database transaction** then writes everything: the `research_papers`
   row, one `paper_manuscripts` row per file, the `supporting_documents` rows,
   the `approval_workflow` entry and the adviser's notification.
9. **Commit.**
10. **The tail runs in its own `try`.** Old Drive files from a previous version
    are moved to Trash, and the adviser is notified. Nothing after the commit
    may report a failed upload: the paper is filed by then, and answering
    "upload failed" for a paper that was in fact filed invites filing it twice.

### If it fails

- **Before the commit:** the transaction is rolled back, every Drive file this
  request created is deleted outright with `files.delete`, and the staging
  copies are removed. Those files were made seconds earlier, nothing references
  them, nobody has seen them. Parking them in Trash would fill the folder with
  rubbish nobody could identify.
- **The message the student sees:** a `UserFacingException` is something they
  can act on (Drive not connected, a missing section, a bad date). A plain
  `Exception` is a fault and says nothing except a reference. Off production, the
  real cause is shown too, because on staging the server log is somewhere nobody
  testing can look.

### Rules that constrain this workflow

- **A draft holds no PDF.** Not on the server, not on Drive. The files stay in
  the browser. Coming back to a draft, the student attaches them again, and the
  "Continue your draft?" prompt only lists the names they had used. This is
  deliberate: nothing is stored for work nobody has handed in.
- **The ceiling is 20 MB per file**, `UPLOAD_POLICY_MAX_MB`. That is production's
  own host limit, measured rather than guessed. `max_upload_bytes()` takes the
  smallest of that, PHP's `upload_max_filesize` and PHP's `post_max_size`.
- **No user-facing text carries the number.** Every page, validator and message
  asks `max_upload_label()`, because the figure was once written into eight
  places and a host change made all eight a lie at once.
- **One paper, one or two files.** A paper handed in as both IMRAD and Full
  Manuscript is one paper with two files, never two papers.
- **The mirror rule.** `research_papers.file_path`, `.gdrive_file_id` and
  `.file_size` always describe the *primary* file: the Full Manuscript when
  there are two, otherwise the only one. Older readers depend on it, so anything
  that writes a paper's file writes the mirror in the same request.

---

## 4. Workflow B: review, return, resubmit

**Actors:** Subject Adviser, then Research Coordinator.

The PDF does not move during review. The same Drive file is read by the adviser,
by the Coordinator and later by the public; what changes is the paper's status
and therefore who may open it.

Two moments do touch the file.

### Returning a paper

Returning sets the status back to `draft` and attaches the reviewer's feedback.
`purge_paper_drive_files()` then **moves** every one of the paper's Drive files
into a `Trash` folder inside the storage folder, and clears the `gdrive_file_id`
on each row.

- **It is an ordinary folder, not Drive's own trash.** Drive's trash empties
  itself after 30 days and mixes PAPEL's files in with everything else the
  account has thrown away.
- **It used to be `files.delete`, which is final.** That call does not go through
  Drive's trash at all, so an adviser who returned a paper by mistake destroyed
  the student's only filed copy with nothing to recover. Changed 19 November
  2026.
- **A false return from the move means nothing happened.** The file is still
  where it was and the row is left pointing at it, so a Drive that cannot be
  reached loses nothing. A 404 reads as success, there being nothing left to
  move.
- **No storage folder, no Trash folder.** Otherwise a stray `Trash` would be
  created in the root of whichever account happens to be connected.
- **Nothing empties it.** The Director or the Coordinator opens it in Drive and
  clears it. The Storage Folder page counts what is waiting under "Removed PDFs"
  with a link to it.

### Resubmitting over an earlier version

Same path: the replaced files are moved to Trash, not deleted.

### Archiving (five years)

`ArchiveService::archivePaper()` moves the row from `research_papers` to
`papers_archive` in one transaction and refreshes the repository's filter cache.
**Google Drive is not touched at all.** The PDFs stay where they are under the
same ids. The paper leaves the public repository but is still readable by its
own link, still served by `app/paper_file.php`, still listed on the Director's
Archived tab and in a reader's My Library. Restoring is the exact reverse.

A caveat the panel may find: **nothing schedules the archive cron**, so the
five-year rule has never fired on its own. The rule itself works and was driven
by hand against a seeded six-year-old paper on 19 November 2026.

---

## 5. Workflow C: serving and viewing a PDF

This is the centre of the system and the part most worth being able to explain
without notes.

### Why PAPEL draws PDFs itself

The viewer used to frame **Google Drive's own preview**. That stopped after page
one for anyone not signed in to a Google account, on files set to block
downloading: a spinner, and no request for page two. Since a student reader
cannot be assumed to be signed in to Google, the viewer had to stop depending on
it. PAPEL now carries its own copy of **pdf.js** and renders the pages into
canvases.

### The request path, step by step

1. The reader presses a paper's file tile. The panel (`includes/pdf_dock.php`)
   or the full-window viewer (`app/student/pdf_viewer.php`) opens.
2. **pdf.js is loaded lazily** from `assests/js/pdfjs/pdf.min.mjs`, and its
   worker path is set to `pdf.worker.min.mjs` beside it. Both are vendored in
   the repo, not fetched from a CDN. It is an ES module, so the fetch is a
   dynamic `import()` made by `papelPdfView.loadLib()` the first time a document
   is opened, not a `<script src>`: a reader who never opens a PDF never pays
   for the 1.8MB. One promise is shared, so the panel and the upload page's text
   extraction cannot fetch it twice. `.htaccess` declares
   `AddType text/javascript .mjs`, because a module served as `text/plain` is
   refused outright and every PDF on the site would stop opening.
3. The viewer calls:

   ```js
   fetch(src, {
     headers: { 'X-PAPEL-Viewer': '1' },
     credentials: 'same-origin',
     cache: 'no-store'
   })
   ```

4. `app/paper_file.php` runs **four gates, in order**, and refuses at the first
   that fails:

   | # | Gate | Refusal |
   |---|---|---|
   | 1 | `X-PAPEL-Viewer: 1` present | 403 "Open this file from its page in PAPEL." |
   | 2 | Somebody is signed in | 403 "Sign in to open this file." |
   | 3 | The record exists, in `research_papers` or in `papers_archive` | 404 |
   | 4 | `paper_file_viewable()` says yes for this reader and this paper | 403 |

5. **The session is released** (`session_write_close()`) before the file is
   fetched, so a slow 100 MB download does not block every other page that
   reader opens.
6. `stream_from_gdrive()` asks Drive for the bytes. **Three attempts**, because
   the failures here are mostly momentary. A 401 is retried after forcing a
   token refresh. A 403 or 404 is Drive's verdict on that file and is not
   retried, because asking again cannot change it.
7. The bytes are **passed straight through**: output buffering is cleared first,
   nothing is cached, nothing is written to disk. The response carries
   `Content-Type: application/pdf`, `Content-Disposition: inline`,
   `Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff` and
   `Cross-Origin-Resource-Policy: same-origin`.
8. The browser hands the `ArrayBuffer` to `pdfjsLib.getDocument({ data })`.
   That call used to carry `isEvalSupported: false` to keep pdf.js 3.11 from
   compiling glyphs with `eval`, which is how CVE-2024-4367 ran a script hidden
   in a crafted font. Version 6 has no eval path to switch off, so the option is
   gone with the machinery behind it.
9. **Pages are laid out, then drawn one at a time**, and only those near the
   window. Scrolling past a page still being drawn cancels its render task.
10. For each page the canvas backing store is sized as
    `min(devicePixelRatio, 2, sqrt(8_000_000 / area))`, so a 109 page thesis at
    500% zoom on a phone cannot exhaust memory.
11. **The watermark is stamped before the page counts as drawn**, so there is no
    frame in which an unmarked page is on screen.
12. The text layer is built afterwards, and only for readers who get one.

### The header check, explained properly

`X-PAPEL-Viewer` is a header **no browser adds to an address it is simply
pointed at**. So pasting the file's URL into the address bar is refused rather
than opening the PDF with a Save button beside it.

Be honest about what this is: **it is not a lock.** The pages have to reach the
reader's browser for anyone to read them at all, exactly as they did when Drive's
viewer was framed. What it does is close the ordinary ways of downloading. The
real access decision is gate 4.

### Who may read what: `paper_file_viewable()`

In the order the function asks:

1. A guest pass or nobody signed in: **no**.
2. The paper's own author: **yes**.
3. An `admin` (Research Coordinator or Head of Academic Programs): **yes**.
4. A `faculty` member who created the author's account: **yes** (their own
   students).
5. If the file is a **supporting document** and none of the above matched: **no**.
   Supporting documents are never public.
6. Otherwise the paper must be **published or archived**. If it is not: **no**.
7. For a published paper: `faculty`, `super_admin`, `head_academic` and
   `librarian` get **yes**.
8. A `student` gets **yes** only with a live librarian grant
   (`student_manuscript_access()`).

### Zoom and panning

Zoom moves along the browser's own ladder
(`25, 33, 50, 67, 75, 80, 90, 100, 110, 125, 150 …`) rather than jumps of a
quarter. Ctrl and wheel used to take a whole step per wheel *event*, and a
touchpad sends dozens a second: a one-second flick went from 100% to the 25%
floor in 35 ms. Movement is now gathered (a mouse notch is 100 units, a step
needs 40) and two steps are never closer than 140 ms, so a touchpad and a mouse
zoom at the same pace as the buttons.

---

## 6. Workflow D: view protection

### What was asked for, and what is possible

Asked for as "make a screenshot or a screen recording come out black, the way
Netflix does". **That cannot be done on the web.** Netflix's black frame is not
something its page does: the video is decrypted and decoded inside a
hardware-protected path (Widevine L1, FairPlay) whose frames the compositor
refuses to hand to any capture API. That path is open to encrypted video and to
nothing else. No browser offers a page the equivalent of Android's `FLAG_SECURE`,
and no page can be told that it is being captured.

So PAPEL does the two things that are real, decided in one place,
`pdf_view_protection()`.

### Layer 1: the watermark, in the pixels

Two lines, tiled diagonally across the canvas:

```
<Full name> · <student or faculty ID>
23 Nov 2026, 1:19 pm · PAPEL, PUP Biñan
```

A Library Pass carries `Library Pass · <username>` instead. The time is **when
the page was opened**, not when the stamp was drawn, so a view left open all
afternoon carries the hour it began.

- **It is drawn into the canvas, not laid over it.** An element over the page is
  two keystrokes away in the inspector and never appears in what the compositor
  hands a screenshot. In the pixels, every capture there is, a photograph of the
  monitor included, names the account it was taken from.
- **It is sized in the reader's pixels, not the canvas's.** The backing store is
  up to twice the box on a high-density screen, so a naive size came out at
  about 35 px on screen and read as a poster over the paper. It asks for 13 px to
  the eye and stays that size at every zoom, because the mark belongs to the view
  and not to the page.

### Layer 2: no text layer

A protected view draws the canvas and no selectable layer, so a published paper
cannot be lifted whole with one Ctrl+A.

**The cost is real and was accepted deliberately:** there is no in-page text
search, and a screen reader gets nothing but the canvas's "Page 1 of 109".
Everything that promised selection follows it: the mode button is not drawn, the
H and T shortcuts do nothing, and the intro hint reads "Drag the page to move
it". It is one line in `pdf_view_protection()` if that trade is ever
reconsidered.

### Layer 3: the cover

The pages are covered whenever the window is not the one in front: on `blur`, on
`visibilitychange`, on a PrintScreen keyup, and on `beforeprint`. The print rule
is in the stylesheet **as well as** in the script, because a browser that renders
the sheet before `beforeprint` runs would otherwise put the paper on paper.

- **The cover fades out and never in.** 200 ms of fade on the way in is 200 ms of
  the paper still on screen after the window has gone, which is the whole of what
  a snipping tool needs.
- **PrintScreen is past tense.** Windows sends the keyup without a keydown, so
  the picture was already taken. The response is to cover, to try to clear the
  clipboard (which Chrome allows a focused page to do), and to say plainly that
  the document is watermarked. All of it is best effort.

### Who is protected

| | |
|---|---|
| **Protected** | A student with a librarian's grant, a student on their own paper's page, a Library Pass. |
| **Exempt** | Subject Adviser, Research Coordinator, Head of Academic Programs, Director, Librarian. Asked through `user_has_role()`, so a second post counts. |
| **Why exempt** | An adviser reads a paper to mark it against a checklist beside it. A document that blanked every time they looked at the form would obstruct the work. |
| **Not protected at all** | The upload page's own panel. That file is the student's own, chosen a moment ago, has never left their browser, and watermarking a paper somebody is still writing is in the way of its only reader. |

---

## 7. Workflow E: the manuscript request and grant

A published paper's **record** is public. Its **manuscript file** is not. A
student who wants the PDF asks a Librarian.

### Filing a request

`manuscript_request_file()` refuses in four cases, in order:

1. The asker is not a student.
2. The `manuscript_requests` table does not exist on this copy of the site.
3. The student already has a request **in progress** for this paper, meaning one
   that is pending, or granted and not yet expired.
4. The paper has no manuscript file on record.

Otherwise the row is written and **every active Librarian is emailed and
notified**, including anyone holding Librarian as a second post.

### The states

```
No request ──asks──> Pending ──granted, N hours──> Granted ──clock runs out──> Expired
                        │                              │
                        │                              └──librarian revokes──> Revoked
                        └──librarian denies──> Denied

   Expired, Revoked and Denied may all ask again.
```

**There is no `revoked` value in the database.** A revocation is written as
`denied` with `denied_at` set, and the `granted_at` already on the row is what
tells it from a refusal. A new ENUM value would be a migration every copy of the
site must run before the code can deploy at all, and an ENUM value this database
does not know is stored as an empty string in silence.

Every page asks `manuscript_request_state()` rather than reading `status`
directly, which is what keeps a student from being told their request was
"denied" when in fact they were given access and it was later ended.

### While a grant is live

- `student_manuscript_access()` requires `status = 'granted'` and
  `expires_at > UTC_TIMESTAMP() + INTERVAL 8 HOUR`, which is the Philippine
  offset.
- A countdown pill sits in the corner of every page, polling
  `app/manuscript_grants.php` every 30 seconds. The **server's** clock is used,
  not the device's: a laptop ten minutes fast would otherwise announce an expiry
  that has not happened.
- A grant the page has counted to zero is confirmed with the server before the
  "it has ended" pop-up appears. Reaching zero is a question, not a verdict.
- The paper is **pinned to the top of the public repository** for that student
  alone, for as long as the request is open, with a chip saying why.

### Revoking

The Librarian's desk ends a grant with **Revoke**, not Delete. Delete used to
remove the row, which stopped the student's access *and* erased every record
that access had ever been given. Ending access and rubbing out the record of it
are two different acts, and this desk only ever needed the first.

---

## 8. Minor workflows

### F. Extract with AI

The student presses it on Step 1 and the title, authors, year and keywords are
filled in.

1. `extract_pdf_text()` reads the uploaded temp file with **Smalot PdfParser**,
   with a crude regex fallback if the library is unavailable.
2. `pdf_text_clip()` cuts it to 25,000 bytes. **It cuts with `mb_strcut()`, not
   `substr()`.** `substr()` counts bytes, so cutting at a fixed length splits an
   "ñ", a "₱" or a curly quote whenever one falls on the line. The whole string
   is then invalid UTF-8, every `/u` pattern reading it fails silently, and
   `json_encode()` returns **false**, so the request to the AI went out with no
   body and the student read "The AI could not identify the details in this
   document". That affected one in sixteen of the papers on file.
3. The first 8,000 characters go to Groq. **The PDF itself never leaves the
   server**, only its text.
4. The Abstract comes from `extract_abstract_from_text()`, not from the AI,
   which copies it word for word or returns null. Null means the Abstract box
   keeps what the student typed. A wrong abstract is worse than none.

### G. The preview while writing

A PDF attached to the upload form opens in a pane beside it before anything is
submitted. The file is handed from one tab to another as a `File` object over
`postMessage`, same origin only. It is readable without ever having been
uploaded, and it is the one view with no watermark.

### H. Supporting document viewers

Each supporting document opens in a viewer of its own
(`papelPdfDock.popOut()`), not in the manuscript panel, which holds one file at a
time. Opening a consent form used to replace the paper the reader was checking it
against.

### I. PAPEL writing PDFs

The other direction. `includes/pdf_writer.php` writes a PDF from scratch: no
library, because none can be assumed on the host. It does headings, paragraphs,
tables with repeating headers, and JPEG figures.

- JPEG and not PNG, because a PDF can carry a JPEG byte for byte while a PNG
  would have to be decoded and recompressed, and GD is not enabled here.
- Text is written as WinAnsi, with characters outside it folded to an ASCII
  equivalent rather than dropped.
- **Librarian and administrator exports come out landscape**, decided by
  `export_landscape()`. The Subject Adviser's and the Head's stay portrait.
- **Nothing is cut to an ellipsis.** A value too long for its column is wrapped,
  including inside a word with no space to break at.

### J. The ownership check

`config/gdrive_inventory.php` collects the distinct Drive ids this installation
references, and the Storage Folder page lists them by owning Google account with
a badge each: Accessible, View only, Not accessible, In Drive trash, Not checked,
Not confirmed. It reads only PDFs this installation points at, never everything
in somebody's Drive. "View only" is **not** accessible to PAPEL, which streams a
file's contents.

### K. Compression, switched off

A browser worker used to shrink PDFs between 10 MiB and 36 MiB before upload,
using vendored pdf-lib and pako. It is **off** at the owner's instruction,
20 November 2026: the script tag is commented out and every call site asks
`window.papelPdfCompressor &&` first. The size limits never depended on it. A
file over the limit is refused and the student is asked to compress it
themselves.

---

## 9. Data model touchpoints

| Table | Columns that matter here |
|---|---|
| `research_papers` | `file_path`, `gdrive_file_id`, `file_size` (the mirror of the primary file), `manuscript_type`, `current_status` |
| `paper_manuscripts` | `paper_id`, `format` (`imrad`, `full`, `other`), `file_path`, `gdrive_file_id`, `file_size`. No foreign key, so rows survive archive and restore. |
| `supporting_documents` | `document_type` (a `VARCHAR`, not an ENUM, since 21 October), `document_name`, `gdrive_file_id` |
| `manuscript_requests` | `student_user_id`, `paper_id`, `status`, `duration_hours`, `granted_at`, `expires_at`, `denied_at` |
| `gdrive_file_inventory` | owner, parent folder, and the account and date used for the last access check |
| `papers_archive` | the same column list as `research_papers`; archiving copies the row across |
| `system_settings` | `gdrive_parent_folder_id`, the system OAuth token |

**Why `document_type` is a `VARCHAR`.** It was an ENUM that did not list
`copyright_doc`. MariaDB here runs without `STRICT_TRANS_TABLES`, so writing a
value an ENUM does not list stores an **empty string** and the insert still
reports success. Both the copyright document and any named optional document
were stored as `''`, and the label function quietly read `''` as the copyright
document. This is a good thing to be able to explain: it is a silent data
corruption that a test asserting "the insert succeeded" would never catch.

---

## 10. Known limitations, stated before you are asked

Say these first. A panel that finds a limitation you did not mention treats it as
a gap; one you volunteered is a design decision.

1. **A screen recorder running while the reader reads sees everything.** The
   cover answers a window losing focus, and a recording does not take focus away.
2. **Windows freezes the screen as its own snipping overlay opens**, faster than
   any script can respond.
3. **The raw PDF reaches the browser**, as it must for anyone to read it, so it
   can be saved out of the network panel by somebody who knows how.
4. **A protected view has no text layer**, so there is no in-page search and a
   screen reader hears only "Page 1 of 109".
5. **Nothing empties the Trash folder.** Deliberate, and it means somebody has
   to clear it.
6. **Nothing schedules the archive cron**, so the five-year rule has never fired
   by itself.
7. **Every uploaded PDF is given an `anyone: reader` permission on Drive.** This
   is left over from when Drive's own viewer was framed, and PAPEL's server-side
   streaming uses its own OAuth token, so the permission is no longer needed for
   the viewer to work. The file ids are not published anywhere a reader can see,
   so this is not an open door, but it is a wider permission than the system now
   requires. **If the panel asks about Drive-side security, say this before they
   find it.**
8. **Drive is a single point of failure for file viewing.** Records, search and
   the review desks all keep working without it.
9. **One Drive account's quota is the system's quota.** There is no sharding
   across accounts.

---

## 11. Final defence question bank

Model answers are short on purpose. Say the short answer, then stop. Offer the
detail only if asked.

### 11.1 Architecture and design choices

**Q1. Why did you not store the PDFs on your own server?**
Three reasons. Hosting disk is the one thing that would have grown without
bound, and free hosting caps it hard. A file on our server is a file we have to
back up and secure. And Drive gives the campus a copy they can still reach if
PAPEL goes away. The trade is that Drive becomes a dependency for viewing files,
which we accepted.

**Q2. Why Google Drive and not Amazon S3, or Dropbox?**
The campus already uses Google Workspace, so there is an account, a quota and
somebody who administers it. S3 needs a paid AWS account and a card. The API cost
us nothing and the client library was already available for PHP.

**Q3. Why no framework? Why not Laravel?**
The system is deployed by copying files to shared hosting with no shell and no
Composer. A framework needs a build step, a writable cache directory and a
console to run migrations, none of which that host gives us. Everything we needed
(routing, templating, a database layer) is small enough to write directly.

**Q4. Is that not harder to maintain?**
It is a real trade. We mitigated it by putting every shared thing in one place:
one header, one footer, one review console used by four desks, one file that
serves every PDF. And the conventions and the traps are written down in
`CLAUDE.md` so the next person does not rediscover them.

**Q5. Why did you write your own PDF viewer instead of embedding one?**
We embedded Google Drive's preview first. It stopped after page one for anyone
not signed in to a Google account on a file set to block downloading, which is
most of our students. We could not require a Google sign-in to read a paper, so
we had to render it ourselves.

**Q6. Why pdf.js specifically?**
It is Mozilla's, it is what Firefox itself uses, it renders to a canvas (which is
what lets us watermark), and it runs entirely in the browser so no server does
rendering work. We vendored it rather than loading it from a CDN so the viewer
works without a third-party request.

**Q7. Why write your own PDF export writer rather than use a library like FPDF or TCPDF?**
The host allows about thirty thousand files and our deploy already trims
`vendor/` to about nine hundred. TCPDF alone is several thousand. We needed
headings, paragraphs, tables and JPEG figures, which is about four hundred lines
written directly.

**Q8. Why is the diagram in your paper not the same as the code?**
If this is asked, the honest answer is the one to give, and then say which is
correct. Do not defend a diagram you know is out of date. This is exactly what
section 0 of this brief exists to prevent.

### 11.2 The PDF viewer (expect the most questions here)

**Q9. Walk us through what happens when a student opens a paper's PDF.**
The viewer loads pdf.js, then fetches the file from `app/paper_file.php` with a
custom header. The server checks four things in order: that the header is there,
that somebody is signed in, that the record exists, and that this reader is
allowed this paper. Then it asks Drive for the bytes and passes them straight
through without storing them. The browser hands the bytes to pdf.js, which draws
one canvas per visible page, and each page is watermarked before it is shown.

**Q10. What is the `X-PAPEL-Viewer` header for?**
No browser adds that header to an address you simply type in. So pasting the file
URL into the address bar is refused instead of handing over the PDF with a Save
button beside it. It is not a security control; the real access decision is the
fourth gate. It closes the easy route.

**Q11. Could I not just add that header myself with a tool like Postman?**
Yes, and it would still fail, because you would also need a valid session cookie
for an account that is allowed that paper. The header stops accidental and casual
downloading, not a determined person with credentials. The access control is
`paper_file_viewable()`.

**Q12. Why do you not cache the PDF on the server to make it faster?**
Because then we would be storing papers, which is the thing we decided not to do.
A cached file has to be invalidated, secured and backed up. The second open is
slower than it would be with a cache, and we accepted that.

**Q13. Is it not slow to fetch from Drive every time?**
There is a round trip to Drive on each open. We reduced what the reader waits
for: the session is released before the fetch so other pages are not blocked, the
request is retried three times because most Drive failures are momentary, and the
browser starts drawing as soon as the bytes arrive rather than waiting for the
whole document.

**Q14. How do you handle a 200-page thesis without the browser freezing?**
Only pages near the window are drawn, one at a time. Scrolling past a page that
is still rendering cancels it. Parsing happens in a Web Worker, off the main
thread, so the page stays responsive. And each canvas is capped in total pixel
area so a high-resolution screen at high zoom cannot exhaust memory.

**Q15. What happens if Google Drive is down?**
The file does not open and the reader gets a message naming the likely cause:
file missing, Drive disconnected, or a momentary failure they can retry. Nothing
else in the system stops. The records, the search, the review desks and the
dashboards are all database only.

**Q16. What if the Drive file was deleted by a staff member in Drive directly?**
The record stays and the viewer answers 404 with a message telling the reader to
inform the Research Coordinator, who can have the file uploaded again. The
Storage Folder page flags it as inaccessible on its next check. We never label it
"deleted", because we cannot tell deletion from a permission change.

**Q17. Why is the file not just downloadable?**
Because an unpublished student's thesis is not meant to be redistributed. The
author keeps the copy; the archive is for reading. A student who needs the file
asks a Librarian, who grants timed access.

**Q18. How does zooming work?**
On the browser's own ladder of steps, so 100 to 110 to 125 rather than jumps of a
quarter. Ctrl and wheel is gathered rather than stepped per event, because a
touchpad sends dozens of events a second and a one-second flick used to go from
100% to the floor in 35 milliseconds.

### 11.3 Security and access control

**Q19. Who can read a paper's PDF?**
The author, any administrator, the adviser who created the author's account, and
for a published or archived paper also faculty, the Director, the Head and
Librarians. A student who is not the author needs a live grant from a Librarian.
Supporting documents are narrower still: never public, whatever the paper's
status.

**Q20. Where is that enforced?**
In `paper_file_viewable()`, called by `app/paper_file.php`, which is the only
route by which a stored PDF reaches a browser. The pages that show file tiles
apply the same rule, but the file route does not trust that a tile was ever on
the page.

**Q21. What stops a student from guessing another paper's id in the URL?**
The id is in the URL, but the fourth gate checks this reader against this paper
before any byte is sent. Guessing an id gets a 403. This class of bug is called
insecure direct object reference, and the fix is exactly this: authorise the
object, not the link to it.

**Q22. How do you prevent SQL injection?**
Prepared statements everywhere. The one place SQL is interpolated is a
whitelisted sort column in the analytics page, where the value is checked against
a fixed list before it is used.

**Q23. How do you prevent cross-site scripting?**
Output is escaped with a helper, `e()`, and there is a Content Security Policy
with nonces. The policy has no `unsafe-inline` for styles or scripts, so an
injected `<script>` or a style attribute does not run.

**Q24. CSRF?**
Every form carries a token and every POST handler verifies it before doing
anything. A stale token is refused.

**Q25. Can somebody open the Drive file directly, going around PAPEL?**
Give limitation 7 here, honestly: the files carry an `anyone with the link can
read` permission left over from when Drive's own viewer was embedded, and we no
longer need it because the server streams with its own token. The file ids are
not published anywhere a reader can see, so there is no link to follow, but
tightening that permission is the first item on our hardening list.

**Q26. How are the Google credentials stored?**
In `.env` and a token file, neither of which is in version control, and a deploy
leaves both alone. The OAuth tokens are never written into the history table that
records who connected Drive and when.

**Q27. Is the connection between PAPEL and the reader encrypted?**
Production is served over HTTPS behind Cloudflare, and the session cookie is
marked `Secure` whenever the request is HTTPS. That is decided by the request
itself rather than by a setting somebody could forget, because a copy served over
HTTPS whose configuration said otherwise used to hand its session cookie to plain
HTTP.

### 11.4 The watermark and view protection

**Q28. Can you stop somebody screenshotting a paper?**
No, and no web application can. The black frame you see on Netflix comes from a
hardware-protected decode path that browsers give to encrypted video and to
nothing else. A page cannot even be told it is being captured. So we did the two
things that are possible: we put the reader's identity into the image, and we
cover the page when the window is not in front.

**Q29. Then what is the point?**
Deterrence and traceability. Prevention is not available; attribution is. Any
capture at all, including a phone photograph of the monitor, carries the name,
the ID and the hour of the account that opened it.

**Q30. Why is the watermark drawn into the canvas instead of placed over it?**
An element laid over the page is two keystrokes away in the browser's inspector,
and it never appears in what a screenshot captures anyway, because the
compositor hands over the painted pixels. Drawn into the canvas, it is part of
the image.

**Q31. Could a student not just edit the canvas in the inspector?**
They could draw over the canvas, but the stamp is applied during rendering, so
the clean pixels never exist in the page to be recovered. They would be painting
over a marked image, not revealing an unmarked one.

**Q32. Why do staff not get the watermark?**
Because they read papers as their work. An adviser marking a paper against a
checklist beside it would have the document blank every time they looked at the
form. The protection exists for readers of published work, not for the people
reviewing it.

**Q33. Is it not a problem that the protected view has no text layer?**
It is a real cost and we accepted it knowingly. There is no in-page search, and a
screen reader gets only the page number. The reason is that one Ctrl+A would
otherwise lift an entire thesis as text. It is one line to reverse if the campus
decides accessibility matters more than that.

**Q34. That sounds like an accessibility problem. What is your answer?**
It is. The rest of the system is built for screen readers, and this one view is
not. The honest position is that we chose protection over accessibility for the
manuscript view only, and we have written down where to change it. A better
answer in future work would be a server-rendered text alternative for readers who
need one, gated the same way the file is.

### 11.5 Data integrity and failure handling

**Q35. What happens if the upload fails halfway through?**
The database write is one transaction, so either the whole paper is filed or none
of it is. If the transaction fails, every Drive file that request created is
deleted outright, because they were made seconds earlier and nobody has seen
them.

**Q36. Why check Drive before uploading rather than handling the error after?**
Because a half-stored paper is worse than a refused one, and because connection
and folder are two separate settings that fail separately. Checking first means
the student is told in words what is wrong instead of getting a generic failure.

**Q37. Why is deleting a returned paper's file different from deleting a failed upload's?**
The question is whether anybody could still want it. A returned paper might have
been returned by mistake, so its file is moved to a Trash folder where a person
can fetch it. A file from a submission that never completed cannot be wanted by
anyone, so it goes.

**Q38. Why not use Google Drive's own trash?**
It empties itself after 30 days, and it mixes PAPEL's files in with everything
else that account has ever thrown away. A folder we control means the Coordinator
can see exactly what PAPEL removed and when.

**Q39. You said an invalid ENUM value is silently discarded. Explain.**
MariaDB here runs without strict mode, so writing a value an ENUM does not list
stores an empty string and the insert still reports success. We hit that twice:
notification types and supporting document types. The second one meant a named
document and the copyright document were both stored as an empty string, and the
label function read the empty string as "copyright". The fix was to make it a
`VARCHAR` checked in PHP, so a wrong value fails loudly.

**Q40. How do you know a paper's file is still there?**
The Storage Folder page checks ownership and accessibility for each referenced
file, in batches, and shows a badge per file. A failed lookup keeps the last
known owner rather than overwriting it, and a 404 is labelled inaccessible or
absent, never "deleted", because we cannot tell those apart.

**Q41. What if the database says one thing and Drive says another?**
The database is the index and Drive is the store, so the database can point at a
file that is gone. That shows up as a 404 in the viewer and as "not accessible"
in the Storage Folder. There is no automatic reconciliation; a person is told and
decides.

### 11.6 Performance and scalability

**Q42. How many papers can this handle?**
The database side scales with the index; the repository's filter lists are cached
in one compressed snapshot rather than recomputed. The real ceiling is the Drive
account's quota, since every paper is a file in one folder tree. At 27 approved
papers the queries use the status index and still sort in memory for the
date-order expression, which is measured and documented; it would need an index
change well before the quota mattered.

**Q43. What is the slowest part of the system?**
Opening a PDF, because of the round trip to Drive. Everything else is a database
query against indexed columns.

**Q44. How did you measure that rather than guess?**
Browser performance traces against the running site, with the cache disabled and
network conditions throttled realistically. That is also how we found the campus
photo was the largest contentful paint on nearly every page, and that the icon
font was 3.84 MB before we narrowed what we asked for.

**Q45. Why is the file size limited to 20 MB?**
Because that is what production's host actually accepts. The owner's cap was 30,
but the live site already refused anything over 20, so a paper that uploaded in
testing could be refused by the real site. We measured production's own figure
and lowered ours to match.

**Q46. You built a PDF compressor and turned it off. Why?**
It saved only about 3% on the owner's own paper losslessly, and the lossy mode
that saved more rasterised images at a quality the owner did not want for an
archived thesis. The size limits never depended on it. The code is still there
behind a check, so it can be switched back on.

### 11.7 Privacy, ethics and compliance

**Q47. What personal data does the watermark expose?**
The reader's own name and ID, to the reader. It is not shown to anybody else
unless the reader shares the capture, which is the point of it.

**Q48. Is the student's paper shared with the AI?**
No. Only extracted **text**, capped at 8,000 characters for the prompt, and only
when the student presses Extract with AI. The file itself never leaves the
server. The chatbot is separately prevented from being told anything off the
reader's screen: it is sent the page address and nothing else.

**Q49. What about the Data Privacy Act?**
The system sets exactly one cookie, the session, which is strictly necessary and
consent-exempt. There is no analytics and no advertising anywhere in the
application. The privacy page names the one cookie, every browser preference key,
reCAPTCHA and the remaining library CDNs.

**Q50. Why is there no cookie consent banner?**
Because there is nothing non-essential to refuse. A banner whose only option
either breaks sign-in or does nothing is theatre. Instead we removed the actual
third-party contact: the fonts are served from our own origin now, so reading the
public repository contacts nobody.

**Q51. Who owns the papers?**
The authors. PAPEL is an archive, not an assignment of rights, which is why the
copyright document is one of the supporting documents collected at submission.

**Q52. Can a student withdraw their paper?**
There is a withdraw window before review, and a paper can be returned or
archived afterwards. Archiving removes it from the public repository while
keeping it readable by its own link.

### 11.8 Testing and verification

**Q53. How did you test this?**
Against the running application rather than by reading code, because several bugs
in this project looked correct in source and failed in the browser. For
behaviour we drive a real headless browser over the DevTools protocol; for layout
we take screenshots and read pixel measurements.

**Q54. Do you have unit tests?**
There are unit-style checks for the pure rules: what an account field may
contain, what a paper type may be called, how a name is split, how an abstract is
found. The rest is driven end to end, including real submissions that create and
then clean up real Drive files.

**Q55. How do you test something that needs a Director's account?**
By writing a session file directly, which is what signing in does. Scripted
sign-in through the form does not work, deliberately, because reCAPTCHA exists to
stop exactly that.

**Q56. Give an example of a bug you only found by testing in the browser.**
Several. A conflict marker committed into a PHP file became a text node, and
because `<body>` is a flex container that text became a flex item one line tall,
pushing the whole navbar 22 pixels down the page. PHP's linter passed it happily,
because a marker outside `<?php` is valid HTML text.

### 11.9 Scope, limitations and future work

**Q57. What would you do differently?**
Tighten the Drive file permission, which is wider than the design now needs.
Schedule the archive job, which works but nothing triggers. And give the
protected viewer a text alternative for screen readers.

**Q58. What is the biggest risk to this system in production?**
The single Drive account. Its quota, its token and its owner are all one point of
failure for every PDF. Mitigating it properly means more than one storage account
and a way to choose between them.

**Q59. Could this support another campus?**
The data model would carry it, but the storage folder, the programme list and the
role structure are all single-campus assumptions today. It is a change of scope,
not a change of architecture.

**Q60. What happens when the Drive account runs out of space?**
Uploads begin to fail, and the student is told the real reason because the folder
check reports it. There is no automatic overflow to a second account.

### 11.10 Curveballs

**Q61. Show me where in the code that happens.**
Know these five paths by name: `app/student/student_upload_ai.php` (submit),
`config/gdrive_config.php` (Drive), `app/paper_file.php` (serving),
`assests/js/papel-pdf-view.js` (rendering), `config/core.php`
(`paper_file_viewable`, `pdf_view_protection`).

**Q62. What is the single most important design decision in this part of the system?**
That PAPEL never stores a PDF. Everything else here follows from it: the Drive
dependency, the streaming route, the four gates, the fact that there is nothing
on the server to steal.

**Q63. If I had thirty seconds, how would you describe the PDF viewer?**
It is a reading room. The file never leaves the stockroom permanently, you read
it at the desk, the desk checks you are allowed in, and every page you look at
has your name printed across it.

**Q64. Did you build this or did an AI build it?**
Answer with the division honestly and specifically: the design decisions, the
requirements and the testing against the real system are the team's. Be ready to
explain any one mechanism in this document without notes, because that is the
real question being asked.

---

## 12. One-line answers worth memorising

- PAPEL stores **no PDF on its own server**. Files stream from Drive on demand.
- There are **four gates** before any byte is sent, and the real one is the
  fourth.
- The watermark is **in the pixels**, not over them.
- **Prevention is impossible on the web; traceability is what we built.**
- Returned files go to a **Trash folder**, not to deletion.
- A failed submission's files **are** deleted, because nobody ever saw them.
- Archiving is a **database move**; Drive is never touched.
- The size ceiling is **20 MB**, which is production's own measured limit.
- Only **text** goes to the AI, never the file.
- A revoked grant is **not** a denied one, and the system says so.
