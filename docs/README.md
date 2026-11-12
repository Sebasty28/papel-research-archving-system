# What is in `docs/`

An index, so that nobody has to open eight files to find out which one is still
true. **`CLAUDE.md` at the top of the project is the first thing to read**: it is
the conventions and the traps, it is kept current, and nothing here supersedes it.

Each entry says what the file is **for** and whether it is **current** (describes
the system as it stands) or a **record** (describes something that happened, and
is not maintained). A record is not stale; a record that claims to be current is.

---

## Current

| File | For |
|---|---|
| [WORKFLOW_DIAGRAM.md](WORKFLOW_DIAGRAM.md) | The approval chain: every status, who sets it, how a paper goes backwards, and the withdraw window. Cited to the lines that write each status. |
| [PAPEL_System_Overview.md](PAPEL_System_Overview.md) | The long reference: what the system is, the version history, the database design. Written for Chapters 2 and 3 of the capstone paper, so it explains things the code takes for granted. |
| [RELEASING.md](RELEASING.md) | How the version number in the footer is set and what to click to move a build to staging or production. GitHub Desktop, no commands. |
| [DEPLOYING_PRODUCTION.md](DEPLOYING_PRODUCTION.md) | Setting up a copy of the site from nothing: hosting, secrets, database, Drive connection, storage folder. Production was built from it on 12 November 2026, so it is now also the record of that. |
| [GIT_GUIDE.md](GIT_GUIDE.md) | Git and GitHub for someone who has not used version control before. Explains what the commands do, not only what to type. |
| [field_guide.html](field_guide.html) | The architecture field guide: what each part of the code is and how the parts reach each other. **Generated** by `scripts/build_field_guide.py`, which measures everything countable on every run and fails loudly if it names a file that has gone. Do not edit the HTML; edit `scripts/field_guide_content.py` and rebuild. |
| [diagrams/](diagrams/) | The entity-relationship and use case diagrams as Figma-importable SVGs, each with the PHP script that generates it from `database/schema.sql`. Regenerate rather than edit. |

## Records

| File | Of |
|---|---|
| [security_vulnerability_fixes.md](security_vulnerability_fixes.md) | The OWASP ZAP findings and what was done about each, 19 June 2026. Referenced from the System Overview as the security record. |
| [GROQ_MODEL_MIGRATION.md](GROQ_MODEL_MIGRATION.md) | Moving off `llama-3.3-70b-versatile` before Groq decommissioned it, 15 August 2026, and the trap that the model was hardcoded in two files that never read `GROQ_MODEL`. |
| `PLAN_*.md` (four files) | The plans for four features, each written before the code and each noting in its section 0 where the build departed from it: the two manuscript files, which written sections a paper is filed with, the review checklist, and sections of the author's own. All four are implemented; `CLAUDE.md` is where their rules are maintained. |
| [handbook/](handbook/) | The Workflow Handbook, HTML and PDF, with the Python that builds it. **The built copy is from 23 September 2026 and `content.py` has moved on since**, including today's rename of the Research Adviser to the Subject Adviser (50 mentions). Rebuild before handing it to anyone: `python docs/handbook/build_handbook.py`. |

---

## Cleared out on 12 November 2026

Thirteen files went. They are in the git history; this note is here so that nobody
finds a reference to one and assumes something is missing.

- **Five from January 2026** (`QUICK_START`, `INSTALLATION_CHECKLIST`,
  `BEFORE_AFTER`, `README_IMPROVEMENTS`, `SECURITY_IMPROVEMENTS`) described a
  "security and performance improvements package" and told the reader to run
  `run_migration.php`, `security_audit.php` and `archive_handler.php`. None of
  those three files exists any more. The SQL injection fixes they describe did
  happen; prepared statements everywhere is now a convention in `CLAUDE.md`, and
  the security record is `security_vulnerability_fixes.md`.
- **Six about a five-stage approval chain** (`ADMIN_LEVEL_IMPLEMENTATION`,
  `SETUP_TWO_LEVEL_ADMIN`, `COMPLETE_UPDATE_GUIDE`, `IMPLEMENTATION_SUMMARY`,
  `UPDATE_PROGRESS_TRACKER`, and the old `WORKFLOW_DIAGRAM`) were step-by-step
  instructions to add approval stages for the Head of Academic Programs and the
  Director. That chain was deliberately shortened to end at the Research
  Coordinator, so the files were not merely out of date: they told the reader to
  undo a decision. `config/workflow.php` records the same reasoning for deleting
  two dead functions that gave the wrong chain. `WORKFLOW_DIAGRAM.md` has been
  rewritten to the chain that exists.
- **`Capstone_RMS_Path_Fix_Guide.md`**, 24KB of instructions for correcting
  `require` paths after the folder restructure, finished in February 2026.
- **`GROQ_AI_FEATURES.md`**, a wish list of AI features nobody built (plagiarism
  scoring, automatic quality assessment), which `GROQ_MODEL_MIGRATION.md`
  nonetheless linked as "what each AI feature does".

---

## Before adding a file here

- **Would it be truer in `CLAUDE.md`?** A convention, a trap or a rule about how
  the code is written belongs there, where it is read at the start of every
  session. A document nobody opens is a document nobody corrects.
- **Say the date and say which kind it is.** A record that reads as current is
  what made the two clusters above dangerous rather than merely untidy.
- **Do not write a list of steps for somebody else to apply to the code.** Four
  of the deleted files were that, and the code moved while they waited. Change the
  code, then describe it.
