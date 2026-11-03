# Releasing PAPEL, with GitHub Desktop

How the version number in the footer is set, and what to click to move a build to
staging or to production. No commands: everything here is GitHub Desktop, except
editing one file, which you do in your editor.

Companion to [GIT_GUIDE.md](GIT_GUIDE.md), which covers git itself, and to
[PAPEL_System_Overview.md](PAPEL_System_Overview.md) section 3, which is the
version history and the rule the numbers follow.

---

## 1. The short version

| | Who does it |
|---|---|
| Staging redeploys when you push | **Automatic** |
| The commit shown on staging's footer | **Automatic** |
| The version number itself (`0.13.0`) | **You**, by editing one file |
| Production deploys | **You**, by pushing a tag that starts with `v` |

The number lives in one file called `VERSION` at the top of the project. It holds
one line:

```
0.13.0
```

That is all. The footer reads it.

What each copy of the site shows:

```
your laptop   ·  Version 0.13.0 (local)
staging       ·  Version 0.13.0 (staging · 7465ea4)
production    ·  Version 0.13.0
```

The `7465ea4` on staging is the commit, added automatically on every deploy.
Staging takes *every* push, not only the releases, so the number on its own could
not tell you which build is actually up there.

---

## 2. Every day: send your work to staging

This is the normal loop. It does not change the version number.

1. Open **GitHub Desktop**. Make sure the top left says
   `papel-research-archving-system` and **Current branch: main**.
2. Click **Fetch origin** (top right) first, in case the work was pushed from
   another computer.
3. Go to the **Changes** tab on the left. Tick the files you want to include.
   Leave `.env` unticked if it ever appears; it must never be committed.
4. In the **Summary** box at the bottom left, write what you changed, for example
   `Library Pass rename and phone preview`.
5. Click **Commit to main**.
6. Click **Push origin** (top right).

That push starts the staging deploy by itself. Nothing else is needed.

---

## 3. When you finish something worth a new version

Do this when a batch of work is done, not for every commit.

### Step 1: decide the number

The rule, from the System Overview section 3.1:

| Change | What goes up | Example from this project |
|---|---|---|
| A new feature, nothing else broken | **MINOR**: `0.13.0` to `0.14.0` | Editable review checklist (0.10.0) |
| A fix, no new feature | **PATCH**: `0.13.0` to `0.13.1` | `manuscript_requests` table fix (0.11.1) |
| Something that breaks existing work or stored data | MAJOR | has not happened yet |

While PAPEL is still being built it stays at `0.something`. The first number
becomes `1.0.0` only when the system is accepted after User Acceptance Testing.

### Step 2: write it in the file

1. Open `VERSION` (top of the project folder) in your editor.
2. Replace the number with the new one. Nothing else: no `v`, no date, no spaces.
3. Save.

### Step 3: commit and push it

Back in GitHub Desktop:

1. **Changes** tab. `VERSION` is now listed. Tick it.
2. Summary: `0.14.0` and a few words, for example
   `0.14.0: Library Pass, phone preview, uniform snackbars`.
3. **Commit to main**, then **Push origin**.

Staging now shows `Version 0.14.0 (staging · …)`.

### Step 4: mark the release in the history

This records which commit the release was. It deploys nothing.

1. Go to the **History** tab on the left.
2. **Right-click** the commit you just made.
3. Choose **Create Tag…**.
4. Type the number exactly as in the file: `0.14.0`. **No `v` in front.**
5. Click **Create Tag**.
6. Click **Push origin** again to send the tag up.

> **Why no `v`?** A tag starting with `v` deploys to production. See section 4.
> A tag without it is just a bookmark in the history.

If your version of GitHub Desktop has no **Create Tag** item, use the website
instead: open the repository on github.com, click **Releases** on the right, then
**Draft a new release**, then **Choose a tag** and type `0.14.0`, then
**Publish release**.

---

## 4. Putting a build on production

Production moves **only** when you push a tag beginning with `v`. Nothing else
touches it, so testers never meet a half finished change.

1. Make sure the build you want is already pushed and working on staging.
2. Make sure `VERSION` holds the number you want people to see.
3. **History** tab, right-click that commit, **Create Tag…**.
4. Type the tag **with** the `v`, for example `v1.0.0-rc.1` for the first build
   for User Acceptance Testing.
5. **Create Tag**, then **Push origin**.

The production deploy starts on its own.

> ⚠ **Never put a `v` tag on an old commit.** Pushing `v0.1.0` today would send the
> August code to production and replace the live site. Tag the past without the
> `v`, the way section 3 step 4 does.

**Rolling back.** On github.com open **Actions**, choose **Deploy to production**,
click **Run workflow**, and pick an older tag. That redeploys the older build.

---

## 5. Checking it worked

1. On github.com open the **Actions** tab.
2. The newest run is at the top: **Deploy to staging** or **Deploy to production**.
3. A green tick means it finished. A red cross means it stopped: click it and read
   the step that failed.
4. In the staging run, the step **Stamp the commit into VERSION** prints the line
   `Deploying 0.14.0+abc1234`, which is exactly what the footer will show.

Then open the site and scroll to the bottom. The footer should read:

```
Terms & Conditions    Privacy    Version 0.14.0 (staging · abc1234)
```

---

## 6. If something looks wrong

**The footer shows no version at all.**
`VERSION` did not reach the server. Check it is committed: in GitHub Desktop it
must *not* appear in the **Changes** tab as a new file. If it does, tick it,
commit and push.

**The footer shows an old number.**
The number is only what is written in `VERSION`. Edit the file, commit, push.
Staging follows within a few minutes.

**Staging shows a commit you do not recognise.**
Somebody pushed after you. **Fetch origin** and look at the **History** tab.

**Production did not move.**
It only reacts to tags starting with `v`. A tag of `0.14.0` is deliberately
ignored. Check the **Actions** tab for a run named **Deploy to production**; if
there is none, the tag never arrived, so push again.

**You tagged the wrong commit.**
Right-click the tag in **History** and delete it, then create it on the right
commit. If it was already pushed and it was a `v` tag, production has already
deployed: fix forward by tagging a corrected build rather than trying to undo it.

---

## 7. What never goes in a commit

The same list as GIT_GUIDE.md section 7, repeated because a release is when people
are least careful:

- `.env`
- `config/gdrive_token.json`
- anything under `uploads/`
- `vendor/`

If any of these show in the **Changes** tab, leave them unticked and tell someone.
A password that reaches GitHub has to be changed, not just deleted.
