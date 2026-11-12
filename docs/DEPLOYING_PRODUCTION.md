# Putting PAPEL on production, the first time

> **This has now been done.** Production went live on **12 November 2026** at
> **pupbcpapel.com**: the `PROD_FTP_*` secrets are set, the first full upload ran
> that morning (about 17 minutes, which is what sending `vendor/` over FTP costs),
> and the `v1.0.0` tag deployed on top of it. Check it with
> `gh run list --workflow "Deploy to production"` rather than by reading this file.
>
> So steps A to G below are no longer a plan: they are the record of how that copy
> was set up, and the guide for setting up the next one. Production moves from here
> exactly as [RELEASING.md](RELEASING.md) section 4 describes: push a tag beginning
> with `v` and wait for the green tick.

The first run is the one that is nothing like the others. Everything below is the
groundwork a `v` tag needs to land on.

Companion to [RELEASING.md](RELEASING.md) (the version number and the tags) and
to the CLAUDE.md section "Setting up a copy of the site", which is the short form
of steps C to F.

---

## 1. What a deploy does, and what it does not

`.github/workflows/deploy-production.yml` checks the code out, installs PHP 8.2
and Composer's production dependencies, refuses the build if any file is over
10 MB (the host rejects those halfway through, with a confusing error), and sends
the result over FTP to `/htdocs/`.

It does **not** send, and never overwrites:

| Left alone | Why |
|---|---|
| `.env` | Database password, Drive secrets, the AI keys. Written once on the server. |
| `config/gdrive_token.json` | Written by signing in to Google on the site itself. |
| `uploads/**` | Students' own work. |
| `scripts/**`, `database/**`, `tests/**`, `docs/**`, `*.md` | Not part of the running site. Several of the older scripts have no web guard, so they must stay off a public server. |

So a fresh production account has the code and nothing else: no database, no
configuration, no Drive, and **no account to sign in with**. `database/schema.sql`
seeds no users, and `scripts/setup.php`, which would mint the first Director, is
not deployed and could not run anyway because the host has no shell.

That is what steps C to F are for. Do them in order; each one fails in a way that
looks like a different problem if the one before it was skipped.

**One piece of good news.** `database/schema.sql` is now complete: it has
`manuscript_requests`, `paper_checklist.items_json`,
`supporting_documents.document_name`, `paper_manuscripts` and the `'manuscript'`
notification type. A production database imported from it needs **none** of the
three catch-up files in `scripts/migrations/sql/`. Those exist for staging, which
was built from a 14 October export and fell behind the code. Production starts
level, and the diagnostics page will say so.

---

## 2. Before you start

- The build must already be working on staging. Production is not the place to
  find out.
- You will need the Google account that owns the Drive folder papers are filed
  in, signed in on the browser you do step F from.
- Set aside half an hour. Steps A, C and F each wait on somebody else's web
  interface.

---

## Step A: a second hosting account

Production needs its own hosting account, separate from staging: its own FTP
credentials, its own database, its own domain. Sharing one account would mean a
deploy of either copy writing over the other.

InfinityFree allows three hosting accounts per person, so a second one is within
their terms. A third would still be spare.

1. Sign in to the InfinityFree client area with the account staging already uses.
2. **Create Account**. The account has to be created against a **free
   subdomain**: that is the only thing the form accepts, and it becomes the
   account's primary domain, served from `/htdocs/`.
3. The real domain is added afterwards, in the account's **Domains** panel, and
   it arrives as an **addon**: it gets a directory of its own, named after it,
   and InfinityFree does not allow a document root to be changed. So
   `pupbcpapel.com` is served from `/pupbcpapel.com/htdocs/`, and
   `server-dir` in `deploy-production.yml` points there rather than at
   `/htdocs/`.

   This is the trap worth knowing: get that path wrong and the deploy still
   finishes **green**. The files land in the free subdomain's folder and the
   real domain goes on serving nothing, which reads as a deploy that never
   happened. The Health Check panel in the client area names the directory the
   workflow has to match, and will say the directory is missing until you press
   **Recreate Directory** once.
4. Wait for the account to finish setting up, then open its control panel and
   write down four things:
   - the **FTP username** and **FTP password** (Accounts, FTP Details)
   - the **MySQL hostname**, which is not `localhost` on this host
   - the **domain**, as `https://...`

Turn the free SSL certificate on for the domain people will actually use. It can
only be requested once the domain is added and resolving, it takes a few minutes
to issue, and it has to be **installed** as well as issued.

This is not cosmetic: `COOKIE_SECURE=true` in step D means the session cookie is
not sent over plain HTTP at all, so without a certificate nobody stays signed in.

**A custom domain is two more registrations, not one.** Whatever the address
ends up being, it goes in four places: `BASE_URL` in `.env`, the **Domains** list
of your reCAPTCHA site, the **Authorized redirect URIs** of your Google OAuth
client (as `https://<domain>/pages/gdrive_callback.php`, because
`GDRIVE_REDIRECT_URI` is derived from `BASE_URL` in `config/gdrive_config.php`),
and `server-dir` above. Miss the second and every sign-in fails as a robot; miss
the third and Reconnect dies with `redirect_uri_mismatch`, so no Drive and no
submissions.

**Checking DNS, rather than waiting on the panel.** The client area's Health
Check lags: it said the domain was not pointing at their server while Google's
resolver already had the nameservers and an A record. Ask a public resolver
directly instead:

```bash
nslookup -type=NS pupbcpapel.com 8.8.8.8
nslookup pupbcpapel.com 8.8.8.8
```

Note that `curl` is not a useful check against this host, which answers
non-browser clients with an empty reply. Open it in a real browser.

---

## Step B: tell GitHub how to reach it

The workflow reads two secrets that do not exist yet. Without them it runs and
fails at the FTP step with a message about credentials.

On github.com, in the repository: **Settings**, then **Secrets and variables**,
then **Actions**, then **New repository secret**, twice:

| Name | Value |
|---|---|
| `PROD_FTP_USER` | the FTP username from step A |
| `PROD_FTP_PASS` | the FTP password from step A |

Spell them exactly. A secret named `PROD_FTP_USERNAME` is simply not read, and
the failure says nothing about the name.

They sit beside `STAGE_FTP_USER` and `STAGE_FTP_PASS`, which were added on
14 October and are not touched.

---

## Step C: the database

In the production account's control panel:

1. **MySQL Databases**, create one. The host prefixes the name with the account
   id, so the name you get back is the one that goes in `.env`, not the one you
   typed.
2. Open **phpMyAdmin** for it and select that database. This matters: a script
   pasted with no database selected runs against whatever phpMyAdmin was last
   looking at.
3. **Import**, choose `database/schema.sql` from your computer, **Go**. It is
   structure only, 22 tables and no rows.
4. Then **SQL**, and paste one Director so there is a way in. Generate the hash
   on your own machine first, because a password typed into phpMyAdmin in plain
   text is stored in plain text and will never work:

   ```bash
   php -r "echo password_hash('<the password you chose>', PASSWORD_DEFAULT), \"\n\";"
   ```

   ```sql
   INSERT INTO users (username, email, password, full_name, user_role,
                      admin_type, faculty_id, admin_level, is_active)
   VALUES ('director', '<a real address you can read>',
           '<the hash from the line above>',
           'Director', 'super_admin', 'director', 'DIR-0001', 1, 1);
   ```

   Use an address you can actually open. The Director is who Drive asks for in
   step F, and who every "something failed" notice goes to.

Sign in on the **Faculty / Admin** tab with `DIR-0001`. Staff, librarians and the
Director all sign in there; only `student`, `faculty` and `guest` exist as tab
values.

**Do not import the development database.** Production starts empty on purpose:
seeded papers belong to real students, and approving one emails them.

---

## Step D: `.env` on the server

There is no shell, so this is the control panel's file manager (or FTP).

Copy your working `.env`, change the lines in the table below, and upload it to
`/htdocs/.env`. Everything not in the table (the Groq keys, SMTP, the Drive client
id and secret) is the same on every copy.

| Line | On production |
|---|---|
| `APP_ENV` | `production` |
| `BASE_URL` | the full `https://` domain from step A, **no trailing slash** |
| `COOKIE_SECURE` | `true` |
| `DB_HOST` | the MySQL hostname from step C, not `localhost` |
| `DB_NAME`, `DB_USER`, `DB_PASS` | the database from step C, with the host's prefix |
| `CSRF_KEY` | a long random string of its own |
| `GDRIVE_PARENT_FOLDER_ID` | the production folder from step F |

`APP_ENV` is the one that decides whether a failure explains itself.
`config/config.php` defaults it to `production` when it is unset or the file is
missing, and `safe_error_message()` then adds no cause to any error. That is the
right default for a public site and the wrong one for a site you are still
setting up: if a page fails here and says nothing, the first thing to check is
that `.env` arrived at all.

`CSRF_KEY` really does need changing. It is still the placeholder
`CHANGE_THIS_TO_A_LONG_RANDOM_SECRET` on the copies built so far, and it is what
every form's token is signed with. Changing it later signs everyone out once,
which is nothing on day one and a nuisance later.

---

## Step E: reCAPTCHA, which is tied to the domain

Google issues reCAPTCHA keys for a list of domains and refuses a solved challenge
sent from anywhere else. The keys in `.env` know about localhost and staging, so
on an unregistered production domain **every** sign-in fails as a robot, however
right the password is, and the order of the checks means it fails before the
password is read at all.

In the reCAPTCHA admin console, open the site these keys belong to, and add the
production domain to its **Domains** list. Same keys, one more domain. Nothing in
`.env` changes.

---

## Step F: Drive, which is two settings and not one

"Connected" and "has a usable folder" fail separately, and this is the step that
has already cost a day on staging: Drive was connected, every submission still
failed, and the cause was Drive answering `404 File not found` for a folder in an
account the connected one could not see.

1. Signed in as the Director, open **Storage Folder**
   (`app/admin/gdrive_settings.php`). It is Director only; the Coordinator can
   connect Drive but cannot change the folder.
2. **Reconnect**, and sign in as the Google account that owns the folder papers
   go in. This writes `config/gdrive_token.json` on the server, which no deploy
   touches afterwards.
3. Make a **new folder in that account's Drive for production**, separate from
   staging's. Open it in that account to be certain it is there and editable,
   then paste its ID into the Storage Folder page. The page checks the folder
   before it saves it and refuses with the reason, which is the check you want
   before a student meets it.

A separate folder, not staging's. Test papers and real ones in one folder cannot
be told apart afterwards.

Then open `app/student/upload_diagnostics.php` as staff. **Database** should read
"No gaps", because `schema.sql` is current. If it lists anything, stop and read
section 1 again.

---

## Step G: the tag, which is the deploy

Now, and only now, the part that is in [RELEASING.md](RELEASING.md).

1. Make sure `VERSION` holds the number you want testers to see. The footer
   prints it, and on production it prints it with no commit after it.
2. In GitHub Desktop, **History**, right-click the commit that is live on
   staging, **Create Tag**.
3. Type the tag **with** the `v`: `v1.0.0-rc.1` for a first User Acceptance
   Testing build.
4. **Create Tag**, then **Push origin**.

Watch it on github.com under **Actions**, run **Deploy to production**.

> Never put a `v` tag on an old commit. The workflow deploys whatever the tag
> points at, so tagging August's code would replace the live site with it. Tags
> that are only bookmarks go on without the `v`, as RELEASING.md section 3 step 4
> does.

---

## Step H: check it, in this order

The order matters, because each of these failing points at a different step.

1. **The site loads at all.** A blank page or a 500 with no detail is usually
   `.env` missing or `DB_HOST` still `localhost` (step D).
2. **The footer** reads `Version 0.13.0`, with no `(staging · ...)` after it. A
   commit in brackets means you are looking at staging.
3. **Sign in as the Director.** "Please confirm you are not a robot" when you
   have confirmed it is step E. A wrong ID or password said in the panel itself
   is the sign-in panel working as it should.
4. **Make one account of each kind** from the management consoles: an adviser, a
   Coordinator, a student. Every create emails the account, so use addresses you
   can open, or `@example.com`, which is reserved and cannot receive mail.
5. **Submit one paper end to end** as that student, approve it as the adviser,
   publish it as the Coordinator, and read it in the public repository. This is
   the only check that proves Drive, the database, the mail and the approval
   chain at once, and it is the one that catches step F.
6. **Delete that paper and those accounts**, and the file it left in Drive.

---

## If it goes wrong

**The production deploy never ran.** It answers only to tags beginning with `v`.
A tag of `0.14.0` is deliberately ignored. If **Actions** shows no run named
Deploy to production, the tag never reached GitHub: push again.

**It ran and failed at "Send to InfinityFree".** The secrets in step B, by name
or by value.

**It ran, it was green, and the site is unchanged.** The FTP account points at a
different folder than you think. The workflow writes to `/htdocs/`.

**Everything works except submitting a paper.** Step F, and the diagnostics page
will say which half.

**Rolling back.** On github.com, **Actions**, **Deploy to production**, **Run
workflow**, and pick an older tag. That redeploys that build. Nothing rolls the
database back, so a change that moved data is not undone by this.
