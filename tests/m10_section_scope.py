# -*- coding: utf-8 -*-
"""MODULE 10: choosing which written sections a paper is submitted with.

A student (or any uploader) may submit the Abstract only, or any combination of
the six written sections. The choice is stored inside imrad_content under the
reserved key _scope and is shown to everyone in the approval chain.

Run on its own:

    python m10_section_scope.py

It builds the same AUTOTEST accounts as the rest of the suite, through the
app's own forms, and takes them down afterwards. Three papers are submitted for
real, which puts files on the real Google Drive: those, and any now-empty
AUTOTEST folder they leave behind, are deleted again by tests/_scope_probe.php
before the accounts go. Mail only ever reaches @example.com addresses.

It is not part of run_all.py on purpose: that run feeds the progress tracker,
whose rows are keyed ID-nnn, and these cases are not in it.
"""
import http.cookiejar, json, os, random, re, subprocess, sys

import harness as H
import fixtures as F
import m4_submission as M4
from harness import R, check

UPLOAD = M4.UPLOAD
ALL = ['abstract', 'introduction', 'methodology', 'results_discussion',
       'conclusion', 'references']
UNIT = os.path.join(H.HERE, '_scope_unit.php')
PROBE = os.path.join(H.HERE, '_scope_probe.php')

T_ABS = 'AUTOTEST Scope Abstract Only'
T_ALL = 'AUTOTEST Scope Default All'
T_PART = 'AUTOTEST Scope Three Sections'
T_LEGACY = 'AUTOTEST Scope Legacy Paper'
T_DRAFT = 'AUTOTEST Scope Draft'


# ------------------------------------------------------------------ helpers
SESSIONS = []        # session files written by minted(), removed again at the end
COORDINATOR_ID = 151


def minted(user_id):
    """A signed-in client for an account whose password is not ours to know.

    The suite's own sign-in as the Research Coordinator stopped working when
    that person changed their password, and it is not this suite's place to
    reset a real account. The session file is written straight to disk, which
    is what signing in does, and removed again afterwards. harness.mint() is
    not used: it rewrites a tracked file (tests/_mint.php) on every call.
    """
    sid = ''.join(random.choice('0123456789abcdefghijklmnopqrstuv') for _ in range(26))
    code = (
        '$env=[];foreach(file("C:/xampp/htdocs/capstone/.env",FILE_IGNORE_NEW_LINES) as $ln)'
        '{if($ln===""||$ln[0]==="#"||strpos($ln,"=")===false)continue;[$k,$v]=explode("=",$ln,2);'
        '$env[trim($k)]=trim($v," \t\\"\x27");}'
        '$c=new mysqli($env["DB_HOST"],$env["DB_USER"],$env["DB_PASS"],$env["DB_NAME"]);'
        '$u=$c->query("SELECT * FROM users WHERE user_id=%d")->fetch_assoc();'
        'if(!$u){fwrite(STDERR,"no such user");exit(1);}'
        'file_put_contents("C:/xampp/tmp/sess_%s","user|".serialize($u)."seen|".serialize(time()));'
    ) % (int(user_id), sid)
    subprocess.run([H.PHP, '-r', code], check=True)
    SESSIONS.append(os.path.join(H.SESS_DIR, 'sess_' + sid))
    c = H.Client('minted %s' % user_id)
    c.jar.set_cookie(http.cookiejar.Cookie(
        0, 'papel_sid', sid, None, False, 'localhost.local', True, False,
        '/', True, False, None, False, None, None, {}))
    c.signed_in = True
    c.sid = sid          # so a browser can be handed the same session
    return c


def student():
    return minted(F.uid_of('student_id', F.STUDENT['student_id']))


def adviser():
    return minted(F.uid_of('faculty_id', F.ADVISER['faculty_id']))


def setup():
    """The accounts, made through the app's own forms, as run_all.setup() does,
    but with every session minted rather than signed into.

    Signing in by script is refused now: reCAPTCHA is configured, and it exists
    precisely so that a script cannot use the sign-in form. The rows themselves
    are real and are created by the app; only the sign-in step is stood in for."""
    print('-- setting up ------------------------------------------------')
    F.teardown(verbose=False)
    F.make_adviser(minted(COORDINATOR_ID))
    a = adviser()
    F.make_student(a, F.STUDENT)
    F.make_student(a, F.SECOND_STUDENT)
    adviser_id = F.uid_of('faculty_id', F.ADVISER['faculty_id'])
    student_id = F.uid_of('student_id', F.STUDENT['student_id'])
    if not (adviser_id and student_id):
        print('  could not create the test accounts; stopping')
        sys.exit(1)
    print('  test adviser %s and student %s created through the consoles' % (adviser_id, student_id))


def php(script, *args):
    p = subprocess.run([H.PHP, script] + [str(a) for a in args], capture_output=True)
    return p.returncode, p.stdout.decode('utf-8', 'replace')


def probe(*args):
    code, out = php(PROBE, *args)
    lines = [l for l in out.strip().splitlines() if l.strip().startswith('{') or l.strip().startswith('[')]
    try:
        return json.loads(lines[-1]) if lines else {}
    except ValueError:
        return {'unreadable': out[-200:]}


def ajax(client, fields):
    """A background call, as the page makes it, answered in JSON."""
    data = dict(fields)
    if '_token' not in data:
        data['_token'] = client.token(UPLOAD)
    s, t, h = client.raw(UPLOAD, data=data, headers={
        'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'})
    try:
        return s, json.loads(t)
    except ValueError:
        return s, {'raw': t[:300]}


def submit(client, title, **extra):
    """A submission through the real handler, posting what the page posts today.

    M4.full_submission is not used: it was written before a paper could be
    handed in as IMRAD and as Full Manuscript, and posts the manuscript type as
    one word where the page now posts a group, and the file under its old name.
    """
    fields = {
        'action': 'upload_paper', 'paper_type': 'undergrad_thesis', 'research_type': 'quantitative',
        'manuscript_type[]': ['Full Manuscript'],
        'title': title, 'authors': F.STUDENT['full_name'],
        'program_category': F.STUDENT['program'],
        'research_date': '2026-03-15', 'year': '2026',
        'keywords': 'autotest, automation, suite, testing, repository',
    }
    for k in ALL:
        fields[k] = '<p>AUTOTEST %s section written for the automated suite.</p>' % k
    fields.update(extra)
    files = {'pdf_full': ('autotest.pdf', M4.pdf_bytes(), 'application/pdf')}
    s, body = client.post_files(UPLOAD, fields, files, token_from=UPLOAD)
    try:
        return s, json.loads(body)
    except ValueError:
        return s, {'raw': body[:300]}


def paper_id(title):
    return H.one("SELECT paper_id FROM research_papers WHERE title=%s ORDER BY paper_id DESC LIMIT 1"
                 % H.esc(title))


def strip(html):
    """The words on a page: markup, styles and scripts removed."""
    html = re.sub(r'(?is)<(style|script)\b.*?</\1>', ' ', html)
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', html))


def blank_sections(keys):
    return dict((k, '') for k in keys)


# -------------------------------------------------------------------- tests
def unit():
    code, out = php(UNIT)
    m = re.search(r'SUMMARY passed=(\d+) failed=(\d+)', out)
    fails = [l for l in out.splitlines() if l.startswith('FAIL')]
    check('SC-01', code == 0 and m and m.group(2) == '0',
          'the helper unit tests all pass (%s cases)' % (m.group(1) if m else '?'),
          'unit failures: %s' % '; '.join(fails[:3]), out[-200:])


def page_markup():
    c = student()
    html = c.get(UPLOAD)
    needs = ['id="sectionScope" name="section_scope"', 'id="btnChangeScope"',
             'id="reviewSections"', 'id="scopeBar"', 'window.papelChoose',
             'class="papel-dialog-backdrop"']
    missing = [n for n in needs if n not in html]
    check('SC-02', not missing, 'the upload page carries the field, the Change button, '
          'the Step 4 row and the chooser', 'missing from the page: %s' % missing)
    check('SC-03', 'Every section is required.' not in html,
          'the old "every section is required" wording is gone',
          'the page still tells the student every section is required')


def drafts():
    c = student()
    base = {'action': 'save_draft', 'title': T_DRAFT, 'authors': F.STUDENT['full_name'],
            'program_category': F.STUDENT['program'], 'paper_type': 'undergrad_thesis', 'year': '2026'}

    f = dict(base, section_scope='abstract',
             abstract='<p>AUTOTEST abstract.</p>', introduction='<p>AUTOTEST typed before narrowing.</p>')
    s, j = ajax(c, f)
    did = j.get('draft_id')
    p = probe('paper', did) if did else {}
    check('SC-04', s == 200 and did and p.get('scope') == ['abstract'],
          'a draft saved with the abstract only records the choice',
          'status %s, reply %s, scope %s' % (s, j, p.get('scope')))
    check('SC-05', p.get('sections', {}).get('introduction', 0) > 0,
          'the draft keeps text typed in a section outside the choice, so widening it again loses nothing',
          'the typed introduction was dropped from the draft: %s' % p.get('sections'))

    s, j2 = ajax(c, dict(base, title=T_DRAFT + ' none', abstract='<p>AUTOTEST.</p>'))
    p2 = probe('paper', j2.get('draft_id')) if j2.get('draft_id') else {}
    check('SC-06', j2.get('success') and p2.get('has_key') is False and p2.get('info_kind') == 'legacy',
          'a draft saved before any choice records none, so the page asks when it is reopened',
          'scope recorded as %s' % p2)

    s, j3 = ajax(c, dict(base, title=T_DRAFT + ' tampered',
                         section_scope='references,bogus,<script>alert(1)</script>,,Abstract',
                         abstract='<p>AUTOTEST.</p>'))
    p3 = probe('paper', j3.get('draft_id')) if j3.get('draft_id') else {}
    check('SC-07', p3.get('scope') == ['abstract', 'references'],
          'a tampered choice is cleaned: unknown keys dropped, abstract forced in, order restored',
          'stored %s' % p3.get('scope'))

    s, j4 = ajax(c, dict(base, draft_id=str(did), section_scope='conclusion', abstract='<p>AUTOTEST.</p>'))
    p4 = probe('paper', did)
    n = H.one("SELECT COUNT(*) FROM research_papers WHERE title=%s" % H.esc(T_DRAFT))
    check('SC-08', j4.get('draft_id') == did and p4.get('scope') == ['abstract', 'conclusion'] and n == '1',
          'saving again updates the same draft, in place, with the new choice',
          'id %s -> %s, scope %s, rows %s' % (did, j4.get('draft_id'), p4.get('scope'), n))

    html = c.get(UPLOAD + '?draft=%s' % did)
    check('SC-09', '"section_scope":"abstract,conclusion"' in html and '"_scope"' not in html,
          'reopening the draft hands the choice back as the form field, not as a stored key',
          'the resume payload does not carry the field cleanly')


def validation():
    c = student()
    short = 'one, two'           # fewer than five: the handler stops here if it got past the sections

    def reached_keywords(title, **extra):
        """True when the sections check was passed and the keyword check was hit."""
        s, j = submit(c, title, keywords=short, **extra)
        # The keyword check comes straight after the sections check and says so in words
        # now (it used to be a generic 500), so reaching it is the signal that the sections passed.
        return s, j, (s == 422 and 'at least 5 keywords' in (j.get('message') or '') and not paper_id(title))

    s, j, ok = reached_keywords('AUTOTEST Scope V1', section_scope='abstract',
                                **blank_sections(ALL[1:]))
    check('SC-10', ok, 'abstract only, every other section empty: the sections check is passed',
          'refused at the sections: HTTP %s %s' % (s, j.get('message')))

    s, j, ok = reached_keywords('AUTOTEST Scope V2', section_scope='abstract')
    check('SC-11', ok, 'abstract only, the other five posted with text anyway: ignored, not an error',
          'HTTP %s %s' % (s, j.get('message')))

    s, j = submit(c, 'AUTOTEST Scope V3', keywords=short, methodology='')
    check('SC-12', s == 422 and 'Methodology' in (j.get('message') or ''),
          'no choice posted (a page opened before this) keeps meaning every section: a blank one is '
          'refused, and in words the student can read ("%s")' % j.get('message'),
          'HTTP %s %s' % (s, j))

    s, j = submit(c, 'AUTOTEST Scope V4', keywords=short, section_scope='abstract,introduction', introduction='')
    check('SC-13', s == 422 and 'Introduction' in (j.get('message') or ''),
          'a section inside the choice must be written ("%s")' % j.get('message'),
          'HTTP %s %s' % (s, j))

    s, j = submit(c, 'AUTOTEST Scope V5', keywords=short, section_scope='abstract', abstract='')
    check('SC-14', s == 422 and 'Abstract' in (j.get('message') or ''),
          'the abstract is always required, however the choice is made', 'HTTP %s %s' % (s, j))

    s, j = submit(c, 'AUTOTEST Scope V6', keywords=short, section_scope='references', abstract='')
    check('SC-15', s == 422 and 'Abstract' in (j.get('message') or ''),
          'a choice that leaves the abstract out is repaired, so the abstract is still asked for',
          'HTTP %s %s' % (s, j))

    s, j, ok = reached_keywords('AUTOTEST Scope V7', section_scope='not-a-section')
    check('SC-16', ok, 'a choice made of nothing recognisable falls back to every section',
          'HTTP %s %s' % (s, j.get('message')))
    leaked = H.one("SELECT COUNT(*) FROM research_papers WHERE title LIKE 'AUTOTEST Scope V%'")
    check('SC-17', leaked == '0', 'none of the refused submissions left a paper behind',
          '%s papers were stored' % leaked)


def submissions():
    """Three real submissions. Returns (abstract-only id, default id, three-section id)."""
    c = student()
    a = adviser()
    adviser_id = F.uid_of('faculty_id', F.ADVISER['faculty_id'])

    # --- abstract only, submitted from a draft that had text in another section
    s, d = ajax(c, {'action': 'save_draft', 'title': T_ABS, 'authors': F.STUDENT['full_name'],
                    'program_category': F.STUDENT['program'], 'paper_type': 'undergrad_thesis',
                    'section_scope': 'abstract', 'abstract': '<p>AUTOTEST abstract.</p>',
                    'introduction': '<p>typed before narrowing</p>'})
    did = d.get('draft_id')
    s, j = submit(c, T_ABS, section_scope='abstract', draft_id=str(did))
    pid = paper_id(T_ABS)
    drive_ok = bool(j.get('success'))
    if not drive_ok:
        check('SC-18', False, '', 'the abstract-only submission did not go through: HTTP %s %s' % (s, j))
        return None, None, None
    p = probe('paper', pid)
    check('SC-18', str(pid) == str(did) and p.get('status') == 'pending_faculty',
          'an abstract-only paper is filed and goes to the Research Adviser, and it is the draft '
          'it grew out of (id %s)' % pid,
          'id %s vs draft %s, status %s' % (pid, did, p.get('status')))
    check('SC-19', p.get('scope') == ['abstract'] and p['info_kind'] == 'abstract',
          'the choice is stored with the paper', 'scope %s kind %s' % (p.get('scope'), p.get('info_kind')))
    others = dict((k, v) for k, v in p['sections'].items() if k != 'abstract')
    check('SC-20', p['sections']['abstract'] > 0 and not any(others.values()),
          'only the abstract is filed: the text posted for the other five, and the text the '
          'draft held, is not stored', 'section lengths %s' % p['sections'])
    check('SC-21', p['abstract'].startswith('AUTOTEST abstract'),
          'the plain-text abstract column still carries the abstract', p['abstract'])

    notes = probe('notes', adviser_id)
    check('SC-22', any('The author chose to submit the Abstract only.' in m for m in notes),
          'the Research Adviser is told in the notification',
          'notifications: %s' % [m[:90] for m in notes])

    # --- no choice posted at all: every section, recorded explicitly
    s, j = submit(c, T_ALL)
    pid_all = paper_id(T_ALL)
    p = probe('paper', pid_all) if pid_all else {}
    check('SC-23', j.get('success') and p.get('scope') == ALL and p.get('info_kind') == 'full',
          'a submission with no choice (an older page) is filed as every section, and says so',
          'HTTP %s %s scope %s' % (s, j.get('message'), p.get('scope')))
    check('SC-24', all(v > 0 for v in p.get('sections', {}).values()),
          'and all six sections are stored', 'lengths %s' % p.get('sections'))
    notes = probe('notes', adviser_id)
    check('SC-25', not any(T_ALL in m and 'The author chose' in m for m in notes),
          'a complete paper adds nothing to the notification', [m[:120] for m in notes])

    # --- three sections
    s, j = submit(c, T_PART, section_scope='references,introduction')
    pid_part = paper_id(T_PART)
    p = probe('paper', pid_part) if pid_part else {}
    check('SC-26', j.get('success') and p.get('scope') == ['abstract', 'introduction', 'references']
          and p.get('info_kind') == 'partial',
          'a three-section paper is stored in the form order, abstract included',
          'HTTP %s %s scope %s' % (s, j.get('message'), p.get('scope')))
    notes = probe('notes', adviser_id)
    check('SC-27', any('The author chose to submit 3 of 6 sections: Abstract, Introduction and References.' in m
                       for m in notes),
          'its notification names the sections', [m[:140] for m in notes])
    return pid, pid_all, pid_part


def surfaces(pid, pid_all, pid_part):
    a = adviser()
    c = student()

    desk = a.get('/app/faculty/faculty_review_dashboard.php')
    chips = len(re.findall(r'class="scope-chip"', desk))
    check('SC-28', 'Abstract only' in desk and '3 of 6 sections' in desk and chips == 2,
          "the adviser's desk flags the two partial papers and not the complete one (2 chips)",
          '%s chips; Abstract only=%s, 3 of 6=%s' % (chips, 'Abstract only' in desk, '3 of 6 sections' in desk))

    rev = a.get('/app/review_paper.php?id=%s' % pid)
    text = strip(rev)
    check('SC-29', 'Abstract only.' in text and 'were left out on purpose' in text
          and 'their absence is not an omission' in text,
          'the review page says the gap is deliberate', 'notice missing from the review page')
    # The approve lead is the sentence in the confirmation the Approve button
    # opens, so it is in the page's script and is read from the raw page.
    check('SC-30', 'The author chose to submit the Abstract only, so the published page will show just that' in rev,
          'and the approve lead says what publishing will show', 'approve lead is unchanged')

    rev_all = strip(a.get('/app/review_paper.php?id=%s' % pid_all))
    check('SC-31', 'left out on purpose' not in rev_all and 'will show just that' not in rev_all,
          'a complete paper gets no notice on the review page', 'a complete paper is flagged')

    own = c.get('/app/student/paper_details.php?id=%s' % pid)
    check('SC-32', 'You chose to submit the Abstract only.' in strip(own),
          'the author sees their own choice on their record', 'not shown on the author page')

    dash = c.get('/app/student/student_dashboard.php?tab=process')
    check('SC-33', len(re.findall(r'class="scope-chip"', dash)) == 2 and 'Abstract only' in dash,
          "the student's dashboard shows the chip on the two partial papers", 'chips: %d' % len(re.findall(r'class="scope-chip"', dash)))

    # --- the Coordinator, with the paper waiting on them
    H.q("UPDATE research_papers SET current_status='pending_admin' WHERE paper_id IN (%s,%s)" % (pid, pid_part))
    co = minted(COORDINATOR_ID)
    cdesk = co.get('/app/admin/admin_review_dashboard.php')
    check('SC-34', 'Abstract only' in cdesk and 'class="scope-chip"' in cdesk,
          "the Research Coordinator's desk carries the chip too", 'no chip on the coordinator desk')
    crev = co.get('/app/review_paper.php?id=%s' % pid)       # raw: the lead is in the confirmation's script
    check('SC-35', 'This publishes the paper' in crev
          and 'The author chose to submit the Abstract only, so the published page will show just that' in crev,
          'the Coordinator is told what Approve and publish will put on the page',
          'the Coordinator review page does not say it')

    # --- published, and read by someone else
    H.q("UPDATE research_papers SET current_status='approved' WHERE paper_id IN (%s,%s,%s)" % (pid, pid_all, pid_part))
    pub = strip(c.get('/archive/view_paper.php?id=%s' % pid))
    check('SC-36', 'The author chose to publish only the Abstract of this paper.' in pub,
          'the public page tells a reader the paper was published with the abstract only',
          'the public page is silent')
    pub3 = strip(c.get('/archive/view_paper.php?id=%s' % pid_part))
    check('SC-37', 'only these sections of this paper: Abstract, Introduction and References.' in pub3,
          'and names the sections for a partial one', 'the three-section note is missing')
    pub_all = strip(c.get('/archive/view_paper.php?id=%s' % pid_all))
    check('SC-38', 'chose to publish only' not in pub_all, 'a complete paper carries no such note',
          'a complete paper is annotated')
    pubhtml = c.get('/archive/view_paper.php?id=%s' % pid)
    shown = re.findall(r'id="pd-sub-([a-z_]+)"', pubhtml)
    check('SC-39', shown == ['abstract'], 'the public page lists only the abstract as a section',
          'sections shown: %s' % shown)


def archive_roundtrip(pid):
    adviser_id = F.uid_of('faculty_id', F.ADVISER['faculty_id'])
    r1 = probe('archive', pid, adviser_id)
    p1 = probe('paper', pid)
    r2 = probe('restore', pid)
    p2 = probe('paper', pid)
    check('SC-40', r1.get('ok') and p1.get('table') == 'papers_archive' and p1.get('scope') == ['abstract']
          and r2.get('ok') and p2.get('table') == 'research_papers' and p2.get('scope') == ['abstract'],
          'archiving and restoring keep the choice, with no change to the archive code or its tables',
          'archive %s %s %s | restore %s %s %s' % (r1, p1.get('table'), p1.get('scope'),
                                                    r2, p2.get('table'), p2.get('scope')))


def legacy():
    a = adviser()
    pid = F.seed_paper(status='pending_faculty', title=T_LEGACY)
    H.q("UPDATE research_papers SET imrad_content=%s WHERE paper_id=%s" % (
        H.esc('{"abstract":"<p>An older abstract.</p>","introduction":"<p>An older introduction.</p>"}'), pid))
    p = probe('paper', pid)
    check('SC-41', p.get('has_key') is False and p.get('info_kind') == 'legacy',
          'a paper filed before the choice existed has no record of one', p)
    text = strip(a.get('/app/review_paper.php?id=%s' % pid))
    # Only the Written Sections card is looked at. The page header carries the
    # adviser's notifications, and by now one of those legitimately says an
    # earlier paper's author chose to submit fewer sections.
    card = text.split('Written Sections', 1)[1][:2500] if 'Written Sections' in text else ''
    hit = next((m for m in (re.search(re.escape(p_), card) for p_ in ('left out on purpose', 'chose to submit')) if m), None)
    check('SC-42', card != '' and hit is None,
          'and nothing is claimed for it on the review page',
          'a legacy paper is annotated: ...%s...' % (card[max(0, hit.start() - 80):hit.end() + 80] if hit else '(no card found)'))
    desk = a.get('/app/faculty/faculty_review_dashboard.php')
    row = desk.split(T_LEGACY)[1][:1500] if T_LEGACY in desk else ''
    check('SC-43', row != '' and 'scope-chip' not in row, 'or on its card on the desk',
          'a legacy paper carries a chip')
    # An abstract-only paper with the stored key and nothing else, as the old readers see it.
    html = a.get('/app/review_paper.php?id=%s' % pid)
    check('SC-44', 'An older introduction.' in strip(html), 'its sections are shown exactly as before',
          'a legacy paper lost a section')


def cleanup():
    ids = [r[0] for r in H.q("SELECT paper_id FROM research_papers WHERE title LIKE 'AUTOTEST%'")]
    out = probe('drive_clean', *ids) if ids else probe('drive_clean')
    print('  drive clean-up: %s' % out)


def run(only=()):
    """Every step, or just the named ones (unit, page_markup, drafts, validation,
    submissions, legacy). Naming only the cheap ones avoids three real Drive
    uploads while a test is being worked on."""
    def want(name):
        return not only or name in only

    print('\n== MODULE 10: choosing which sections to submit ==')
    for fn in (unit, page_markup, drafts, validation):
        if want(fn.__name__):
            H.run('SC-' + fn.__name__, fn)
    if want('submissions'):
        pid = pid_all = pid_part = None
        try:
            pid, pid_all, pid_part = submissions()
        except Exception as e:
            R.record('SC-submissions', 'FAILED', 'test error: %s' % str(e)[:120])
        if pid:
            H.run('SC-surfaces', lambda: surfaces(pid, pid_all, pid_part))
            H.run('SC-archive', lambda: archive_roundtrip(pid))
    if want('legacy'):
        H.run('SC-legacy', legacy)


def main():
    setup()
    try:
        run(tuple(sys.argv[1:]))
    finally:
        print('\n-- clearing up -----------------------------------------------')
        try:
            cleanup()
        finally:
            F.teardown()
            for f in SESSIONS:
                try:
                    os.remove(f)
                except OSError:
                    pass
    bad = [k for k, v in R.rows.items() if v['verdict'] != 'PASSED']
    print('\n%d cases, %d not passed' % (len(R.rows), len(bad)))
    sys.exit(1 if bad else 0)


if __name__ == '__main__':
    main()
