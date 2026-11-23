# -*- coding: utf-8 -*-
"""MODULE 16: the same PDF, or the same title, cannot be filed twice.

The upload page compares what is IN a PDF (a SHA-256, kept in
paper_manuscripts.file_hash), never what it is called, and refuses a title that is
already on a submitted paper. The paper being resubmitted is never a duplicate of
itself: a returned paper keeps its rows, and its author attaches the same file again.

    python m16_duplicate_pdf.py              every step
    python m16_duplicate_pdf.py unit refusals  only the named steps (unit, refusals,
                                             submission); the last is the only one that
                                             touches Google Drive (two uploads)

It builds the AUTOTEST accounts through the app's own forms (tests/m10's setup), and
removes what it filed from Drive, its seeded rows and the accounts afterwards. The
refusals are answered before anything is sent to Drive, so `refusals` costs nothing.
Mail only reaches @example.com.
"""
import hashlib, json, os, re, sys

import harness as H
import fixtures as F
import m4_submission as M4
import m10_section_scope as M10
from harness import R, check

UPLOAD = M10.UPLOAD
UNIT = os.path.join(H.HERE, '_duplicate_unit.php')
T_THEIRS = 'AUTOTEST Dup Theirs'
T_MINE = 'AUTOTEST Dup Mine'
T_NEW = 'AUTOTEST Dup Filed Once'
STATE = {}


def sha(b):
    return hashlib.sha256(b).hexdigest()


def submit(client, title, files, formats=('Full Manuscript',), **extra):
    """A submission through the real handler. files is {field: (name, bytes)}."""
    fields = {
        'action': 'upload_paper', 'paper_type': 'undergrad_thesis', 'research_type': 'quantitative',
        'manuscript_type[]': list(formats),
        'title': title, 'authors': F.STUDENT['full_name'],
        'program_category': F.STUDENT['program'],
        'research_date': '2026-03-15', 'year': '2026',
        'keywords': 'autotest, automation, suite, testing, repository',
    }
    for k in M10.ALL:
        fields[k] = '<p>AUTOTEST %s section written for the automated suite.</p>' % k
    fields.update(extra)
    sent = {k: (n, b, 'application/pdf') for k, (n, b) in files.items()}
    s, body = client.post_files(UPLOAD, fields, sent, token_from=UPLOAD)
    try:
        return s, json.loads(body)
    except ValueError:
        return s, {'raw': body[:300]}


def row(title):
    r = H.q("SELECT paper_id, current_status FROM research_papers WHERE title=%s ORDER BY paper_id DESC LIMIT 1"
            % H.esc(title))
    return r[0] if r else None


def seed(title, user_id, status, data):
    """A paper with one manuscript file whose fingerprint is that of `data`."""
    H.q("INSERT INTO research_papers (title,author_names,year,abstract,keywords,file_path,file_size,uploaded_by,"
        "current_status,paper_type,gdrive_file_id) VALUES (%s,'AUTOTEST',2026,'x','a,b,c,d,e','',0,%d,%s,"
        "'undergrad_thesis',NULL)" % (H.esc(title), int(user_id), H.esc(status)))
    pid = H.one("SELECT paper_id FROM research_papers WHERE title=%s ORDER BY paper_id DESC LIMIT 1" % H.esc(title))
    H.q("INSERT INTO paper_manuscripts (paper_id, format, file_path, gdrive_file_id, file_size, file_hash) "
        "VALUES (%s,'full','',NULL,%d,%s)" % (pid, len(data), H.esc(sha(data))))
    return pid


def unseed(*pids):
    for p in pids:
        if p:
            H.q("DELETE FROM paper_manuscripts WHERE paper_id=%s" % p)
            H.q("DELETE FROM research_papers WHERE paper_id=%s" % p)


# -------------------------------------------------------------------- tests
def unit():
    code, out = M10.php(UNIT)
    check('DP-01', code == 0 and out.strip().startswith('OK:'),
          'the rules pass their cases (%s)' % out.strip().splitlines()[-1:],
          'output: %s' % out[-400:])


def refusals():
    me, other = M10.student(), M10.minted(F.uid_of('student_id', F.SECOND_STUDENT['student_id']))
    my_id = F.uid_of('student_id', F.STUDENT['student_id'])
    other_id = F.uid_of('student_id', F.SECOND_STUDENT['student_id'])
    pdf_a, pdf_b = M4.pdf_bytes(), M4.pdf_bytes()
    pa = seed(T_THEIRS, other_id, 'approved', pdf_a)
    pb = seed(T_MINE, my_id, 'pending_faculty', pdf_b)
    try:
        s, j = submit(me, 'AUTOTEST Dup R1', {'pdf_full': ('whatever.pdf', pdf_a)})
        msg = j.get('message') or ''
        check('DP-02', not j.get('success') and 'already been submitted' in msg
              and 'Theirs' not in msg and row('AUTOTEST Dup R1') is None,
              "somebody else's PDF is refused, without saying whose it is or what it is called", 'HTTP %s %s' % (s, j))

        s, j = submit(me, 'AUTOTEST Dup R2', {'pdf_full': ('a-completely-different-name.pdf', pdf_a)})
        check('DP-03', not j.get('success') and 'already been submitted' in (j.get('message') or ''),
              'the same bytes under another file name are still the same PDF',
              'HTTP %s %s' % (s, j))

        s, j = submit(me, 'AUTOTEST Dup R3', {'pdf_full': ('x.pdf', pdf_b)})
        msg = j.get('message') or ''
        check('DP-04', not j.get('success') and 'already filed under your paper' in msg and T_MINE in msg,
              'your own PDF on another paper is refused, and names that paper', 'HTTP %s %s' % (s, j))

        s, j = submit(me, T_THEIRS.lower(), {'pdf_full': ('x.pdf', M4.pdf_bytes())})
        msg = j.get('message') or ''
        check('DP-05', not j.get('success') and 'title is already on file' in msg and 'Theirs' not in msg,
              "somebody else's title is refused whatever its case, and not described", 'HTTP %s %s' % (s, j))

        one = M4.pdf_bytes()
        s, j = submit(me, 'AUTOTEST Dup R4', {'pdf_imrad': ('a.pdf', one), 'pdf_full': ('b.pdf', one)},
                      formats=('IMRAD', 'Full Manuscript'))
        msg = j.get('message') or ''
        check('DP-06', not j.get('success') and 'same PDF' in msg and 'IMRAD' in msg and 'Full Manuscript' in msg
              and row('AUTOTEST Dup R4') is None,
              'one file in both manuscript boxes is refused, naming both', 'HTTP %s %s' % (s, j))
    finally:
        unseed(pa, pb)


def submission():
    me = M10.student()
    other = M10.minted(F.uid_of('student_id', F.SECOND_STUDENT['student_id']))
    pdf = M4.pdf_bytes()
    s, j = submit(me, T_NEW, {'pdf_full': ('first.pdf', pdf)})
    r = row(T_NEW)
    if not (j.get('success') and r):
        check('DP-07', False, '', 'the first submission did not go through: HTTP %s %s' % (s, j))
        return
    pid = r[0]
    stored = H.one("SELECT file_hash FROM paper_manuscripts WHERE paper_id=%s" % pid)
    check('DP-07', stored == sha(pdf),
          'a filed paper keeps the SHA-256 of its PDF (%s...)' % (stored or '')[:12],
          'stored %s, expected %s' % (stored, sha(pdf)))

    s, j = submit(me, 'AUTOTEST Dup Again', {'pdf_full': ('second.pdf', pdf)})
    msg = j.get('message') or ''
    check('DP-08', not j.get('success') and 'already filed under your paper' in msg and T_NEW in msg
          and row('AUTOTEST Dup Again') is None,
          'the same file again, under a new title, is refused and nothing is filed', 'HTTP %s %s' % (s, j))

    s, j = submit(other, 'AUTOTEST Dup Copycat', {'pdf_full': ('copy.pdf', pdf)})
    msg = j.get('message') or ''
    check('DP-09', not j.get('success') and 'already been submitted' in msg and T_NEW not in msg
          and row('AUTOTEST Dup Copycat') is None,
          "another student's attempt is refused without naming the paper", 'HTTP %s %s' % (s, j))

    s, j = submit(me, T_NEW.upper(), {'pdf_full': ('new.pdf', M4.pdf_bytes())})
    check('DP-10', not j.get('success') and 'already submitted a paper with this title' in (j.get('message') or ''),
          'a new file under the same title (any case) is refused', 'HTTP %s %s' % (s, j))

    # Returned by the adviser: back in drafts, with its history, and its rows kept. The author
    # fixes the typed sections and attaches the same PDF again, which must not be called a duplicate.
    H.q("UPDATE research_papers SET current_status='draft' WHERE paper_id=%s" % pid)
    H.q("INSERT INTO approval_workflow (paper_id, reviewer_id, review_level, status, feedback) VALUES (%s,%s,'faculty','declined','AUTOTEST')"
        % (pid, F.uid_of('faculty_id', F.ADVISER['faculty_id'])))
    s, j = submit(me, T_NEW, {'pdf_full': ('again.pdf', pdf)}, draft_id=str(pid))
    r = row(T_NEW)
    n = H.one("SELECT COUNT(*) FROM paper_manuscripts WHERE paper_id=%s" % pid)
    stored = H.one("SELECT file_hash FROM paper_manuscripts WHERE paper_id=%s" % pid)
    check('DP-11', j.get('success') and r and r[0] == pid and r[1] == 'pending_faculty' and n == '1'
          and stored == sha(pdf),
          'a returned paper is resubmitted with the same PDF: not a duplicate of itself, one row, same paper',
          'HTTP %s %s; row %s; rows %s' % (s, j, r, n))


STEPS = [('unit', unit), ('refusals', refusals), ('submission', submission)]


def main():
    only = tuple(sys.argv[1:])
    M10.setup()
    try:
        print('\n== MODULE 16: the same PDF cannot be filed twice ==')
        for name, fn in STEPS:
            if not only or name in only:
                H.run('DP-' + name, fn)
    finally:
        print('\n-- clearing up -----------------------------------------------')
        try:
            M10.cleanup()
        finally:
            for t in (T_THEIRS, T_MINE):
                p = H.one("SELECT paper_id FROM research_papers WHERE title=%s" % H.esc(t))
                unseed(p)
            F.teardown()
            for f in M10.SESSIONS:
                try:
                    os.remove(f)
                except OSError:
                    pass
    bad = [k for k, v in R.rows.items() if v['verdict'] != 'PASSED']
    print('\n%d cases, %d not passed' % (len(R.rows), len(bad)))
    sys.exit(1 if bad else 0)


if __name__ == '__main__':
    main()
