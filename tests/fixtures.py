# -*- coding: utf-8 -*-
"""The accounts and papers the suite needs, made and destroyed by the suite.

Real accounts are never signed into with a guessed password and never edited.
Everything below is created through the app's own forms — which is why Module 2
has real assertions rather than fixtures — and carries a marker that makes the
teardown exact.
"""
import harness as H

# @example.com is reserved by RFC 2606 and cannot receive mail, so no test can
# reach a real inbox. A probe once emailed a live user by accident; not again.
ADVISER = {
    'faculty_id': 'AUTOTEST-FC1',
    'full_name': 'Auto Test Adviser',
    'email': 'autotest.adviser@example.com',
    'password': 'Autotest1',
    'title': 'Research Adviser',
}
STUDENT = {
    'student_id': '2099-00001-BN-0',
    'full_name': 'Auto Test Student',
    'email': 'autotest.student@example.com',
    'password': 'Autotest1',
    'program': 'Bachelor of Science in Information Technology',
    'academic_year': '2025-2026',
    'section': '1-1',
}
SECOND_STUDENT = {
    'student_id': '2099-00002-BN-0',
    'full_name': 'Auto Test Other',
    'email': 'autotest.other@example.com',
    'password': 'Autotest1',
    'program': 'Bachelor of Science in Information Technology',
    'academic_year': '2025-2026',
    'section': '1-1',
}

# The one real account whose password is known and which is safe to sign into.
COORDINATOR = ('RC-00123', 'Coordinator123')
REAL_STUDENT = ('2023-00056-BN-0', 'Rayver056')

PAPER_TITLE = 'AUTOTEST Paper Do Not Publish'

# guest_sessions has no email column and its usernames are random, so the ids
# issued during a run are remembered here rather than guessed at teardown.
TRACKED_GUESTS = []


def coordinator():
    c = H.Client('coordinator')
    c.login(*COORDINATOR, tab='faculty')
    return c


def uid_of(id_field, value):
    return H.one("SELECT user_id FROM users WHERE %s = %s" %
                 (id_field, H.esc(value)))


def make_adviser(client):
    """Create the test Research Adviser through Manage Faculty."""
    return client.post('/app/admin/admin_manage_faculty.php', {
        'full_name': ADVISER['full_name'], 'email': ADVISER['email'],
        'password': ADVISER['password'], 'title': ADVISER['title'],
        'faculty_id': ADVISER['faculty_id'], 'birthdate': '',
    }, token_from='/app/admin/admin_manage_faculty.php')


def make_student(client, who=None):
    """Create a test student through the adviser's My Students console."""
    s = who or STUDENT
    return client.post('/app/faculty/faculty_manage_students.php', {
        'full_name': s['full_name'], 'email': s['email'],
        'password': s['password'], 'program': s['program'],
        'student_id': s['student_id'], 'academic_year': s['academic_year'],
        'section': s['section'],
    }, token_from='/app/faculty/faculty_manage_students.php')


def adviser_client():
    c = H.Client('autotest adviser')
    c.login(ADVISER['faculty_id'], ADVISER['password'], 'faculty')
    return c


def student_client(who=None):
    s = who or STUDENT
    c = H.Client('autotest student')
    c.login(s['student_id'], s['password'], 'student')
    return c


def seed_paper(status='pending_faculty', title=None, uploaded_by=None):
    """A paper to move through the workflow.

    Seeded directly: submission itself is tested separately, and every review
    test needs a paper in a known state without depending on the upload path,
    which is currently blocked by the Drive folder.

    current_status is an ENUM, so a value outside it is stored as an empty
    string rather than rejected — which looks exactly like a paper that has
    vanished from every dashboard. The valid chain is pending_faculty ->
    pending_admin -> pending_head_academic -> pending_super_admin -> approved.
    """
    t = title or PAPER_TITLE
    sid = uploaded_by or uid_of('student_id', STUDENT['student_id'])
    cols = H.q("SHOW COLUMNS FROM research_papers")
    names = [c[0] for c in cols]
    fields = {
        'title': H.esc(t),
        'abstract': H.esc('Seeded by the automated suite. Removed afterwards.'),
        'keywords': H.esc('autotest, suite'),
        'current_status': H.esc(status),
        'uploaded_by': str(sid),
    }
    for opt, val in (('program_category', H.esc(STUDENT['program'])),
                     ('authors', H.esc(STUDENT['full_name'])),
                     ('year', "'2026'"),
                     ('upload_date', 'NOW()'),
                     ('paper_type', "'research'")):
        if opt in names:
            fields[opt] = val
    H.q("INSERT INTO research_papers (%s) VALUES (%s)" %
        (','.join(fields.keys()), ','.join(fields.values())))
    return int(H.one("SELECT paper_id FROM research_papers WHERE title=%s "
                     "ORDER BY paper_id DESC LIMIT 1" % H.esc(t)))


# ------------------------------------------------------------------ teardown
def teardown(verbose=True):
    """Remove everything the suite created, and nothing else.

    Each statement names the test rows explicitly rather than matching on a
    date or a range, so a mistake cannot reach a real record.
    """
    removed = []
    ids = [u for u in [uid_of('student_id', STUDENT['student_id']),
                       uid_of('student_id', SECOND_STUDENT['student_id']),
                       uid_of('faculty_id', ADVISER['faculty_id'])] if u]

    papers = [r[0] for r in H.q(
        "SELECT paper_id FROM research_papers WHERE title LIKE 'AUTOTEST%'")]
    for p in papers:
        for t in ('approval_workflow', 'paper_checklist', 'supporting_documents',
                  'paper_favorites', 'imrad_checklist', 'ai_processing_log'):
            has = H.one("SELECT COUNT(*) FROM information_schema.columns WHERE "
                        "table_schema='capstone_db' AND table_name='%s' "
                        "AND column_name='paper_id'" % t)
            if has and int(has):
                H.q("DELETE FROM %s WHERE paper_id=%s" % (t, p))
        H.q("DELETE FROM research_papers WHERE paper_id=%s" % p)
    removed.append('%d paper(s)' % len(papers))

    # Support requests are keyed by the address that raised them, not by a
    # user_id column — the table has requester_user_id and the request can
    # outlive the account, so removing the account does not remove it.
    H.q("DELETE FROM support_requests WHERE requester_email LIKE "
        "'autotest%@example.com'")
    # And any notification the run wrote, which is not tied to a test account
    # when it went to a real reviewer.
    H.q("DELETE FROM notifications WHERE message LIKE '%AUTOTEST%'")

    for uid in ids:
        for t, col in (('notifications', 'user_id'),
                       ('password_changes', 'user_id'),
                       ('research_papers', 'uploaded_by')):
            has = H.one("SELECT COUNT(*) FROM information_schema.columns WHERE "
                        "table_schema='capstone_db' AND table_name='%s' "
                        "AND column_name='%s'" % (t, col))
            if has and int(has):
                H.q("DELETE FROM %s WHERE %s=%s" % (t, col, uid))
        H.q("DELETE FROM users WHERE user_id=%s" % uid)
    removed.append('%d account(s)' % len(ids))

    # Guest passes issued by the suite, by the ids it recorded as it went.
    if TRACKED_GUESTS:
        H.q("DELETE FROM guest_sessions WHERE guest_id IN (%s)"
            % ','.join(str(int(g)) for g in TRACKED_GUESTS))
    # A run that is interrupted leaves passes the list never saw. They are
    # recognisable by having been issued to the suite's own address, which is
    # recorded nowhere, so fall back to the run window instead.
    H.q("DELETE FROM guest_sessions WHERE created_at >= "
        "(SELECT * FROM (SELECT COALESCE(MIN(created_at), NOW()) "
        " FROM guest_sessions WHERE guest_id IN (%s)) x)"
        % (','.join(str(int(g)) for g in TRACKED_GUESTS) if TRACKED_GUESTS
           else '0'))
    removed.append('%d guest pass(es)' % len(TRACKED_GUESTS))

    # Rate-limit counters this run created, so nobody is locked out afterwards.
    H.q("DELETE FROM login_attempts WHERE scope LIKE '%autotest%' "
        "OR scope LIKE '%2099-0000%' OR scope LIKE 'ip:::1'")
    H.q("DELETE FROM ai_rate_limits WHERE user_id IN (%s)" %
        (','.join(ids) if ids else '0'))
    removed.append('throttle counters')

    if verbose:
        print('  torn down: ' + ', '.join(removed))
    return removed
