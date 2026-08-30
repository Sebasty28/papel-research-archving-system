# -*- coding: utf-8 -*-
"""MODULE 3 — Role access through the user-tailored dashboard.

Each role is asked for its own desk and has to get a working page with its own
name on it. Where the password is known the sign-in is real; the three accounts
whose passwords are not ours to know are reached with a minted session, which
proves the page works for that role but says nothing about the door — that is
Module 8's job, and it uses real sign-ins.
"""
import re
import harness as H
import fixtures as F
from harness import R, check

HOMES = {
    'student':     '/app/student/student_dashboard.php',
    'faculty':     '/app/faculty/faculty_review_dashboard.php',
    'coordinator': '/app/admin/admin_review_dashboard.php',
    'hap':         '/app/faculty/head_review_dashboard.php',
    'director':    '/app/admin/super_admin_review_dashboard.php',
    'librarian':   '/app/librarian/librarian_manage_guests.php',
}


def works(html):
    """A real page for a signed-in person, not a redirect or an error card."""
    if len(html) < 2000:
        return False, 'page too small (%d bytes)' % len(html)
    if 'logout' not in html.lower():
        return False, 'not signed in'
    m = re.search(r'ERR-[0-9A-F]{8}', html)
    if m:
        return False, 'error card %s' % m.group(0)
    if re.search(r'Fatal error|Warning:|Notice:', html):
        return False, 'PHP notice on the page'
    return True, '%d KB' % (len(html) // 1024)


def desk(tid, who, client, label, must_contain=()):
    html = client.get(HOMES[who])
    ok, why = works(html)
    missing = [w for w in must_contain if w.lower() not in html.lower()]
    check(tid, ok and not missing,
          '%s reaches %s (%s)' % (label, HOMES[who].rsplit('/', 1)[-1], why),
          '%s could not use their dashboard: %s%s'
          % (label, why, '; missing %s' % missing if missing else ''),
          HOMES[who])


def t017():
    desk('ID-017', 'student', F.student_client(), 'the student',
         ('upload', 'submission'))


def t018():
    desk('ID-018', 'faculty', F.adviser_client(), 'the Research Adviser',
         ('review',))


def t019():
    desk('ID-019', 'coordinator', F.coordinator(), 'the Research Coordinator',
         ('review',))


def t020():
    """The Librarian console — reported not implemented in the tracker."""
    c = H.as_role('librarian')
    html = c.get(HOMES['librarian'])
    ok, why = works(html)
    manages = 'guest' in html.lower()
    check('ID-020', ok and manages,
          'the Librarian has a working console for guest passes (%s) — this '
          'was reported not implemented' % why,
          'the Librarian console did not load: %s' % why)


def t021():
    """Two kinds of record mean Head of Academic Programs; both land here."""
    c = H.as_role('hap')
    html = c.get(HOMES['hap'])
    ok, why = works(html)
    # The bug this guards against: a HAP served the Coordinator's navbar.
    wrong_desk = 'Research Coordinator' in html and 'Head of Academic' not in html
    check('ID-021', ok and not wrong_desk,
          'the Head of Academic Programs reaches their own desk (%s) with the '
          'right title, not the Coordinator one' % why,
          'HAP dashboard wrong: %s%s' % (why, ' — showing the Coordinator title'
                                         if wrong_desk else ''))


def t022():
    c = H.as_role('director')
    html = c.get(HOMES['director'])
    ok, why = works(html)
    check('ID-022', ok, 'the Director reaches the final-approval desk (%s)' % why,
          'Director dashboard did not load: %s' % why)


def t023():
    """Analytics has to draw real figures, not empty tiles."""
    c = F.coordinator()
    html = c.get('/analytics/analytics_dashboard.php')
    ok, why = works(html)
    has_chart = 'chart' in html.lower()
    nums = re.findall(r'>\s*(\d{1,6})\s*<', html)
    real = sum(1 for n in nums if int(n) > 0) > 3
    check('ID-023', ok and has_chart and real,
          'analytics renders with charts and populated figures (%s)' % why,
          'analytics did not display properly: %s (charts %s, figures %s)'
          % (why, has_chart, real))


def t024():
    """Logout must actually end the session, not just redirect."""
    c = F.student_client()
    before = c.signed_in_now()
    c.get('/app/auth/logout.php')
    after = c.signed_in_now()
    # And the protected page must refuse afterwards.
    html = c.get(HOMES['student'])
    shut_out = 'logout' not in html.lower()
    check('ID-024', before and not after and shut_out,
          'logout ends the session and the dashboard is no longer reachable',
          'logout did not clear the session (before %s, after %s, dashboard '
          'still open %s)' % (before, after, not shut_out))


TESTS = [('ID-0%d' % n, globals()['t0%d' % n]) for n in range(17, 25)]


def run():
    print('\n-- MODULE 3: ROLE ACCESS THROUGH USER TAILORED DASHBOARD --')
    for tid, fn in TESTS:
        H.run(tid, fn)
