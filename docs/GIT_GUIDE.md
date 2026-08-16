# Working with Git and GitHub — a beginner's guide for PAPEL

This is written for someone who has not used version control before. It explains
what the commands actually do, not just what to type, so that when something
goes wrong you have some idea why.

Everything here is run from the project folder:

```bash
cd c:/xampp/htdocs/capstone
```

---

## 1. What git is doing

Git keeps a history of your project. Think of three places a file can be:

| Place | What it means |
|---|---|
| **Working tree** | The actual files on your disk, as you edit them right now. |
| **Staging area** | The changes you have chosen to include in your *next* save point. |
| **History** | The saved points (commits). Permanent, and shareable. |

A file moves along that line: you edit it, you *stage* it, you *commit* it.
**GitHub** is just a copy of the history kept on a server so other people — and
other computers — can get at it.

Nothing you do locally reaches GitHub until you `push`.

---

## 2. The five commands you will use most

### See where you are

```bash
git status
```

Run this constantly. It tells you which files changed, which are staged, and
which branch you are on. If you only ever learn one command, learn this one.

```bash
git status --short      # the same thing, compact
```

### See what actually changed

```bash
git diff                # changes you have NOT staged yet
git diff --staged       # changes you HAVE staged, i.e. what a commit would contain
git diff config/config.php   # just one file
```

Read this before every commit. It is the difference between "I changed the
login page" and "I changed the login page and also accidentally deleted a
function".

### Stage the changes you want to keep

```bash
git add analytics/analytics_dashboard.php     # one file
git add analytics/                            # a whole folder
git add -A                                    # everything that changed
```

`git add -A` is convenient and is how most people work, but it is also how
secrets and junk get committed. Run `git status` first and look at the list.

Changed your mind before committing?

```bash
git restore --staged somefile.php   # unstage it (keeps your edits)
git restore somefile.php            # throw away your edits — CANNOT be undone
```

### Commit — make a save point

```bash
git commit -m "Add year and section to student accounts"
```

**Write the message for the person reading it in six months, who is probably
you.** Say what changed and, when it is not obvious, why.

```
Good:  Fix stray </div> that closed the create form early
       Move SMTP credentials out of config.php into .env
Bad:   update
       fix
       asdf
```

A commit is a checkpoint you can return to. Commit often — small commits are
much easier to understand and to undo than one enormous one.

### Send it to GitHub

```bash
git push
```

The first time on a new branch, git will ask you to be specific:

```bash
git push -u origin your-branch-name
```

`-u` remembers the answer, so afterwards plain `git push` is enough.

---

## 3. Getting other people's work

```bash
git pull
```

This fetches what is on GitHub and merges it into your branch. Do it **before**
you start working, not after — it is far easier to build on top of someone's
changes than to reconcile two different versions of the same file.

If you have uncommitted work and want to pull anyway, park your changes first:

```bash
git stash          # put your changes aside
git pull
git stash pop      # bring them back
```

---

## 4. Branches

A branch is a separate line of work. The main branch should always be in a state
you would be happy to demo. Anything risky happens on its own branch.

```bash
git branch                          # which branches exist, * marks the current one
git switch -c analytics-rework      # create a new branch and move to it
git switch main                     # go back
```

Working on a branch means you can experiment freely: if it goes wrong, you
switch away and the main branch was never touched.

Naming them after the work — `analytics-rework`, `fix-login-redirect` — is
enough. You do not need a scheme.

---

## 5. Pull requests

A pull request (PR) is a proposal: *"here is my branch, please review it and
merge it into main."* It exists so somebody looks at the change before it
becomes official. On a solo project it is still useful, because it gives you a
readable summary of everything a branch changed.

The flow:

1. `git switch -c my-change` — start a branch
2. Do the work, committing as you go
3. `git push -u origin my-change`
4. Open GitHub. It will show a banner offering to open a pull request from that
   branch — click it.
5. Write what the change does and why. Reviewers comment; you push more commits
   to the same branch and the PR updates itself.
6. When it is approved, click **Merge pull request**.
7. Locally: `git switch main` then `git pull` to bring the merged work down.

---

## 6. Undoing things

Everyone needs this. In rough order of severity:

```bash
# Not committed yet — throw away edits to one file
git restore path/to/file.php

# Committed, but not pushed — undo the commit, KEEP the changes as edits
git reset --soft HEAD~1

# Committed, but not pushed — undo the commit and THROW AWAY the changes
git reset --hard HEAD~1        # destructive

# Already pushed — make a new commit that reverses an old one (safe, honest)
git revert <commit-hash>
```

**Use `git revert`, not `reset`, for anything already on GitHub.** `reset`
rewrites history; if someone else has pulled it, you have made their copy
disagree with yours.

To find a commit hash:

```bash
git log --oneline          # short list, newest first
git log --oneline -10      # last ten
```

Accidentally deleted something and committed it? The file is still in history:

```bash
git checkout <commit-hash> -- path/to/file.php
```

---

## 7. What must never be committed

This project keeps its secrets in **`.env`**, which is listed in `.gitignore` so
git ignores it. Never take a password, an API key or a token out of `.env` and
paste it into a `.php` file.

Also never committed:

- **`app/student/uploads/`** — students' papers, and their consent and copyright
  forms. Other people's personal documents do not belong in a code repository.
- **`vendor/`** — installed libraries, restorable with a command
- **`*.log`** — noise, and sometimes contains personal data

Check what you are about to commit:

```bash
git status --short
git diff --staged
```

If you see `.env`, a PDF, or anything under `uploads/`, stop and unstage it.

> **If you ever do commit a secret:** removing it in a later commit is *not*
> enough. It stays in the history and anyone who clones the repository can read
> it. The only real fix is to **revoke the key and issue a new one** — change
> the password at its source. Treat any leaked key as compromised, permanently.

---

## 8. Setting up on another computer

```bash
git clone https://github.com/YOUR-USERNAME/YOUR-REPO.git
cd YOUR-REPO
cp .env.example .env        # then fill in the real values by hand
```

`.env` is deliberately not in the repository, so a fresh clone will not have
one. `.env.example` shows which entries are needed.

---

## 9. A normal day

```bash
git pull                        # start from what is on GitHub
git switch -c fix-the-thing     # branch for today's work

# ...edit files...

git status                      # what changed?
git diff                        # is that really what I meant?
git add -A
git commit -m "Explain the change plainly"
git push -u origin fix-the-thing

# then open the pull request on GitHub
```

---

## 10. When something looks broken

| Symptom | What is happening | What to do |
|---|---|---|
| `rejected — non-fast-forward` | GitHub has commits you do not | `git pull`, resolve, push again |
| `CONFLICT (content)` | You and someone else changed the same lines | Open the file, pick what is right, delete the `<<<<<<<` markers, `git add`, `git commit` |
| `Repository not found` | Wrong URL, or no permission | Check `git remote -v`; confirm you are signed in |
| `detached HEAD` | You are on a commit, not a branch | `git switch main` |
| `Filename too long` (Windows) | Path over 260 characters | `git config --global core.longpaths true` |

Nothing in git is really lost until you run a `--hard` or a `--force`. If you
are unsure, **stop before typing either of those** and ask.

---

## Quick reference

```bash
git status                  # where am I, what changed
git diff                    # what exactly changed
git add -A                  # stage everything
git commit -m "message"     # save a checkpoint
git push                    # send to GitHub
git pull                    # get from GitHub
git log --oneline           # history
git switch -c name          # new branch
git switch main             # change branch
git revert <hash>           # safely undo a pushed commit
```
