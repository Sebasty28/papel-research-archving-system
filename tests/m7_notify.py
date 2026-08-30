# -*- coding: utf-8 -*-
"""MODULE 7 — Notifications and email.

Every address used here is @example.com, which cannot receive mail, so the
tests prove that the site tried to send and handled the outcome without ever
reaching a real inbox.
"""
import re
import subprocess
import harness as H
import fixtures as F
from harness import R, check

FACULTY_DESK = '/app/faculty/faculty_review_dashboard.php'
ADMIN_DESK = '/app/admin/admin_review_dashboard.php'
GUESTS = '/app/librarian/librarian_manage_guests.php'


# Rebuilding the tables during the database recovery restarted AUTO_INCREMENT
# inside a gap, so a newly inserted notification can carry a LOWER id than one
# written weeks ago. Anything that wants "the latest" has to order by the
# timestamp; ordering by id quietly returns an old row.
def notes_for(uid):
    return int(H.one("SELECT COUNT(*) FROM notifications WHERE user_id=%s"
                     % uid) or 0)


def try_send(to, subject, body):
    """Ask the site's own mailer to send, and report what happened."""
    php = ('<?php require_once "c:/xampp/htdocs/capstone/config/core.php"; '
           'try { $ok = send_email($argv[1], $argv[2], $argv[3]); '
           'echo $ok ? "SENT" : "REFUSED"; } '
           'catch (Throwable $e) { echo "THREW: ", substr($e->getMessage(),0,120); }')
    import os
    p = os.path.join(H.HERE, '_mail.php')
    open(p, 'w', encoding='utf-8').write(php)
    r = subprocess.run([H.PHP, p, to, subject, body], capture_output=True,
                       timeout=180)
    return (r.stdout + r.stderr).decode('utf-8', 'replace').strip()[:200]


def t080():
    """The OTP email — the step no longer runs, but the mailer still must."""
    out = try_send('autotest.otp@example.com', 'AUTOTEST delivery check',
                   'Sent by the automated suite to an unreachable address.')
    works = 'SENT' in out
    R.record('ID-080', 'NOT IMPLEMENTED' if works else 'FAILED',
             'superseded: no OTP is sent, because the OTP step is commented '
             'out in login.php. The mail path underneath it does work — a '
             'test message was accepted for delivery (%s).' % out[:40]
             if works else
             'the mailer itself failed: %s' % out)


def t081():
    """Telling people a paper was approved."""
    pid = F.seed_paper('pending_admin', 'AUTOTEST Approval Notice')
    student = F.uid_of('student_id', F.STUDENT['student_id'])
    before = notes_for(student)
    c = F.coordinator()
    c.post(ADMIN_DESK, {'paper_id': str(pid), 'action': 'approve',
                        'feedback': 'AUTOTEST approved.'},
           token_from=ADMIN_DESK)
    after = notes_for(student)
    latest = H.one("SELECT message FROM notifications WHERE user_id=%s ORDER BY "
                   "created_at DESC, notification_id DESC LIMIT 1" % student) or ''
    check('ID-081', after > before,
          'the student is notified on approval: "%s" — reported not '
          'implemented in the tracker' % latest[:70],
          'no notification was raised when the paper was approved (%d -> %d)'
          % (before, after))
    H.q("DELETE FROM approval_workflow WHERE paper_id=%d" % pid)
    H.q("DELETE FROM research_papers WHERE paper_id=%d" % pid)


def t082():
    """And when it is sent back."""
    pid = F.seed_paper('pending_faculty', 'AUTOTEST Rejection Notice')
    student = F.uid_of('student_id', F.STUDENT['student_id'])
    before = notes_for(student)
    a = F.adviser_client()
    a.post(FACULTY_DESK, {'paper_id': str(pid), 'action': 'decline',
                          'feedback': 'AUTOTEST returned for revision.'},
           token_from=FACULTY_DESK)
    after = notes_for(student)
    latest = H.one("SELECT message, notification_type FROM notifications WHERE "
                   "user_id=%s ORDER BY created_at DESC, notification_id DESC LIMIT 1" % student)
    kind = H.one("SELECT notification_type FROM notifications WHERE user_id=%s "
                 "ORDER BY created_at DESC, notification_id DESC LIMIT 1" % student) or ''
    check('ID-082', after > before and kind == 'decline',
          'the student is notified when a paper is returned, typed as '
          '"%s" — reported not implemented' % kind,
          'no rejection notification (%d -> %d, type %r)'
          % (before, after, kind))
    H.q("DELETE FROM approval_workflow WHERE paper_id=%d" % pid)
    H.q("DELETE FROM research_papers WHERE paper_id=%d" % pid)


def t083():
    """A revision request carries the reason with it."""
    pid = F.seed_paper('pending_faculty', 'AUTOTEST Revision Email')
    student = F.uid_of('student_id', F.STUDENT['student_id'])
    reason = 'AUTOTEST please expand the methodology section.'
    a = F.adviser_client()
    a.post(FACULTY_DESK, {'paper_id': str(pid), 'action': 'decline',
                          'feedback': reason}, token_from=FACULTY_DESK)
    msg = H.one("SELECT message FROM notifications WHERE user_id=%s ORDER BY "
                "created_at DESC, notification_id DESC LIMIT 1" % student) or ''
    carries = 'methodology' in msg
    check('ID-083', carries,
          'the revision request reaches the student with the reviewer reason '
          'included: "%s"' % msg[:80],
          'the reason did not reach the student (message: %r)' % msg[:80])
    H.q("DELETE FROM approval_workflow WHERE paper_id=%d" % pid)
    H.q("DELETE FROM research_papers WHERE paper_id=%d" % pid)


def t084():
    """Guest credentials are emailed when a pass is issued."""
    import m6_archive as M6
    lib = H.as_role('librarian')
    before = set(r[0] for r in H.q("SELECT guest_id FROM guest_sessions"))
    s, body = lib.post(GUESTS, {'action': 'create_guest', 'duration': '4',
                                'email': 'autotest.guest@example.com'},
                       token_from=GUESTS)
    rows = H.q("SELECT guest_id FROM guest_sessions")
    fresh = [r[0] for r in rows if r[0] not in before]
    for g in fresh:
        F.TRACKED_GUESTS.append(g)
    sent = bool(re.search(r'created and sent to|credentials (have been )?sent',
                          body, re.I))
    shown = bool(re.search(r'could not be (sent|emailed)|shown below', body, re.I))
    check('ID-084', bool(fresh) and (sent or shown),
          'the pass is created and its credentials emailed to the address '
          'given; if the mail fails they are shown once instead',
          'guest credentials were not emailed or shown (created %s)'
          % bool(fresh))


def t085():
    """What happens when mail cannot be delivered."""
    # A malformed address, not merely an undeliverable one: a relay accepts
    # anything that parses and bounces it later, so only a bad address proves
    # the failure is handled here rather than silently swallowed.
    bad = try_send('not-an-address', 'AUTOTEST malformed',
                   'This should never leave the building.')
    unreachable = try_send(
        'nobody@invalid-domain-that-cannot-exist-autotest.test',
        'AUTOTEST bounce', 'This domain cannot resolve.')
    handled = ('THREW' in bad or 'REFUSED' in bad)
    crashed = 'Fatal error' in bad or 'Uncaught' in bad
    check('ID-085', handled and not crashed,
          'a malformed address is caught and reported by the mailer (%s) '
          'rather than crashing the page; an address that merely cannot be '
          'delivered is accepted by the relay and bounces later (%s), which '
          'is normal SMTP behaviour'
          % (bad.split(':')[0][:24], unreachable[:16]),
          'a bad address was not handled: %s' % bad[:90])


TESTS = [('ID-0%d' % n, globals()['t0%d' % n]) for n in range(80, 86)]


def run():
    print('\n-- MODULE 7: NOTIFICATIONS & EMAIL SYSTEM --')
    for tid, fn in TESTS:
        H.run(tid, fn)
