# -*- coding: utf-8 -*-
"""Build the PAPEL Workflow Handbook as a PDF.

    python docs/handbook/build_handbook.py

Writes PAPEL_Workflow_Handbook.html and PAPEL_Workflow_Handbook.pdf beside this
file. The words and diagram specifications live in content.py; the drawing is
done by diagrams.py; this file lays the pages out and prints them.

Printing is done by headless Chrome, twice: the first pass finds the page each
section landed on, the second writes those numbers into the contents. Chrome
always gets a throwaway --user-data-dir. Without one it opens the everyday
profile, and because Chrome allows one process per profile it would close the
browser window the person at this computer is using. The process is left to
exit on its own; it is never killed by image name, which would take every
Chrome window on the machine with it.

Needs: Python 3.8+, pypdf, Chrome or Edge. PHP and the XAMPP database are
optional: when they are reachable, every table and column the diagrams draw
is checked against the live schema, and anything that has drifted is reported.
"""
import datetime
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from html import escape
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
sys.path.insert(0, str(HERE))

import content as K          # noqa: E402
import diagrams as D         # noqa: E402

OUT_HTML = HERE / 'PAPEL_Workflow_Handbook.html'
OUT_PDF = HERE / 'PAPEL_Workflow_Handbook.pdf'
LOGO = ROOT / 'assests' / 'images' / 'Logo-Papel.png'
EDITION = 'Edition 1 · September 2026'

CHROME_CANDIDATES = [
    r'C:\Program Files\Google\Chrome\Application\chrome.exe',
    r'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
    r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
    r'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
]


# ================================================================ helpers
def md(s):
    """The two bits of inline markup content.py uses: **bold** and `code`."""
    s = escape(str(s), quote=False)
    s = re.sub(r'\*\*(.+?)\*\*', r'<strong>\1</strong>', s)
    s = re.sub(r'`(.+?)`', r'<code>\1</code>', s)
    return s


def table(headers, rows, cls=''):
    h = ''.join('<th>%s</th>' % md(x) for x in headers)
    b = ''.join('<tr>%s</tr>' % ''.join('<td>%s</td>' % md(c) for c in r) for r in rows)
    return '<table class="%s"><thead><tr>%s</tr></thead><tbody>%s</tbody></table>' % (cls, h, b)


def box(kind, title, inner):
    return '<div class="box %s"><div class="box-title">%s</div>%s</div>' % (kind, title, inner)


def blocks(items):
    out = []
    for b in items:
        k = b[0]
        if k == 'p':
            out.append('<p>%s</p>' % md(b[1]))
        elif k == 'h':
            out.append('<h3>%s</h3>' % md(b[1]))
        elif k == 'steps':
            out.append('<ol class="steps">%s</ol>' % ''.join('<li>%s</li>' % md(x) for x in b[1]))
        elif k == 'bullets':
            out.append('<ul class="dots">%s</ul>' % ''.join('<li>%s</li>' % md(x) for x in b[1]))
        elif k == 'table':
            out.append(table(b[1], b[2]))
        elif k == 'remember':
            out.append(box('remember', 'Remember',
                           '<ul>%s</ul>' % ''.join('<li>%s</li>' % md(x) for x in b[1])))
        elif k == 'words':
            out.append(box('words', 'Words to know', '<dl>%s</dl>' % ''.join(
                '<div><dt>%s</dt><dd>%s</dd></div>' % (md(t), md(d)) for t, d in b[1])))
        elif k == 'tip':
            out.append(box('tip', 'Good to know', '<p>%s</p>' % md(b[1])))
        else:
            raise ValueError('unknown block ' + k)
    return ''.join(out)


def figure(no, kind, title, svg, caption):
    return ('<figure class="fig"><div class="fig-head"><span class="fig-no">Figure %s</span>'
            '<span class="fig-kind">%s</span></div><div class="fig-title">%s</div>%s'
            '<figcaption><strong>What it shows:</strong> %s</figcaption></figure>'
            % (no, kind, md(title), svg, md(caption)))


def chips(items, cls=''):
    return ''.join('<span class="chip %s">%s</span>' % (cls, md(x)) for x in items)


# ================================================================ sections
SECTIONS = []   # (key, kicker, title, group) in page order, for the contents and bookmarks


def section(key, kicker, title, group, inner, cls='front'):
    SECTIONS.append((key, kicker, title, group))
    return ('<section class="%s" id="%s"><div class="kicker">%s</div><h1>%s</h1>%s</section>'
            % (cls, key, kicker, md(title), inner))


def cover():
    logo = ('<img src="%s" alt="">' % LOGO.as_uri()) if LOGO.exists() else ''
    return ('<section class="cover"><div class="cover-ring"></div><div class="cover-ring two"></div>'
            '<div class="cover-brand"><div class="cover-logo">%s</div><div><div class="cover-name">PAPEL</div>'
            '<div class="cover-org">Research Archiving System<br>PUP Biñan Campus</div></div></div>'
            '<div class="cover-main"><div class="cover-kicker">HANDBOOK FOR USERS AND THE SYSTEM TEAM</div>'
            '<h1>Workflow<br>Handbook</h1>'
            '<p class="cover-sub">How research papers are uploaded, approved, published and archived. '
            'Step by step, in simple English, with pictures.</p></div>'
            '<div class="cover-facts"><div><b>14</b><span>workflows</span></div>'
            '<div><b>3</b><span>diagrams for each:<br>use case, sequence, ERD</span></div>'
            '<div><b>7</b><span>kinds of users</span></div></div>'
            '<div class="cover-foot">%s</div></section>' % (logo, EDITION))


def contents(pages):
    rows = []
    group = None
    for key, kicker, title, grp in SECTIONS:
        if grp != group:
            rows.append('<div class="toc-group">%s</div>' % md(grp))
            group = grp
        num = ''
        m = re.match(r'WORKFLOW (\d+)', kicker)
        if m:
            num = str(int(m.group(1)))
        elif kicker.startswith('APPENDIX'):
            num = kicker.split()[-1]
        elif kicker.startswith('START HERE'):
            num = kicker.split()[-1]
        rows.append('<a class="toc-row" href="#%s"><span class="toc-n">%s</span><span class="toc-t">%s</span>'
                    '<span class="toc-dots"></span><span class="toc-p">%s</span></a>'
                    % (key, num, md(title), pages.get(key, '00')))
    return ('<section class="front toc" id="contents"><div class="kicker">CONTENTS</div><h1>Contents</h1>'
            '<p class="toc-lead">Each workflow has the same parts: <b>In short</b>, <b>the steps</b>, '
            '<b>Remember</b>, and three diagrams. You can read only the parts you need.</p>%s</section>'
            % ''.join(rows))


def start_about():
    inner = (
        '<p class="lead">This handbook explains how PAPEL works, step by step.</p>'
        '<p>PAPEL is the research archiving system of PUP Biñan Campus. Students upload their research '
        'papers. Teachers check them. Approved papers are shared with the public. Later, old papers can be '
        'archived.</p>'
        '<h2>Who should read it</h2>'
        '<p>Students, Research Advisers, the Research Coordinator, the Head of Academic Programs, the Director, '
        'Librarians and guests. Also the people who look after the system: they will find notes for them in '
        'Appendix D.</p>'
        '<h2>How each workflow is arranged</h2>'
        '<ol class="steps">'
        '<li><strong>In short</strong>: one sentence about the workflow.</li>'
        '<li><strong>Who takes part</strong>: the people, and any outside service.</li>'
        '<li><strong>The steps</strong>: what to do, in order.</li>'
        '<li><strong>Remember</strong>: the important rules.</li>'
        '<li><strong>Words to know</strong>: new words, explained simply.</li>'
        '<li><strong>Three diagrams</strong>: a use case diagram (who does what), a sequence diagram (what happens '
        'behind the screen) and an ERD (where the information is saved).</li></ol>'
        + box('tip', 'Good to know',
              '<p>You do not need the diagrams to use PAPEL. The steps are enough. The diagrams are for people '
              'who want to see how PAPEL works inside. Start Here 3 explains how to read them.</p>')
        + '<h2>How words are written</h2>'
        '<ul class="dots"><li>Words in <strong>bold</strong> are the words you see on the screen, like '
        '<strong>Submit Paper</strong>.</li>'
        '<li>Words in <code>this style</code> are names used inside the system, like the status '
        '<code>draft</code>. You do not need them to use PAPEL.</li>'
        '<li>"Workflow 5" means chapter 5 of this handbook.</li></ul>'
        '<h2>The parts of the book</h2>'
        + table(['Part', 'What it covers'], [
            ['Start Here', 'This introduction, the people, how to read the diagrams, and the big picture.'],
            ['A · Accounts and Signing In', 'Workflows 1 and 2.'],
            ['B · Submitting a Paper', 'Workflows 3, 4 and 5: uploading, drafts and cancelling.'],
            ['C · Review and Approval', 'Workflows 6, 7 and 8: the two reviews, and fixing a returned paper.'],
            ['D · The Public Repository', 'Workflows 9, 10 and 11: reading papers, guest passes, full manuscripts.'],
            ['E · Records and Help', 'Workflows 12, 13 and 14: archiving, notices, and account help.'],
            ['Appendices', 'The database map, common questions, words to know, notes for the system team.'],
        ]))
    return section('about', 'START HERE · 1', 'About This Handbook', 'Start here', inner)


def start_people():
    inner = (
        '<p class="lead">Seven kinds of people use PAPEL. Each one has a home page and a job.</p>'
        + table(['Who', 'What they do', 'Their home page'], K.ROLES)
        + box('tip', 'Good to know',
              '<p>Your Research Adviser is the faculty member who made your account. Your papers always go to '
              'that person first.</p>')
        + '<h2>Approval ends at the Research Coordinator</h2>'
        '<p>Only two people approve a paper: the Research Adviser, then the Research Coordinator. The Head of '
        'Academic Programs and the Director <strong>read</strong> the published papers. They do not approve them.</p>'
        '<h2>Outside services</h2>'
        '<p>PAPEL uses four services from other companies. In the diagrams they are blue boxes marked '
        '«system».</p>'
        + table(['Service', 'What PAPEL uses it for'], K.SERVICES))
    return section('people', 'START HERE · 2', 'The People in PAPEL', 'Start here', inner)


def start_diagrams():
    uc = D.usecase('Example use case diagram', **K.LEGEND_UC)
    sq = D.sequence('Example sequence diagram', **K.LEGEND_SEQ)
    er = D.erd('Example ERD', **K.LEGEND_ERD)
    inner = (
        '<p class="lead">Each workflow has three diagrams. They follow UML, a standard way of drawing '
        'software. Here is how to read them.</p>'
        '<h2>1. The use case diagram: who does what</h2>'
        '<div class="legend"><div class="legend-art">%s</div><ul class="dots">'
        '<li>A <strong>stick figure</strong> is a person. It is called an <em>actor</em>.</li>'
        '<li>A <strong>blue box</strong> marked «system» is an outside service, like Google Drive.</li>'
        '<li>The <strong>big pink box</strong> is PAPEL. Everything inside it happens in PAPEL.</li>'
        '<li>An <strong>oval</strong> is one thing a person can do. It is called a <em>use case</em>.</li>'
        '<li>A <strong>plain line</strong> means: this person does this.</li>'
        '<li>A dashed arrow marked <strong>«include»</strong> means: this step <em>always</em> happens as part of the other.</li>'
        '<li>A dashed arrow marked <strong>«extend»</strong> means: this step happens <em>only sometimes</em>.</li>'
        '</ul></div>'
        '<h2>2. The sequence diagram: what happens, in order</h2>'
        '<div class="legend"><div class="legend-art">%s</div><ul class="dots">'
        '<li>The boxes at the top are the <strong>people and the parts</strong> of the system. The maroon box is '
        'PAPEL. The cylinder is the database. Blue dashed boxes are outside services.</li>'
        '<li>The <strong>dashed line</strong> going down from each box is its <em>lifeline</em>. Time goes down.</li>'
        '<li>A <strong>solid arrow</strong> is a request or an action. The <strong>number</strong> shows the order.</li>'
        '<li>A <strong>dashed arrow</strong> is an answer coming back.</li>'
        '<li>A <strong>loop arrow</strong> means the part does something by itself.</li>'
        '<li>An <strong>alt</strong> box has two or more paths. Only one happens. The words in [brackets] say when.</li>'
        '<li>An <strong>opt</strong> box happens only sometimes. A <strong>loop</strong> box repeats.</li>'
        '<li>A <strong>yellow note</strong> explains something.</li>'
        '</ul></div>'
        '<h2>3. The ERD: where the information is saved</h2>'
        '<div class="legend"><div class="legend-art">%s</div><ul class="dots">'
        '<li>ERD means <em>Entity Relationship Diagram</em>. Each <strong>box</strong> is one table in the database. '
        'The dark top shows the table\'s name.</li>'
        '<li>Each line in the box is one <strong>column</strong>: one piece of information.</li>'
        '<li><span class="k pk">PK</span> (primary key) is the ID that makes each row different.</li>'
        '<li><span class="k fk">FK</span> (foreign key) is an ID that points to a row in another table.</li>'
        '<li>Two short bars <strong>||</strong> at the end of a line mean <strong>one</strong>. The three-toed '
        '<strong>crow\'s foot</strong> means <strong>many</strong>. The example reads: one user uploads many papers.</li>'
        '<li>Each workflow shows only the columns it uses. "+ 20 more columns" tells you the rest are hidden. '
        'Appendix A has the full map.</li>'
        '</ul></div>') % (uc, sq, er)
    return section('diagrams', 'START HERE · 3', 'How to Read the Diagrams', 'Start here', inner)


def start_big_picture():
    j = K.JOURNEY
    journey = D.flow('The journey of a paper through PAPEL', j['nodes'], j['edges'], j['H'], lanes=j['lanes'])
    s = K.STATES
    states = D.flow('The status of a paper', s['nodes'], s['edges'], s['H'])
    inner = (
        '<p class="lead">A paper moves through PAPEL in five stages.</p>'
        '<ol class="steps">'
        '<li>The <strong>student</strong> writes the paper and uploads it.</li>'
        '<li>The <strong>Research Adviser</strong> checks it.</li>'
        '<li>The <strong>Research Coordinator</strong> checks it again and approves it.</li>'
        '<li>The paper is <strong>published</strong> in the Public Repository. Everyone can find it.</li>'
        '<li>Later, the <strong>Director</strong> can move it to the archive.</li></ol>'
        '<p>At stage 2 or stage 3, the reviewer can <strong>return</strong> the paper. The student fixes it and '
        'sends it again. It then starts again at the Research Adviser.</p>'
        + figure('0.1', 'Activity Diagram', 'The journey of a paper', journey,
                 'Each column is one person or place. Follow the arrows from the black dot at the top. '
                 'A diamond is a question. "Yes" moves the paper on. "No" sends it back to the student.')
        + '<h2>The status of a paper</h2>'
        '<p>The <strong>status</strong> says where a paper is in the process. Your dashboard shows it as a badge '
        'on the paper\'s card.</p>'
        + table(['The badge you see', 'What it means', 'Status inside the system'], K.STATUS_ROWS)
        + '<p>Your dashboard also has four tabs: <strong>Approved</strong>, <strong>Under Review</strong>, '
        '<strong>Needs Revision</strong> and <strong>Drafts</strong>.</p>'
        + figure('0.2', 'State Machine Diagram', 'Every status, and how a paper moves between them', states,
                 'Each rounded box is a status. Each arrow is something that changes the status. The small grey '
                 'text is the name the system uses. A staff member\'s own paper skips the review and is '
                 'published at once.'))
    return section('bigpicture', 'START HERE · 4', 'The Big Picture', 'Start here', inner)


def part_name(letter):
    return dict(K.PARTS)[letter]


def chapter_html(ch):
    n = ch['num']
    kicker = 'WORKFLOW %02d' % n
    grp = 'Part %s · %s' % (ch['part'], part_name(ch['part']))
    SECTIONS.append((ch['key'], kicker, ch['title'], grp))
    who = '<div class="who-row"><span class="who-label">Who takes part</span>%s</div>' % chips(ch['who'])
    if ch.get('outside'):
        who += ('<div class="who-row"><span class="who-label">Outside services</span>%s</div>'
                % chips(ch['outside'], 'ext'))
    head = ('<header class="ch-head"><div class="kicker">%s <span class="kicker-part">· PART %s · %s</span></div>'
            '<h1>%s</h1><p class="short"><span>In short</span>%s</p>%s</header>'
            % (kicker, ch['part'], part_name(ch['part']).upper(), md(ch['title']), md(ch['short']), who))
    uc = D.usecase('Use case diagram: ' + ch['title'], **ch['uc'])
    sq = D.sequence('Sequence diagram: ' + ch['title'], **ch['seq'])
    er = D.erd('ERD: ' + ch['title'], **ch['erd'])
    figs = (
        '<div class="diagrams"><div class="diagrams-keep">'
        '<h2 class="diagrams-head">The diagrams for Workflow %d</h2>' % n
        + figure('%d.1' % n, 'Use Case Diagram', 'Who does what', uc, ch['uc_note']) + '</div>'
        + figure('%d.2' % n, 'Sequence Diagram', 'What happens, step by step', sq, ch['seq_note'])
        + figure('%d.3' % n, 'Entity Relationship Diagram (ERD)', 'Where the information is saved', er,
                 ch['erd_note'])
        + '</div>')
    return '<section class="chapter" id="%s">%s%s%s</section>' % (ch['key'], head, blocks(ch['body']), figs)


def appendix_a():
    e1 = D.erd('Database map, part 1: papers and reviews', **K.APPENDIX_ERD_1)
    e2 = D.erd('Database map, part 2: access, help and settings', **K.APPENDIX_ERD_2)
    rows = [['`%s`' % t, s['desc'], str(len(s['cols']))] for t, s in K.SCHEMA.items()]
    inner = (
        '<p class="lead">PAPEL saves everything in one database with 21 tables. These two pictures show the '
        'main tables and how they link.</p>'
        '<p>Most links are kept by the program, not forced by the database. For example, the database does not '
        'stop a paper from pointing to a user who was deleted. So when you delete rows by hand, delete the '
        'linked rows too.</p>'
        + figure('A.1', 'Entity Relationship Diagram (ERD)', 'Papers and reviews', e1,
                 'The paper tables. Every paper belongs to the user who uploaded it. Its review decisions, '
                 'documents, checklist and numbers point back to it by paper_id.')
        + figure('A.2', 'Entity Relationship Diagram (ERD)', 'Access, help and settings', e2,
                 'Every line here means: one user has many rows in that table. The tables in the bottom row '
                 'stand alone.')
        + '<h2>All 21 tables</h2>'
        + table(['Table', 'What it holds', 'Columns'], rows, 'tight'))
    return section('appA', 'APPENDIX A', 'The Database Map', 'Appendices', inner)


def appendix_b():
    inner = ('<p class="lead">Short answers to questions people often ask.</p>'
             + ''.join('<div class="faq"><div class="faq-q">%s</div><p>%s</p></div>' % (md(q), md(a))
                       for q, a in K.FAQ))
    return section('appB', 'APPENDIX B', 'Common Questions', 'Appendices', inner)


def appendix_c():
    inner = ('<p class="lead">The words in this handbook, explained simply.</p>'
             + '<dl class="glossary">%s</dl>' % ''.join(
                 '<div><dt>%s</dt><dd>%s</dd></div>' % (md(t), md(d)) for t, d in K.GLOSSARY))
    return section('appC', 'APPENDIX C', 'Words to Know', 'Appendices', inner)


def appendix_d(issues):
    inner = (
        '<p class="lead">For the people who run and change PAPEL. The rest of the handbook does not need this.</p>'
        '<h2>Which file does the work</h2>'
        + table(['Workflow', 'Main files'], K.CODE_MAP, 'tight')
        + '<h2>Tasks that must be scheduled</h2>'
        '<p>These scripts do nothing unless Windows Task Scheduler (or cron on Linux) runs them. Run them with '
        'the PHP command line, for example <code>php notifications/cron/auto_archive_papers.php</code>.</p>'
        + table(['Script', 'How often', 'What it does'], K.TASKS, 'tight')
        + '<h2>Where the files live</h2>'
        + table(['Files', 'Where'], [
            ['Submitted papers and their supporting documents', 'Google Drive, in the folder the Director chose. '
             'The server copy is deleted after the upload. Readers see them through `app/paper_file.php`, '
             'which passes the file on from Drive without keeping a copy. Keep the folder\'s link sharing '
             'at Viewer: at Editor, anyone holding a link could change or delete a paper.'],
            ['Draft PDFs', 'On the server: `app/student/uploads/drafts/<user_id>/`'],
            ['Pictures pasted into sections', 'On the server: `uploads/section_images/<user_id>/`'],
            ['Older papers from before Drive-only storage', 'Some are still in `app/student/uploads/research/`. '
             '`scripts/migrations/run_drive_only_cleanup.php` removes the ones Drive already holds.'],
        ], 'tight')
        + '<p>Back up the database <strong>and</strong> the two server folders above. Google Drive has no copy '
        'of drafts or pictures.</p>'
        '<h2>Old status values</h2>'
        '<p>The <code>current_status</code> column still allows values from the old four-step chain.</p>'
        + table(['Status', 'What PAPEL does with it'], K.LEGACY_STATUSES, 'tight')
        + '<h2>Two accounts do the Head of Academic Programs job</h2>'
        '<p>A <code>head_academic</code> account, and an <code>admin</code> account with <code>admin_level = 2</code>. '
        'Both land on <code>app/faculty/head_review_dashboard.php</code>. Any role check must cover both.</p>'
        '<h2>Things found while writing this handbook</h2>'
        '<ul class="dots">%s</ul>'
        '<p class="small">This handbook was built from the code on %s by <code>docs/handbook/build_handbook.py</code>. '
        'Run it again after a change to a workflow.</p>'
        % (''.join('<li>%s</li>' % md(x) for x in issues), datetime.date.today().strftime('%d %B %Y')))
    return section('appD', 'APPENDIX D', 'Notes for the System Team', 'Appendices', inner)


# The known issues are facts about the code, not opinions, so each one names
# where to look. Keep this list short and delete a line once it is fixed.
ISSUES = [
    "The Research Coordinator's approval notice is saved with the type `progress` "
    "(`app/models/PaperService.php`, `approvePaper`). `progress` is not one of the values "
    "`notifications.notification_type` allows. On this XAMPP, where MariaDB is not in strict mode, the notice is "
    "saved with a blank type. On a server in strict mode (the MariaDB default), the insert fails silently and "
    "the student gets the email but no notice in the bell.",
    "The upload page says PDFs may be up to 50 MB, but this XAMPP's `php.ini` has `upload_max_filesize` and "
    "`post_max_size` set to 40M. A PDF between 40 and 50 MB fails. Set both to at least 60M on every server.",
    "The approve dialog on the Review Desk asks for a \"Note for the student\" and says it is passed on. The "
    "Adviser's note is saved in `approval_workflow.feedback`, but no page shows it: the student dashboard, "
    "`paper_details.php` and the review console read feedback only from `declined` rows. The Coordinator's note "
    "is not saved at all: `admin_review_dashboard.php` does not pass it to `PaperService::approvePaper`.",
    "`analytics.view_count`, `download_count` and `citation_count` are never written. Only `time_to_approval` "
    "is (by `PaperService::recordTimeToApproval`).",
    "Five tables are not used by any page: `imrad_checklist`, `paper_favorites`, `ai_processing_log`, "
    "`storage_usage` and `gdrive_settings`.",
]


# ================================================================ checks
def live_schema():
    """{table: [columns]} from the running database, or None if unreachable."""
    php = r'C:\xampp\php\php.exe' if os.path.exists(r'C:\xampp\php\php.exe') else shutil.which('php')
    cfg = ROOT / 'config' / 'config.php'
    if not php or not cfg.exists():
        return None
    code = ('<?php require %s; $c = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);'
            'if ($c->connect_errno) exit(3);'
            '$r = $c->query("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS '
            'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION");'
            '$o = []; while ($x = $r->fetch_assoc()) $o[$x["t"]][] = $x["c"]; echo json_encode($o);'
            % json.dumps(str(cfg).replace('\\', '/')))
    fd, path = tempfile.mkstemp(suffix='.php')
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            f.write(code)
        r = subprocess.run([php, path], capture_output=True, text=True, timeout=60)
        if r.returncode != 0 or not r.stdout.strip().startswith('{'):
            return None
        return json.loads(r.stdout)
    except Exception:
        return None
    finally:
        os.unlink(path)


def check_schema():
    live = live_schema()
    if live is None:
        print('  schema    database not reachable - column lists not re-checked')
        return True
    ok = True
    for t, spec in K.SCHEMA.items():
        mine = [c[0] for c in spec['cols']]
        theirs = live.get(t)
        if theirs is None:
            print('  SCHEMA    table %s is drawn but does not exist' % t)
            ok = False
            continue
        missing = [c for c in mine if c not in theirs]
        extra = [c for c in theirs if c not in mine]
        if missing or extra:
            ok = False
            print('  SCHEMA    %s: drawn but gone %s, new and not drawn %s' % (t, missing, extra))
    for t in live:
        if t not in K.SCHEMA:
            ok = False
            print('  SCHEMA    table %s exists but the handbook never mentions it' % t)
    if ok:
        print('  schema    all %d tables and their columns match the live database' % len(K.SCHEMA))
    return ok


# ================================================================ print
def find_chrome():
    for p in CHROME_CANDIDATES:
        if os.path.exists(p):
            return p
    raise SystemExit('Chrome or Edge not found; add its path to CHROME_CANDIDATES.')


def render(html_path, pdf_path):
    profile = tempfile.mkdtemp(prefix='papel-handbook-chrome-')
    try:
        cmd = [find_chrome(), '--headless=new', '--disable-gpu', '--no-first-run',
               '--no-default-browser-check', '--disable-extensions', '--user-data-dir=' + profile,
               '--no-pdf-header-footer', '--virtual-time-budget=25000',
               '--print-to-pdf=' + str(pdf_path), html_path.as_uri()]
        subprocess.run(cmd, check=True, timeout=300, capture_output=True)
    finally:
        shutil.rmtree(profile, ignore_errors=True)
    if not pdf_path.exists() or pdf_path.stat().st_size < 10000:
        raise SystemExit('Chrome did not write the PDF.')


def locate(pdf_path):
    """Page number (1-based) of each section, found by its kicker text."""
    from pypdf import PdfReader
    texts = [re.sub(r'\s+', '', p.extract_text() or '') for p in PdfReader(str(pdf_path)).pages]
    pages = {}
    for key, kicker, _t, _g in SECTIONS:
        needle = re.sub(r'\s+', '', kicker)
        for i, t in enumerate(texts):
            if needle in t:
                pages[key] = str(i + 1)
                break
    return pages, len(texts)


CSS = (HERE / 'handbook.css').read_text(encoding='utf-8')

# Static font files, one per weight, rather than Google Fonts' css2 API. css2
# serves Inter and Plus Jakarta Sans as variable fonts, and Chrome's PDF engine
# cannot embed a variable font as a font: it redraws every glyph as a Type3
# shape instead. The first build came out at 13.6 MB, 12 of it glyph drawings.
FONT_URL = 'https://cdn.jsdelivr.net/npm/@fontsource/%s/files/%s-latin-%d-%s.woff2'
FONTS = [
    ('Inter', 'inter', [(400, 'normal'), (500, 'normal'), (600, 'normal'), (700, 'normal'),
                        (400, 'italic'), (600, 'italic')]),
    ('Plus Jakarta Sans', 'plus-jakarta-sans', [(500, 'normal'), (600, 'normal'), (700, 'normal'),
                                                (800, 'normal')]),
    ('JetBrains Mono', 'jetbrains-mono', [(500, 'normal'), (700, 'normal')]),
]


def font_faces():
    return ''.join(
        "@font-face{font-family:'%s';font-style:%s;font-weight:%d;font-display:block;"
        "src:url(%s) format('woff2')}" % (fam, style, wt, FONT_URL % (slug, slug, wt, style))
        for fam, slug, faces in FONTS for wt, style in faces)


def build_html(pages):
    SECTIONS.clear()
    body = [start_about(), start_people(), start_diagrams(), start_big_picture()]
    body += [chapter_html(ch) for ch in K.CHAPTERS]
    body += [appendix_a(), appendix_b(), appendix_c(), appendix_d(ISSUES)]
    toc = contents(pages)   # built last: SECTIONS is filled while the body is written
    return ('<!doctype html><html lang="en"><head><meta charset="utf-8">'
            '<title>PAPEL Workflow Handbook</title>'
            '<meta name="author" content="PAPEL · PUP Biñan Campus">'
            '<style>%s%s</style></head><body>%s%s%s</body></html>'
            % (font_faces(), CSS, cover(), toc, ''.join(body)))


def finish(src, dst, pages):
    """Copy the PDF with a title, an author and a bookmark tree."""
    from pypdf import PdfReader, PdfWriter
    w = PdfWriter(clone_from=PdfReader(str(src)))
    w.add_metadata({'/Title': 'PAPEL Workflow Handbook', '/Author': 'PAPEL · PUP Biñan Campus',
                    '/Subject': 'How research papers are uploaded, approved, published and archived',
                    '/Keywords': 'PAPEL, workflow, use case, sequence diagram, ERD'})
    w.page_mode = '/UseOutlines'
    parents = {}
    for key, kicker, title, grp in SECTIONS:
        if key not in pages:
            continue
        if grp not in parents:
            parents[grp] = w.add_outline_item(grp, int(pages[key]) - 1)
        label = title
        m = re.match(r'WORKFLOW (\d+)', kicker)
        if m:
            label = '%d. %s' % (int(m.group(1)), title)
        elif kicker.startswith('APPENDIX'):
            label = '%s. %s' % (kicker.split()[-1], title)
        w.add_outline_item(label, int(pages[key]) - 1, parent=parents[grp])
    with open(dst, 'wb') as f:
        w.write(f)


def main():
    print('PAPEL Workflow Handbook')
    schema_ok = check_schema()
    tmp = Path(tempfile.mkdtemp(prefix='papel-handbook-'))
    try:
        pages = {}
        total = 0
        for attempt in range(1, 4):
            OUT_HTML.write_text(build_html(pages), encoding='utf-8')
            draft = tmp / ('pass%d.pdf' % attempt)
            render(OUT_HTML, draft)
            found, total = locate(draft)
            missing = [k for k, *_ in SECTIONS if k not in found]
            if missing:
                raise SystemExit('Could not find these sections in the PDF: %s' % missing)
            print('  pass %d    %d pages' % (attempt, total))
            if found == pages:
                break
            pages = found
        else:
            raise SystemExit('Page numbers did not settle after three passes.')
        finish(draft, OUT_PDF, pages)
    finally:
        shutil.rmtree(tmp, ignore_errors=True)
    print('  wrote     %s (%d pages, %.1f MB)' % (OUT_PDF.relative_to(ROOT), total,
                                                  OUT_PDF.stat().st_size / 1048576))
    print('  wrote     %s' % OUT_HTML.relative_to(ROOT))
    return 0 if schema_ok else 1


if __name__ == '__main__':
    sys.exit(main())
