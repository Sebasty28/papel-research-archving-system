# -*- coding: utf-8 -*-
"""MODULE 11: what a failed submission tells the person who hit it.

On staging a student saw "Upload failed: Upload failed. Please try again." and
nobody, including the person testing, could say why. The handler answered every
failure that was not written for the reader with that one line, the dialog did
not show the reference that leads to the log, and a step after the paper was
already filed could report a failure for a paper that was in fact filed.

Run on its own:

    python m11_submit_failures.py            # everything
    python m11_submit_failures.py messages   # the cheap steps only

Real failures are induced, not mocked: Google Drive is set aside (its token, in the
database and in the file) for a few seconds, and the notifications table is renamed
for one request. Both are put back in a finally, and checked. One real submission
reaches Drive; it and any AUTOTEST folder are deleted again before the accounts go.
"""
import os, re, shutil, subprocess, sys

import harness as H
import fixtures as F
import m10_section_scope as M
from harness import R, check

UNIT = os.path.join(H.HERE, '_failure_unit.php')
TOKEN_FILE = r'C:\xampp\htdocs\capstone\config\gdrive_token.json'
ASIDE_FILE = TOKEN_FILE + '.aside'
APACHE_LOG = r'C:\xampp\apache\logs\error.log'


def unit():
    code, out = M.php(UNIT)
    m = re.search(r'SUMMARY passed=(\d+) failed=(\d+)', out)
    fails = [l for l in out.splitlines() if l.startswith('FAIL')]
    check('SF-01', code == 0 and m and m.group(2) == '0',
          'the error helper adds the cause on a copy of the site and never on production (%s cases)'
          % (m.group(1) if m else '?'), 'unit failures: %s' % '; '.join(fails[:3]), out[-200:])


def messages():
    """Failures a person can act on used to read "Upload failed" and now say what to fix."""
    c = M.student()
    s, j = M.submit(c, 'AUTOTEST Fail Keywords', keywords='one, two')
    check('SF-02', s == 422 and 'at least 5 keywords' in (j.get('message') or ''),
          'too few keywords is explained ("%s")' % j.get('message'), 'HTTP %s %s' % (s, j))
    s, j = M.submit(c, 'AUTOTEST Fail Date', research_date='2026-02-31')
    check('SF-03', s == 422 and 'valid date' in (j.get('message') or ''),
          'an impossible date is explained ("%s")' % j.get('message'), 'HTTP %s %s' % (s, j))
    s, j = M.submit(c, 'AUTOTEST Fail Range', research_date='1850-01-01')
    check('SF-04', s == 422 and '1900 and 2100' in (j.get('message') or ''),
          'a date out of range is explained', 'HTTP %s %s' % (s, j))
    check('SF-05', 'reference' in j and 'detail' not in j,
          'a message written for the reader carries a reference and no detail',
          'keys: %s' % sorted(j.keys()))
    n = H.one("SELECT COUNT(*) FROM research_papers WHERE title LIKE 'AUTOTEST Fail%'")
    check('SF-06', n == '0', 'none of them stored a paper', '%s stored' % n)


def drive_disconnected():
    """The likeliest cause on staging, induced for real."""
    c = M.student()
    moved_db = False
    moved_file = False
    try:
        H.q("UPDATE system_settings SET setting_key='gdrive_system_token__aside' WHERE setting_key='gdrive_system_token'")
        moved_db = True
        if os.path.isfile(TOKEN_FILE):
            shutil.move(TOKEN_FILE, ASIDE_FILE)
            moved_file = True
        s, j = M.submit(c, 'AUTOTEST Fail NoDrive')
    finally:
        if moved_file and os.path.isfile(ASIDE_FILE):
            shutil.move(ASIDE_FILE, TOKEN_FILE)
        if moved_db:
            H.q("UPDATE system_settings SET setting_key='gdrive_system_token' WHERE setting_key='gdrive_system_token__aside'")
    back = H.one("SELECT CHAR_LENGTH(setting_value) FROM system_settings WHERE setting_key='gdrive_system_token'")
    check('SF-07', back == '512' and os.path.isfile(TOKEN_FILE),
          'the Drive token is back in both places afterwards', 'token length %s, file present %s' % (back, os.path.isfile(TOKEN_FILE)))
    msg = j.get('message') or ''
    check('SF-08', s == 422 and 'Google Drive has not been connected' in msg and 'Nothing was submitted' in msg,
          'with Drive not connected the student is told why, and that nothing was submitted ("%s...")' % msg[:70],
          'HTTP %s %s' % (s, j))
    check('SF-09', not M.paper_id('AUTOTEST Fail NoDrive'), 'and no paper was stored', 'a paper row exists')
    check('SF-10', 'detail' not in j, 'a message written for the reader needs no detail', j)


def after_filing():
    """A failure after the paper is filed must not be reported as a failed upload."""
    c = M.student()
    title = 'AUTOTEST Fail After Filing'
    before = H.one("SELECT COUNT(*) FROM notifications")
    # The token first: with the table gone even the page that issues it fails, since
    # every page's header reads the notifications. It belongs to the session, so one
    # fetched now is good for the submit.
    token = c.token(M.UPLOAD)
    marker = os.path.getsize(APACHE_LOG)
    renamed = False
    try:
        H.q("RENAME TABLE notifications TO notifications__aside")
        renamed = True
        s, j = M.submit(c, title, _token=token)
    finally:
        if renamed:
            H.q("RENAME TABLE notifications__aside TO notifications")
    after = H.one("SELECT COUNT(*) FROM notifications")
    check('SF-11', after == before, 'the notifications table is back, with what it held (%s rows)' % after,
          'before %s, after %s' % (before, after))
    pid = M.paper_id(title)
    p = M.probe('paper', pid) if pid else {}
    check('SF-12', bool(pid) and p.get('status') == 'pending_faculty',
          'the paper was filed', 'no paper row: HTTP %s %s' % (s, j))
    check('SF-13', j.get('success') is True and bool(j.get('redirect')),
          'and the student is told so, not "Upload failed", although the step after it threw',
          'HTTP %s %s' % (s, j))
    with open(APACHE_LOG, 'rb') as f:
        f.seek(marker)
        newlog = f.read().decode('utf-8', 'replace')
    check('SF-14', re.search(r'\[ERR-[0-9A-F]{8}\].*bind_param', newlog) is not None,
          'the late failure is still logged, against a reference', 'nothing logged: %s' % newlog[-200:])
    # A second press of Submit would have filed it twice; it is one row.
    n = H.one("SELECT COUNT(*) FROM research_papers WHERE title=%s" % H.esc(title))
    check('SF-15', n == '1', 'exactly one paper exists', '%s rows' % n)


def run(only=()):
    def want(name):
        return not only or name in only

    print('\n== MODULE 11: what a failed submission tells the person ==')
    for fn in (unit, messages, drive_disconnected, after_filing):
        if want(fn.__name__):
            H.run('SF-' + fn.__name__, fn)


def main():
    M.setup()
    try:
        run(tuple(sys.argv[1:]))
    finally:
        print('\n-- clearing up -----------------------------------------------')
        # The two things set aside, whatever happened above.
        for sql in ("UPDATE system_settings SET setting_key='gdrive_system_token' WHERE setting_key='gdrive_system_token__aside'",):
            try:
                H.q(sql)
            except Exception:
                pass
        if os.path.isfile(ASIDE_FILE) and not os.path.isfile(TOKEN_FILE):
            shutil.move(ASIDE_FILE, TOKEN_FILE)
        try:
            M.cleanup()
        finally:
            F.teardown()
            for f in M.SESSIONS:
                try:
                    os.remove(f)
                except OSError:
                    pass
    bad = [k for k, v in R.rows.items() if v['verdict'] != 'PASSED']
    print('\n%d cases, %d not passed' % (len(R.rows), len(bad)))
    sys.exit(1 if bad else 0)


if __name__ == '__main__':
    main()
