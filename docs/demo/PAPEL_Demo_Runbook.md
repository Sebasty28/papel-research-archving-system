**For the capstone defence · demonstrated on the live site, pupbcpapel.com**

This is the preparation list, not the handbook. It answers one question: *what has
to exist before we stand up, so that every workflow can be shown without waiting,
apologising, or typing something that fails in front of the panel.*

Read Part 1 the day before. Everything in it takes time that you will not have on
the day.

---

## How to use this document

Every workflow in Part 3 is written the same way:

| | |
|---|---|
| **Needs** | What must already exist. If this is missing, the demo stops. |
| **Show** | What to click, in order. |
| **They see** | What should appear, so you know it worked. |
| **Say** | The sentence that explains why it is built that way. A panel asks *why*, not *what*. |
| **Again** | How to put it back, to run the same demo twice. |

> **This is the live site.** Every account you create sends a real email. Every
> notice a workflow raises reaches a real inbox. Use addresses you own, keep to
> papers whose titles start with `DEMO`, and never demonstrate on a real
> student's work.

---

# Part 1 · The day before

## 1.1 Run the two database updates

Without these, two things in the demo simply do not work.

Open **phpMyAdmin** on the production hosting, select the site's database on the
left, open the **SQL** tab, paste each file whole, and press **Go**. Run them in
this order. Each one prints a line saying what it did, and each is safe to run
twice.

| Order | File | If you skip it |
|---|---|---|
| 1 | `scripts/migrations/sql/2026-11-15_request_history_visibility.sql` | A student cannot clear their request history; My Library may error. |
| 2 | `scripts/migrations/sql/2026-11-23_manuscript_file_hash.sql` | **Workflow 3b cannot be demonstrated at all.** The duplicate check has nothing to compare a PDF against. |

These files are **not on the server**. They are in the project folder on your own
computer, under `scripts/migrations/sql/`. Open each in Notepad, select all, copy.

**Then check it worked.** Sign in as the Research Coordinator or the Director and
open:

```
pupbcpapel.com/app/student/upload_diagnostics.php
```

The **Database** section must read **"No gaps"**. If it lists anything, the paste
did not take. Do not proceed until it is clean.

## 1.2 Check Google Drive

Sign in as the **Director** or the **Research Coordinator** and open **Storage
Folder**. Confirm all three:

- Drive says **connected**, and names the Google account.
- The storage folder is **usable** by that account, in so many words.
- The folder opens in Drive when you press **Open folder**.

If the folder is not usable, **no paper can be submitted at all** and Workflows 3,
6, 7 and 8 are dead. This is the single most common cause of a failed demo: the
connection and the folder are two separate settings and they fail separately.

## 1.3 The accounts you will use

**Use the accounts that already exist on the live site.** Do not create a set of
demo accounts: every account made here is real, is emailed a password, and stays
on the roll afterwards.

Fill this in the day before, and keep it beside the laptop. Write the passwords
on paper, not in a file you would have to open on the projector.

| Role | Account on the live site | ID to type | Tab | Lands on |
|---|---|---|---|---|
| Student | *your own student account* | `2023-00056-BN-0` | Student | My Dashboard |
| Subject Adviser | Kris Rommel Ramos | `FA0004BN2026` | Faculty | Review Desk |
| Research Coordinator | Michael Anjelo O. Miguel | `FA0001BN2026` | Faculty | Review Desk |
| Librarian | Franchesca Bernardo | `FA0002BN2026` | Faculty | Manuscript Requests |
| Director | Director | `DIR-0001` | Faculty | My Dashboard |

Staff, librarians and the Director all sign in on the **Faculty** tab. Only the
student uses the Student tab. Write each password on the card; they are not here.

**There is exactly one Subject Adviser account on the live site**, so every
student paper goes to Kris Rommel Ramos. That makes Workflow 6 simple, and it
also means there is no second desk to fall back on if that password is not to
hand. Settle it tonight.

The student row is yours to choose: any of the fifteen student accounts will do,
**provided it is on that adviser's roll** (see below). `2023-00056-BN-0` is
filled in because it is the account the project's own test fixtures already use.
Every student account expires **31 July 2028**, so none of them is near expiry.

The Head of Academic Programs (Archie C. Arevalo, `FA0003BN2026`) is not in the
table because no workflow needs him. Mention the role when the chain is
explained: he reads, he does not approve.

### Three things to check about them, not assume

**1. The student must be on that adviser's roll.** This is the one thing that
can quietly break the review demo. A paper does not go to "an adviser"; the desk
lists the students whose accounts **that** adviser created (`users.created_by`),
and a paper by anybody else is in no queue at all. It is not visible from the
student's side, so check it from the adviser's.

Sign in as **Kris Rommel Ramos** and open **My Students**. The student you chose
must be on that list. If the list is empty, or your student is missing, pick a
student that *is* on it and use that account instead.

If you would rather check it in phpMyAdmin, this answers it in one line and
writes nothing:

```sql
SELECT user_id, full_name, student_id FROM users
 WHERE user_role = 'student' AND created_by = 384;
```

`384` is Kris Rommel Ramos. Whatever comes back is exactly what his desk can see.
If it comes back empty, the students were created by somebody else, and Workflow
6 has to be demonstrated from whichever account did create them.

**2. The student account must not have expired.** All fifteen expire on
**31 July 2028**, so this is not a risk tomorrow. It is still worth knowing why:
the life of a student account is counted from the section on it, and an expired
account cannot sign in at all. Moving a student up a year is how one is renewed.

**3. You must actually hold five passwords.** These are real accounts and the
passwords are not in this document. Four of the five belong to other people; if
you are borrowing them, arrange it tonight and confirm each one signs in.

Do not plan to reset anybody's password in the morning. A password reset by
somebody else holds that account on a change your password screen until it is
replaced, and nothing else on the site opens: you would be changing a colleague's
password in front of the panel.

> **A real desk shows real work.** The Review Desk of a working adviser lists
> other students' names and the titles of papers that are not published. The
> Coordinator's desk and the support queue are the same. Decide in advance
> whether that is acceptable to project, and if it is not, use the desk's own
> filters to bring your demo paper up before you share the screen.

### What you lose by not creating accounts, and what to do instead

Two things in the demo are about an account being new. With existing accounts
they cannot be shown as they stand:

| | What to do |
|---|---|
| **Workflow 2**, creating an account | Fill the form in and show the generated password **without pressing Create**, then explain what would happen. Or create one real throwaway account with an email you own, and delete it afterwards. |
| **The first-sign-in password gate** | Describe it rather than show it. It only appears for an account whose password somebody else set. |

## 1.4 Prepare the PDF files

You need **six different PDF files** on the laptop, in one folder, named so you
can find them under pressure:

```
demo-01-imrad.pdf        demo-04-returned.pdf
demo-02-full.pdf         demo-05-ethics.pdf
demo-03-duplicate.pdf    demo-06-consent.pdf
```

> **They must genuinely be different files.** PAPEL now refuses a PDF whose
> contents are already on file, and it knows a file by what is inside it, so
> copying one and renaming it is still the same file and will be refused. This
> is a feature you are going to demonstrate; do not let it ambush you first.

`demo-03-duplicate.pdf` is the exception: it is **a copy of `demo-01-imrad.pdf`
under another name**, and it exists only so you can show the duplicate check
catching it.

## 1.5 Stage the papers

One paper cannot be in two states at once, so the demo needs several, each parked
where a workflow needs it. Upload them **as the student from 1.3**, so they land
on Kris Rommel Ramos's desk, and give every one of them a title beginning
`DEMO ` so the reset script can find them and nothing else can be touched by it.
Prepare them in this order, the day before.

| Paper title | Leave it in this state | Used by |
|---|---|---|
| `DEMO Draft Paper` | Draft, never submitted | 4 |
| `DEMO Waiting Adviser` | Submitted, with the Subject Adviser | 5, 6 |
| `DEMO Waiting Coordinator` | Adviser approved, with the Coordinator | 7 |
| `DEMO Returned Paper` | Returned, back in the student's Drafts with feedback | 8 |
| `DEMO Published Paper` | Approved and published | 9, 11, 12 |

**`DEMO Waiting Adviser` must be submitted on the day, or late the night before.**
A paper can be withdrawn only in the **first 24 hours** with the adviser, and
Workflow 5 is that window. Submit it too early and the button is gone.

**`DEMO Published Paper` must be filed after step 1.1**, not before. A paper filed
before the database update has no fingerprint, so it cannot be used to demonstrate
the duplicate check.

## 1.6 Last checks, the morning of

- [ ] Sign in once as **every** account on the list in 1.3, and out again.
- [ ] The demo student appears on that adviser's **My Students** list.
- [ ] `upload_diagnostics.php` still reads **No gaps**.
- [ ] Storage Folder still says connected and usable.
- [ ] The five demo papers are each in the right state.
- [ ] The six PDFs are on the laptop, in one folder.
- [ ] Browser zoom at 100%, one window, no other tabs signed in as another role.
- [ ] The passwords are written on paper, beside the laptop.

> **Sessions end after 30 minutes of doing nothing.** If you set up in the morning
> and present in the afternoon, you will be signed out. PAPEL warns five minutes
> before with a **Stay signed in** button, but plan to sign in fresh.

---

# Part 2 · Running order

Do not demo the workflows in numerical order. This order tells one story, and each
step leaves the system ready for the next.

1. **The shape of it** — show the Public Repository signed out. One sentence on
   what PAPEL is for.
2. **Signing in** (1) as the student.
3. **Uploading** (3), then the **duplicate check** (3b).
4. **Drafts** (4) and **withdrawing** (5).
5. **Adviser review** (6) → **Coordinator publishes** (7).
6. **Returned paper** (8).
7. **Reading** (9), **manuscript request** (11), **guest pass** (10).
8. **Archiving** (12).
9. **Notices** (13) and **support** (14).
10. **Accounts** (2) last, because it sends email and is the least visual.

---

# Part 3 · The workflows

## Workflow 1 · Signing In

**Needs** — the student account from 1.3, and its password in your hand.

**Show** — press **Sign In**, choose the **Student** tab, type the ID and
password, tick the robot box, sign in.

**They see** — the student dashboard.

**Say** — "The robot check and the lock are tested *before* the password is ever
looked at, so this form cannot be used to try passwords. Five wrong tries lock
that account for fifteen minutes."

**Again** — sign out.

---

## Workflow 2 · Creating User Accounts

**Needs** — the Subject Adviser signed in. Decide beforehand which of the two
ways you are doing this, because one of them makes a real account.

**Show, without creating anything** — My Students → fill the form in → press
**Generate** and let it build the password from the name and the ID → stop
there and explain what Create would do. Then show **Import** beside the search
box and the spreadsheet preview, which checks every row without creating
anything until you press Create.

**Or, if you would rather show it end to end** — use an email address you own,
create one account, and delete it immediately afterwards. It is a real account
on the real roll until you do.

**They see** — the generated password, and the rules answering under each box
as it is typed in.

**Say** — "The password is generated from the name and the ID, and it is
temporary: the first time they sign in, PAPEL holds them on one page until they
choose their own, because a password that was emailed has been seen by somebody
else."

**Again** — if you created one, delete it now rather than later.

> Mention the spreadsheet import even if you do not run it. Forty students at the
> start of a term is the real case, and the preview checks every row by the same
> rules as typing one in.

---

## Workflow 3 · Uploading a Paper

**Needs** — the student signed in; `demo-01-imrad.pdf` and `demo-02-full.pdf`;
Drive connected.

**Show** — Upload Paper. Walk the four steps. On Step 1 tick **both** IMRAD and
Full Manuscript so there are two file boxes. Attach a different PDF to each. On
Step 2 press **Extract with AI**. On Step 2 also show the question that asks
**which written sections** go on the paper's page. Submit.

**They see** — the paper filed, the dashboard showing **ON PROCESS**.

**Say** — "Both ticked is **one paper with two files**, not two papers. And
nothing reaches Google Drive until every check has passed, so a refusal costs the
student no waiting."

**Again** — the paper is now with the adviser; use it for Workflow 6, or have the
adviser return it.

---

## Workflow 3b · The duplicate check  ← *new, and worth showing*

**Needs** — step 1.1 done; `DEMO Published Paper` filed **after** it;
`demo-03-duplicate.pdf`, which is a renamed copy of a PDF already filed.

**Show** — start a new upload, fill the required boxes, attach
`demo-03-duplicate.pdf`, and submit.

**They see** — a refusal in words: the PDF is already filed, naming the student's
own paper.

**Say** — "It compares what is **inside** the file, not its name, so renaming it
changes nothing. Two students really do both call a file *final.pdf*, and a
renamed copy of a filed paper is still that paper. Resubmitting a returned paper
with the same file is allowed, because that is a revision, not a duplicate."

**Again** — nothing was filed, so nothing to undo. It can be run as often as you
like.

---

## Workflow 4 · Saving a Draft

**Needs** — `DEMO Draft Paper` in Drafts.

**Show** — open the draft from the dashboard, show the typing restored, point at
the file boxes being empty.

**Say** — "A draft keeps everything typed but **no PDF**, on purpose: nothing is
stored for work nobody has handed in. Signing out also clears the copy kept in
this browser, because on a campus machine the next person is somebody else."

**Again** — nothing changes.

---

## Workflow 5 · Cancelling a Submission

**Needs** — `DEMO Waiting Adviser`, submitted **less than 24 hours ago** and still
with the adviser.

**Show** — the dashboard → **Under Review** → **Withdraw**.

**They see** — the paper back in Drafts.

**Say** — "The two stages work in opposite directions. With the adviser it is
allowed for the first 24 hours, a grace period for second thoughts. Once the
adviser has approved it, withdrawing *unlocks* only after the Coordinator has
held it a day: the paper has earned an approval and is not pulled back on a whim."

**Again** — submit it again. Note it will then be a fresh 24-hour window.

---

## Workflow 6 · Review by the Subject Adviser

**Needs** — a paper waiting with the adviser; the adviser signed in.

**Show** — Review Desk → open the paper → the PDF beside the form → the
**checklist** → **Edit sections** → Approve.

**They see** — the paper leaves the desk and reaches the Coordinator.

**Say** — "The checklist is stored as the adviser left it, so changing the
ready-made lists in code never rewrites what a student was shown. The Coordinator
reads this list but cannot change it."

**Again** — the Coordinator can return it, which sends it back to the student.

---

## Workflow 7 · Final Review and Publishing

**Needs** — `DEMO Waiting Coordinator`; the Coordinator signed in.

**Show** — Review Desk → open → Approve → then open the **Public Repository** and
find it.

**Say** — "Approval ends here, deliberately. The Head of Academic Programs and the
Director read what comes out; they do not approve. A fourth step was considered
and cut."

**Again** — the Director can archive it, or you can leave it published.

---

## Workflow 8 · Fixing a Returned Paper

**Needs** — `DEMO Returned Paper` in the student's **Needs Revision**.

**Show** — open it, read the feedback on the card, fix something, **attach the
PDFs again**, submit.

**Say** — "Its files were taken out of the storage folder when it was returned, so
the new version needs a new upload. They are not destroyed: they are moved to a
Trash folder inside the storage folder, because an adviser who returns a paper by
mistake must not destroy the student's only filed copy."

**Again** — have the adviser return it once more.

---

## Workflow 9 · Finding and Reading Published Papers

**Needs** — at least one published paper. Best shown **signed out** first.

**Show** — the Public Repository: search, the keyword strip, the filters. Then the
four views in the profile menu: **Cards, Rows, Hardbounds, Bookshelf**. Open a
paper. Press the **bookmark**, then open **My Library**.

**Say** — "The repository is the point of the system: work that was reviewed, kept
where the next batch can find it. The shelf views exist because a list of
twenty-seven rows does not look like a library."

**Again** — press the bookmark again to unsave.

---

## Workflow 10 · Guest Passes

**Needs** — the Librarian account from 1.3; an email address you can open.

**Show** — Guest Passes → create one → open the email → sign in on the **Guest**
tab in a private window.

**Say** — "A pass is for a visiting researcher or a panel member who has no
account. It lasts from one to twenty-four hours and then stops working by itself."

**Again** — create another; delete the old one.

---

## Workflow 11 · Asking to Open a Full Manuscript

**Needs** — `DEMO Published Paper`; the student with **no** open request on it;
the Librarian signed in in a second window.

> The student may hold three requests at once, and a paper with one still open
> cannot be asked about again. If you have practised this, clear it first: the
> reset SQL in the appendix does it.

**Show** — as the student, open the paper, press to request the manuscript. Switch
to the Librarian: the request is waiting. Grant it for an hour. Switch back: the
**countdown** appears in the corner. Open the PDF and point at the **watermark**.
Then have the Librarian **revoke** it.

**Say** — "The reader's name is burnt into the page as it is drawn, so any copy,
even a photograph of the screen, names the account it came from. We cannot stop a
screenshot; no web page can. What we can do is make every copy traceable."

**Again** — the student may request again at once after a revoke.

> A student may hold **three** active requests at a time. Do not use up the demo
> student's allowance the day before.

---

## Workflow 12 · Archiving a Paper

**Needs** — `DEMO Published Paper`, published; the Director signed in.

**Show** — the Director's desk → Archived tab → archive the demo paper → show it
is gone from the Public Repository → restore it.

**Say** — "Archiving is a database move, not a file one. The PDFs on Drive are not
touched at all, the paper is still readable by its own link, and restoring puts it
straight back. The five-year rule is written and tested; nothing schedules it yet,
which is why we are doing it by hand."

**Again** — **restore it**, so the repository is as you found it.

> Only ever archive a `DEMO` paper. Archiving a real student's paper removes it
> from the repository for everyone.

---

## Workflow 13 · Notifications and Emails

**Needs** — any of the earlier workflows already run, so notices exist.

**Show** — the bell, the notification centre, then the matching email in the inbox.

**Say** — "Every notice is both a bell and an email, from one call, so the two can
never disagree. The subject of the email is also the heading printed inside it."

**Again** — nothing to reset.

---

## Workflow 14 · Getting Help with an Account

**Needs** — nothing. Best shown **signed out**.

**Show** — Contact Support → send one → sign in as the Coordinator and show the
queue.

**Say** — "It needs no account, which is the whole point: it is for the person who
cannot sign in."

**Again** — close the request from the queue.

---

# Part 4 · Say these before you are asked

A panel trusts a team that volunteers the limits. Each of these is a real
boundary, and each has a reason.

- **A screenshot cannot be blocked.** No web page can stop PrintScreen, a
  recorder, or a phone camera; the blackout people have seen on video sites is a
  hardware path open only to video. We mark every page with the reader's name
  instead, so a copy can be traced. Prevention was not available; traceability was.
- **Nothing schedules the five-year archive yet.** The rule works and was driven
  by hand against a seeded six-year-old paper. Starting it is one cron entry.
- **The PDF compressor is switched off** at the owner's instruction. The size
  limit does not depend on it: a file over the limit is refused and the student
  compresses it themselves.
- **The AI can be wrong.** Extract with AI fills the metadata and copies the
  abstract only when it can find it exactly; the student checks every field. A
  wrong abstract is worse than none, so when it cannot find one it leaves the
  box alone.
- **Staff papers skip the review** and publish at once. That is deliberate: the
  chain exists to review *students'* work.
- **One person may approve twice** if they are both the Subject Adviser and the
  Research Coordinator. The owner chose that; for those papers the second step
  adds nothing.

---

# Part 5 · Questions the panel will probably ask

| Question | The short answer |
|---|---|
| Why only two approval steps? | It was shortened on purpose. The Head and the Director read what comes out; a fourth step added delay and no judgement. |
| Where are the files actually kept? | One Google Drive folder, chosen by the Director or the Coordinator. Never on the web server; the local copy is deleted as soon as Drive has it. |
| What stops two students uploading the same paper? | The contents are fingerprinted. A renamed copy is still the same file. Resubmitting your own returned paper is allowed. |
| What if Google Drive is down? | Submission is refused in words before anything is stored, rather than half-filing a paper. |
| Is anything deleted? | Almost nothing. A returned paper's files move to a Trash folder; archiving only moves a database row; a revoked grant keeps its record. |
| How do you stop a student seeing another student's paper? | A paper under review is visible only to its author and its reviewers. The full PDF of a published paper needs a Librarian's grant. |
| What about passwords? | Stored hashed, never in plain text. A password set by somebody else is temporary and the account is held until it is changed. |
| Does it work on a phone? | Yes. The layout changes at 900px and the upload form becomes one column. |
| What happens when the account expires? | A student account's life comes from the section, counted in academic years and ending 31 July. Moving a student up a year renews it. |

---

# Part 6 · If something goes wrong

| It happens | Do this |
|---|---|
| A page shows **Something Went Wrong** | Do not retry in front of them. Say "that is the error page, it carries a reference and the cause is logged", and move to the next workflow. |
| The upload fails | Open Storage Folder and read the folder check. This is almost always Drive, not the paper. |
| You are signed out mid-demo | The session idles after 30 minutes. Sign in again; it takes ten seconds. |
| The duplicate check does not fire | The SQL in 1.1 was not run, or the paper you are testing against was filed before it. |
| A paper is stuck in the wrong state | Use the reset SQL in the appendix. |
| The AI does not answer | Say so plainly and fill the fields manually. The AI is a convenience, not the system. |

**Have a second browser window already signed in as the adviser.** Switching
roles by signing out and in again is the slowest thing in the whole demo.

---

# Appendix · Resetting the demo data

`docs/demo/demo_reset.sql` puts the demo papers back to their starting states so
the whole run can be done again. Paste it into phpMyAdmin exactly as you pasted
the files in 1.1.

It only ever touches rows whose title begins with `DEMO`, and it changes no real
student's work. Read the comments at the top before running it; it tells you what
it will do and prints what it did.

It cannot create papers, because a paper needs a real PDF on Google Drive. Papers
are made by uploading them, as in 1.5.
