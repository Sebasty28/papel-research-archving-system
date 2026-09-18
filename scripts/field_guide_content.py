# -*- coding: utf-8 -*-
"""What the field guide says. The build script supplies what it counts.

The split matters. Everything measurable - how many files, how long they are,
what pulls in what - is read from the codebase at build time by
build_field_guide.py and is never written down here. This file holds only the
part a scanner cannot work out: why a file exists and what a piece of code is
doing.

Two rules keep it honest:

  * Every path mentioned below is checked against the real project when the
    guide is built. Rename a file and the build says so instead of quietly
    printing a path that no longer exists.
  * Code samples are not pasted here. Each one names a file and a line of code
    to find, and the builder lifts the current text out of that file. Edit the
    code and the guide follows; delete the anchor and the build complains.

Add a module by adding to MODULES. Add a walkthrough by adding to CODE.
"""

# --------------------------------------------------------------- modules
# id, number, title, blurb, features [{title, blurb, files [(path, why)]}],
# wires (what it connects to)
MODULES = [
 {
  'id': 'm1', 'num': 1, 'title': 'Foundation — what runs before your page does',
  'blurb': 'Pulled in by the first line of nearly every file. If this breaks, '
           'everything breaks at once.',
  'features': [
    {'title': 'The toolbox every page uses',
     'blurb': 'Opens the database, starts the session, and defines the shared '
              'helpers so the same rule is never written twice.',
     'files': [
       ('config/core.php',
        'Holds db() (the database connection), current_user(), require_role(), '
        'send_email(), create_notification(), and the rules: the programme '
        'list, the paper types, how long a student account lives.'),
       ('config/config.php',
        'Constants and paths. Reads the secrets out of .env so passwords and '
        'API keys are never written in the code.'),
     ]},
    {'title': 'Turning a crash into a reference number',
     'blurb': 'When something fails the visitor sees ERR-B09E2179 rather than a '
              'stack trace. The detail goes to the server log, where that same '
              'code can be looked up.',
     'files': [
       ('config/error_handler.php',
        'Catches every uncaught error, generates the reference, writes the '
        'detail to Apache’s log and shows a safe page. This is why a code '
        'can be traced back to an exact line.'),
       ('pages/error_page.php',
        'The card the visitor sees. Deliberately has no navigation, because '
        'the failure may be in the navigation.'),
     ]},
    {'title': 'The approval rules, in one place',
     'blurb': '',
     'files': [
       ('config/workflow.php',
        'Which statuses count as still in progress, which are final, and '
        'whether a paper may still be withdrawn. Kept apart from core.php '
        'because it is the business rule, not the plumbing.'),
     ]},
  ],
  'wires': 'talks to everything — MySQL, and every page in every other module',
 },
 {
  'id': 'm2', 'num': 2, 'title': 'Look and feel — the shell around every page',
  'blurb': 'A page that hand-rolls its own header is a bug, not a style choice: '
           'change these and the whole site changes.',
  'features': [
    {'title': 'The three-part shell',
     'blurb': 'Every page is a sandwich: head, header, your content, footer.',
     'files': [
       ('includes/site_head.php',
        'Fonts, the colour tokens (--maroon, --ink and the rest) and the shared '
        'stylesheet. It pulls in the theme, the focus rings and the dropdown skin.'),
       ('includes/site_header.php',
        'The top bar. The menu it draws depends on your role, so a student '
        'never sees Review Submissions.'),
       ('includes/site_footer.php',
        'The foot of the page, plus the shared JavaScript: the notification '
        'bell, the avatar menu, the login panel, the loading bar.'),
     ]},
    {'title': 'Theme, light and dark',
     'blurb': '',
     'files': [
       ('includes/theme.php',
        'Both palettes, applied before the page paints so it never flashes '
        'white and then turns dark.'),
       ('includes/focus_ring.php',
        'The outline that follows the keyboard, and the themed scrollbar.'),
       ('includes/select_skin.php',
        'Replaces the browser’s grey dropdown with one matching the site, '
        'while keeping the real control underneath so forms still work.'),
       ('includes/page_theme.php', 'Per-page colour tweaks for the consoles.'),
     ]},
    {'title': 'Things that talk to the visitor',
     'blurb': '',
     'files': [
       ('includes/action_dialogs.php',
        'The site-styled "are you sure?". Put data-confirm on a button and this '
        'handles it — which is why the code never calls the browser’s '
        'own confirm().'),
       ('includes/flash_banner.php',
        'The green and red messages after an action, with the dismiss X.'),
       ('includes/loading_bar.php',
        'The bar along the bottom while the site is busy. It wraps the '
        'browser’s own request functions, so every existing request '
        'reports without being edited.'),
       ('includes/accessibility.php',
        'The accessibility widget — text size, contrast, spacing.'),
       ('includes/key_nav.php',
        'Arrow keys move between links and boxes, the same way Tab does. '
        'It steps aside wherever the arrows already mean something — '
        'inside a text box, a dropdown, or a group of radio buttons.'),
       ('includes/scroll_jump.php', 'The jump-to-top button on long pages.'),
       ('includes/theme_welcome.php',
        'The one-time note introducing the theme switcher to a new visitor.'),
       ('includes/back_button.php',
        'Five lines. The back link, kept shared so it looks the same everywhere.'),
     ]},
  ],
  'wires': 'used by every page · needs core.php for the CSP nonce',
 },
 {
  'id': 'm3', 'num': 3, 'title': 'Signing in',
  'blurb': 'ID and password. No usernames, no birthdate, no OTP — all three '
           'were removed, though the OTP code is still there, commented out.',
  'features': [
    {'title': 'The sign-in itself',
     'blurb': 'One ID box. It matches against four columns, because which one '
              'holds your ID depends on who created your account.',
     'files': [
       ('app/auth/login.php',
        'Checks the password, counts failed attempts, refuses an expired '
        'student account, makes sure the account matches the tab you used, '
        'then sends you to your own dashboard.'),
       ('includes/recaptcha.php',
        'The “I’m not a robot” box on both sign-in forms. It stays out of the way entirely unless both Google keys are set, so a machine without them still signs in normally.'),
       ('app/auth/logout.php', 'Empties the session. Small on purpose.'),
       ('archive/login.php',
        'The standalone sign-in page, used by the link in the guest '
        'credentials email.'),
     ]},
    {'title': 'Guessing protection',
     'blurb': 'The IDs follow a predictable pattern, so without this the only '
              'defence would be the password rule.',
     'files': [
       ('config/core.php',
        'The login_throttle_* helpers: five failures per account and twenty '
        'per machine inside fifteen minutes, then a lockout. Counted in the '
        'login_attempts table.'),
     ]},
  ],
  'wires': 'reads users, login_attempts, guest_sessions · then hands you to '
           'the right desk',
 },
 {
  'id': 'm4', 'num': 4, 'title': 'Accounts — who exists, and who advises whom',
  'blurb': 'Nobody registers themselves. Every account is created by someone one '
           'step above, and that link is what routes papers later.',
  'features': [
    {'title': 'The chain of who creates whom',
     'blurb': 'Director → Coordinator → Adviser → Student. The '
              'created_by column records it, and that single column is how a '
              'submitted paper knows which adviser’s desk to land on.',
     'files': [
       ('app/faculty/faculty_manage_students.php',
        'The adviser’s roll: create, edit, archive a student, reset a '
        'password. Everything here is filtered by created_by so one adviser '
        'never sees another’s students.'),
       ('app/admin/admin_manage_faculty.php',
        'The Coordinator creating advisers.'),
       ('app/admin/super_admin_manage_admins.php',
        'The Director creating coordinators, heads and librarians.'),
       ('app/librarian/librarian_manage_guests.php',
        'Guest passes — a temporary username and password valid 1 to 24 '
        'hours, emailed to a visitor.'),
     ]},
    {'title': 'The shared console look',
     'blurb': 'Three management pages that look identical because they share '
              'their parts.',
     'files': [
       ('includes/manage_page.php', 'The layout and stylesheet for all three.'),
       ('includes/manage_console.php', 'The list, the tabs and the row actions.'),
       ('includes/manage_save_confirm.php',
        '"Save these changes?" with a field-by-field list of what you altered.'),
       ('includes/password_generator.php',
        'The Generate button. Builds a password from the name and ID already typed.'),
       ('includes/password_once.php',
        'Shows a new password exactly once. Nothing stores it afterwards.'),
       ('includes/password_audit_tab.php',
        'The record of password changes, scoped to your own students.'),
     ]},
    {'title': 'The rules behind the forms',
     'blurb': '',
     'files': [
       ('app/models/FacultyManagementService.php',
        'Validates and creates an adviser: name has no digits, valid email, '
        'password rules, ID not already taken.'),
       ('app/models/AdminManagementService.php',
        'The same for staff accounts, and it maps a job title to a role and level.'),
       ('app/models/UserRepository.php',
        'The actual database queries about users, kept apart from the rules above.'),
     ]},
    {'title': 'Student accounts expire',
     'blurb': 'An account’s life comes from the section: a first year gets '
              'five years, a fourth year two. Moving a student up a year is how '
              'you renew them.',
     'files': [
       ('config/core.php',
        'student_account_years() and student_expiry_date(). Recalculated on '
        'every create and edit, so the renewal is automatic.'),
       ('pages/settings.php',
        'What each person can change about their own account.'),
     ]},
  ],
  'wires': 'writes users, password_changes · feeds the review desks',
 },
 {
  'id': 'm5', 'num': 5, 'title': 'Submitting a paper',
  'blurb': 'The biggest single thing in the project, and the hardest file to '
           'work in.',
  'features': [
    {'title': 'The four-step wizard',
     'blurb': 'Details, then the PDF and the writing, then supporting '
              'documents, then review and submit. All four steps live in one '
              'file; only one is visible at a time.',
     'files': [
       ('app/student/student_upload_ai.php',
        'The form, the AI extraction, the draft saving, the validation and the '
        'submit handler all in one file. The one most worth splitting up.'),
       ('app/student/student_upload.php',
        'An older, simpler upload form. Nothing links to it any more.'),
     ]},
    {'title': 'What it refuses, and why',
     'blurb': 'Checked on the server, not just in the browser, because anything '
              'checked only in the browser can be walked around.',
     'files': [
       ('app/helpers/UploadHelper.php',
        'The PDF check: it reads the file’s actual content type rather '
        'than trusting the .pdf on the end, and rejects an empty file or one '
        'over the limit.'),
       ('includes/validation.php', 'Shared field checks used by the forms.'),
       ('config/core.php',
        'rich_text_sanitize() — the written sections arrive as HTML from '
        'the editors, and this is where untrusted markup stops.'),
     ]},
    {'title': 'Drafts, and changing your mind',
     'blurb': '',
     'files': [
       ('app/student/student_draft_delete.php',
        'Throws away a draft the student no longer wants.'),
       ('app/student/student_cancel_submission.php',
        'Withdraws a submission, but only inside a time window — once the '
        'reviewer has had it a while, the button is gone.'),
       ('app/student/student_submit.php',
        'Hands a finished draft to the adviser.'),
     ]},
  ],
  'wires': 'needs Google Drive (the PDF goes there first) · hands to the '
           'review desks',
 },
 {
  'id': 'm6', 'num': 6, 'title': 'Review and approval',
  'blurb': 'Four different desks that are all the same file, configured '
           'differently.',
  'features': [
    {'title': 'One review desk, four roles',
     'blurb': 'Rather than four near-identical pages drifting apart, each '
              'role’s page sets a few options and includes the shared console.',
     'files': [
       ('includes/review_console.php',
        'The whole review desk: the list, the filters, the tabs, the paper '
        'cards. Four pages configure it with an $RC array.'),
       ('app/faculty/faculty_review_dashboard.php',
        'The Research Adviser’s desk. Approving here also records the '
        'checklist of what the adviser confirmed was present.'),
       ('app/admin/admin_review_dashboard.php',
        'The Research Coordinator’s desk. Approving here publishes the '
        'paper — this is the end of the chain.'),
       ('app/faculty/head_review_dashboard.php',
        'The Head of Academic Programs. Reads; does not approve.'),
       ('app/admin/super_admin_review_dashboard.php',
        'The Director. Reads, and can archive a published paper.'),
       ('app/review_paper.php',
        'One paper opened in full, with the PDF beside the decision form.'),
     ]},
    {'title': 'Recording the decision',
     'blurb': '',
     'files': [
       ('app/models/PaperService.php',
        'Approve and decline: writes the history row, moves the status on, '
        'notifies the next person.'),
       ('app/models/PaperRepository.php', 'The paper queries themselves.'),
       ('config/workflow.php',
        'Which status follows which, and whether a paper can still be withdrawn.'),
     ]},
  ],
  'wires': 'writes approval_workflow, paper_checklist, research_papers · '
           'triggers the notifications',
 },
 {
  'id': 'm7', 'num': 7, 'title': 'Reading — the public repository',
  'blurb': 'The only part a visitor sees without an account. There is no '
           'download anywhere: a paper is read in place.',
  'features': [
    {'title': 'Browsing and searching',
     'blurb': '',
     'files': [
       ('archive/index.php',
        'The public repository: search, filter by programme and year, '
        'paginate. Only papers with status approved appear.'),
       ('includes/browse_console.php',
        'The search and filter interface, shared with the signed-in browsing pages.'),
       ('includes/browse_console_js.php',
        'The type-ahead suggestions. It escapes the text first and then '
        'highlights the match — never the other way round.'),
     ]},
    {'title': 'Reading one paper',
     'blurb': '',
     'files': [
       ('archive/view_paper.php',
        'A single paper. The old ?download=1 endpoint was removed, which is '
        'what stops a guest taking a copy.'),
       ('includes/pdf_dock.php',
        'The PDF panel that slides in beside the text, so the reader keeps '
        'their place.'),
       ('app/student/pdf_viewer.php', 'The viewer itself.'),
       ('archive/archive_handler.php',
        'Fifteen lines. A small shared piece the archive pages lean on.'),
       ('app/models/ArchiveRepository.php',
        'The queries behind the archive.'),
       ('app/models/ArchiveService.php',
        'The rules on top of them — what may be shown, and to whom.'),
     ]},
    {'title': 'Visitors without an account',
     'blurb': '',
     'files': [
       ('archive/guest_access.php', 'The guest entrance.'),
       ('config/core.php',
        'guest_pass_still_valid() re-checks the pass on every page, so '
        'revoking one ends the session immediately rather than whenever it '
        'would have expired.'),
     ]},
  ],
  'wires': 'reads research_papers (approved only), papers_archive',
 },
 {
  'id': 'm8', 'num': 8, 'title': 'Messages — the bell, email, and asking for help',
  'blurb': '',
  'features': [
    {'title': 'Notifications',
     'blurb': 'Written whenever something happens to your paper. The bell is in '
              'the shared header, so it is on every page at once.',
     'files': [
       ('notifications/notifications_handler.php',
        'Marks them read, in the background, without reloading the page.'),
       ('notifications/notification_center.php', 'The full list.'),
       ('config/core.php',
        'create_notification() — one function called from everywhere, so '
        'every notification has the same shape.'),
     ]},
    {'title': 'Email',
     'blurb': '',
     'files': [
       ('config/core.php',
        'send_email() and the layout helpers, so every message the system '
        'sends looks the same. Goes out through Gmail’s SMTP using the '
        'credentials in .env.'),
     ]},
    {'title': 'Forgotten password, and account problems',
     'blurb': 'There is no self-service reset. You say who set up your account, '
              'the site checks that is really your adviser, and the request '
              'lands on that person’s desk.',
     'files': [
       ('pages/contact_support.php',
        'The form. It verifies the requester exists and that the person named '
        'really is their adviser, so a request cannot be aimed at a stranger.'),
       ('app/support_requests.php',
        'The desk that answers them. A request disappears once actioned.'),
     ]},
  ],
  'wires': 'writes notifications, support_requests · triggered by '
           'submitting, reviewing and account changes',
 },
 {
  'id': 'm9', 'num': 9, 'title': 'Analytics and exports',
  'blurb': '',
  'features': [
    {'title': 'The charts',
     'blurb': '',
     'files': [
       ('analytics/analytics_dashboard.php',
        'Submissions over time, by programme, by status. The chart colours are '
        'read from the theme at draw time and rebuilt when the theme changes, '
        'so they survive dark mode.'),
       ('analytics/paper_analysis.php', 'One paper in detail.'),
       ('app/models/AnalyticsService.php', 'The counting queries.'),
       ('analytics/dashboard.php',
        'Nineteen lines, and all of them a redirect. There used to be two '
        'analytics pages for two audiences; they were merged, and this stays '
        'as a signpost so old links still arrive somewhere.'),
     ]},
    {'title': 'Export to a file',
     'blurb': 'Written by hand, because the usual libraries were not available. '
              'A spreadsheet and a Word file are both a zip of XML underneath.',
     'files': [
       ('includes/pdf_writer.php',
        'Builds a PDF byte by byte, including the charts as images.'),
       ('includes/xlsx_writer.php', 'Builds an Excel file.'),
       ('includes/docx_writer.php', 'Builds a Word file.'),
     ]},
  ],
  'wires': 'reads research_papers, approval_workflow, analytics, users',
 },
 {
  'id': 'm10', 'num': 10, 'title': 'The AI assistant',
  'blurb': 'Two separate jobs: reading a student’s PDF to fill the form, '
           'and answering questions about the system.',
  'features': [
    {'title': 'Reading the uploaded PDF',
     'blurb': 'Pulls out the title, authors, year, abstract and keywords so the '
              'student does not retype them. It extracts what the paper says; '
              'it does not invent what is missing.',
     'files': [
       ('config/groq_config.php',
        'Which model, and extract_pdf_text() — pulls the text out of the '
        'PDF before the AI sees it. A scan with no text layer is refused with '
        'an explanation. This is the copy every page actually uses.'),
       ('ai/ai_extract.php', 'The extraction endpoint.'),
     ]},
    {'title': 'The chatbot',
     'blurb': '',
     'files': [
       ('ai/help_chatbot.php',
        'Answers questions about using PAPEL. Each question is answered on its '
        'own; nothing is remembered between them.'),
       ('app/student/student_chatbot.php', 'The chat window.'),
     ]},
    {'title': 'Keeping it in bounds',
     'blurb': '',
     'files': [
       ('ai/prompt_guard.php',
        'Blocks attempts to talk the assistant out of its instructions.'),
       ('ai/rate_limiter.php',
        'Counts each person’s use in ai_rate_limits and refuses once the '
        'allowance is spent, so one student cannot exhaust the account.'),
     ]},
  ],
  'wires': 'called from the upload form · talks to Groq over the internet '
           '· falls back to typing it in by hand',
 },
 {
  'id': 'm11', 'num': 11, 'title': 'Storage — Google Drive',
  'blurb': 'The PDFs do not live on the server. They go to a Google account, '
           'and the database keeps only the file’s id.',
  'features': [
    {'title': 'Uploading and filing',
     'blurb': '',
     'files': [
       ('config/gdrive_config.php',
        'Signs in to Google, refreshes the access token, creates the folder '
        'for the programme and year, uploads the file and returns its id.'),
       ('app/admin/gdrive_settings.php',
        'Where the Director connects the Google account and names the '
        'destination folder.'),
       ('pages/gdrive_callback.php',
        'Where Google sends the browser back after you approve access.'),
     ]},
    {'title': 'Keeping the connection alive',
     'blurb': '',
     'files': [
       ('scripts/gdrive_keepalive.php',
        'Run weekly. Google throws away a refresh token that goes six months '
        'unused, and expires one every seven days while the app is in Testing '
        'mode. This refreshes it deliberately and says plainly when it cannot.'),
     ]},
  ],
  'wires': 'used when submitting and when reading · when it fails, '
           'submission stops dead — the upload happens before the database '
           'row is written',
 },
 {
  'id': 'm12', 'num': 12, 'title': 'The test suite — Python, not PHP',
  'blurb': 'Uses the site the way a person would: sign in, click, submit, and '
           'check what actually happened.',
  'features': [
    {'title': 'The machinery',
     'blurb': '',
     'files': [
       ('tests/harness.py',
        'A pretend browser: keeps cookies, signs in through the real form, '
        'sends the security token with every post, and can read the database '
        'to check the result.'),
       ('tests/fixtures.py',
        'Creates a test adviser and two test students through the real '
        'consoles, then deletes them at the end. Every test address ends in '
        '@example.com, which cannot receive mail, so a test can never email a '
        'real person.'),
       ('tests/run_all.py', 'Runs everything and clears up afterwards.'),
     ]},
    {'title': 'The tests themselves',
     'blurb': '',
     'files': [
       ('tests/m1_auth.py', 'Signing in, and creating accounts.'),
       ('tests/m3_dashboard.py', 'Each role reaches its own desk.'),
       ('tests/m4_submission.py',
        'Submitting, including building a real PDF in memory to upload.'),
       ('tests/m5_review.py', 'The approval chain end to end.'),
       ('tests/m6_archive.py', 'Guests, the repository, search, files.'),
       ('tests/m7_notify.py', 'Notifications and email.'),
       ('tests/m8_security.py',
        'Injection, cross-site scripting, role separation, reading someone '
        'else’s paper.'),
       ('tests/m9_ai.py', 'The assistant.'),
       ('tests/report.py',
        'Turns the run into the spreadsheet format of the tracker.'),
     ]},
  ],
  'wires': 'drives the whole site over HTTP · touches only rows it created',
 },
 {
  'id': 'm13', 'num': 13, 'title': 'Dashboards and everyday pages',
  'blurb': 'Where each person actually lands, and the pages anyone can read. '
           'These sit on top of every other module rather than beside them.',
  'features': [
    {'title': 'The front door',
     'blurb': 'The first decision the site makes about you.',
     'files': [
       ('index.php',
        'Twelve lines, and the most-used file in the project: if you are '
        'signed in it sends you to your own desk, and if you are not it sends '
        'you to the public repository. Nothing else.'),
       ('pages/index.php', 'The same idea for the Resources area.'),
     ]},
    {'title': 'Each role’s desk',
     'blurb': 'The page you see after signing in. Which one you get is decided '
              'by role_home() in core.php.',
     'files': [
       ('app/student/student_dashboard.php',
        'The student’s home: their papers in four tabs — approved, in '
        'process, returned, drafts — with the progress tracker. The tabs '
        'matter: a paper under review is not on the default view.'),
       ('app/faculty/faculty_dashboard.php',
        'The adviser’s overview, separate from their review desk.'),
       ('app/student/paper_details.php',
        'One of the student’s own papers in full, including the reviewer’s '
        'feedback if it was returned.'),
     ]},
    {'title': 'The shared dashboard furniture',
     'blurb': 'Why every dashboard looks like the others.',
     'files': [
       ('includes/console_shell.php',
        'The card layout and the progress tracker that shows how far along the '
        'chain a paper has got. Used by every dashboard.'),
       ('includes/card_collapse.php',
        'The fold-away sections on a long card.'),
       ('includes/paper_record_css.php',
        'The styling for a paper record, shared by the pages that show one.'),
     ]},
    {'title': 'Pages anyone can read',
     'blurb': '',
     'files': [
       ('pages/help_center.php', 'How to use the system.'),
       ('pages/about_us.php', 'About the project.'),
       ('pages/terms_and_conditions.php', 'Terms.'),
       ('pages/privacy.php', 'Privacy.'),
     ]},
    {'title': 'One dangerous tool',
     'blurb': '',
     'files': [
       ('reset_system_data.php',
        'Director-only, and exactly what it sounds like: it empties the '
        'system’s data. Worth knowing it exists so it is never run by '
        'accident.'),
     ]},
  ],
  'wires': 'uses every other module · role_home() in core.php decides which '
           'desk you land on',
 },
]


# ------------------------------------------------------------ code tours
# Each one names a file and a distinctive line to find. The builder lifts the
# current text from that file, so these can never quote code that has changed.
CODE = [
 {
  'id': 'c1',
  'title': 'The first four lines of almost every page',
  'file': 'app/faculty/faculty_manage_students.php',
  'anchor': "require_once '../../config/core.php';",
  'before': 1, 'lines': 4,
  'intro': 'Open any page in the project and it starts like this. Once you can '
           'read these four lines you can open any file and know where you are.',
  'notes': [
    ('<?php',
     'Everything from here runs on the server. Nothing between these tags is '
     'ever sent to the browser — only whatever it prints.'),
    ("require_once '../../config/core.php'",
     'Pastes the toolbox in. The ../../ climbs two folders up to reach it, '
     'which is why the path differs between files at different depths.'),
    ('require_role', 'The door. If your role is not in that list, the page '
     'stops right here and you never see it. Nothing below runs.'),
    ('$conn=db()',
     'Opens the database and keeps the connection in $conn. Every query on the '
     'page uses it.'),
    ('$u=current_user()',
     'Reads the session and puts your account in $u. From now on '
     "$u['user_id'] is who is looking at the page."),
  ],
  'takeaway': 'Four lines, and the page now knows who you are, whether you are '
              'allowed, and how to reach the data.',
 },
 {
  'id': 'c2',
  'title': 'A complete action, from button to database',
  'file': 'app/faculty/faculty_manage_students.php',
  'anchor': "if (isset($_POST['action']) && $_POST['action'] === 'toggle_active')",
  'before': 0, 'lines': 16,
  'intro': 'This is the adviser archiving a student. It is fifteen lines, and '
           'it contains nearly every pattern the rest of the codebase uses. If '
           'you only read one piece of PHP here, read this one.',
  'notes': [
    ("$_POST['action']",
     'What the form sent. One page handles several actions, so the first thing '
     'it does is ask which button was pressed.'),
    ('(int)($_POST[\'user_id\'] ?? 0)',
     'Forces the value to a whole number, and uses 0 if it is missing. Anything '
     'that is not a number becomes 0, which then fails the check below.'),
    ('$conn->prepare(',
     'The safe way to query. The ? marks are holes, not text — the value '
     'goes in separately, so it can never be read as a command. This is what '
     'makes SQL injection fail.'),
    ("created_by=?",
     'The important half. Without it, the id arrives in a form field and an '
     'adviser could archive somebody else’s student by posting their '
     'number. The project’s own comment above says exactly this.'),
    ("bind_param('ii'",
     'Fills the two holes, in order. The "ii" says both are integers — one '
     'letter per hole, and a mismatch fails at run time, not when you save.'),
    ('flash(',
     'Leaves a message for the next page to show, once.'),
    ("header('Location:", 'Sends the browser to a fresh page instead of '
     'printing one here. That is why refreshing after an action never repeats '
     'it — and exit stops the rest of the file running.'),
  ],
  'takeaway': 'Read the action, clean the input, run a prepared statement '
              'scoped to what this person owns, leave a message, redirect. '
              'Almost every write in the project is this shape.',
 },
 {
  'id': 'c3',
  'title': 'The door itself',
  'file': 'config/core.php',
  'anchor': 'function require_role(array $roles): void',
  'before': 0, 'lines': 1,
  'intro': 'The whole permission system is one line. Everything that keeps a '
           'student out of the Coordinator’s desk is here.',
  'notes': [
    ('require_login()',
     'First: are you signed in at all? If not you are sent to the sign-in page '
     'and this stops.'),
    ('in_array($u[\'user_role\'], $roles, true)',
     'Is your role one of the ones this page listed? The true means compare '
     'exactly — no loose matching.'),
    ('http_response_code(403); exit',
     'If not: refuse, and stop the program. Nothing after the require_role '
     'line in the page ever runs, so there is no way to reach the content.'),
  ],
  'takeaway': 'Permission is not a decoration on the page — it stops the '
              'program before the page exists. That is why it cannot be '
              'clicked around.',
 },
 {
  'id': 'c4',
  'title': 'Why forms carry a hidden token',
  'file': 'config/core.php',
  'anchor': 'function csrf_field(): string',
  'before': 0, 'lines': 1,
  'intro': 'Every form in PAPEL has an invisible field in it. Without it, '
           'another website could quietly submit PAPEL’s forms using your '
           'signed-in browser.',
  'notes': [
    ('csrf_token()',
     'A long random string, generated once and kept in your session.'),
    ('type="hidden" name="_token"',
     'It rides along with every form you submit, where you never see it.'),
    ('e(',
     'The project’s escape helper. Anything printed into HTML goes '
     'through it, so text can never become markup.'),
  ],
  'takeaway': 'The form proves it came from your own session. At the other end '
              'csrf_verify() checks it and refuses the post if it does not '
              'match — which is why every POST handler starts with that call.',
 },
 {
  'id': 'c5',
  'title': 'A message that survives exactly one page',
  'file': 'config/core.php',
  'anchor': 'function flash(string $key, string $msg = null): ?string',
  'before': 0, 'lines': 8,
  'intro': 'The green and red banners. One small function, and a trap worth '
           'knowing about.',
  'notes': [
    ('if ($msg === null)',
     'The same function does both jobs. Called with a message it stores one; '
     'called without, it reads one.'),
    ('unset($_SESSION[\'flash\'][$key]); return $m;',
     'Reading it deletes it. That is the trap: whichever page reads a flash '
     'first consumes it, so if a message appears on the wrong screen, '
     'something read it early.'),
    ("$_SESSION['flash'][$key] = $msg",
     'Storing it puts it in the session, which is the only thing that survives '
     'to the next page load.'),
  ],
  'takeaway': 'Store on the page that acted, read on the page that follows. '
              'It works because the session outlives the redirect.',
 },
 {
  'id': 'c6',
  'title': 'PHP and HTML woven together',
  'file': 'app/student/student_upload_ai.php',
  'anchor': 'foreach (programs_map() as $progName => $progCode)',
  'before': 2, 'lines': 4,
  'intro': 'This is the pattern that looks strangest if you have not seen PHP '
           'before: code and markup in the same file, taking turns. It is the '
           'Academic Program dropdown.',
  'notes': [
    ('<?php foreach (', 'Start a loop. It runs once for every programme in the '
     'shared list.'),
    ('<option value=',
     'Plain HTML, sent to the browser as it is — once per turn of the loop.'),
    ('<?= e($progName) ?>',
     '<?= means "print this here". So the loop prints ten <option> lines, each '
     'with a different programme in it.'),
    ('endforeach', 'Close the loop. Everything between it and the foreach was '
     'repeated.'),
  ],
  'takeaway': 'PHP does not build the page in memory and hand it over. It '
              'prints text as it goes, and the browser receives finished HTML '
              'with no idea a loop was involved.',
 },
 {
  'id': 'c7',
  'title': 'Moving a paper along the chain',
  'file': 'config/core.php',
  'anchor': 'function add_workflow(int $paper_id, int $reviewer_id',
  'before': 0, 'lines': 6,
  'intro': 'When an adviser approves, two things are written: the history, and '
           'the paper’s new position. This is the history half — the '
           'row that later lets the system tell a returned paper from one that '
           'was never submitted.',
  'notes': [
    ('INSERT INTO approval_workflow',
     'A new row every time somebody decides something. Nothing is overwritten, '
     'so the full history of a paper survives.'),
    ('CASE WHEN ? IN (\'approved\',\'declined\') THEN NOW() ELSE NULL END',
     'The timestamp is only set for a real decision. A row that merely records '
     '"this is waiting on you" has no reviewed_at, which is how the code tells '
     'the two apart.'),
    ("bind_param('iissss'",
     'Six holes, six letters: two integers then four strings. Count them — '
     'a wrong count here fails only when the code actually runs.'),
  ],
  'takeaway': 'The paper’s current_status says where it is now; '
              'approval_workflow says how it got there. Both are needed, which '
              'is why every decision writes twice.',
 },
]


# ------------------------------------------------------------------- misc
GLOSSARY = [
 ('require / include',
  'Paste this other file in here, right now. It is how one file uses another; '
  'there is no import system.'),
 ('&lt;?php … ?&gt;',
  'Everything between these runs on the server. Everything outside them is sent '
  'to the browser as-is. That is why HTML and code are interleaved.'),
 ('&lt;?= $x ?&gt;',
  'Shorthand for "print this value here". You will see it inside HTML constantly.'),
 ('$_POST / $_GET',
  'What arrived from the browser. $_POST is a submitted form; $_GET is the part '
  'after the ? in the address.'),
 ('$_SESSION',
  'The one thing that survives between page loads. It is where "you are signed '
  'in as user 154" is kept.'),
 ('Prepared statement',
  'Sending a query and its values separately, so a value can never be read as a '
  'command. It is what makes injection fail.'),
 ('CSRF token',
  'A secret in every form proving the form came from your own session, so '
  'another site cannot post on your behalf.'),
 ('CSP nonce',
  'A one-time password for a &lt;style&gt; or &lt;script&gt; block. Without it '
  'the browser refuses to run it. This is why style="…" attributes '
  'silently do nothing here.'),
 ('e( )',
  'This project’s escape helper. Anything a person typed goes through it '
  'before being printed, so typed text can never become markup.'),
 ('Flash message',
  'A message stored for exactly one page load — the green or red banner '
  'after an action. Reading it consumes it.'),
]

DB_NOTES = [
 ('users', 'Every account of every kind. created_by is the link that decides '
           'whose desk a paper reaches.'),
 ('research_papers', 'The papers, including the written sections and the '
                     'Google Drive file id. current_status moves along the chain.'),
 ('approval_workflow', 'The history: who decided what, when, and the reason '
                       'given. Also how "draft" is told apart from "returned".'),
 ('notifications', 'What the bell shows.'),
 ('papers_archive', 'Copies kept after a paper is removed. Meant to outlive its '
                    'paper — rows here with no matching paper are correct, '
                    'not orphans.'),
 ('paper_checklist', 'What the adviser ticked when approving.'),
 ('supporting_documents', 'Ethics clearance, consent form and the rest.'),
 ('guest_sessions', 'Temporary visitor passes. Note it also keeps the password '
                    'in plain text so the Librarian can re-show it.'),
 ('support_requests', 'Forgotten passwords and account corrections, waiting on '
                      'someone’s desk.'),
 ('password_changes', 'The audit of who reset whose password.'),
 ('login_attempts', 'Failed sign-ins, for the lockout.'),
 ('analytics', 'Pre-computed figures for the dashboard.'),
 ('system_settings', 'Site-wide settings, including the Google Drive '
                     'destination folder.'),
 ('gdrive_settings', 'The stored Google token.'),
 ('ai_rate_limits', 'How much AI each person has used.'),
 ('ai_processing_log', 'Intended to record what the AI was asked. Currently '
                       'never written to.'),
 ('imrad_checklist', 'Section-by-section checks. Currently empty.'),
 ('paper_favorites', 'Saved papers. Currently empty.'),
 ('storage_usage', 'How much Drive space is used.'),
 ('notification_schedule', 'Groundwork for reminder emails. Unused.'),
]

DEAD_NOTES = {
 'archive/home.php':
   'The largest dead file in the project. An earlier version of the public '
   'repository; nothing links to it and archive/index.php replaced it.',
 'ai/groq_config.php':
   'The trap: a near-copy of config/groq_config.php, right down to the same '
   'function names. Nothing includes it. Every AI page uses the copy in '
   'config/, so editing this one changes nothing and will cost you an afternoon.',
 'includes/progress_tracker.php':
   'The real progress tracker lives in console_shell.php; this is an earlier '
   'version left behind.',
 'config/sri_hashes.php':
   'Security hashes for the CDN scripts, no longer referenced.',
 'config/mail_config.php':
   'Empty file. The mail settings moved into core.php and .env.',
 'includes/mail_config.php': 'Also empty, for the same reason.',
}
