# -*- coding: utf-8 -*-
"""MODULE 13: a copy of the site whose database is behind the code.

Staging was built from a database export of 14 October. Two schema changes were
made a week later: supporting_documents.document_type became a VARCHAR and gained
document_name, and paper_manuscripts was created. Staging has no shell to run
the migration scripts from, and scripts/ is not deployed, so its database stayed
as it was. Five queries named document_name, so the Research Adviser's desk ended
in a 500 page the moment a paper was waiting on it, and a student's submission
would have failed at its last step.

This reproduces that server on the dev box:

  * the live database is copied into a scratch one (papel_m13) and put back to its
    14 October shape;
  * the application is served from a second PHP server pointed at the scratch
    database, with mysqli behaving as it does from PHP 8.1 (a failed query throws;
    the 8.0 on this machine stays silent);
  * nothing here touches capstone_db.

Run on its own:

    python m13_old_schema.py              # everything
    python m13_old_schema.py pages        # or just the named steps

Three papers are submitted for real, which puts files on the real Google Drive:
those, and any now-empty AUTOTEST folder, are deleted again by
tests/_scope_probe.php before the accounts, the scratch database and the server
go. Mail only ever reaches @example.com addresses.

If a run is killed, three things can be left behind: the database papel_m13 (drop
it), the php.exe listening on port 8093 (stop it by its PID, never by image name),
and AUTOTEST files on Drive (`php tests/_scope_probe.php drive_clean` removes them,
and any empty AUTOTEST folder). The live database is never written to, so there is
nothing to put right there.

It is not part of run_all.py, for the same reason m10 is not.
"""
import http.cookiejar, json, os, random, re, socket, subprocess, sys, tempfile, time
import urllib.error, urllib.request

import harness as H
import fixtures as F
import m4_submission as M4
import m14_review_checklist as M14
from harness import R, check

UPLOAD = M4.UPLOAD
LIVE = 'capstone_db'
SCRATCH = 'papel_m13'
PORT = 8093
SERVER_BASE = 'http://localhost:%d/capstone' % PORT
MYSQLDUMP = r'c:\xampp\mysql\bin\mysqldump.exe'
DOCROOT = r'C:\xampp\htdocs'
PREPEND = os.path.join(H.HERE, '_old_schema_prepend.php')
PROBE = os.path.join(H.HERE, '_scope_probe.php')
PROBE_OS = os.path.join(H.HERE, '_old_schema_probe.php')
CATCH_UP = os.path.normpath(os.path.join(H.HERE, '..', 'scripts', 'migrations', 'sql', '2026-10-21_catch_up.sql'))
CHECKLIST_SQL = os.path.normpath(os.path.join(H.HERE, '..', 'scripts', 'migrations', 'sql', '2026-10-23_checklist_items.sql'))
SQL_FILES = [CATCH_UP, CHECKLIST_SQL]
SERVER_LOG = os.path.join(tempfile.gettempdir(), 'papel_m13_server.log')
COORDINATOR_ID = 151
ALL = ['abstract', 'introduction', 'methodology', 'results_discussion', 'conclusion', 'references']

T_WAITING = 'AUTOTEST Old Schema Waiting Paper'
T_OLD = 'AUTOTEST Old Schema Submission'
T_NEW = 'AUTOTEST Old Schema Caught Up'
T_BOTH = 'AUTOTEST Old Schema Both Ways'
OTHER_NAME = 'AUTOTEST Letter of Endorsement'

SESSIONS = []        # session files written by minted(), removed again at the end
STATE = {'server': None, 'paper_ids': []}


# ------------------------------------------------------------------ helpers
def mysql(db, sql=None, stdin_path=None):
    """Run SQL through the mysql client, on a database that is named, never assumed."""
    cmd = [H.MYSQL, '-uroot'] + ([db] if db else []) + (['-e', sql] if sql else [])
    stdin = open(stdin_path, 'rb') if stdin_path else None
    try:
        p = subprocess.run(cmd, stdin=stdin, capture_output=True)
    finally:
        if stdin:
            stdin.close()
    return p.returncode, p.stdout.decode('utf-8', 'replace'), p.stderr.decode('utf-8', 'replace')


def php(*args, env=None):
    """A PHP CLI run against the scratch database, with mysqli throwing as it does on 8.1+."""
    e = dict(os.environ, PAPEL_TEST_DB=SCRATCH)
    e.update(env or {})
    p = subprocess.run([H.PHP, '-d', 'auto_prepend_file=' + PREPEND] + [str(a) for a in args],
                       capture_output=True, env=e)
    return p.returncode, p.stdout.decode('utf-8', 'replace'), p.stderr.decode('utf-8', 'replace')


def minted(user_id):
    """A signed-in client for an account in the scratch database (see m10 for why not a real sign-in)."""
    sid = ''.join(random.choice('0123456789abcdefghijklmnopqrstuv') for _ in range(26))
    code = (
        '$c=new mysqli("localhost","root","","%s");'
        '$u=$c->query("SELECT * FROM users WHERE user_id=%d")->fetch_assoc();'
        'if(!$u){fwrite(STDERR,"no such user");exit(1);}'
        'file_put_contents("C:/xampp/tmp/sess_%s","user|".serialize($u)."seen|".serialize(time()));'
    ) % (SCRATCH, int(user_id), sid)
    subprocess.run([H.PHP, '-r', code], check=True)
    SESSIONS.append(os.path.join(H.SESS_DIR, 'sess_' + sid))
    c = H.Client('minted %s' % user_id)
    c.jar.set_cookie(http.cookiejar.Cookie(
        0, 'papel_sid', sid, None, False, 'localhost.local', True, False,
        '/', True, False, None, False, None, None, {}))
    c.signed_in = True
    return c


def strip(html):
    """The words on a page: markup, styles and scripts removed."""
    html = re.sub(r'(?is)<(style|script)\b.*?</\1>', ' ', html)
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', html))


def paper_id(title):
    return H.one("SELECT paper_id FROM research_papers WHERE title=%s ORDER BY paper_id DESC LIMIT 1"
                 % H.esc(title))


def docs_of(pid):
    """(kind, name or None) for each supporting document, whichever shape the table has."""
    has_name = bool(H.q("SHOW COLUMNS FROM supporting_documents LIKE 'document_name'"))
    cols = "document_type, document_name" if has_name else "document_type, NULL"
    return [(r[0], None if r[1] in ('NULL', '\\N') else r[1])
            for r in H.q("SELECT %s FROM supporting_documents WHERE paper_id=%s ORDER BY doc_id" % (cols, pid))]


def submit(client, title, formats=('Full Manuscript',), docs=None, extra=None):
    """A submission through the real handler, posting what the page posts today."""
    fields = {
        'action': 'upload_paper', 'paper_type': 'undergrad_thesis', 'research_type': 'quantitative',
        'manuscript_type[]': list(formats),
        'title': title, 'authors': F.STUDENT['full_name'],
        'program_category': F.STUDENT['program'],
        'research_date': '2026-03-15', 'year': '2026',
        'keywords': 'autotest, automation, suite, testing, repository',
    }
    for k in ALL:
        fields[k] = '<p>AUTOTEST %s section written for the automated suite.</p>' % k
    fields.update(extra or {})
    files = {}
    if 'Full Manuscript' in formats:
        files['pdf_full'] = ('autotest.pdf', M4.pdf_bytes(), 'application/pdf')
    if 'IMRAD' in formats:
        files['pdf_imrad'] = ('autotest_imrad.pdf', M4.pdf_bytes('AUTOTEST imrad manuscript'), 'application/pdf')
    for k in (docs or ()):
        files[k] = ('autotest_%s.pdf' % k, M4.pdf_bytes('AUTOTEST ' + k), 'application/pdf')
    s, body = client.post_files(UPLOAD, fields, files, token_from=UPLOAD)
    try:
        return s, json.loads(body)
    except ValueError:
        return s, {'raw': body[:300]}


# ------------------------------------------------------------- the scratch copy
def build():
    """Copy the live database and put it back to the shape staging was built with."""
    assert SCRATCH != LIVE
    mysql(None, 'DROP DATABASE IF EXISTS %s; CREATE DATABASE %s CHARACTER SET utf8mb4 '
                'COLLATE utf8mb4_general_ci' % (SCRATCH, SCRATCH))
    dump = subprocess.run([MYSQLDUMP, '-uroot', '--single-transaction', LIVE],
                          capture_output=True, check=True).stdout
    p = subprocess.run([H.MYSQL, '-uroot', SCRATCH], input=dump, capture_output=True)
    if p.returncode != 0:
        raise RuntimeError('loading the copy failed: %s' % p.stderr.decode('utf-8', 'replace')[:200])
    code, _, err = mysql(SCRATCH, (
        "DELETE FROM supporting_documents WHERE doc_id >= 0;"
        "DROP TABLE IF EXISTS paper_manuscripts;"
        "ALTER TABLE paper_checklist DROP COLUMN items_json;"
        "ALTER TABLE supporting_documents DROP COLUMN document_name;"
        "ALTER TABLE supporting_documents MODIFY document_type "
        "ENUM('ethics_clearance','consent_form','data_collection','other') NOT NULL DEFAULT 'other';"))
    if code != 0:
        raise RuntimeError('ageing the copy failed: %s' % err[:200])
    H.DB = SCRATCH
    print('  scratch database %s built from %s and aged to the 14 October shape' % (SCRATCH, LIVE))


def port_open():
    s = socket.socket()
    s.settimeout(0.5)
    try:
        return s.connect_ex(('127.0.0.1', PORT)) == 0
    finally:
        s.close()


def serve():
    """A second PHP server for the same code, on the scratch database."""
    if port_open():
        raise RuntimeError('port %d is already in use; is an earlier run still going?' % PORT)
    log = open(SERVER_LOG, 'wb')
    STATE['server'] = subprocess.Popen(
        [H.PHP, '-d', 'auto_prepend_file=' + PREPEND, '-S', '127.0.0.1:%d' % PORT, '-t', DOCROOT],
        env=dict(os.environ, PAPEL_TEST_DB=SCRATCH), stdout=log, stderr=log)
    H.BASE = SERVER_BASE
    for _ in range(100):
        try:
            urllib.request.urlopen(SERVER_BASE + '/archive/index.php', timeout=10).read()
            break
        except urllib.error.HTTPError:
            break
        except Exception:
            time.sleep(0.25)
    else:
        raise RuntimeError('the scratch server did not come up')
    print('  application served on %s from database %s' % (SERVER_BASE, SCRATCH))


def stop():
    p = STATE['server']
    if p and p.poll() is None:
        p.terminate()          # by handle: never by image name (CLAUDE.md, headless Chrome trap)
        try:
            p.wait(timeout=10)
        except Exception:
            p.kill()
    STATE['server'] = None


def setup():
    print('-- setting up ------------------------------------------------')
    build()
    serve()
    F.teardown(verbose=False)
    F.make_adviser(minted(COORDINATOR_ID))
    adviser_id = F.uid_of('faculty_id', F.ADVISER['faculty_id'])
    F.make_student(minted(adviser_id), F.STUDENT)
    student_id = F.uid_of('student_id', F.STUDENT['student_id'])
    if not (adviser_id and student_id):
        print('  could not create the test accounts; stopping')
        sys.exit(1)
    print('  test adviser %s and student %s created through the consoles' % (adviser_id, student_id))


def adviser():
    return minted(F.uid_of('faculty_id', F.ADVISER['faculty_id']))


def student():
    return minted(F.uid_of('student_id', F.STUDENT['student_id']))


# -------------------------------------------------------------------- tests
def shape():
    """The copy really is behind, and the application knows it."""
    code, out, err = php(PROBE_OS, 'gaps')
    keys = []
    try:
        keys = json.loads(out.strip().splitlines()[-1])
    except (ValueError, IndexError):
        pass
    check('OS-01', sorted(keys) == ['checklist_items', 'document_name', 'document_type', 'paper_manuscripts'],
          'the application reports the three gaps in the copy: %s' % ', '.join(sorted(keys)),
          'schema_gaps() said %s (exit %s) %s' % (keys, code, err[-120:]))

    # The control that makes every later pass mean something: the statement the desk used to
    # run really does fail on this database, under the behaviour of the PHP that staging runs.
    code, out, err = php(PROBE_OS, 'old_query')
    check('OS-02', "Unknown column 'document_name'" in out,
          'the old query throws on this database, as it did on staging',
          'the control did not fail: %s' % (out or err)[:160])


def pages():
    """The adviser's desk and the paper pages, with a paper waiting and documents filed."""
    pid = F.seed_paper('pending_faculty', T_WAITING)
    STATE['paper_ids'].append(pid)
    H.q("INSERT INTO supporting_documents (paper_id, document_type, file_path, gdrive_file_id) VALUES "
        "(%s,'ethics_clearance','uploads/x/%s_ethics_clearance_a.pdf','GD-AUTOTEST-1'),"
        "(%s,'other','uploads/x/%s_letter_b.pdf','GD-AUTOTEST-2')" % (pid, pid, pid, pid))

    a = adviser()
    desk = a.get('/app/faculty/faculty_review_dashboard.php?tab=queue')
    words = strip(desk)
    check('OS-03', a.last_status == 200 and 'Something Went Wrong' not in words and T_WAITING in words,
          'the adviser\'s desk lists the waiting paper instead of ending in a 500',
          'status %s, error page %s, paper listed %s' % (
              a.last_status, 'Something Went Wrong' in words, T_WAITING in words), words[:200])

    rv = strip(a.get('/app/review_paper.php?id=%s' % pid))
    check('OS-04', a.last_status == 200 and 'Ethical Clearance' in rv and 'Other Document' in rv,
          'the review page names the documents by their type (no name column to read)',
          'status %s, page said: %s' % (a.last_status, rv[:200]))

    s = student()
    dt = strip(s.get('/app/student/paper_details.php?id=%s' % pid))
    check('OS-05', s.last_status == 200 and 'Something Went Wrong' not in dt and T_WAITING in dt,
          'the author\'s own page for the paper opens',
          'status %s: %s' % (s.last_status, dt[:200]))


def diagnostics():
    a = adviser()
    page = a.get('/app/student/upload_diagnostics.php')
    words = strip(page)
    check('OS-06', 'schema gaps: 4' in words and 'paper_manuscripts' in words and 'ALTER TABLE paper_checklist' in words
          and 'ALTER TABLE supporting_documents' in words,
          'staff see the four gaps and the SQL that closes them',
          'the Database section said: %s' % words[words.find('Database'):][:240])
    s = student()
    sw = strip(s.get('/app/student/upload_diagnostics.php'))
    check('OS-07', 'schema gaps' not in sw and 'ALTER TABLE' not in sw,
          'a student sees no Database section',
          'a student was shown the schema')


def failure_page():
    """A real fault shows its cause on the error page here, because this is not production."""
    a = adviser()
    mysql(SCRATCH, 'RENAME TABLE research_papers TO research_papers__aside')
    try:
        s, body, _ = a.raw('/app/faculty/faculty_review_dashboard.php?tab=queue')
    finally:
        mysql(SCRATCH, 'RENAME TABLE research_papers__aside TO research_papers')
    cause = re.search(r'class="error-detail".*?<code>(.*?)</code>', body, re.S)
    text = cause.group(1) if cause else ''
    check('OS-08', s == 500 and 'research_papers' in text,
          'a failing page names its cause on the error page (%s)' % text[:70],
          'status %s, cause block %r' % (s, text[:120]))


def submissions_old():
    """A student files a paper that carries the copyright document and a named extra one."""
    c = student()
    s, j = submit(c, T_OLD, docs=['copyright_doc', 'other_doc'], extra={'other_doc_name': OTHER_NAME})
    pid = paper_id(T_OLD)
    if pid:
        STATE['paper_ids'].append(pid)
    kinds = docs_of(pid) if pid else []
    check('OS-09', s == 200 and j.get('success') and pid,
          'the paper is filed on the old schema',
          'status %s, reply %s' % (s, j))
    check('OS-10', sorted(k for k, _ in kinds) == ['other', 'other'] and all(k != '' for k, _ in kinds),
          'both documents are kept, under kinds the old column can hold (none lost as an empty string)',
          'stored %s' % kinds)

    a = adviser()
    desk = strip(a.get('/app/faculty/faculty_review_dashboard.php?tab=queue'))
    rv = strip(a.get('/app/review_paper.php?id=%s' % pid)) if pid else ''
    check('OS-11', T_OLD in desk and 'Something Went Wrong' not in desk and 'Other Document' in rv,
          'it reaches the adviser\'s desk and opens for review',
          'listed %s, review said %s' % (T_OLD in desk, rv[:160]))

    n_before = H.one("SELECT COUNT(*) FROM research_papers WHERE title=%s" % H.esc(T_BOTH))
    s2, j2 = submit(c, T_BOTH, formats=('IMRAD', 'Full Manuscript'))
    n_after = H.one("SELECT COUNT(*) FROM research_papers WHERE title=%s" % H.esc(T_BOTH))
    msg = (j2.get('message') or j2.get('error') or j2.get('raw') or '')
    check('OS-12', s2 in (400, 422) and not j2.get('success') and 'system update' in msg and n_before == n_after,
          'a paper handed in both ways is refused plainly, with nothing half-filed',
          'status %s, reply %s, rows %s -> %s' % (s2, j2, n_before, n_after))


def catch_up():
    """The SQL for phpMyAdmin repairs the copy, repairs the rows, and is safe to run twice."""
    runs = [mysql(SCRATCH, stdin_path=f) for f in SQL_FILES]
    check('OS-13', all(r[0] == 0 for r in runs), 'both SQL files run clean',
          'exit %s: %s' % ([r[0] for r in runs], ' | '.join(r[2][:120] for r in runs)))

    cols = dict((r[0], r[1]) for r in H.q(
        "SELECT column_name, column_type FROM information_schema.columns WHERE table_schema='%s' "
        "AND table_name='supporting_documents' AND column_name IN ('document_type','document_name')" % SCRATCH))
    table = H.q("SHOW TABLES LIKE 'paper_manuscripts'")
    has_items = H.q("SHOW COLUMNS FROM paper_checklist LIKE 'items_json'")
    check('OS-14', cols.get('document_type', '').startswith('varchar') and 'document_name' in cols and table and has_items,
          'document_type is a VARCHAR, document_name exists, paper_manuscripts exists, paper_checklist has items_json',
          'columns %s, table %s, items_json %s' % (cols, table, has_items))

    pid = paper_id(T_OLD)
    kinds = sorted(k for k, _ in docs_of(pid)) if pid else []
    check('OS-15', kinds == ['copyright_doc', 'other'],
          'the copyright document filed as "other" while the schema was behind is read back as itself',
          'kinds now %s' % kinds)

    before = H.q("SELECT doc_id, document_type FROM supporting_documents ORDER BY doc_id")
    before_m = H.q("SELECT paper_id, format FROM paper_manuscripts ORDER BY paper_id, format")
    again = [mysql(SCRATCH, stdin_path=f) for f in SQL_FILES]
    code2 = max(r[0] for r in again)
    err2 = ' | '.join(r[2] for r in again)
    after = H.q("SELECT doc_id, document_type FROM supporting_documents ORDER BY doc_id")
    after_m = H.q("SELECT paper_id, format FROM paper_manuscripts ORDER BY paper_id, format")
    check('OS-16', code2 == 0 and before == after and before_m == after_m,
          'run again, it changes nothing and raises nothing',
          'exit %s %s; documents equal %s; manuscripts equal %s' % (code2, err2[:120], before == after, before_m == after_m))

    a = adviser()
    words = strip(a.get('/app/student/upload_diagnostics.php'))
    check('OS-17', 'schema gaps: none' in words and 'No gaps' in words,
          'the diagnostics page now reads "No gaps"',
          'it said: %s' % words[words.find('Database'):][:200])


def sql_file_shape():
    """Every SQL file for phpMyAdmin ends on a statement that reads no table.

    phpMyAdmin looks at the last statement of a pasted script and, if it reads from a
    database, switches to that database before running any of it: a closing SELECT
    from information_schema made every DATABASE() above it answer information_schema,
    and step 2 was refused with #1044. The mysql client does not do this, so no other
    case here would notice an edit that brought it back."""
    folder = os.path.dirname(CATCH_UP)
    files = sorted(f for f in os.listdir(folder) if f.endswith('.sql'))
    bad = []
    for name in files:
        text = open(os.path.join(folder, name), encoding='utf-8').read()
        body = chr(10).join(l for l in text.splitlines() if not l.lstrip().startswith('--'))
        statements = [x.strip() for x in body.split(';') if x.strip()]
        last = statements[-1] if statements else ''
        flat = ' ' + re.sub(r'\s+', ' ', last.lower()) + ' '
        if not (last.upper().startswith('SELECT') and ' from ' not in flat and 'information_schema' not in flat):
            bad.append('%s ends: %s' % (name, re.sub(r'\s+', ' ', last)[:120]))
    check('OS-21', files and not bad,
          'each SQL file (%s) ends on a statement that reads no table' % ', '.join(files),
          '; '.join(bad))


def checklist_old():
    """The checklist while the column is missing: limited, and it still works on the strict server."""
    a = adviser()
    pid = M14.seed('AUTOTEST Old Schema Checklist', 'IMRAD', 'journal')
    STATE['checklist_paper'] = pid
    cfg = M14.editor_config(a.get('/app/review_paper.php?id=%s' % pid))
    check('OS-22', cfg and cfg['canEdit'] is False and [l['key'] for l in cfg['lists']] == ['imrad', 'undergrad_thesis', 'capstone']
          and 'database update' in cfg['notice'],
          'the editor is limited to the three lists the old columns can hold, cannot be edited, and says why',
          'config %s' % (json.dumps(cfg)[:200] if cfg else None))
    fp = M14.seed('AUTOTEST Old Schema Feasibility', 'IMRAD', 'project')
    start = (M14.editor_config(a.get('/app/review_paper.php?id=%s' % fp)) or {}).get('template')
    check('OS-23', start == 'imrad', 'a feasibility study starts on a list this server can keep',
          'it started on %s' % start)

    M14.approve(a, pid, 'imrad', M14.IMRAD5, ticked=(0, 2))
    flags = H.q("SELECT imrad_intro, imrad_method, imrad_result FROM paper_checklist WHERE paper_id=%s" % pid)
    check('OS-24', M14.status_of(pid) == 'pending_admin' and flags == [['1', '0', '1']],
          'approving saves the list in the old columns',
          'status %s, flags %s' % (M14.status_of(pid), flags))

    # A paper approved, returned, revised and approved again: a plain INSERT hits the unique key,
    # which PHP 8.1 turns into an exception and this server does not swallow.
    H.q("UPDATE research_papers SET current_status='pending_faculty' WHERE paper_id=%s" % pid)
    s, body = M14.approve(a, pid, 'imrad', M14.IMRAD5, ticked=(1,))
    flags = H.q("SELECT imrad_intro, imrad_method, imrad_result FROM paper_checklist WHERE paper_id=%s" % pid)
    n = H.one("SELECT COUNT(*) FROM paper_checklist WHERE paper_id=%s" % pid)
    check('OS-25', M14.status_of(pid) == 'pending_admin' and n == '1' and flags == [['0', '1', '0']],
          'approving the same paper a second time replaces its checklist, with mysqli throwing as on PHP 8.1+',
          'status %s, rows %s, flags %s, page: %s' % (M14.status_of(pid), n, flags, M14.text_of(body)[:120]))

    p3 = M14.seed('AUTOTEST Old Schema Checklist Custom', 'IMRAD', 'journal')
    s3, body3 = M14.approve(a, p3, 'imrad', M14.IMRAD5 + ['Extra section'])
    check('OS-26', M14.status_of(p3) == 'pending_faculty' and 'database update' in M14.text_of(body3),
          'a custom list is refused in words, and the paper stays with the adviser',
          'status %s, page: %s' % (M14.status_of(p3), M14.text_of(body3)[:140]))
    text = M14.text_of(student().get('/app/student/paper_details.php?id=%s' % pid).split('Review Checklist', 1)[-1][:6000])
    check('OS-27', 'IMRaD Sections' in text and 'Indicated' in text,
          'the author reads it', 'student page said: %s' % text[:200])


def checklist_new():
    """And once the column is there the editor is whole, and the strict server takes a second round."""
    a = adviser()
    pid = M14.seed('AUTOTEST Caught Up Checklist', 'IMRAD', 'journal')
    cfg = M14.editor_config(a.get('/app/review_paper.php?id=%s' % pid))
    check('OS-28', cfg and cfg['canEdit'] is True and len(cfg['lists']) == 5 and not cfg['notice'],
          'the editor is whole: five lists, editable, no notice', 'config %s' % (json.dumps(cfg)[:200] if cfg else None))
    M14.approve(a, pid, 'imrad', M14.IMRAD5 + ['Extra section'], ticked=(0,))
    first = M14.stored(pid)
    H.q("UPDATE research_papers SET current_status='pending_faculty' WHERE paper_id=%s" % pid)
    M14.approve(a, pid, 'feasibility', ['Executive Summary', 'Risks'], ticked=(0, 1))
    second = M14.stored(pid)
    n = H.one("SELECT COUNT(*) FROM paper_checklist WHERE paper_id=%s" % pid)
    check('OS-29', first and first['edited'] is True and second and second['template'] == 'feasibility'
          and [i['label'] for i in second['items']] == ['Executive Summary', 'Risks'] and n == '1',
          'a custom list is kept, and a second approval replaces it, in one row',
          'rows %s, first %s, second %s' % (n, first, second))
    old = STATE.get('checklist_paper')
    text = M14.text_of(student().get('/app/student/paper_details.php?id=%s' % old).split('Review Checklist', 1)[-1][:6000]) if old else ''
    check('OS-30', 'IMRaD Sections' in text and 'Indicated' in text,
          'a checklist recorded while the column was missing still reads after it is added',
          'student page said: %s' % text[:200])


def submissions_new():
    """And once caught up, the documents are kept as written, and a paper can go in both ways."""
    c = student()
    s, j = submit(c, T_NEW, docs=['copyright_doc', 'other_doc'], extra={'other_doc_name': OTHER_NAME})
    pid = paper_id(T_NEW)
    if pid:
        STATE['paper_ids'].append(pid)
    kinds = docs_of(pid) if pid else []
    check('OS-18', s == 200 and j.get('success') and kinds == [('copyright_doc', None), ('other', OTHER_NAME)],
          'the copyright document keeps its own kind and the extra one keeps the name the student gave it',
          'status %s, reply %s, stored %s' % (s, j, kinds))
    a = adviser()
    rv = strip(a.get('/app/review_paper.php?id=%s' % pid)) if pid else ''
    check('OS-19', 'Copyright / IP Document' in rv and OTHER_NAME in rv,
          'the review page shows both by name',
          'review said: %s' % rv[:200])

    s2, j2 = submit(c, T_BOTH, formats=('IMRAD', 'Full Manuscript'))
    pid2 = paper_id(T_BOTH)
    if pid2:
        STATE['paper_ids'].append(pid2)
    formats = sorted(r[0] for r in H.q("SELECT format FROM paper_manuscripts WHERE paper_id=%s" % pid2)) if pid2 else []
    check('OS-20', s2 == 200 and j2.get('success') and formats == ['full', 'imrad'],
          'a paper handed in both ways is one paper with two files',
          'status %s, reply %s, files %s' % (s2, j2, formats))


# ----------------------------------------------------------------- run / clean
STEPS = [('sql_file_shape', sql_file_shape), ('shape', shape), ('pages', pages), ('diagnostics', diagnostics), ('failure_page', failure_page),
         ('checklist_old', checklist_old), ('submissions_old', submissions_old), ('catch_up', catch_up),
         ('checklist_new', checklist_new), ('submissions_new', submissions_new)]


def cleanup():
    """Take back what the submissions put on Drive.

    Always asked, even with no paper left to name: a submission that failed after its
    uploads rolls its files back itself but leaves the AUTOTEST folder it made, and that
    folder is only ever found by the sweep for empty AUTOTEST folders."""
    ids = [r[0] for r in H.q("SELECT paper_id FROM research_papers WHERE title LIKE 'AUTOTEST%'")] if H.DB == SCRATCH else []
    code, out, err = php(PROBE, 'drive_clean', *ids)
    print('  drive clean-up: %s' % (out.strip().splitlines()[-1] if out.strip() else err[-160:]))


def main():
    only = tuple(sys.argv[1:])
    ok = False
    try:
        setup()
        ok = True
        print('\n== MODULE 13: a database behind the code ==')
        for name, fn in STEPS:
            if not only or name in only:
                H.run('OS-' + name, fn)
    finally:
        print('\n-- clearing up -----------------------------------------------')
        try:
            if ok:
                cleanup()
                F.teardown()
        finally:
            stop()
            for f in SESSIONS:
                try:
                    os.remove(f)
                except OSError:
                    pass
            if SCRATCH != LIVE:
                mysql(None, 'DROP DATABASE IF EXISTS %s' % SCRATCH)
                print('  scratch database dropped, server stopped')
    bad = [k for k, v in R.rows.items() if v['verdict'] != 'PASSED']
    print('\n%d cases, %d not passed' % (len(R.rows), len(bad)))
    sys.exit(1 if bad else 0)


if __name__ == '__main__':
    main()
