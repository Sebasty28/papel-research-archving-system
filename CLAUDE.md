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
| `librarian` | Librarian | `app/guest/admin_manage_guests.php` |

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
| `action_dialogs.php` | Site-styled confirm on any `[data-confirm]` element |
| `manage_save_confirm.php` | "Save these changes?" with a field-by-field diff |
| `theme.php` | Palette + light/dark, applied pre-paint |
| `scroll_jump.php` | Scroll to top/bottom button |

**Never use `alert()` or `confirm()`.** Put `data-confirm="..."` on the button
and `action_dialogs.php` handles it.

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

Sign-in is **ID + password**. No usernames, no birthdate (both were removed).

The lookup matches `username`, `email`, `student_id` **or** `faculty_id` —
students carry their ID in `student_id`, staff in `faculty_id`, and which column
depends on who created the account. All four are checked.

`admin_level` is stored in the session at login. Do not reintroduce a
`$u['admin_level'] ?? 1` that reads from a session which never had it — that bug
gave a HAP the Coordinator's navbar for months.

Staff, librarians and the Director all sign in on the **faculty** tab. Only
`selected_role` values `student`, `faculty` and `guest` exist.

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

### Programmes

`programs_map()` and `program_code()` in `config/core.php`. Full name in the
database, short code (`BSIT`) in tables and chips.

---

## Database

**Not in the repository.** Schema changes are shared as a `.sql` export or a
migration script in `scripts/migrations/`.

Migrations are plain PHP, safe to run twice, and idempotent — they check whether
the column exists first. Run them from the CLI:

```bash
php scripts/migrations/run_student_cohort_migration.php
```

Recent ones: `run_student_cohort_migration.php` (academic_year, section),
`run_account_expiry_migration.php` (expires_on).

Papers deleted from `research_papers` leave rows behind in `approval_workflow`,
`paper_checklist` and `supporting_documents`. `papers_archive` is *meant* to
outlive its papers — never treat its rows as orphans.

---

## Testing

There is no test framework. Changes are verified against the running app:

1. Sign in over HTTP with a real account and fetch the page.
2. For behaviour, inject a probe script into the fetched HTML and run it in
   headless Chrome (`--headless=new --dump-dom`), writing results into
   `document.title`.
3. For layout, take a screenshot and measure with `getBoundingClientRect()`.

**Verify against the running site, not by reading code.** Several bugs in this
project's history looked correct in source and failed in the browser — a form
closed early by a stray `</div>`, a dialog painted under its own backdrop, a
`\b` regex that never matched at end-of-string.

Seed realistic data, assert, then clean up. Never leave test rows behind.

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

- **`time_to_approval` is never written.** The `analytics` table has the column
  and the dashboard has a tile for it, but nothing populates it on approval, so
  it always reads "—".
- **`CSRF_KEY` is still the placeholder** in `.env`. Changing it logs everyone
  out once.
- **The Director's password is unrecoverable.** Testing Director-only pages
  means minting a session file directly in `C:/xampp/tmp` (26-character id from
  the `0-9a-v` alphabet — PHP rejects anything else).
- **17 files in `docs/`**, several stale. `ADMIN_L2_DASHBOARD.md` in particular
  describes routing that has since changed.
