# -*- coding: utf-8 -*-
"""Words and diagram specifications for the PAPEL Workflow Handbook.

Written for readers whose first language is not English: short sentences, one
idea each, common words, and the same name for the same thing every time.
Button and page names are quoted exactly as the screen shows them, in **bold**.

Everything here was checked against the code as it stood in September 2026.
The column lists in SCHEMA match the live database; build_handbook.py compares
them again on every build and says so if the database has moved on.

Inline markup in body text: **bold**, `code`.
"""

# =========================================================== database schema
# (column, short type, key). key is PK, FK (a logical link, most of which the
# program keeps rather than the database) or ''.
SCHEMA = {
    'users': dict(sub='people with accounts', desc='Everyone with an account: students and staff.', cols=[
        ('user_id', 'INT', 'PK'), ('username', 'VARCHAR', ''), ('email', 'VARCHAR', ''),
        ('birthdate', 'DATE', ''), ('password', 'VARCHAR', ''), ('full_name', 'VARCHAR', ''),
        ('user_role', 'ENUM', ''), ('admin_type', 'ENUM', ''), ('created_by', 'INT', 'FK'),
        ('created_at', 'TIMESTAMP', ''), ('last_login', 'TIMESTAMP', ''), ('is_active', 'TINYINT', ''),
        ('is_otp_exempt', 'TINYINT', ''), ('gdrive_token', 'TEXT', ''), ('reset_token', 'VARCHAR', ''),
        ('reset_expires', 'DATETIME', ''), ('title', 'VARCHAR', ''), ('faculty_id', 'VARCHAR', ''),
        ('program', 'VARCHAR', ''), ('academic_year', 'VARCHAR', ''), ('section', 'VARCHAR', ''),
        ('expires_on', 'DATE', ''), ('student_id', 'VARCHAR', ''), ('admin_level', 'TINYINT', '')]),
    'research_papers': dict(sub='every paper not archived', desc='Every paper that is not archived: drafts, papers under review and published papers.', cols=[
        ('paper_id', 'INT', 'PK'), ('title', 'VARCHAR', ''), ('author_names', 'TEXT', ''),
        ('year', 'INT', ''), ('research_date', 'DATE', ''), ('abstract', 'TEXT', ''),
        ('imrad_content', 'LONGTEXT', ''), ('keywords', 'TEXT', ''), ('is_published', 'TINYINT', ''),
        ('publication_details', 'TEXT', ''), ('file_path', 'VARCHAR', ''), ('source_code_path', 'VARCHAR', ''),
        ('gdrive_file_id', 'VARCHAR', ''), ('file_size', 'BIGINT', ''), ('uploaded_by', 'INT', 'FK'),
        ('upload_date', 'TIMESTAMP', ''), ('current_status', 'ENUM', ''), ('paper_type', 'VARCHAR', ''),
        ('research_type', 'VARCHAR', ''), ('manuscript_type', 'VARCHAR', ''),
        ('publication_status', 'VARCHAR', ''), ('publication_location', 'VARCHAR', ''),
        ('program_category', 'VARCHAR', ''), ('paper_format', 'ENUM', ''),
        ('is_imrad_complete', 'TINYINT', ''), ('ai_summary', 'TEXT', ''), ('ai_methodology', 'VARCHAR', ''),
        ('ai_sample_size', 'VARCHAR', ''), ('ai_statistical_methods', 'TEXT', ''), ('ai_variables', 'TEXT', ''),
        ('ai_research_field', 'VARCHAR', ''), ('ai_analyzed_at', 'TIMESTAMP', ''), ('backup_status', 'VARCHAR', '')]),
    'approval_workflow': dict(sub='each review decision', desc='Each review decision: who decided, approve or return, and the feedback.', cols=[
        ('workflow_id', 'INT', 'PK'), ('paper_id', 'INT', 'FK'), ('reviewer_id', 'INT', 'FK'),
        ('review_level', 'ENUM', ''), ('status', 'ENUM', ''), ('feedback', 'TEXT', ''),
        ('reviewed_at', 'TIMESTAMP', ''), ('submitted_at', 'TIMESTAMP', ''), ('admin_level', 'TINYINT', '')]),
    'paper_checklist': dict(sub="the Adviser's checklist", desc='The parts of the paper the Research Adviser ticked when approving.', cols=[
        ('checklist_id', 'INT', 'PK'), ('paper_id', 'INT', 'FK'), ('imrad_intro', 'TINYINT', ''),
        ('imrad_method', 'TINYINT', ''), ('imrad_result', 'TINYINT', ''), ('imrad_discussion', 'TINYINT', ''),
        ('imrad_references', 'TINYINT', ''), ('full_ch1', 'TINYINT', ''), ('full_ch2', 'TINYINT', ''),
        ('full_ch3', 'TINYINT', ''), ('full_ch4', 'TINYINT', ''), ('full_ch5', 'TINYINT', ''),
        ('full_references', 'TINYINT', ''), ('created_at', 'TIMESTAMP', '')]),
    'imrad_checklist': dict(sub='older checklist (unused)', desc='An older section checklist. No page uses it now.', cols=[
        ('checklist_id', 'INT', 'PK'), ('paper_id', 'INT', 'FK'), ('has_introduction', 'TINYINT', ''),
        ('has_methods', 'TINYINT', ''), ('has_results', 'TINYINT', ''), ('has_discussion', 'TINYINT', ''),
        ('has_abstract', 'TINYINT', ''), ('has_references', 'TINYINT', ''), ('checked_by', 'INT', 'FK'),
        ('checked_at', 'TIMESTAMP', '')]),
    'supporting_documents': dict(sub='files sent with a paper', desc='Ethics clearance, consent form and the other files sent with a paper.', cols=[
        ('doc_id', 'INT', 'PK'), ('paper_id', 'INT', 'FK'), ('document_type', 'ENUM', ''),
        ('file_path', 'VARCHAR', ''), ('gdrive_file_id', 'VARCHAR', ''), ('uploaded_at', 'TIMESTAMP', '')]),
    'analytics': dict(sub='numbers about a paper', desc='Numbers about a paper. Only time_to_approval (days from upload to approval) is written today.', cols=[
        ('analytics_id', 'INT', 'PK'), ('paper_id', 'INT', 'FK'), ('view_count', 'INT', ''),
        ('download_count', 'INT', ''), ('citation_count', 'INT', ''), ('approval_date', 'TIMESTAMP', ''),
        ('time_to_approval', 'INT', '')]),
    'notifications': dict(sub='notices in the bell', desc='The notices shown in the bell. Each one is also sent by email.', cols=[
        ('notification_id', 'INT', 'PK'), ('user_id', 'INT', 'FK'), ('paper_id', 'INT', 'FK'),
        ('notification_type', 'ENUM', ''), ('message', 'TEXT', ''), ('is_read', 'TINYINT', ''),
        ('created_at', 'TIMESTAMP', '')]),
    'notification_schedule': dict(sub='reminder times', desc='The times when a person gets a reminder about waiting papers.', cols=[
        ('schedule_id', 'INT', 'PK'), ('user_id', 'INT', 'FK'), ('scheduled_time', 'TIME', ''),
        ('last_sent', 'TIMESTAMP', ''), ('is_active', 'TINYINT', '')]),
    'papers_archive': dict(sub='papers taken out of public view', desc='Papers moved out of the Public Repository. Kept for the record.', cols=[
        ('paper_id', 'INT', 'PK'), ('title', 'VARCHAR', ''), ('author_names', 'TEXT', ''), ('year', 'INT', ''),
        ('research_date', 'DATE', ''), ('abstract', 'TEXT', ''), ('imrad_content', 'LONGTEXT', ''),
        ('keywords', 'TEXT', ''), ('is_published', 'TINYINT', ''), ('publication_details', 'TEXT', ''),
        ('file_path', 'VARCHAR', ''), ('file_size', 'INT', ''), ('uploaded_by', 'INT', 'FK'),
        ('paper_type', 'VARCHAR', ''), ('paper_format', 'ENUM', ''), ('gdrive_file_id', 'VARCHAR', ''),
        ('ai_summary', 'TEXT', ''), ('ai_methodology', 'TEXT', ''), ('ai_sample_size', 'VARCHAR', ''),
        ('ai_statistical_methods', 'TEXT', ''), ('ai_variables', 'TEXT', ''), ('ai_research_field', 'VARCHAR', ''),
        ('upload_date', 'DATETIME', ''), ('archived_date', 'DATETIME', ''), ('archived_by', 'INT', 'FK'),
        ('research_type', 'VARCHAR', ''), ('manuscript_type', 'VARCHAR', ''), ('publication_status', 'VARCHAR', ''),
        ('publication_location', 'VARCHAR', ''), ('program_category', 'VARCHAR', ''),
        ('source_code_path', 'VARCHAR', ''), ('current_status', 'VARCHAR', ''), ('is_imrad_complete', 'TINYINT', ''),
        ('ai_analyzed_at', 'TIMESTAMP', ''), ('backup_status', 'VARCHAR', '')]),
    'guest_sessions': dict(sub='guest passes', desc='Guest passes and the time each one ends.', cols=[
        ('guest_id', 'INT', 'PK'), ('username', 'VARCHAR', ''), ('password', 'VARCHAR', ''),
        ('plain_password', 'VARCHAR', ''), ('created_at', 'DATETIME', ''), ('expires_at', 'DATETIME', '')]),
    'login_attempts': dict(sub='wrong sign-in tries', desc='Counts wrong sign-in tries, to stop people from guessing passwords.', cols=[
        ('scope', 'VARCHAR', 'PK'), ('attempts', 'INT', ''), ('first_at', 'DATETIME', ''),
        ('last_at', 'DATETIME', ''), ('locked_until', 'DATETIME', '')]),
    'manuscript_requests': dict(sub='requests to open a PDF', desc="Students' requests to open a full PDF, and the Librarian's answer.", cols=[
        ('request_id', 'INT', 'PK'), ('paper_id', 'INT', 'FK'), ('student_user_id', 'INT', 'FK'),
        ('status', 'ENUM', ''), ('duration_hours', 'TINYINT', ''), ('granted_by', 'INT', 'FK'),
        ('granted_at', 'DATETIME', ''), ('expires_at', 'DATETIME', ''), ('denied_by', 'INT', 'FK'),
        ('denied_at', 'DATETIME', ''), ('created_at', 'DATETIME', '')]),
    'paper_favorites': dict(sub='favourites (unused)', desc='Meant for favourite papers. No page uses it yet.', cols=[
        ('favorite_id', 'INT', 'PK'), ('user_id', 'INT', 'FK'), ('paper_id', 'INT', 'FK'),
        ('created_at', 'TIMESTAMP', '')]),
    'password_changes': dict(sub='record of password changes', desc='A record of every password change: whose, by whom and when.', cols=[
        ('change_id', 'INT', 'PK'), ('user_id', 'INT', 'FK'), ('changed_by', 'INT', 'FK'),
        ('changed_at', 'DATETIME', '')]),
    'support_requests': dict(sub='requests for help', desc='Forgotten-password and account-problem requests, waiting for a handler.', cols=[
        ('request_id', 'INT', 'PK'), ('kind', 'ENUM', ''), ('requester_name', 'VARCHAR', ''),
        ('requester_email', 'VARCHAR', ''), ('requester_role', 'VARCHAR', ''), ('requester_ident', 'VARCHAR', ''),
        ('requester_user_id', 'INT', 'FK'), ('handler_role', 'VARCHAR', ''), ('handler_user_id', 'INT', 'FK'),
        ('message', 'TEXT', ''), ('created_at', 'DATETIME', '')]),
    'system_settings': dict(sub='system settings', desc='System settings, such as the Google Drive connection and folder.', cols=[
        ('setting_key', 'VARCHAR', 'PK'), ('setting_value', 'TEXT', ''), ('description', 'VARCHAR', ''),
        ('updated_by', 'INT', 'FK'), ('updated_at', 'TIMESTAMP', '')]),
    'gdrive_settings': dict(sub='older Drive settings (unused)', desc='Older Google Drive settings. No page reads it now; system_settings is used instead.', cols=[
        ('setting_key', 'VARCHAR', 'PK'), ('setting_value', 'TEXT', ''), ('description', 'TEXT', ''),
        ('updated_by', 'INT', 'FK'), ('updated_at', 'DATETIME', '')]),
    'ai_processing_log': dict(sub='AI job log (unused)', desc='Meant as a log of AI jobs. No page writes to it now.', cols=[
        ('log_id', 'INT', 'PK'), ('paper_id', 'INT', 'FK'), ('user_id', 'INT', 'FK'),
        ('operation_type', 'VARCHAR', ''), ('ip_protection_level', 'VARCHAR', ''),
        ('content_filtered', 'TINYINT', ''), ('sensitive_data_removed', 'TEXT', ''), ('status', 'VARCHAR', ''),
        ('created_at', 'DATETIME', '')]),
    'ai_rate_limits': dict(sub='AI use per person', desc='Limits how often one person can use the AI helpers.', cols=[
        ('id', 'INT', 'PK'), ('user_id', 'INT', 'FK'), ('action', 'VARCHAR', ''), ('created_at', 'DATETIME', '')]),
    'storage_usage': dict(sub='storage checks (unused)', desc='Meant for storage checks. No page writes to it now.', cols=[
        ('id', 'INT', 'PK'), ('check_timestamp', 'DATETIME', ''), ('gdrive_total_mb', 'DECIMAL', ''),
        ('local_backup_mb', 'DECIMAL', ''), ('total_papers', 'INT', ''), ('backed_up_papers', 'INT', ''),
        ('note', 'TEXT', '')]),
}


def tbl(tid, x, y, cols, w=196, sub=None):
    """One ERD box: the named columns of a table, plus a count of the rest."""
    master = SCHEMA[tid]
    lookup = {c[0]: c for c in master['cols']}
    chosen = [lookup[c] for c in cols]
    return dict(id=tid, x=x, y=y, w=w, name=tid, sub=sub or master['sub'], cols=chosen,
                more=len(master['cols']) - len(chosen))


def rel(a, b, text='', ca='1', cb='N', sa='r', sb='l', ra=None, rb=None, **kw):
    d = dict(a=a, b=b, text=text, ca=ca, cb=cb, sa=sa, sb=sb, ra=ra, rb=rb)
    d.update(kw)
    return d


def note(x, y, w, text):
    return dict(x=x, y=y, w=w, text=text)


# =========================================================== front matter
ROLES = [
    ('Student', 'Writes a paper and uploads it. Fixes it if it is returned.', 'My Dashboard'),
    ('Research Adviser', 'Checks the papers of their own students first. Makes student accounts.', 'Review Desk'),
    ('Research Coordinator', 'Gives the final approval. Approving a paper publishes it. Makes Research Adviser and Librarian accounts.', 'Review Desk'),
    ('Head of Academic Programs', 'Reads all published papers and the reports. Does not approve.', 'My Dashboard'),
    ('Director', 'Reads all published papers. Archives papers. Makes staff accounts. Chooses the Google Drive folder.', 'My Dashboard'),
    ('Librarian', 'Makes guest passes. Answers requests to open a full manuscript.', 'Guest Passes'),
    ('Guest', 'A visitor with a pass that works for a few hours. Can read paper details.', 'Public Repository'),
]

SERVICES = [
    ('Google Drive', 'Keeps the PDF files of submitted papers.'),
    ('Gmail', 'Sends emails: sign-in details, notices and reminders.'),
    ('Groq AI', 'Reads a PDF and fills in the title, authors and abstract. Writes the AI summary. Runs the PUPPY helper.'),
    ('Google reCAPTCHA', 'The "I\'m not a robot" check on the sign-in panel, when it is turned on.'),
]

STATUS_ROWS = [
    ['DRAFT', 'Not sent yet. Only you can see it.', '`draft`'],
    ['ON PROCESS', 'Waiting for the Research Adviser.', '`pending_faculty`'],
    ['ON PROCESS', 'The Adviser approved it. Waiting for the Research Coordinator.', '`pending_admin`'],
    ['DECLINED', 'A reviewer returned it. Read the feedback and fix it.', '`draft` plus feedback'],
    ['APPROVED', 'Published. Everyone can find it in the Public Repository.', '`approved`'],
    ['(not shown)', 'Archived. Taken out of the Public Repository.', 'moved to `papers_archive`'],
]

# The journey of a paper, as a swimlane activity diagram.
LANES = [('Student', 0, 136), ('Research Adviser', 136, 272), ('Research Coordinator', 272, 408),
         ('Public Repository', 408, 544), ('Director', 544, 680)]
JOURNEY = dict(H=560, lanes=LANES, nodes={
    'start': dict(x=68, y=56, kind='start'),
    'write': dict(x=68, y=116, kind='action', w=118, text='Write the paper. Fill in the four steps.'),
    'submit': dict(x=68, y=200, kind='action', w=118, text='Click Submit Paper'),
    'review': dict(x=204, y=200, kind='action', w=118, text='Review the paper'),
    'd1': dict(x=204, y=290, kind='decision', w=96, h=50, text='Good?'),
    'fix': dict(x=68, y=290, kind='action', w=118, text='Read the feedback. Fix. Submit again.'),
    'final': dict(x=340, y=290, kind='action', w=118, text='Final review'),
    'd2': dict(x=340, y=378, kind='decision', w=96, h=50, text='Good?'),
    'pub': dict(x=476, y=378, kind='good', w=118, text='Published'),
    'read': dict(x=476, y=470, kind='action', w=118, text='People search and read it'),
    'arch': dict(x=612, y=470, kind='muted', w=118, text='Archive it, when needed'),
    'end': dict(x=612, y=532, kind='end'),
}, edges=[
    dict(a='start', b='write', sa='b', sb='t'),
    dict(a='write', b='submit', sa='b', sb='t'),
    dict(a='submit', b='review', sa='r', sb='l'),
    dict(a='review', b='d1', sa='b', sb='t'),
    dict(a='d1', b='fix', sa='l', sb='r', text='No', lpos=(142, 283, 'middle')),
    dict(a='fix', b='submit', sa='t', sb='b', text='again', lpos=(74, 250, 'start')),
    dict(a='d1', b='final', sa='r', sb='l', text='Yes', lpos=(266, 283, 'middle')),
    dict(a='final', b='d2', sa='b', sb='t'),
    dict(a='d2', b='fix', sa='l', sb='b', text='No: return to the student', lpos=(180, 372, 'middle')),
    dict(a='d2', b='pub', sa='r', sb='l', text='Yes', lpos=(403, 371, 'middle')),
    dict(a='pub', b='read', sa='b', sb='t'),
    dict(a='pub', b='arch', sa='r', sb='t', text='Director|decides', lpos=(606, 410, 'end')),
    dict(a='arch', b='end', sa='b', sb='t'),
])

STATES = dict(H=600, nodes={
    's1': dict(x=34, y=96, kind='start'),
    'draft': dict(x=150, y=96, kind='state', w=128, text='Draft', sub='draft'),
    'wfa': dict(x=372, y=96, kind='state', w=152, text='Waiting for the Research Adviser', sub='pending_faculty'),
    'wfc': dict(x=586, y=96, kind='state', w=152, text='Waiting for the Research Coordinator', sub='pending_admin'),
    'ret': dict(x=372, y=286, kind='state', w=152, text='Returned (Needs Revision)', sub='draft + feedback'),
    'pub': dict(x=586, y=396, kind='good', w=152, text='Published', sub='approved'),
    's2': dict(x=372, y=396, kind='start'),
    'arch': dict(x=586, y=508, kind='muted', w=152, text='Archived', sub='papers_archive'),
    'end': dict(x=586, y=580, kind='end'),
    'gone': dict(x=150, y=286, kind='end'),
}, edges=[
    dict(a='s1', b='draft', sa='r', sb='l'),
    dict(a='draft', b='wfa', sa='r', sb='l', oa=-10, ob=-10, text='Submit', lpos=(261, 80, 'middle')),
    dict(a='wfa', b='draft', sa='l', sb='r', oa=12, ob=12, text='Cancel|(first 24 hours)', lpos=(255, 124, 'middle')),
    dict(a='wfa', b='wfc', sa='r', sb='l', text='Adviser|approves', lpos=(479, 74, 'middle')),
    dict(a='wfc', b='pub', sa='b', sb='t', oa=34, ob=34, text='Coordinator approves', lpos=(612, 336, 'end')),
    dict(a='wfa', b='ret', sa='b', sb='t', oa=-34, ob=-34, text='Adviser returns', lpos=(332, 196, 'end')),
    dict(a='ret', b='wfa', sa='t', sb='b', oa=34, ob=34, text='Edit and Re-submit', lpos=(412, 196, 'start')),
    dict(a='wfc', b='ret', sa='b', sb='r', oa=-44, text='Coordinator returns', lpos=(536, 240, 'end')),
    dict(a='wfc', b='draft', sa='t', sb='t', text='Cancel (after a 24-hour wait at the Coordinator)',
         lpos=(368, 30, 'middle')),
    dict(a='s2', b='pub', sa='r', sb='l', text='Staff upload (no review)', lpos=(441, 388, 'middle')),
    dict(a='pub', b='arch', sa='b', sb='t', text='Director archives,|or the 5-year rule', lpos=(578, 446, 'end')),
    dict(a='arch', b='end', sa='b', sb='t'),
    dict(a='draft', b='gone', sa='b', sb='t', text='Delete', lpos=(156, 200, 'start')),
    dict(a='ret', b='gone', sa='l', sb='r', text='Delete', lpos=(250, 279, 'middle')),
])

LEGEND_UC = dict(
    cases={'up': 'Upload a paper', 'pdf': 'Attach the PDF', 'ai': 'Read details with AI'},
    grid=[('up', 'pdf'), (None, 'ai')],
    left=[('stu', 'Student', 'person')], right=[('groq', 'Groq AI', 'system')],
    links=[('stu', 'up'), ('groq', 'ai')],
    rels=[('up', 'pdf', 'include'), ('ai', 'up', 'extend')])

LEGEND_SEQ = dict(
    parts=[('u', 'Student', 'person'), ('w', 'PAPEL', 'system'), ('d', 'Database', 'db'),
           ('g', 'Google Drive', 'ext')],
    steps=[('msg', 'u', 'w', 'Click Submit Paper'),
           ('self', 'w', 'Check the form'),
           ('msg', 'w', 'g', 'Upload the PDF'),
           ('ret', 'g', 'w', 'File ID'),
           ('alt', [('the upload worked', [('msg', 'w', 'd', 'Save the paper'),
                                            ('ret', 'w', 'u', '“Upload complete”')]),
                    ('the upload failed', [('ret', 'w', 'u', 'Show an error')])])])

LEGEND_ERD = dict(
    tables=[tbl('users', 40, 10, ['user_id', 'full_name'], w=220),
            tbl('research_papers', 420, 10, ['paper_id', 'title', 'uploaded_by'], w=220)],
    rels=[rel('users', 'research_papers', 'uploads', ra='user_id', rb='uploaded_by')])


# =========================================================== the chapters
PARTS = [
    ('A', 'Accounts and Signing In'),
    ('B', 'Submitting a Paper'),
    ('C', 'Review and Approval'),
    ('D', 'The Public Repository'),
    ('E', 'Records and Help'),
]

CHAPTERS = []


def chapter(**kw):
    CHAPTERS.append(kw)


# ----------------------------------------------------------- 1 Signing in
chapter(
    key='ch01', num=1, part='A', title='Signing In',
    short='You sign in with your ID and your password.',
    who=['Students', 'All staff', 'Guests'], outside=['Google reCAPTCHA'],
    body=[
        ('p', 'The **Sign In** button is at the top of every page. It opens a sign-in panel. The panel has three tabs.'),
        ('table', ['Tab', 'Who uses it', 'What to type as your ID'], [
            ['**Student**', 'Students', 'Your Student ID'],
            ['**Faculty**', 'Research Advisers, the Research Coordinator, the Head of Academic Programs, the Director and Librarians', 'Your Employee ID, or your email'],
            ['**Guest**', 'Visitors with a guest pass', 'The guest username from your email'],
        ]),
        ('h', 'Steps'),
        ('steps', [
            'Click **Sign In** at the top of the page.',
            'Choose your tab: **Student**, **Faculty** or **Guest**.',
            'Type your ID.',
            'Type your password.',
            'Tick **I\'m not a robot**, if you see it.',
            'Click the sign-in button.',
            'PAPEL opens your home page. (See the table of people in Start Here 2.)',
        ]),
        ('remember', [
            '**Five wrong passwords lock the account for 15 minutes.** This stops people who try to guess passwords. Wait 15 minutes, then try again.',
            'Twenty wrong tries from one computer lock that computer for 15 minutes, for every account.',
            'Choose the right tab. A staff account cannot sign in on the **Student** tab.',
            'A student account has an end date. After that date it stops working. Ask your Research Adviser to renew it.',
            'Forgot your password? Use **Contact Support**. See Workflow 14.',
        ]),
        ('h', 'How long a student account lasts'),
        ('p', 'The end date comes from your year level and section. PAPEL counts school years from the academic year on your account. The account ends on **31 July** of the last school year.'),
        ('table', ['Your section', 'Your account lasts'], [
            ['1st year (1-1, 1-2, ...)', '5 school years'],
            ['2nd year (2-1, 2-2, ...)', '4 school years'],
            ['3rd year (3-1, 3-2, ...)', '3 school years'],
            ['4th year (4-1, 4-2, ...)', '2 school years'],
            ['Ladderized', '2 school years'],
        ]),
        ('p', 'To renew an account, the Research Adviser moves the student up a year. PAPEL then works out a new end date.'),
        ('words', [('ID', 'The number the school gave you, like a Student ID or Employee ID.'),
                   ('Lock', 'PAPEL stops accepting tries for a short time.'),
                   ('Expire', 'To reach the end date and stop working.')]),
    ],
    uc=dict(
        cases={'tab': 'Choose a sign-in tab', 'robot': 'Pass the robot check', 'signin': 'Sign in',
               'help': 'Ask for password help', 'locked': 'Get locked after too many wrong tries',
               'out': 'Sign out'},
        # Ovals a person touches sit on the left, so no actor line has to cross
        # an oval to reach its own; the steps PAPEL does by itself sit on the right.
        grid=[(None, 'tab'), ('signin', 'robot'), ('help', 'locked'), ('out', None)],
        left=[('stu', 'Student', 'person'), ('staff', 'Staff member', 'person'), ('guest', 'Guest', 'person')],
        right=[('cap', 'Google reCAPTCHA', 'system')],
        links=[('stu', 'signin'), ('staff', 'signin'), ('guest', 'signin'), ('stu', 'out'), ('staff', 'out'),
               ('guest', 'out'), ('stu', 'help'), ('staff', 'help'), ('cap', 'robot')],
        rels=[('signin', 'tab', 'include'), ('signin', 'robot', 'include'), ('locked', 'signin', 'extend'),
              ('help', 'signin', 'extend')]),
    uc_note='Everyone signs in the same way. Choosing a tab and passing the robot check are always part of signing in. Being locked out and asking for help happen only sometimes.',
    seq=dict(
        parts=[('u', 'Person signing in', 'person'), ('w', 'PAPEL', 'system'), ('c', 'Google reCAPTCHA', 'ext'),
               ('d', 'Database', 'db')],
        steps=[
            ('msg', 'u', 'w', 'Choose a tab. Type ID and password. Tick the robot box.'),
            ('msg', 'w', 'c', 'Check the robot answer'),
            ('ret', 'c', 'w', 'OK'),
            ('msg', 'w', 'd', 'Is this ID locked? (login_attempts)'),
            ('ret', 'd', 'w', 'Not locked'),
            ('alt', [('Guest tab', [('msg', 'w', 'd', 'Find a guest pass that has not expired (guest_sessions)')]),
                     ('Student or Faculty tab', [('msg', 'w', 'd', 'Find the account by ID or email (users)')])]),
            ('self', 'w', 'Check the password'),
            ('alt', [('password is wrong', [('msg', 'w', 'd', 'Count one wrong try'),
                                            ('ret', 'w', 'u', '“That ID or password is not right.”')]),
                     ('password is right', [('msg', 'w', 'd', 'Clear the wrong tries'),
                                            ('self', 'w', 'Check the tab, the role and the end date'),
                                            ('ret', 'w', 'u', 'Open the home page')])]),
        ]),
    seq_note='The robot check comes first, before the password is looked at. This stops robots from testing passwords. The lock check comes next.',
    erd=dict(
        tables=[tbl('users', 6, 10, ['user_id', 'student_id', 'faculty_id', 'email', 'password', 'user_role',
                                     'admin_level', 'is_active', 'expires_on']),
                tbl('guest_sessions', 242, 10, ['guest_id', 'username', 'password', 'expires_at']),
                tbl('login_attempts', 478, 10, ['scope', 'attempts', 'first_at', 'last_at', 'locked_until'])],
        rels=[],
        notes=[note(242, 170, 432, 'These three tables are not joined by IDs. PAPEL reads them one after '
                                   'another: first login_attempts (is this ID locked?), then guest_sessions for '
                                   'the Guest tab, or users for the Student and Faculty tabs. password is '
                                   'stored scrambled (hashed), never as plain text.')]),
    erd_note='Sign-in reads three tables. "users" holds real accounts. "guest_sessions" holds guest passes. "login_attempts" counts wrong tries.',
)

# ----------------------------------------------------------- 2 Accounts
chapter(
    key='ch02', num=2, part='A', title='Creating User Accounts',
    short='Accounts are made from the top down. Each person makes the accounts of the people below them.',
    who=['Director', 'Research Coordinator', 'Research Adviser'], outside=['Gmail'],
    body=[
        ('p', 'Nobody can make their own account. Someone above you makes it.'),
        ('table', ['Who makes it', 'Which accounts', 'Page'], [
            ['Director', 'Research Coordinator, Head of Academic Programs, Librarian', '**Manage Admins**'],
            ['Research Coordinator', 'Research Advisers and Librarians', '**Manage Faculty** (the page is called **Advisers & Librarians**)'],
            ['Research Adviser', 'Students', '**My Students**'],
        ]),
        ('tip', 'The person who makes a student account becomes that student\'s Research Adviser. The student\'s papers always go to that person first.'),
        ('h', 'Steps: a Research Adviser makes a student account'),
        ('steps', [
            'Open **My Students**.',
            'Fill in the form: full name, Student ID, email, program, section and academic year.',
            'Click **Generate** to make a password. It is made from the name and the ID, for example `JuanDelaCruz-056`.',
            'Save the account.',
            'PAPEL checks that the Student ID and the email are not used yet.',
            'PAPEL works out the end date of the account from the section (see Workflow 1).',
            'PAPEL emails the student their Student ID and password.',
            'The password shows on your screen **one time only**. Write it down if the email fails.',
        ]),
        ('p', 'Staff accounts are made in the same way, on **Manage Admins** or **Manage Faculty**. PAPEL emails the sign-in details to the new staff member.'),
        ('remember', [
            'A password needs **at least 6 characters**, **one capital letter** and **one number**.',
            'Tell new users to change their password after they sign in for the first time.',
            'To renew a student, edit the account and move the student up a year.',
            'PAPEL records every password change: whose password, who changed it, and when.',
        ]),
        ('words', [('Account', 'Your name, ID and password in PAPEL.'),
                   ('Generate', 'Let PAPEL make something for you, here a password.'),
                   ('Renew', 'Give an account a new end date.')]),
    ],
    uc=dict(
        cases={'create': 'Create an account', 'gen': 'Generate a password', 'mail': 'Email the sign-in details',
               'edit': 'Edit or reset an account', 'renew': 'Renew a student account',
               'own': 'Change my own password'},
        grid=[('create', 'gen'), (None, 'mail'), ('edit', 'renew'), 'own'],
        left=[('dir', 'Director', 'person'), ('coord', 'Research Coordinator', 'person'),
              ('adv', 'Research Adviser', 'person')],
        right=[('gmail', 'Gmail', 'system'), ('new', 'New user', 'person')],
        links=[('dir', 'create'), ('coord', 'create'), ('adv', 'create'), ('dir', 'edit'), ('coord', 'edit'),
               ('adv', 'edit'), ('adv', 'own'), ('coord', 'own'), ('dir', 'own'), ('gmail', 'mail'),
               ('new', 'mail')],
        rels=[('create', 'gen', 'include'), ('create', 'mail', 'include'), ('renew', 'edit', 'extend')]),
    uc_note='Three people can create accounts, each for the people below them. Every new account gets a password and an email. Renewing a student is a special kind of edit.',
    seq=dict(
        parts=[('a', 'Research Adviser', 'person'), ('w', 'PAPEL', 'system'), ('d', 'Database', 'db'),
               ('g', 'Gmail', 'ext'), ('s', 'Student', 'person')],
        steps=[
            ('msg', 'a', 'w', 'Open My Students. Fill in the form.'),
            ('msg', 'a', 'w', 'Click Generate'),
            ('ret', 'w', 'a', 'A new password'),
            ('msg', 'a', 'w', 'Save the account'),
            ('msg', 'w', 'd', 'Is the Student ID or email used?'),
            ('ret', 'd', 'w', 'Not used'),
            ('self', 'w', 'Check the password rule. Work out the end date.'),
            ('msg', 'w', 'd', 'Save the account (created_by = this adviser)'),
            ('msg', 'w', 'g', 'Send the ID and password'),
            ('msg', 'g', 's', 'Email with sign-in details'),
            ('ret', 'w', 'a', '“Student created.” Show the password once.'),
        ]),
    seq_note='The Adviser\'s own user ID is saved in the new account (created_by). That link is what sends the student\'s papers to this Adviser later.',
    erd=dict(
        tables=[tbl('users', 110, 10, ['user_id', 'full_name', 'email', 'password', 'user_role', 'admin_level',
                                       'student_id', 'faculty_id', 'program', 'section', 'academic_year',
                                       'expires_on', 'is_active', 'created_by'], w=216),
                tbl('password_changes', 470, 150, ['change_id', 'user_id', 'changed_by', 'changed_at'], w=204)],
        rels=[rel('users', 'users', 'is made by', ca='N', cb='1', sa='l', sb='l', ra='created_by', rb='user_id',
                  lpos=(88, 184, 'end')),
              rel('users', 'password_changes', 'has', sa='r', sb='l', ra='user_id', rb='user_id',
                  lpos=(392, 190, 'end')),
              rel('users', 'password_changes', 'changed by', sa='r', sb='l', ra='user_id', rb='changed_by',
                  via=[(420, 63), (420, 239)], lpos=(426, 118, 'start'))]),
    erd_note='An account points to the account that made it (created_by). The line that goes out of "users" and back into it shows this. Each password change is one row in "password_changes".',
)

# ----------------------------------------------------------- 3 Upload
chapter(
    key='ch03', num=3, part='B', title='Uploading a Paper',
    short='The student fills in four steps and submits the paper. It goes to their Research Adviser.',
    who=['Student', 'Research Adviser (gets a notice)'], outside=['Groq AI', 'Google Drive', 'Gmail'],
    body=[
        ('p', 'Open **Upload Paper** from your dashboard. The upload page has four steps. You can go back to an earlier step at any time.'),
        ('h', 'Step 1: Paper Details'),
        ('bullets', [
            'Choose your academic program, the paper type and the other basic facts.',
            'Paper types: **Capstone Project**, **Undergraduate Thesis**, **Conference Paper**, **Journal Article** and **Feasibility Study**.',
        ]),
        ('h', 'Step 2: Upload & Details'),
        ('bullets', [
            'Attach your paper as a **PDF** file. The page accepts files up to 50 MB.',
            'Choose how to fill in the details:',
            '**Extract Metadata with AI**: PAPEL reads your PDF. It fills in the title, authors, year, keywords and abstract. If the AI is busy, wait a minute and press it again, or fill the details in yourself.',
            '**Fill Manually**: you type the details yourself.',
            '**Always check what the AI wrote.** Fix any mistakes.',
            'Write or paste each section of your paper: Abstract, Introduction, Methodology, Results and Discussion, Conclusion, References.',
            'You can paste pictures into a section. Each picture can be up to 5 MB.',
        ]),
        ('h', 'Step 3: Supporting Docs'),
        ('p', 'A **Capstone Project** needs all four documents below. For the other paper types, they are optional. Every document must be a PDF.'),
        ('bullets', ['Ethics Clearance', 'Consent Form', 'Data Collection Tool', 'Copyright / IP Document']),
        ('h', 'Step 4: Review & Submit'),
        ('p', 'Check everything one last time. Then click **Submit Paper**.'),
        ('h', 'What happens after you click Submit Paper'),
        ('steps', [
            'PAPEL checks that every required part is there.',
            'PAPEL sends your PDF and your documents to Google Drive. Submitted papers are kept there.',
            'PAPEL saves the paper. The badge on your card says **ON PROCESS**.',
            'Your Research Adviser gets a notice and an email.',
            'You go back to your dashboard. The paper is in the **Under Review** tab.',
        ]),
        ('remember', [
            'Your paper always goes first to **your** Research Adviser: the faculty member who made your account.',
            'If the upload to Google Drive fails, the paper is **not** submitted. Try again later.',
            'You can save your work before you submit. See Workflow 4.',
            'You can cancel a submission for a short time. See Workflow 5.',
            'Staff can upload their own papers too. A staff paper is **published at once**, with no review.',
        ]),
        ('words', [('PDF', 'A file type for documents. It looks the same on every computer.'),
                   ('Metadata', 'Basic facts about a paper, like the title, authors and year.'),
                   ('Submit', 'Send your paper for review.')]),
    ],
    uc=dict(
        cases={'upload': 'Upload a paper', 'ai': 'Read details with AI', 'docs': 'Attach supporting documents',
               'draft': 'Save a draft', 'submit': 'Submit the paper', 'store': 'Save files to Google Drive',
               'notify': 'Notify the Research Adviser'},
        grid=[('upload', 'ai'), ('docs', None), ('draft', None), ('submit', 'store'), (None, 'notify')],
        left=[('stu', 'Student', 'person'), ('staff', 'Staff member', 'person')],
        right=[('groq', 'Groq AI', 'system'), ('drive', 'Google Drive', 'system'),
               ('adv', 'Research Adviser', 'person')],
        links=[('stu', 'upload'), ('stu', 'docs'), ('stu', 'draft'), ('stu', 'submit'), ('staff', 'upload'),
               ('staff', 'submit'), ('groq', 'ai'), ('drive', 'store'), ('adv', 'notify')],
        rels=[('ai', 'upload', 'extend'), ('upload', 'docs', 'include'), ('draft', 'upload', 'extend'),
              ('submit', 'store', 'include'), ('submit', 'notify', 'include')]),
    uc_note='The student uploads and submits the paper. Using the AI and saving a draft are optional. Every submission saves its files to Google Drive and tells the Research Adviser.',
    seq=dict(
        parts=[('s', 'Student', 'person'), ('w', 'PAPEL', 'system'), ('g', 'Groq AI', 'ext'),
               ('v', 'Google Drive', 'ext'), ('d', 'Database', 'db')],
        steps=[
            ('msg', 's', 'w', 'Step 1: fill in the paper details'),
            ('msg', 's', 'w', 'Step 2: attach the PDF'),
            ('opt', 'student clicks Extract Metadata with AI', [
                ('msg', 'w', 'g', 'Send the text of the PDF'),
                ('ret', 'g', 'w', 'Title, authors, year, keywords, abstract'),
                ('ret', 'w', 's', 'Show the details to check'),
            ]),
            ('msg', 's', 'w', 'Write the sections. Step 3: attach the documents.'),
            ('msg', 's', 'w', 'Step 4: click Submit Paper'),
            ('self', 'w', 'Check the fields and the required documents'),
            ('msg', 'w', 'v', 'Upload the PDF and the documents'),
            ('ret', 'v', 'w', 'File IDs'),
            ('self', 'w', 'Delete the short-time copy on the server'),
            ('msg', 'w', 'd', 'Save the paper (status: pending_faculty)'),
            ('msg', 'w', 'd', 'Save a notice for the Adviser, and email it'),
            ('ret', 'w', 's', '“Upload complete.” Open the dashboard.'),
        ]),
    seq_note='The PDF stays on the PAPEL server only for a moment. After Google Drive has it, the server copy is deleted. If the Drive upload fails, nothing is saved.',
    erd=dict(
        tables=[tbl('users', 6, 10, ['user_id', 'full_name', 'user_role', 'created_by']),
                tbl('research_papers', 242, 10, ['paper_id', 'title', 'author_names', 'paper_type', 'abstract',
                                                 'imrad_content', 'file_path', 'gdrive_file_id', 'uploaded_by',
                                                 'current_status', 'upload_date']),
                tbl('supporting_documents', 478, 10, ['doc_id', 'paper_id', 'document_type', 'file_path',
                                                      'gdrive_file_id', 'uploaded_at']),
                tbl('notifications', 478, 196, ['notification_id', 'user_id', 'paper_id', 'notification_type',
                                                'message', 'is_read', 'created_at'])],
        rels=[rel('users', 'research_papers', 'uploads', ra='user_id', rb='uploaded_by', lpos=(219, 180, 'end')),
              rel('research_papers', 'supporting_documents', 'has', ra='paper_id', rb='paper_id', lpos=(458, 55, 'middle')),
              rel('research_papers', 'notifications', 'is about', ra='paper_id', rb='paper_id', lpos=(458, 180, 'middle')),
              rel('users', 'notifications', 'receives', sa='b', sb='b')]),
    erd_note='One user uploads many papers. One paper has many supporting documents. A notice belongs to one user and is about one paper. The sections of the paper are kept in imrad_content. gdrive_file_id is the file\'s address in Google Drive.',
)

# ----------------------------------------------------------- 4 Drafts
chapter(
    key='ch04', num=4, part='B', title='Saving a Draft',
    short='A draft keeps your unfinished paper, so you can continue later.',
    who=['Student'], outside=[],
    body=[
        ('p', 'PAPEL keeps your work in two different ways. It is important to know the difference.'),
        ('table', ['', 'Automatic copy', '**Save as Draft** button'], [
            ['Where is it kept?', 'In your browser, on this device only', 'On the PAPEL server'],
            ['When is it saved?', 'By itself, a moment after you stop typing', 'When you click **Save as Draft**'],
            ['What is kept?', 'Your typed text and your choices', 'Your typed text, your choices and your PDF'],
            ['Is the PDF kept?', '**No.** You must attach it again.', '**Yes**'],
            ['Is it on your dashboard?', 'No', 'Yes, in the **Drafts** tab'],
            ['Can you open it on another computer?', 'No', 'Yes'],
        ]),
        ('h', 'Steps: save a draft'),
        ('steps', [
            'Fill in what you have so far.',
            'Click **Save as Draft** at the bottom of the upload page.',
            'A message says **Draft saved**.',
            'Later, find it in the **Drafts** tab on your dashboard.',
        ]),
        ('h', 'Steps: continue a draft'),
        ('steps', [
            'Open your dashboard.',
            'Open the **Drafts** tab.',
            'Click **Continue editing** on the draft.',
            'Finish the steps. Then click **Submit Paper**.',
        ]),
        ('h', 'Steps: delete a draft'),
        ('steps', [
            'Click the delete (trash) button on the draft card.',
            'Confirm. The draft and its PDF are removed.',
        ]),
        ('remember', [
            'Supporting documents are **not** saved in a draft. Attach them when you submit.',
            'Drafts are **not** sent to Google Drive. They stay on the PAPEL server.',
            'If you save again with a new PDF, the old PDF is deleted.',
            'Nobody reviews a draft. Only you can see it. To send it for review, you must submit it.',
            'If you come back to the upload page on the same device, PAPEL asks **Continue your draft?**',
            'Pictures you paste into a section are saved on the server at once.',
        ]),
        ('words', [('Draft', 'Work that is not finished and not sent.'),
                   ('Browser', 'The program you use to open websites, like Chrome or Edge.'),
                   ('Device', 'Your computer, tablet or phone.')]),
    ],
    uc=dict(
        cases={'save': 'Save a draft', 'pdf': 'Keep the PDF on the server', 'cont': 'Continue a draft',
               'restore': 'Continue from the copy on this device', 'submit': 'Submit a draft',
               'auto': 'Keep an automatic copy', 'del': 'Delete a draft'},
        grid=[('save', 'pdf'), ('cont', 'restore'), ('submit', 'auto'), ('del', None)],
        left=[('stu', 'Student', 'person')],
        right=[('brw', 'Web browser', 'system')],
        links=[('stu', 'save'), ('stu', 'cont'), ('stu', 'submit'), ('stu', 'del'), ('brw', 'auto'),
               ('brw', 'restore')],
        rels=[('save', 'pdf', 'include'), ('restore', 'cont', 'extend')]),
    uc_note='The student saves, continues, submits or deletes a draft. The web browser keeps its own copy by itself. That copy can fill the form again on the same device.',
    seq=dict(
        parts=[('s', 'Student', 'person'), ('b', 'Browser (this device)', 'browser'), ('w', 'PAPEL', 'system'),
               ('d', 'Database', 'db')],
        steps=[
            ('msg', 's', 'b', 'Type in the upload form'),
            ('self', 'b', 'Save a copy on this device (no PDF)'),
            ('msg', 's', 'b', 'Click Save as Draft'),
            ('msg', 'b', 'w', 'Send the form and the PDF (no supporting documents)'),
            ('self', 'w', 'Save the PDF in the drafts folder. Delete the old PDF.'),
            ('msg', 'w', 'd', 'Save or update the paper (status: draft)'),
            ('ret', 'd', 'w', 'Draft number'),
            ('ret', 'w', 'b', 'Saved'),
            ('ret', 'b', 's', '“Draft saved.”'),
            ('note', ('s', 'd'), 'Later, maybe on another computer'),
            ('msg', 's', 'w', 'Open Drafts. Click Continue editing.'),
            ('msg', 'w', 'd', 'Load the draft'),
            ('ret', 'w', 's', 'Show the upload form, filled in'),
        ]),
    seq_note='The browser copy never leaves your device. Only the Save as Draft button sends your work, and your PDF, to PAPEL.',
    erd=dict(
        tables=[tbl('users', 6, 10, ['user_id', 'full_name', 'student_id']),
                tbl('research_papers', 300, 10, ['paper_id', 'title', 'imrad_content', 'file_path', 'uploaded_by',
                                                 'current_status', 'upload_date'], w=230)],
        rels=[rel('users', 'research_papers', 'owns', ra='user_id', rb='uploaded_by')],
        notes=[note(6, 222, 320, "A saved draft is a row with current_status = 'draft'. file_path points to "
                                 "its PDF on the server, in app/student/uploads/drafts/<user_id>/."),
               note(340, 222, 334, 'The automatic copy is kept by the browser (localStorage). It is not in the '
                                   'database, and it never holds the PDF.'),
               note(6, 306, 668, 'Pictures pasted into a section are files in uploads/section_images/<user_id>/. '
                                 'The section text in imrad_content points to them.')]),
    erd_note='A draft is an ordinary paper row with the status "draft". Its PDF is a file on the server, not in Google Drive.',
)

# ----------------------------------------------------------- 5 Cancel
chapter(
    key='ch05', num=5, part='B', title='Cancelling a Submission',
    short='You can take back a submitted paper, but only at certain times.',
    who=['Student', 'Research Adviser and Research Coordinator (get a notice)'], outside=[],
    body=[
        ('p', 'After you submit, your paper card shows a **Cancel submission** button when it is allowed. Cancelling brings the paper back to you as a draft.'),
        ('table', ['Where is your paper?', 'When can you cancel?'], [
            ['With your Research Adviser', 'Only in the **first 24 hours** after you submit.'],
            ['With the Research Coordinator', 'Only **after** the Coordinator has had it for 24 hours with no decision.'],
            ['Published', 'You cannot cancel.'],
        ]),
        ('p', 'Why is it different? At the first stage, 24 hours give you time to change your mind. After that, the Adviser should not review a paper that keeps changing. At the second stage, your paper already has an approval. So you can take it back only if it waits too long.'),
        ('h', 'Steps'),
        ('steps', [
            'Open your dashboard. Go to the **Under Review** tab.',
            'Find the paper.',
            'Click **Cancel submission**.',
            'A box asks **Withdraw this submission?** Click **Withdraw it**.',
            'The paper goes back to your **Drafts**.',
            'Your reviewers get a notice.',
        ]),
        ('remember', [
            'Approvals that were already given stay on record.',
            'If the time has passed, the card tells you why. Ask your Research Adviser for help.',
            'If a reviewer acts at the same moment as you, the reviewer\'s decision wins.',
            'To send the paper again, open the draft and submit it. It starts again with your Research Adviser.',
        ]),
        ('words', [('Cancel / Withdraw', 'Take back something you sent.'),
                   ('Window', 'The time when something is allowed.')]),
    ],
    uc=dict(
        cases={'cancel': 'Cancel a submission', 'window': 'Check the time window', 'resend': 'Submit it again',
               'back': 'Put the paper back in Drafts', 'notify': 'Notify the reviewers'},
        grid=[('cancel', 'window'), ('resend', 'back'), (None, 'notify')],
        left=[('stu', 'Student', 'person')],
        right=[('adv', 'Research Adviser', 'person'), ('coord', 'Research Coordinator', 'person')],
        links=[('stu', 'cancel'), ('stu', 'resend'), ('adv', 'notify'), ('coord', 'notify')],
        rels=[('cancel', 'window', 'include'), ('cancel', 'back', 'include'), ('cancel', 'notify', 'include')]),
    uc_note='Cancelling always checks the time window first. If it is allowed, the paper goes back to Drafts and the reviewers are told.',
    seq=dict(
        parts=[('s', 'Student', 'person'), ('w', 'PAPEL', 'system'), ('d', 'Database', 'db'),
               ('r', 'Reviewers', 'person')],
        steps=[
            ('msg', 's', 'w', 'Click Cancel submission. Click Withdraw it.'),
            ('msg', 'w', 'd', 'Load the paper and its review times'),
            ('ret', 'd', 'w', 'Status and times'),
            ('self', 'w', 'Is it inside the allowed time?'),
            ('alt', [('not allowed', [('ret', 'w', 's', 'Show why, for example: the 24-hour window has passed')]),
                     ('allowed', [('msg', 'w', 'd', 'Remove the “waiting” review row'),
                                  ('msg', 'w', 'd', 'Set the status back to draft'),
                                  ('msg', 'w', 'r', 'Notice: the student withdrew the paper'),
                                  ('ret', 'w', 's', 'The paper is in Drafts')])]),
        ]),
    seq_note='PAPEL checks the time again when you click. The button on your screen may be old, so PAPEL does not trust it.',
    erd=dict(
        tables=[tbl('users', 6, 10, ['user_id', 'full_name', 'created_by']),
                tbl('research_papers', 242, 10, ['paper_id', 'title', 'uploaded_by', 'current_status', 'upload_date']),
                tbl('approval_workflow', 478, 10, ['workflow_id', 'paper_id', 'reviewer_id', 'review_level', 'status',
                                                   'submitted_at', 'reviewed_at']),
                tbl('notifications', 242, 200, ['notification_id', 'user_id', 'paper_id', 'notification_type',
                                                'message'])],
        rels=[rel('users', 'research_papers', 'uploads', ra='user_id', rb='uploaded_by', lpos=(222, 52, 'middle')),
              rel('research_papers', 'approval_workflow', 'has', ra='paper_id', rb='paper_id', lpos=(458, 52, 'middle')),
              rel('research_papers', 'notifications', 'is about', sa='b', sb='t'),
              rel('users', 'notifications', 'receives', sa='b', sb='l', rb='user_id')],
        notes=[note(478, 222, 196, "Cancelling deletes only the 'pending' rows in approval_workflow. "
                                   "Approvals already given stay.")]),
    erd_note='Cancelling changes the paper\'s status and removes any "waiting" review row. Notices go to the reviewers.',
)

# ----------------------------------------------------------- 6 Adviser
chapter(
    key='ch06', num=6, part='C', title='Review by the Research Adviser',
    short='The Research Adviser checks the paper first. They approve it or return it.',
    who=['Research Adviser', 'Student', 'Research Coordinator (gets a notice)'], outside=['Google Drive', 'Gmail'],
    body=[
        ('p', 'The Research Adviser works on the **Review Desk**. It has four tabs.'),
        ('table', ['Tab', 'What is in it'], [
            ['**Waiting for you**', 'Papers you must review now.'],
            ['**With Coordinator**', 'Papers you approved. They wait for the Research Coordinator.'],
            ['**Published**', 'Your students\' published papers.'],
            ['**Returned**', 'Papers you sent back.'],
        ]),
        ('p', 'You see papers from **your own students only**: the students whose accounts you made.'),
        ('h', 'Steps: approve a paper'),
        ('steps', [
            'Open the paper in **Waiting for you**. Read it.',
            'Click **Approve and forward**. A checklist opens.',
            'Tick each part the paper has. For example: Introduction, Methods, Results, Discussion and References. Or: Chapter 1 to Chapter 5 and References.',
            'You can leave the **Note for the student** box empty. (Today, no page shows this note to the student.)',
            'Confirm with **Approve and forward**.',
            'The paper goes to the Research Coordinator. The Coordinator gets a notice and an email.',
        ]),
        ('h', 'Steps: return a paper'),
        ('steps', [
            'Open the paper. Click **Return with feedback**.',
            'A box asks **Return this paper?**',
            'Write **What needs to change**. Be clear, so the student knows what to fix.',
            'Click **Return to student**.',
            'The paper goes back to the student. Their dashboard shows it under **Needs Revision**.',
            'The student gets a notice and an email with your feedback.',
        ]),
        ('remember', [
            '**Feedback is required** when you return a paper. PAPEL refuses feedback that is empty, looks like random letters, or has rude words.',
            'When you return a paper, **its files are deleted from Google Drive**. The student must attach the PDF again.',
            'Your checklist is saved with the paper.',
            'You can act only on papers that are waiting for you.',
        ]),
        ('words', [('Approve', 'Say yes. The paper moves on.'),
                   ('Return', 'Send the paper back to the student to fix.'),
                   ('Feedback', 'Your comments about what to change.')]),
    ],
    uc=dict(
        cases={'view': "See my students' papers", 'review': 'Review a paper', 'approve': 'Approve and forward',
               'check': 'Tick the checklist', 'notc': 'Notify the Research Coordinator',
               'ret': 'Return with feedback', 'nots': 'Notify the student', 'purge': 'Delete the files from Google Drive'},
        grid=[('view', None), ('review', None), ('approve', 'check'), (None, 'notc'), ('ret', 'nots'),
              (None, 'purge')],
        left=[('adv', 'Research Adviser', 'person')],
        right=[('coord', 'Research Coordinator', 'person'), ('stu', 'Student', 'person'),
               ('drive', 'Google Drive', 'system')],
        links=[('adv', 'view'), ('adv', 'review'), ('adv', 'approve'), ('adv', 'ret'), ('coord', 'notc'),
               ('stu', 'nots'), ('drive', 'purge')],
        rels=[('approve', 'check', 'include'), ('approve', 'notc', 'include'), ('ret', 'nots', 'include'),
              ('ret', 'purge', 'include')]),
    uc_note='The Adviser makes one of two decisions. Approving always saves a checklist and tells the Coordinator. Returning always tells the student and deletes the files from Google Drive.',
    seq=dict(
        parts=[('a', 'Research Adviser', 'person'), ('w', 'PAPEL', 'system'), ('d', 'Database', 'db'),
               ('v', 'Google Drive', 'ext')],
        steps=[
            ('msg', 'a', 'w', 'Open the Review Desk'),
            ('msg', 'w', 'd', 'Find my students\' papers that wait for me (pending_faculty)'),
            ('ret', 'w', 'a', 'Show the list'),
            ('msg', 'a', 'w', 'Choose Approve and forward, or Return with feedback'),
            ('self', 'w', 'Check: is this paper waiting for this adviser?'),
            ('alt', [('Approve and forward', [
                        ('msg', 'w', 'd', 'Save the checklist (paper_checklist)'),
                        ('msg', 'w', 'd', 'Record “approved” (approval_workflow)'),
                        ('msg', 'w', 'd', 'Set the status to pending_admin'),
                        ('msg', 'w', 'd', 'Notice for the Coordinator, and email it'),
                        ('ret', 'w', 'a', '“Paper forwarded to the Research Coordinator.”')]),
                     ('Return with feedback', [
                        ('self', 'w', 'Check the feedback'),
                        ('msg', 'w', 'd', 'Record “declined” and the feedback'),
                        ('msg', 'w', 'd', 'Set the status to draft'),
                        ('msg', 'w', 'v', 'Delete the paper\'s files'),
                        ('msg', 'w', 'd', 'Notice for the student, and email it'),
                        ('ret', 'w', 'a', '“Paper returned to the student.”')])]),
        ]),
    seq_note='Step 5 is a safety check. A paper can leave this desk only if it belongs to this Adviser\'s student and is still waiting for this Adviser.',
    erd=dict(
        tables=[tbl('users', 70, 34, ['user_id', 'full_name', 'user_role', 'created_by'], w=170),
                tbl('research_papers', 280, 34, ['paper_id', 'title', 'uploaded_by', 'current_status'], w=176),
                tbl('approval_workflow', 496, 34, ['workflow_id', 'paper_id', 'reviewer_id', 'review_level',
                                                   'status', 'feedback', 'reviewed_at'], w=178),
                tbl('paper_checklist', 496, 250, ['checklist_id', 'paper_id', 'imrad_intro', 'imrad_references',
                                                  'full_ch1', 'full_references'], w=178),
                tbl('notifications', 280, 250, ['notification_id', 'user_id', 'paper_id', 'notification_type',
                                                'message'], w=176)],
        rels=[rel('users', 'users', 'made by', ca='N', cb='1', sa='l', sb='l', ra='created_by', rb='user_id',
                  lpos=(50, 118, 'end')),
              rel('users', 'research_papers', 'uploads', ra='user_id', rb='uploaded_by', lpos=(260, 76, 'middle')),
              rel('research_papers', 'approval_workflow', 'has', ra='paper_id', rb='paper_id', lpos=(476, 76, 'middle')),
              rel('research_papers', 'paper_checklist', 'has', ca='1', cb='1', ra='paper_id', rb='paper_id',
                  lpos=(476, 240, 'middle')),
              rel('users', 'approval_workflow', 'reviews', sa='t', sb='t'),
              rel('research_papers', 'notifications', 'is about', sa='b', sb='t'),
              rel('users', 'notifications', 'receives', sa='b', sb='l', rb='user_id')]),
    erd_note='The Adviser is found through created_by: the student\'s account points to the Adviser who made it. Each decision is a new row in approval_workflow. The checklist is one row per paper.',
)

# ----------------------------------------------------------- 7 Coordinator
chapter(
    key='ch07', num=7, part='C', title='Final Review and Publishing',
    short='The Research Coordinator makes the final decision. Approving a paper publishes it.',
    who=['Research Coordinator', 'Student', 'Head of Academic Programs and Director (read)'], outside=['Google Drive', 'Gmail'],
    body=[
        ('p', 'The Research Coordinator also works on a **Review Desk**. It has four tabs: **Waiting for you**, **With Advisers**, **Published** and **Returned**. The Coordinator sees papers from **all** programs.'),
        ('h', 'Steps: approve and publish'),
        ('steps', [
            'Open the paper in **Waiting for you**.',
            'Read the paper and the Adviser\'s checklist.',
            'Click **Approve and publish**.',
            'Confirm with **Approve and publish**. (The **Note for the student** box is not saved at this step.)',
            'The paper is now **APPROVED**. It shows in the Public Repository at once.',
            'PAPEL records how many days the paper took, from upload to approval.',
            'The student gets a notice: the paper is approved and public.',
        ]),
        ('h', 'Steps: return a paper'),
        ('p', 'This works the same way as for the Adviser (Workflow 6). Click **Return with feedback**, write **What needs to change**, then click **Return to student**. The files are deleted from Google Drive. The student gets a notice. When the student sends it again, it starts again at the **Research Adviser**.'),
        ('h', 'After publishing: who reads the paper'),
        ('table', ['Who', 'What they can do with published papers'], [
            ['Head of Academic Programs', 'Read all published papers. See the **Analytics** reports. Cannot approve or return.'],
            ['Director', 'Read all published papers. Archive a paper (Workflow 12).'],
            ['Research Adviser', 'See their own students\' published papers.'],
            ['Everyone', 'Find the paper in the Public Repository (Workflow 9).'],
        ]),
        ('remember', [
            '**Approving is the last step.** Nobody approves after the Research Coordinator.',
            'The Coordinator can act only on papers the Adviser has already approved.',
            'Feedback is required to return a paper.',
        ]),
        ('words', [('Publish', 'Make the paper public, so everyone can find it.'),
                   ('Analytics', 'Reports with numbers and charts about the papers.')]),
    ],
    uc=dict(
        cases={'review': 'Review a forwarded paper', 'pub': 'Approve and publish', 'days': 'Record the days to approval',
               'notify': 'Notify the student', 'ret': 'Return with feedback', 'purge': 'Delete the files from Google Drive',
               'read': 'Read published papers', 'reports': 'View the analytics reports'},
        grid=[('review', None), ('pub', 'days'), (None, 'notify'), ('ret', 'purge'), (None, 'read'), (None, 'reports')],
        left=[('coord', 'Research Coordinator', 'person')],
        right=[('stu', 'Student', 'person'), ('drive', 'Google Drive', 'system'),
               ('hap', 'Head of Academic Programs', 'person'), ('dir', 'Director', 'person')],
        links=[('coord', 'review'), ('coord', 'pub'), ('coord', 'ret'), ('coord', 'read'), ('coord', 'reports'),
               ('stu', 'notify'), ('drive', 'purge'), ('hap', 'read'), ('hap', 'reports'), ('dir', 'read'),
               ('dir', 'reports')],
        rels=[('pub', 'days', 'include'), ('pub', 'notify', 'include'), ('ret', 'notify', 'include'),
              ('ret', 'purge', 'include')]),
    uc_note='Only the Coordinator approves and publishes. The Head of Academic Programs and the Director read the results. They have no approve or return button.',
    seq=dict(
        parts=[('c', 'Research Coordinator', 'person'), ('w', 'PAPEL', 'system'), ('d', 'Database', 'db'),
               ('v', 'Google Drive', 'ext')],
        steps=[
            ('msg', 'c', 'w', 'Open the Review Desk: Waiting for you'),
            ('msg', 'w', 'd', 'Find papers with the status pending_admin'),
            ('ret', 'w', 'c', 'Show the list'),
            ('msg', 'c', 'w', 'Choose Approve and publish, or Return with feedback'),
            ('self', 'w', 'Check: did the Adviser approve this paper?'),
            ('alt', [('Approve and publish', [
                        ('msg', 'w', 'd', 'Record “approved” (approval_workflow)'),
                        ('msg', 'w', 'd', 'Set the status to approved'),
                        ('msg', 'w', 'd', 'Save the days from upload to approval (analytics)'),
                        ('msg', 'w', 'd', 'Notice for the student, and email it'),
                        ('ret', 'w', 'c', '“Paper approved and published.”'),
                        ('note', ('w', 'd'), 'The paper now shows in the Public Repository.')]),
                     ('Return with feedback', [
                        ('msg', 'w', 'd', 'Record “declined” and the feedback'),
                        ('msg', 'w', 'd', 'Set the status to draft'),
                        ('msg', 'w', 'v', 'Delete the paper\'s files'),
                        ('msg', 'w', 'd', 'Notice for the student, and email it'),
                        ('ret', 'w', 'c', '“Paper returned to the student.”')])]),
        ]),
    seq_note='Publishing is only a change of status. The Public Repository shows every paper with the status "approved", so the paper appears there at once.',
    erd=dict(
        tables=[tbl('users', 70, 34, ['user_id', 'full_name', 'user_role', 'admin_level'], w=170),
                tbl('research_papers', 280, 34, ['paper_id', 'title', 'uploaded_by', 'current_status',
                                                 'upload_date'], w=176),
                tbl('approval_workflow', 496, 34, ['workflow_id', 'paper_id', 'reviewer_id', 'review_level',
                                                   'status', 'feedback', 'reviewed_at'], w=178),
                tbl('analytics', 496, 250, ['analytics_id', 'paper_id', 'time_to_approval', 'approval_date'], w=178),
                tbl('notifications', 280, 268, ['notification_id', 'user_id', 'paper_id', 'notification_type',
                                                'message'], w=176)],
        rels=[rel('users', 'research_papers', 'uploads', ra='user_id', rb='uploaded_by', lpos=(260, 76, 'middle')),
              rel('research_papers', 'approval_workflow', 'has', ra='paper_id', rb='paper_id', lpos=(476, 76, 'middle')),
              rel('research_papers', 'analytics', 'has', ra='paper_id', rb='paper_id', lpos=(476, 240, 'middle')),
              rel('users', 'approval_workflow', 'reviews', sa='t', sb='t'),
              rel('research_papers', 'notifications', 'is about', sa='b', sb='t'),
              rel('users', 'notifications', 'receives', sa='b', sb='l', rb='user_id')]),
    erd_note='Publishing sets current_status to "approved". The Coordinator\'s decision is one more row in approval_workflow, with review_level "admin". time_to_approval is the number of days from upload to approval.',
)

# ----------------------------------------------------------- 8 Returned
chapter(
    key='ch08', num=8, part='C', title='Fixing a Returned Paper',
    short='If a reviewer returns your paper, read the feedback, fix the paper and submit it again.',
    who=['Student', 'Research Adviser (gets it again)'], outside=['Google Drive'],
    body=[
        ('p', 'A returned paper shows in the **Needs Revision** tab of your dashboard. Its badge says **DECLINED**. The card shows **Revision feedback**: the reviewer\'s words.'),
        ('tip', '"Returned" does not mean "rejected forever". It means: please fix it and send it again.'),
        ('h', 'Steps'),
        ('steps', [
            'Open the **Needs Revision** tab.',
            'Read the **Revision feedback** carefully.',
            'Click **Edit and Re-submit**.',
            'Fix your paper, as the feedback says.',
            'Attach your PDF again. The old files were deleted from Google Drive when the paper was returned.',
            'Attach the supporting documents again, if your paper type needs them.',
            'Click **Submit Paper**.',
            'The paper goes to your **Research Adviser** again, even if the Research Coordinator was the one who returned it.',
        ]),
        ('remember', [
            'Old feedback stays on record. Reviewers can see the history.',
            'You can delete a returned paper if you do not want to continue.',
            'Nobody can see your changes until you submit again.',
        ]),
        ('words', [('Revision', 'A change to make your work better.'),
                   ('Re-submit', 'Send your paper again after fixing it.')]),
    ],
    uc=dict(
        cases={'see': 'See returned papers', 'read': 'Read the feedback', 'edit': 'Edit and re-submit',
               'pdf': 'Attach the PDF again', 'docs': 'Attach the documents again', 'del': 'Delete the returned paper',
               'notify': 'Notify the Research Adviser'},
        grid=[('see', 'read'), ('edit', 'pdf'), (None, 'docs'), ('del', 'notify')],
        left=[('stu', 'Student', 'person')],
        right=[('adv', 'Research Adviser', 'person'), ('drive', 'Google Drive', 'system')],
        links=[('stu', 'see'), ('stu', 'edit'), ('stu', 'del'), ('adv', 'notify'), ('drive', 'pdf')],
        rels=[('see', 'read', 'include'), ('edit', 'pdf', 'include'), ('docs', 'edit', 'extend'),
              ('edit', 'notify', 'include')]),
    uc_note='Fixing a returned paper always means attaching the PDF again. The documents are needed only for some paper types. Sending it again tells the Research Adviser.',
    seq=dict(
        parts=[('s', 'Student', 'person'), ('w', 'PAPEL', 'system'), ('d', 'Database', 'db'), ('v', 'Google Drive', 'ext')],
        steps=[
            ('msg', 's', 'w', 'Open Needs Revision'),
            ('msg', 'w', 'd', 'Find my papers that are drafts and have feedback'),
            ('ret', 'w', 's', 'Show the papers and the Revision feedback'),
            ('msg', 's', 'w', 'Click Edit and Re-submit'),
            ('msg', 'w', 'd', 'Load the paper'),
            ('ret', 'w', 's', 'Show the upload form, filled in'),
            ('msg', 's', 'w', 'Fix the paper. Attach the PDF again. Click Submit Paper.'),
            ('msg', 'w', 'v', 'Upload the new files'),
            ('ret', 'v', 'w', 'File IDs'),
            ('msg', 'w', 'd', 'Save the paper (status: pending_faculty)'),
            ('msg', 'w', 'd', 'Notice for the Research Adviser, and email it'),
            ('ret', 'w', 's', 'The paper is Under Review again'),
        ]),
    seq_note='A returned paper is a draft that has feedback. PAPEL shows it in Needs Revision, not in Drafts, because of that feedback.',
    erd=dict(
        tables=[tbl('research_papers', 40, 10, ['paper_id', 'title', 'uploaded_by', 'current_status', 'file_path',
                                                'gdrive_file_id'], w=220),
                tbl('approval_workflow', 440, 10, ['workflow_id', 'paper_id', 'reviewer_id', 'review_level', 'status',
                                                   'feedback', 'reviewed_at'], w=220),
                tbl('supporting_documents', 440, 226, ['doc_id', 'paper_id', 'document_type', 'gdrive_file_id'], w=220),
                tbl('notifications', 40, 226, ['notification_id', 'user_id', 'paper_id', 'notification_type',
                                               'message'], w=220)],
        rels=[rel('research_papers', 'approval_workflow', 'has decisions', ra='paper_id', rb='paper_id'),
              rel('research_papers', 'supporting_documents', 'has', ra='paper_id', rb='paper_id'),
              rel('research_papers', 'notifications', 'is about', sa='b', sb='t')],
        notes=[note(40, 400, 620, "When a paper is returned, PAPEL deletes its files from Google Drive and sets "
                                  "gdrive_file_id to empty (NULL) in research_papers and supporting_documents. "
                                  "The feedback stays in approval_workflow, in a row with status = 'declined'.")]),
    erd_note='The feedback lives in approval_workflow. The empty gdrive_file_id is why the PDF must be attached again.',
)

# ----------------------------------------------------------- 9 Repository
chapter(
    key='ch09', num=9, part='D', title='Finding and Reading Published Papers',
    short='Everyone can search the published papers. To open the details, sign in or use a guest pass.',
    who=['Visitors', 'Guests', 'Students', 'Staff'], outside=['Groq AI'],
    body=[
        ('p', 'The **Public Repository** lists every approved paper. Anyone can open it, even without signing in.'),
        ('table', ['Who', 'See the list', 'Open the details', 'Open the full PDF'], [
            ['Visitor (not signed in)', 'Yes', 'No: sign in first', 'No'],
            ['Guest (with a pass)', 'Yes', 'Yes', 'No'],
            ['Student', 'Yes', 'Yes', 'Only with a Librarian\'s permission (Workflow 11)'],
            ['Staff: Adviser, Coordinator, Head of Academic Programs, Director, Librarian', 'Yes', 'Yes', 'Yes'],
        ]),
        ('h', 'Steps'),
        ('steps', [
            'Open the **Public Repository**.',
            'Type in the search box. PAPEL suggests titles, authors and keywords as you type.',
            'Use the filters to make the list shorter, for example by paper type, program or year.',
            'Click a title to open the paper details. If you are not signed in, you see **Login to view details** instead.',
            'On the details page, read the abstract, the sections and the AI summary.',
        ]),
        ('p', 'The **AI summary** gives the methodology, sample size, statistical methods, variables and research field. If a paper has no summary yet, PAPEL makes one with Groq AI the first time someone opens it.'),
        ('remember', [
            'Only **approved** papers are in the Public Repository. Drafts and papers under review are never public.',
            'An archived paper leaves the public list (Workflow 12).',
            'The AI summary is made by a computer. Always check it against the paper.',
        ]),
        ('words', [('Repository', 'A place where things are kept so people can find them.'),
                   ('Filter', 'A choice that hides what you do not need.'),
                   ('Abstract', 'A short summary at the start of a paper.')]),
    ],
    uc=dict(
        cases={'browse': 'Browse and search papers', 'filter': 'Filter the list', 'details': 'Open the paper details',
               'signin': 'Sign in, or use a guest pass', 'summary': 'Make the AI summary', 'pdf': 'Open the full PDF',
               'request': 'Ask for PDF access'},
        grid=[('browse', 'filter'), ('details', 'signin'), (None, 'summary'), ('pdf', None), ('request', None)],
        left=[('vis', 'Visitor', 'person'), ('guest', 'Guest', 'person'), ('stu', 'Student', 'person'),
              ('staff', 'Staff member', 'person')],
        right=[('groq', 'Groq AI', 'system')],
        links=[('vis', 'browse'), ('vis', 'details'), ('guest', 'browse'), ('guest', 'details'), ('stu', 'browse'),
               ('stu', 'details'), ('stu', 'request'), ('staff', 'browse'), ('staff', 'details'), ('staff', 'pdf'),
               ('groq', 'summary')],
        rels=[('filter', 'browse', 'extend'), ('details', 'signin', 'include'), ('summary', 'details', 'extend'),
              ('request', 'pdf', 'extend')]),
    uc_note='Anyone can browse. Opening the details needs a sign-in or a guest pass. Staff can open the full PDF. Students can ask for it.',
    seq=dict(
        parts=[('r', 'Reader', 'person'), ('w', 'PAPEL', 'system'), ('d', 'Database', 'db'), ('g', 'Groq AI', 'ext')],
        steps=[
            ('msg', 'r', 'w', 'Open the Public Repository and search'),
            ('msg', 'w', 'd', 'Find approved papers that match'),
            ('ret', 'w', 'r', 'Show the list'),
            ('msg', 'r', 'w', 'Click a paper title'),
            ('alt', [('not signed in, no guest pass', [('ret', 'w', 'r', 'Show “Login to view details”')]),
                     ('signed in, or guest pass', [
                         ('msg', 'w', 'd', 'Load the paper (status approved)'),
                         ('opt', 'no AI summary yet', [
                             ('msg', 'w', 'g', 'Send the text of the paper'),
                             ('ret', 'g', 'w', 'Method, sample, variables, field'),
                             ('msg', 'w', 'd', 'Save the summary')]),
                         ('self', 'w', 'Decide who may open the PDF'),
                         ('ret', 'w', 'r', 'Show the details, with a PDF or Request button')])]),
        ]),
    seq_note='Staff see a button to open the PDF. Students see a button to ask for access. Guests see neither.',
    erd=dict(
        tables=[tbl('research_papers', 40, 10, ['paper_id', 'title', 'author_names', 'year', 'keywords', 'abstract',
                                                'paper_type', 'program_category', 'current_status', 'gdrive_file_id',
                                                'uploaded_by', 'ai_summary', 'ai_methodology', 'ai_research_field'],
                    w=236),
                tbl('users', 440, 10, ['user_id', 'full_name', 'program'], w=220),
                tbl('guest_sessions', 440, 170, ['guest_id', 'username', 'expires_at'], w=220)],
        rels=[rel('users', 'research_papers', 'uploads', sa='l', sb='r', ra='user_id', rb='uploaded_by')],
        notes=[note(40, 348, 620, "The public list shows only rows where current_status = 'approved'. To open the "
                                  "details, a reader needs an account (users) or a guest pass (guest_sessions). The "
                                  "AI columns (ai_summary, ai_methodology and the others) are filled the first time "
                                  "the paper is opened, if they are empty.")]),
    erd_note='The Public Repository reads research_papers, but only the approved rows.',
)

# ----------------------------------------------------------- 10 Guest passes
chapter(
    key='ch10', num=10, part='D', title='Guest Passes',
    short='A Librarian gives a visitor a pass that works for a few hours.',
    who=['Librarian', 'Guest'], outside=['Gmail'],
    body=[
        ('p', 'A guest is a visitor without a PAPEL account. A guest pass lets them read paper details for a short time. The Research Coordinator and the Director can also open this page.'),
        ('h', 'Steps: the Librarian makes a pass'),
        ('steps', [
            'Open **Guest Passes**.',
            'Type the guest\'s email address.',
            'Choose how long the pass works: **1, 2, 4, 8, 12 or 24 hours**.',
            'Create the pass.',
            'PAPEL checks that the email does not belong to a PAPEL account.',
            'PAPEL makes a username, like `guest_3fa9c21b`, and a 12-character password.',
            'PAPEL emails them to the guest. You can also see them on the screen.',
        ]),
        ('h', 'Steps: the guest signs in'),
        ('steps', [
            'Open the email from PAPEL.',
            'Go to PAPEL and click **Sign In**.',
            'Choose the **Guest** tab.',
            'Type the username and the password.',
            'Read the Public Repository.',
            'When the time ends, PAPEL signs you out.',
        ]),
        ('remember', [
            'A guest can read paper details. A guest **cannot** open the full PDF.',
            'A pass cannot be made for an email that already has a PAPEL account, even if it is written differently, for example with a dot or a "+" in a Gmail address. That person signs in with their own account.',
            'A pass cannot be made longer. To give more time, make a new pass.',
            'The Librarian can delete a pass early. Nobody can sign in with it after that.',
            'The password is easy to read out loud. It never uses the letters I, l, O or the number 0.',
            'Expired passes are listed apart. The Librarian can clear them.',
        ]),
        ('words', [('Guest', 'A visitor without a PAPEL account.'),
                   ('Pass', 'A username and password that work for a short time.')]),
    ],
    uc=dict(
        cases={'create': 'Create a guest pass', 'gen': 'Make a username and password', 'mail': 'Email the pass',
               'del': 'Delete a pass early', 'clear': 'Clear expired passes', 'signin': 'Sign in as a guest',
               'read': 'Read paper details', 'out': 'Be signed out when time ends'},
        grid=[('create', 'gen'), (None, 'mail'), ('del', None), ('clear', None), (None, 'signin'), (None, 'read'),
              (None, 'out')],
        left=[('lib', 'Librarian', 'person')],
        right=[('gmail', 'Gmail', 'system'), ('guest', 'Guest', 'person')],
        links=[('lib', 'create'), ('lib', 'del'), ('lib', 'clear'), ('gmail', 'mail'), ('guest', 'signin'),
               ('guest', 'read'), ('guest', 'out')],
        rels=[('create', 'gen', 'include'), ('create', 'mail', 'include')]),
    uc_note='The Librarian creates and removes passes. The guest signs in, reads, and is signed out when the time is up.',
    seq=dict(
        parts=[('l', 'Librarian', 'person'), ('w', 'PAPEL', 'system'), ('d', 'Database', 'db'), ('g', 'Gmail', 'ext'),
               ('u', 'Guest', 'person')],
        steps=[
            ('msg', 'l', 'w', 'Type the guest\'s email. Choose the hours.'),
            ('msg', 'w', 'd', 'Does this email belong to an account? (users)'),
            ('alt', [('it does', [('ret', 'w', 'l', '“That email already belongs to a PAPEL account.”')]),
                     ('it does not', [('self', 'w', 'Make a username and a 12-character password')])]),
            ('msg', 'w', 'd', 'Save the pass and its end time (guest_sessions)'),
            ('msg', 'w', 'g', 'Send the username, password and end time'),
            ('msg', 'g', 'u', 'Email with the pass'),
            ('msg', 'u', 'w', 'Sign in on the Guest tab'),
            ('msg', 'w', 'd', 'Find a pass that has not expired'),
            ('ret', 'd', 'w', 'Pass found'),
            ('ret', 'w', 'u', 'Open the Public Repository'),
            ('note', ('w', 'u'), 'When the end time passes, PAPEL signs the guest out.'),
        ]),
    seq_note='A guest is not a user account. PAPEL keeps the pass in its own table and checks its end time at every page.',
    erd=dict(
        tables=[tbl('guest_sessions', 40, 10, ['guest_id', 'username', 'password', 'plain_password', 'created_at',
                                               'expires_at'], w=250),
                tbl('login_attempts', 410, 10, ['scope', 'attempts', 'first_at', 'last_at', 'locked_until'], w=250)],
        rels=[],
        notes=[note(40, 186, 620, 'guest_sessions is not linked to users: a guest is not an account. password is '
                                  'stored scrambled (hashed), and sign-in checks it. plain_password is kept on purpose, '
                                  'so the Librarian can read the pass out again. A pass stops working when expires_at '
                                  'has passed. No scheduled task is needed for that.'),
               note(40, 280, 620, 'login_attempts counts wrong sign-in tries for guest usernames too. scope holds the '
                                  'ID that was typed and the address of the computer.')]),
    erd_note='Guest passes live in their own table. They are not linked to the users table.',
)

# ----------------------------------------------------------- 11 Manuscripts
chapter(
    key='ch11', num=11, part='D', title='Asking to Open a Full Manuscript',
    short='A student asks a Librarian for permission to open a paper\'s PDF. The Librarian allows it for a few hours, or says no.',
    who=['Student', 'Librarian'], outside=[],
    body=[
        ('p', 'Students can read the details of every published paper. The full PDF is for staff only. A student who needs the PDF can ask for it.'),
        ('h', 'Steps: the student asks'),
        ('steps', [
            'Open the paper details.',
            'Go to the manuscript part of the page.',
            'Click **Request manuscript access**.',
            'A message says **Request sent. A librarian will review it shortly.**',
            'Wait for a notice.',
        ]),
        ('h', 'Steps: the Librarian answers'),
        ('steps', [
            'Open **Manuscript Requests**.',
            'Look at the waiting requests.',
            'To allow it: choose how long, from **1 to 24 hours**, and click **Grant**.',
            'To refuse it: click **Deny**.',
            'The student gets a notice either way.',
        ]),
        ('remember', [
            'Access ends by itself when the time is up.',
            'A student can have **up to 3** manuscripts open at the same time.',
            'A student cannot ask again for the same paper while a request is waiting, or while access is still open.',
            'If a request was denied, or the access has ended, the student can ask again.',
            'Deleting a granted request ends the access at once.',
            'Every Librarian gets a notice when a student asks.',
        ]),
        ('words', [('Manuscript', 'The full text of the paper, as a PDF file.'),
                   ('Grant', 'Give permission.'), ('Deny', 'Say no.')]),
    ],
    uc=dict(
        cases={'ask': 'Ask for manuscript access', 'notl': 'Notify the Librarians', 'open': 'Open the PDF while access lasts',
               'grant': 'Grant access for 1 to 24 hours', 'nots': 'Notify the student', 'deny': 'Deny the request',
               'del': 'Delete a request'},
        grid=[('ask', 'notl'), ('open', 'grant'), (None, 'nots'), (None, 'deny'), (None, 'del')],
        left=[('stu', 'Student', 'person')],
        right=[('lib', 'Librarian', 'person')],
        links=[('stu', 'ask'), ('stu', 'open'), ('lib', 'notl'), ('lib', 'grant'), ('lib', 'deny'), ('lib', 'del')],
        rels=[('ask', 'notl', 'include'), ('grant', 'nots', 'include'), ('deny', 'nots', 'include')]),
    uc_note='The student asks. Every Librarian is told. A Librarian grants or denies, and the student is told the answer.',
    seq=dict(
        parts=[('s', 'Student', 'person'), ('w', 'PAPEL', 'system'), ('d', 'Database', 'db'), ('l', 'Librarian', 'person')],
        steps=[
            ('msg', 's', 'w', 'Click Request manuscript access'),
            ('msg', 'w', 'd', 'Is a request waiting, or is access open?'),
            ('ret', 'd', 'w', 'No'),
            ('msg', 'w', 'd', 'Save the request (status: pending)'),
            ('msg', 'w', 'l', 'Notice to every Librarian'),
            ('ret', 'w', 's', '“Request sent.”'),
            ('msg', 'l', 'w', 'Open Manuscript Requests'),
            ('alt', [('Grant', [('msg', 'l', 'w', 'Choose 1 to 24 hours. Click Grant.'),
                                ('self', 'w', 'Check: fewer than 3 open for this student?'),
                                ('msg', 'w', 'd', 'Set the status to granted. Save the end time.'),
                                ('msg', 'w', 's', 'Notice: access granted until the end time')]),
                     ('Deny', [('msg', 'l', 'w', 'Click Deny'),
                               ('msg', 'w', 'd', 'Set the status to denied'),
                               ('msg', 'w', 's', 'Notice: the request was denied')])]),
            ('note', ('s', 'w'), 'While access lasts, the student can open the PDF. After the end time, it closes by itself.'),
        ]),
    seq_note='Nothing needs to run when the time is up. PAPEL simply stops counting a grant after its end time.',
    erd=dict(
        tables=[tbl('users', 6, 10, ['user_id', 'full_name', 'user_role'], w=176),
                tbl('manuscript_requests', 224, 10, ['request_id', 'paper_id', 'student_user_id', 'status',
                                                     'duration_hours', 'granted_by', 'granted_at', 'expires_at',
                                                     'denied_by', 'denied_at', 'created_at'], w=222),
                tbl('research_papers', 488, 10, ['paper_id', 'title', 'gdrive_file_id', 'current_status'], w=186),
                tbl('notifications', 488, 190, ['notification_id', 'user_id', 'paper_id', 'notification_type',
                                                'message'], w=186)],
        rels=[rel('users', 'manuscript_requests', 'asks', ra='user_id', rb='student_user_id', lpos=(203, 52, 'middle')),
              rel('users', 'manuscript_requests', 'grants', ra='user_id', rb='granted_by', lpos=(198, 150, 'end')),
              rel('users', 'manuscript_requests', 'denies', ra='user_id', rb='denied_by', lpos=(198, 204, 'end')),
              rel('research_papers', 'manuscript_requests', 'about', sa='l', sb='r', ra='paper_id', rb='paper_id',
                  lpos=(467, 52, 'middle')),
              rel('users', 'notifications', 'receives', sa='b', sb='b')]),
    erd_note='One request links three things: the student who asked, the paper, and the Librarian who answered. expires_at is the end of the access.',
)

# ----------------------------------------------------------- 12 Archive
chapter(
    key='ch12', num=12, part='E', title='Archiving a Paper',
    short='The Director can move a published paper out of the Public Repository and into the archive.',
    who=['Director', 'The automatic 5-year task'], outside=[],
    body=[
        ('p', 'Archiving is a records decision. It is not part of the review. The paper is **not deleted**. PAPEL keeps it safely in the archive record.'),
        ('h', 'Steps: the Director archives a paper'),
        ('steps', [
            'Open **My Dashboard**. It lists all published papers.',
            'Find the paper.',
            'Click **Archive**.',
            'A box asks **Archive this paper?** Click **Archive it**. (Click **Leave it published** to stop.)',
            'PAPEL copies the whole paper into the archive.',
            'PAPEL removes the paper from the list of active papers.',
            'The paper no longer shows in the Public Repository.',
        ]),
        ('h', 'The 5-year rule'),
        ('p', 'PAPEL has a task that archives a published paper by itself when it is more than 5 years old (1,825 days after upload). **This task runs only if the system team schedules it to run every day.**'),
        ('remember', [
            'Only published (approved) papers can be archived.',
            'The archive keeps every part of the paper: the text, the sections, the file link and the details.',
            'The paper\'s files stay in Google Drive.',
            'Archived papers still appear in the reports, marked **Archived**.',
            'There is **no Restore button** on the screen. To bring a paper back, ask the system team. PAPEL has a restore function that returns the paper with the status it had.',
        ]),
        ('words', [('Archive', 'A safe place for old records. They are kept, but not shown.'),
                   ('Restore', 'Bring something back to where it was.')]),
    ],
    uc=dict(
        cases={'list': 'See all published papers', 'arch': 'Archive a paper', 'confirm': 'Confirm the archive',
               'copy': 'Copy the paper to the archive', 'remove': 'Remove it from the public list',
               'auto': 'Archive papers older than 5 years', 'restore': 'Restore a paper'},
        grid=[('list', None), ('arch', 'confirm'), (None, 'copy'), (None, 'remove'), (None, 'auto'), (None, 'restore')],
        left=[('dir', 'Director', 'person')],
        right=[('task', 'Daily task', 'system'), ('team', 'System team', 'person')],
        links=[('dir', 'list'), ('dir', 'arch'), ('task', 'auto'), ('team', 'restore')],
        rels=[('arch', 'confirm', 'include'), ('arch', 'copy', 'include'), ('arch', 'remove', 'include'),
              ('auto', 'copy', 'include')]),
    uc_note='The Director archives one paper at a time. A daily task can archive old papers, if the system team sets it up. Only the system team can restore.',
    seq=dict(
        parts=[('r', 'Director', 'person'), ('w', 'PAPEL', 'system'), ('d', 'Database', 'db'), ('t', 'Daily task', 'ext')],
        steps=[
            ('msg', 'r', 'w', 'Click Archive on a published paper'),
            ('ret', 'w', 'r', 'Ask: “Archive this paper?”'),
            ('msg', 'r', 'w', 'Click Archive it'),
            ('msg', 'w', 'd', 'Start a safe change: all or nothing'),
            ('msg', 'w', 'd', 'Copy the whole paper to papers_archive, with the date and the Director'),
            ('msg', 'w', 'd', 'Delete it from research_papers'),
            ('msg', 'w', 'd', 'Finish the safe change'),
            ('ret', 'w', 'r', '“Paper archived. It is no longer in the public repository.”'),
            ('opt', 'every day, if the system team scheduled it', [
                ('msg', 't', 'w', 'Run the 5-year check'),
                ('msg', 'w', 'd', 'Find approved papers older than 1,825 days'),
                ('loop', 'for each paper found', [
                    ('msg', 'w', 'd', 'Copy it to the archive, then delete it from the active list')])]),
        ]),
    seq_note='Copying and deleting happen together, as one safe step. If one part fails, neither happens. So a paper can never be lost halfway.',
    erd=dict(
        tables=[tbl('research_papers', 6, 34, ['paper_id', 'title', 'uploaded_by', 'current_status', 'gdrive_file_id'],
                    w=180),
                tbl('papers_archive', 250, 34, ['paper_id', 'title', 'uploaded_by', 'current_status', 'gdrive_file_id',
                                                'archived_date', 'archived_by'], w=180),
                tbl('users', 494, 34, ['user_id', 'full_name', 'user_role'], w=180)],
        rels=[rel('research_papers', 'papers_archive', 'moves to', ca='1', cb='1', ra='paper_id', rb='paper_id',
                  lpos=(218, 80, 'middle')),
              rel('users', 'papers_archive', 'archives', sa='l', sb='r', ra='user_id', rb='archived_by',
                  lpos=(462, 80, 'middle')),
              rel('users', 'research_papers', 'uploads', sa='t', sb='t')],
        notes=[note(6, 262, 668, 'papers_archive has the same columns as research_papers, plus archived_date and '
                                 'archived_by. Archiving copies the row, then deletes it from research_papers, as one '
                                 'safe step (a transaction). Rows in approval_workflow, paper_checklist and '
                                 'supporting_documents stay behind, as history. archived_by = 0 means the automatic '
                                 '5-year task did it.')]),
    erd_note='A paper is in research_papers or in papers_archive, never in both. The paper keeps the same paper_id when it moves.',
)

# ----------------------------------------------------------- 13 Notifications
chapter(
    key='ch13', num=13, part='E', title='Notifications and Emails',
    short='PAPEL tells people when something happens. Each notice appears in the bell and is also sent by email.',
    who=['Everyone with an account'], outside=['Gmail'],
    body=[
        ('p', 'The bell at the top of the page shows your new notices. **Notifications** opens the full list.'),
        ('table', ['When this happens', 'Who gets a notice'], [
            ['A student submits a paper', 'Their Research Adviser'],
            ['The Adviser approves it', 'The Research Coordinator'],
            ['The Coordinator approves and publishes it', 'The student'],
            ['A reviewer returns a paper', 'The student, with the feedback'],
            ['A student cancels a submission', 'The reviewers of that paper'],
            ['A student asks for a manuscript', 'Every Librarian'],
            ['A Librarian grants or denies it', 'The student'],
            ['An account, password or support event', 'The person it is about'],
        ]),
        ('p', 'PAPEL also sends each notice to the person\'s email, through Gmail. If the email fails, the notice in the bell is still there.'),
        ('h', 'Steps'),
        ('steps', [
            'Click the bell at the top of the page.',
            'Read your newest notices.',
            'To see all of them, open **Notifications**.',
            'Mark all as read, or delete the ones you do not need.',
        ]),
        ('h', 'Reminders'),
        ('p', 'PAPEL can remind reviewers about papers that wait for them, at **10:00** and **15:30**. This works only if the system team schedules the reminder task, and only for people who have a reminder time set.'),
        ('remember', [
            'A failed email never stops the work. The notice is saved first.',
            'Check your email spam folder if you do not see PAPEL emails.',
        ]),
        ('words', [('Notice', 'A short message from PAPEL about something that happened.'),
                   ('Reminder', 'A message that tells you something is still waiting.')]),
    ],
    uc=dict(
        cases={'see': 'See new notices in the bell', 'create': 'Save a notice', 'list': 'Open Notifications',
               'send': 'Send the email', 'read': 'Mark notices as read, or delete them', 'remind': 'Send a reminder'},
        grid=[('see', 'create'), ('list', 'send'), ('read', 'remind')],
        left=[('stu', 'Student', 'person'), ('rev', 'Reviewer', 'person'), ('lib', 'Librarian', 'person')],
        right=[('gmail', 'Gmail', 'system'), ('task', 'Reminder task', 'system')],
        links=[('stu', 'see'), ('stu', 'list'), ('stu', 'read'), ('rev', 'see'), ('rev', 'list'), ('rev', 'read'),
               ('lib', 'see'), ('lib', 'list'), ('lib', 'read'), ('gmail', 'send'), ('task', 'remind')],
        rels=[('create', 'send', 'include'), ('remind', 'create', 'include')]),
    uc_note='"Save a notice" is used by many other workflows, for example when a paper is approved. Every saved notice is also sent by email.',
    seq=dict(
        parts=[('a', 'Someone acts', 'person'), ('w', 'PAPEL', 'system'), ('d', 'Database', 'db'), ('g', 'Gmail', 'ext'),
               ('r', 'Receiver', 'person')],
        steps=[
            ('msg', 'a', 'w', 'Take an action, for example approve a paper'),
            ('msg', 'w', 'd', 'Save the notice (notifications)'),
            ('msg', 'w', 'd', 'Find the receiver\'s email'),
            ('ret', 'd', 'w', 'Email address'),
            ('msg', 'w', 'g', 'Send the email'),
            ('opt', 'the email fails', [('self', 'w', 'Write the error in the log. The notice stays saved.')]),
            ('msg', 'r', 'w', 'Click the bell'),
            ('msg', 'w', 'd', 'Load my notices'),
            ('ret', 'w', 'r', 'Show the notices'),
            ('msg', 'r', 'w', 'Mark all as read'),
            ('msg', 'w', 'd', 'Set is_read to 1'),
        ]),
    seq_note='The notice is saved before the email is sent. So a problem with email never loses a notice.',
    erd=dict(
        tables=[tbl('users', 6, 10, ['user_id', 'full_name', 'email'], w=180),
                tbl('notifications', 250, 10, ['notification_id', 'user_id', 'paper_id', 'notification_type', 'message',
                                               'is_read', 'created_at']),
                tbl('research_papers', 494, 10, ['paper_id', 'title'], w=180),
                tbl('notification_schedule', 6, 180, ['schedule_id', 'user_id', 'scheduled_time', 'last_sent',
                                                      'is_active'], w=180)],
        rels=[rel('users', 'notifications', 'receives', ra='user_id', rb='user_id', lpos=(218, 52, 'middle')),
              rel('research_papers', 'notifications', 'is about', sa='l', sb='r', ra='paper_id', rb='paper_id',
                  lpos=(470, 52, 'middle')),
              rel('users', 'notification_schedule', 'has reminder times', sa='b', sb='t')],
        notes=[note(250, 204, 424, 'notification_type is one of: submission, approval, decline, reminder, comment, '
                                   'security, support, account, manuscript. is_read becomes 1 when the person marks '
                                   'it as read. The email copy is not stored.')]),
    erd_note='Each notice belongs to one user and may be about one paper. Reminder times are in notification_schedule.',
)

# ----------------------------------------------------------- 14 Support
chapter(
    key='ch14', num=14, part='E', title='Getting Help with an Account',
    short='If you forgot your password or your account is wrong, send a request. It goes to the person who made your account.',
    who=['Anyone', 'Research Adviser, Research Coordinator or Director (handle requests)'], outside=['Gmail'],
    body=[
        ('p', 'Open **Contact Support**. Choose a topic.'),
        ('table', ['Topic', 'What happens'], [
            ['**Forgotten Password**', 'A request goes to the person who made your account.'],
            ['**Account Issue**', 'For example, a wrong name or email. A request goes to the person who made your account.'],
            ['Other topics', 'The message goes to the PAPEL support mailbox.'],
        ]),
        ('table', ['If you are a...', 'Your request goes to...'], [
            ['Student', 'Your Research Adviser'],
            ['Research Adviser or Librarian', 'The Research Coordinator or the Director'],
            ['Research Coordinator or Head of Academic Programs', 'The Director'],
        ]),
        ('h', 'Steps: ask for help'),
        ('steps', [
            'Open **Contact Support**.',
            'Type your name, your email and your ID.',
            'Choose **Forgotten Password** or **Account Issue**.',
            'Choose who should handle it, if PAPEL asks.',
            'Write your message and send it.',
            'PAPEL tells you who got your request.',
        ]),
        ('h', 'Steps: handle a request'),
        ('steps', [
            'Open **Support Requests**. It lists requests from the people whose accounts you made.',
            'Open the account on your own page: **My Students**, **Manage Faculty** or **Manage Admins**.',
            'Reset the password, or fix the details.',
            'PAPEL clears the request by itself and emails the person.',
            'If you solved it in another way, for example in person, click **Done**.',
        ]),
        ('remember', [
            'The Director sees **all** requests. The Director can help when a request goes to the wrong person.',
            'Librarians do not handle support requests.',
            'A new password shows on the handler\'s screen one time. Change it after you sign in.',
            'PAPEL records every password change.',
        ]),
        ('words', [('Handler', 'The person who deals with your request.'),
                   ('Reset', 'Set a new password in place of the old one.')]),
    ],
    uc=dict(
        cases={'send': 'Send a support request', 'route': 'Send it to the account maker', 'see': 'See waiting requests',
               'notify': 'Email the person', 'reset': 'Reset a password', 'fix': 'Fix account details',
               'done': 'Mark a request as done'},
        grid=[('send', 'route'), (None, 'see'), ('notify', 'reset'), (None, 'fix'), (None, 'done')],
        left=[('p', 'Person who needs help', 'person')],
        right=[('adv', 'Research Adviser', 'person'), ('coord', 'Research Coordinator', 'person'),
               ('dir', 'Director', 'person')],
        links=[('p', 'send'), ('p', 'notify'), ('adv', 'see'), ('adv', 'reset'), ('adv', 'fix'), ('adv', 'done'),
               ('coord', 'see'), ('coord', 'reset'), ('coord', 'fix'), ('coord', 'done'), ('dir', 'see'),
               ('dir', 'reset'), ('dir', 'fix'), ('dir', 'done')],
        rels=[('send', 'route', 'include'), ('reset', 'notify', 'include'), ('fix', 'notify', 'include')]),
    uc_note='A request always goes to the person who made the account. When they fix it, PAPEL emails the person who asked.',
    seq=dict(
        parts=[('p', 'Person who needs help', 'person'), ('w', 'PAPEL', 'system'), ('d', 'Database', 'db'),
               ('h', 'Handler', 'person')],
        steps=[
            ('msg', 'p', 'w', 'Send the form: name, email, ID, topic, message'),
            ('self', 'w', 'Find who made this account'),
            ('msg', 'w', 'd', 'Save the request (support_requests)'),
            ('msg', 'w', 'h', 'Email: a request is waiting for you'),
            ('ret', 'w', 'p', '“Your request has been sent to ...”'),
            ('msg', 'h', 'w', 'Open Support Requests'),
            ('msg', 'w', 'd', 'Load the requests for my accounts'),
            ('msg', 'h', 'w', 'Reset the password, or fix the details'),
            ('msg', 'w', 'd', 'Save the change. Record the password change.'),
            ('msg', 'w', 'd', 'Clear the request'),
            ('msg', 'w', 'p', 'Email: your account was updated'),
        ]),
    seq_note='The request is cleared by the fix itself. The handler does not need to remember to close it.',
    erd=dict(
        tables=[tbl('users', 6, 34, ['user_id', 'full_name', 'email', 'user_role', 'created_by'], w=180),
                tbl('support_requests', 250, 34, ['request_id', 'kind', 'requester_name', 'requester_email',
                                                  'requester_role', 'requester_ident', 'requester_user_id',
                                                  'handler_role', 'handler_user_id', 'message', 'created_at']),
                tbl('password_changes', 494, 34, ['change_id', 'user_id', 'changed_by', 'changed_at'], w=180)],
        rels=[rel('users', 'support_requests', 'asks', ra='user_id', rb='requester_user_id', lpos=(218, 80, 'middle')),
              rel('users', 'support_requests', 'handles', ra='user_id', rb='handler_user_id', lpos=(212, 232, 'end')),
              rel('users', 'password_changes', 'has', sa='t', sb='t')],
        notes=[note(6, 310, 668, 'A request is deleted when it is settled: by a new password, by saved details, or '
                                 'by Done. If the person could not sign in, requester_user_id may be empty; the name, '
                                 'email and ID they typed are kept instead.')]),
    erd_note='A request points to two users: the person who asked and the person who handles it.',
)


# =========================================================== appendices
APPENDIX_ERD_1 = dict(
    tables=[tbl('users', 6, 34, ['user_id', 'full_name', 'user_role', 'admin_level', 'student_id', 'faculty_id',
                                 'created_by'], w=180),
            tbl('research_papers', 250, 34, ['paper_id', 'title', 'author_names', 'paper_type', 'current_status',
                                             'gdrive_file_id', 'uploaded_by', 'upload_date'], w=180),
            tbl('approval_workflow', 494, 34, ['workflow_id', 'paper_id', 'reviewer_id', 'review_level', 'status',
                                               'feedback'], w=180),
            tbl('supporting_documents', 494, 240, ['doc_id', 'paper_id', 'document_type', 'gdrive_file_id'], w=180),
            tbl('paper_checklist', 494, 404, ['checklist_id', 'paper_id', 'imrad_intro', 'full_ch1'], w=180),
            tbl('imrad_checklist', 494, 568, ['checklist_id', 'paper_id', 'checked_by'], w=180),
            tbl('analytics', 250, 290, ['analytics_id', 'paper_id', 'time_to_approval'], w=180),
            tbl('papers_archive', 250, 440, ['paper_id', 'title', 'uploaded_by', 'archived_date', 'archived_by'], w=180),
            tbl('notifications', 6, 290, ['notification_id', 'user_id', 'paper_id', 'notification_type', 'is_read'],
                w=180)],
    rels=[rel('users', 'research_papers', 'uploads', ra='user_id', rb='uploaded_by', lpos=(218, 80, 'middle')),
          rel('users', 'approval_workflow', 'reviews', sa='t', sb='t'),
          rel('research_papers', 'approval_workflow', 'has', ra='paper_id', rb='paper_id', lpos=(462, 80, 'middle')),
          rel('research_papers', 'supporting_documents', '', ra='paper_id', rb='paper_id'),
          rel('research_papers', 'paper_checklist', '', ca='1', cb='1', ra='paper_id', rb='paper_id'),
          rel('research_papers', 'imrad_checklist', '', ra='paper_id', rb='paper_id'),
          rel('research_papers', 'analytics', 'has', sa='b', sb='t'),
          rel('research_papers', 'papers_archive', 'moves to', ca='1', cb='1', sa='l', sb='l', ra='title',
              rb='paper_id', via=[(228, 105), (228, 493)], lpos=(222, 470, 'end')),
          rel('users', 'notifications', 'receives', sa='b', sb='t')])

APPENDIX_ERD_2 = dict(
    tables=[tbl('users', 250, 200, ['user_id', 'full_name', 'user_role', 'created_by'], w=180),
            tbl('manuscript_requests', 6, 10, ['request_id', 'paper_id', 'student_user_id', 'status', 'granted_by',
                                               'expires_at'], w=180),
            tbl('support_requests', 6, 210, ['request_id', 'kind', 'requester_user_id', 'handler_user_id'], w=180),
            tbl('password_changes', 6, 372, ['change_id', 'user_id', 'changed_by', 'changed_at'], w=180),
            tbl('notification_schedule', 494, 10, ['schedule_id', 'user_id', 'scheduled_time'], w=180),
            tbl('ai_rate_limits', 494, 150, ['id', 'user_id', 'action', 'created_at'], w=180),
            tbl('ai_processing_log', 494, 290, ['log_id', 'paper_id', 'user_id', 'operation_type'], w=180),
            tbl('paper_favorites', 494, 450, ['favorite_id', 'user_id', 'paper_id', 'created_at'], w=180),
            tbl('guest_sessions', 6, 640, ['guest_id', 'username', 'expires_at'], w=180),
            tbl('login_attempts', 250, 640, ['scope', 'attempts', 'locked_until'], w=180),
            tbl('system_settings', 494, 640, ['setting_key', 'setting_value', 'updated_by'], w=180)],
    rels=[rel('users', 'manuscript_requests', sa='l', sb='r', ra='user_id', rb='student_user_id'),
          rel('users', 'support_requests', sa='l', sb='r', ra='user_id', rb='requester_user_id'),
          rel('users', 'password_changes', sa='l', sb='r', ra='user_id', rb='user_id'),
          rel('users', 'notification_schedule', ra='user_id', rb='user_id'),
          rel('users', 'ai_rate_limits', ra='user_id', rb='user_id'),
          rel('users', 'ai_processing_log', ra='user_id', rb='user_id'),
          rel('users', 'paper_favorites', ra='user_id', rb='user_id')],
    notes=[note(6, 590, 668, 'The three tables below are not linked to other tables by ID. gdrive_settings and '
                             'storage_usage are not drawn: nothing uses them.')])

FAQ = [
    ('Where is my paper now?',
     'Open your dashboard. The tab tells you. **Under Review** means a reviewer has it. The progress tracker on the card shows who: the Research Adviser or the Research Coordinator.'),
    ('Why was my paper returned?',
     'Open **Needs Revision**. The card shows the **Revision feedback**. It says what to change.'),
    ('Why must I attach my PDF again after a return?',
     'When a paper is returned, PAPEL deletes its files from Google Drive to save space. So the new version needs a new upload.'),
    ('I saved my work, but my PDF is gone. Why?',
     'You probably have only the automatic copy. It keeps your typing, but not the PDF. Use **Save as Draft** to keep the PDF too.'),
    ('Can I change my paper after I submit it?',
     'Only by cancelling it, and only at the allowed times (Workflow 5). Otherwise, wait for the review. If it is returned, you can change it then.'),
    ('I cannot sign in. What can I do?',
     'Check that you chose the right tab. Wait 15 minutes if you tried too many times. If your student account expired, ask your Research Adviser. If you forgot your password, use **Contact Support** (Workflow 14).'),
    ('Who approves my paper?',
     'Two people: first your Research Adviser, then the Research Coordinator. After the Coordinator approves it, it is published.'),
    ('Does the Director or the Head of Academic Programs approve papers?',
     'No. They read the published papers. The approval ends at the Research Coordinator.'),
    ('Why can I not open the full PDF of a published paper?',
     'The full PDF is for staff. Students can ask a Librarian for access for up to 24 hours (Workflow 11).'),
    ('Is my draft public?',
     'No. Only you can see a draft. Nobody reviews it until you submit it.'),
    ('How long does a guest pass last?',
     'From 1 to 24 hours. The Librarian chooses when making it.'),
    ('Is an archived paper deleted?',
     'No. It is kept in the archive record. It is only taken out of the Public Repository.'),
]

GLOSSARY = [
    ('Abstract', 'A short summary at the start of a paper.'),
    ('Actor', 'In a use case diagram: a person or an outside service that uses the system.'),
    ('alt (frame)', 'In a sequence diagram: a box with two or more paths. Only one path happens. The words in [brackets] say when.'),
    ('Approve', 'Say yes to a paper, so it moves to the next step.'),
    ('Archive', 'A safe place for old records. They are kept, but not shown to the public.'),
    ('Column', 'In a table: one kind of information, like "title" or "email".'),
    ('Database', 'Where PAPEL saves its information, in tables.'),
    ('Draft', 'A paper that is not finished and not sent.'),
    ('ERD', 'Entity Relationship Diagram. A picture of the database tables and how they link.'),
    ('«extend»', 'In a use case diagram: this step happens only sometimes.'),
    ('Feedback', "A reviewer's comments about what to change."),
    ('FK (foreign key)', 'An ID in one table that points to a row in another table.'),
    ('Google Drive', 'A Google service that keeps files online. PAPEL keeps submitted PDFs there.'),
    ('Guest pass', 'A username and password that work for a few hours.'),
    ('«include»', 'In a use case diagram: this step always happens as part of the other one.'),
    ('Lifeline', 'In a sequence diagram: the dashed line under each part. Time goes down it.'),
    ('Manuscript', 'The full text of a paper, as a PDF file.'),
    ('Metadata', 'Basic facts about a paper: title, authors, year, keywords.'),
    ('Notice (notification)', 'A short message from PAPEL, shown in the bell and sent by email.'),
    ('opt (frame)', 'In a sequence diagram: a box of steps that happen only sometimes.'),
    ('PDF', 'A file type for documents. It looks the same on every computer.'),
    ('PK (primary key)', 'The ID that makes each row in a table different from the others.'),
    ('Publish', 'Make a paper public in the Public Repository.'),
    ('Public Repository', 'The part of PAPEL where anyone can find approved papers.'),
    ('Return', 'Send a paper back to the student to fix.'),
    ('Row', 'In a table: one record, like one paper or one user.'),
    ('Sequence diagram', 'A picture of the steps behind the screen, in time order, from top to bottom.'),
    ('Status', 'Where a paper is in the process, like "draft" or "approved".'),
    ('Submit', 'Send a paper for review.'),
    ('Table', 'In a database: a list of rows with the same columns, like a spreadsheet.'),
    ('Use case', 'In a use case diagram: one thing a person can do with the system.'),
    ('Use case diagram', 'A picture of who uses a part of the system and what they can do there.'),
]

# Which file does the work, for the system team.
CODE_MAP = [
    ('1 Signing In', '`app/auth/login.php`, `config/core.php` (login throttle, account expiry)'),
    ('2 Creating Accounts', '`app/faculty/faculty_manage_students.php`, `app/admin/admin_manage_faculty.php`, `app/admin/super_admin_manage_admins.php`, `includes/password_generator.php`'),
    ('3 Uploading a Paper', '`app/student/student_upload_ai.php` (action `upload_paper`), `config/gdrive_config.php`'),
    ('4 Saving a Draft', '`app/student/student_upload_ai.php` (action `save_draft`), `app/student/student_draft_delete.php`'),
    ('5 Cancelling', '`app/student/student_cancel_submission.php`, `config/workflow.php` (`submission_cancel_state`)'),
    ('6 Adviser Review', '`app/faculty/faculty_review_dashboard.php`, `includes/review_console.php`'),
    ('7 Coordinator Review', '`app/admin/admin_review_dashboard.php`, `app/models/PaperService.php`'),
    ('8 Returned Papers', '`app/student/student_dashboard.php` (Needs Revision tab), `config/gdrive_config.php` (`purge_paper_drive_files`)'),
    ('9 Public Repository', '`archive/index.php`, `archive/view_paper.php`; files are shown by `includes/pdf_dock.php` and `assests/js/papel-pdf-view.js`, from `app/paper_file.php`'),
    ('10 Guest Passes', '`app/librarian/librarian_manage_guests.php`, `app/auth/login.php` (Guest tab)'),
    ('11 Manuscripts', '`archive/view_paper.php` (request), `app/librarian/manuscript_requests.php`'),
    ('12 Archiving', '`app/admin/super_admin_review_dashboard.php`, `app/models/ArchiveService.php`, `notifications/cron/auto_archive_papers.php`'),
    ('13 Notifications', '`config/core.php` (`create_notification`), `notifications/notification_center.php`, `notifications/cron/send_notifications.php`'),
    ('14 Support', '`pages/contact_support.php`, `app/support_requests.php`'),
]

TASKS = [
    ('`notifications/cron/auto_archive_papers.php`', 'Every day', 'Archives approved papers more than 1,825 days old (Workflow 12).'),
    ('`notifications/cron/send_notifications.php`', 'Every 15 minutes', 'Sends reminders at 10:00 and 15:30 to people with a reminder time (Workflow 13).'),
    ('`scripts/gdrive_keepalive.php`', 'Every week', 'Keeps the Google Drive connection from expiring.'),
]

LEGACY_STATUSES = [
    ('`pending_admin_l1`', 'Treated like `pending_admin`: waiting for the Research Coordinator.'),
    ('`pending_head_academic`, `pending_admin_l2`, `pending_super_admin`', 'From the old four-step chain. Shown as past the Coordinator. New papers never get them.'),
    ('`declined`, `archived`', 'Allowed by the column, but not written today. A returned paper is `draft` with feedback; an archived paper moves to `papers_archive`.'),
]
