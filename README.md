# PAPEL — PUP Biñan Research Archiving System

A web system for submitting, reviewing and publishing student research papers.
Students upload their work, a Research Adviser reviews it, the Research
Coordinator approves and publishes it, and the public repository makes the
approved papers readable.

**Stack:** PHP 8 · MariaDB/MySQL · XAMPP · vanilla JS · Chart.js

---

## Setting up on your computer

You need XAMPP (Apache + MySQL) and Git.

```bash
# 1. Get the code
cd C:/xampp/htdocs
git clone https://github.com/Sebasty28/papel-research-archving-system.git capstone
cd capstone

# 2. Create your settings file
copy .env.example .env
```

Then open `.env` and fill in the real values. **This file is deliberately not in
the repository** — it holds passwords and API keys, and those must never be
committed. Ask whoever set the project up for the current values.

```bash
# 3. Create the database
#    Open http://localhost/phpmyadmin, create a database named: capstone_db
#    Then import the .sql file you were given.

# 4. Start Apache and MySQL in the XAMPP control panel, then open:
#    http://localhost/capstone
```

> **The database is not in this repository.** Code lives in Git; data does not.
> When one of you changes the database structure — a new column, a new table —
> export it and send it to the other, or share a migration script.

---

## Working together — Seb and Rayver

You are two people editing the same project. Everything below exists to stop you
overwriting each other's work.

### The one rule

**Pull before you start. Push when you finish.**

Most problems come from someone working for three hours on an old copy of the
project. Thirty seconds of `git pull` at the start avoids nearly all of them.

---

### Every working session

```bash
# 1. Before you touch anything — get the other person's latest work
git pull

# 2. Do your work. Edit files normally.

# 3. See what you changed
git status

# 4. Stage and save it
git add -A
git commit -m "Say what you changed, plainly"

# 5. Send it up so the other person can get it
git push
```

That is the whole loop. Repeat it every session.

---

### Working on the same day without collisions

If you are both working at once, use a branch each. A branch is your own copy of
the project that does not disturb the other person's.

```bash
# Seb starts a piece of work
git switch -c seb/analytics-filters

# Rayver starts a different piece
git switch -c rayver/upload-fix
```

Work, commit, then push your branch:

```bash
git push -u origin seb/analytics-filters
```

On GitHub, open a **Pull Request** — that asks the other person to look at your
work before it joins `main`. When it is approved, click **Merge**. Then both of
you run:

```bash
git switch main
git pull
```

Now you are both back in step.

---

### Naming your commits

Write for the other person, who will read it and wonder what you did.

```
Good   Add year and section to the student create form
       Fix the login redirect for Head of Academic Programs
       Remove the orphaned upload PDFs

Bad    update
       fix
       asdf
       final na talaga
```

---

## When git complains

### "Updates were rejected" / "non-fast-forward"

The other person pushed something you do not have yet.

```bash
git pull        # bring their work down
git push        # now yours will go up
```

### "CONFLICT (content): Merge conflict in somefile.php"

You both edited the same lines. Git cannot guess who is right, so it asks you.

1. Open the file. You will see:

   ```
   <<<<<<< HEAD
   your version
   =======
   their version
   >>>>>>> main
   ```

2. Delete the `<<<<<<<`, `=======` and `>>>>>>>` lines, and keep the code that
   should survive — sometimes yours, sometimes theirs, sometimes both.
3. Then:

   ```bash
   git add somefile.php
   git commit -m "Merge Rayver's changes with mine"
   git push
   ```

**If the conflict is confusing, message the other person before guessing.** It
is their code you would be deleting.

### "I need to pull but I have unfinished work"

```bash
git stash        # put your changes aside
git pull
git stash pop    # bring them back
```

### "I broke something and want to start over"

```bash
git restore path/to/file.php     # undo edits to one file (not yet committed)
git restore .                    # undo ALL uncommitted edits — careful
```

Already committed but not pushed:

```bash
git reset --soft HEAD~1          # undo the commit, keep the edits
```

Already pushed:

```bash
git revert <commit-hash>         # a new commit that undoes the old one — safe
```

Find the hash with `git log --oneline`.

> Use `revert`, never `reset`, for anything already pushed. `reset` rewrites
> history and will make the other person's copy disagree with yours.

---

## Never commit these

`.gitignore` already blocks them, but know why:

| What | Why not |
|---|---|
| `.env` | Passwords, API keys, the mail password |
| `client_secret*.json` | Google OAuth secret |
| `app/student/uploads/` | Students' papers, consent forms, copyright forms — other people's personal documents |
| `vendor/` | Installed libraries; restored with a command |
| `*.log` | Noise, sometimes personal data |

Before every commit:

```bash
git status --short
```

If you see `.env`, a PDF, or anything under `uploads/` — **stop** and unstage it:

```bash
git restore --staged the-file
```

> **If a password or key ever does get committed:** deleting it in a later
> commit is not enough. It stays in the history and anyone who clones the
> repository can read it. The only real fix is to **revoke that key and issue a
> new one.**

---

## Signing in

Staff, librarians and the Director all sign in on the **Faculty / Admin** tab —
not a tab of their own. Students use the **Student** tab.

You sign in with your **ID**, not a username:

| Role | ID looks like |
|---|---|
| Student | `2023-00056-BN-0` |
| Research Adviser | `FAC-2026-001` |
| Research Coordinator | `ADM-2026-001` |
| Head of Academic Programs | `HAP-2026-001` |
| Director | `DIR-2026-001` |

---

## How a paper moves through the system

```
Student uploads  →  Research Adviser reviews  →  Research Coordinator approves
                                                          ↓
                                              Published to the public repository
```

The Head of Academic Programs and the Director read what comes out of it; they
do not approve. A returned paper goes back to the student as a draft with the
reviewer's feedback attached.

---

## Where things live

| Folder | What is in it |
|---|---|
| `app/student/` | Upload, dashboard, paper details |
| `app/faculty/` | Adviser review desk, Manage Students |
| `app/admin/` | Coordinator and Director desks, Manage Faculty, Manage Admins |
| `analytics/` | The analytics page |
| `archive/` | Public repository |
| `includes/` | Shared header, footer, styles and dialogs |
| `config/` | Settings, database connection, shared helpers |
| `scripts/migrations/` | Run these once to add new database columns |
| `docs/` | Longer notes, including `GIT_GUIDE.md` |

---

## Quick reference

```bash
git pull                     # get the other person's work
git status                   # what have I changed?
git diff                     # what exactly did I change?
git add -A                   # stage everything
git commit -m "message"      # save a checkpoint
git push                     # send it up
git log --oneline            # history
git switch -c my-branch      # start a branch
git switch main              # go back to main
```

A longer explanation of each command is in [docs/GIT_GUIDE.md](docs/GIT_GUIDE.md).
