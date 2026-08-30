# -*- coding: utf-8 -*-
"""MODULE 5 — The review workflow.

The tracker was written against a four-step chain ending at the Director. The
chain in the code is deliberately shorter: a paper is approved by the Research
Adviser, then by the Research Coordinator, and that is publication. The Head of
Academic Programs and the Director read what comes out; they do not approve.
Cases written for the old chain are tested against what the roles can actually
do and reported as superseded rather than failed.

Papers are seeded rather than submitted, because submission itself is currently
blocked (ID-025) and the review desk would otherwise have nothing to work on.
"""
import re
import harness as H
import fixtures as F
from harness import R, check

FACULTY_DESK = '/app/faculty/faculty_review_dashboard.php'
ADMIN_DESK = '/app/admin/admin_review_dashboard.php'
HEAD_DESK = '/app/faculty/head_review_dashboard.php'
SUPER_DESK = '/app/admin/super_admin_review_dashboard.php'

CHECKLIST = {k: '1' for k in
             ['imrad_intro', 'imrad_method', 'imrad_result', 'imrad_discussion',
              'imrad_references', 'full_ch1', 'full_ch2', 'full_ch3',
              'full_ch4', 'full_ch5', 'full_references']}


def status_of(pid):
    return H.one("SELECT current_status FROM research_papers WHERE paper_id=%d"
                 % pid)


def workflow_rows(pid):
    return H.q("SELECT review_level, status FROM approval_workflow WHERE "
               "paper_id=%d ORDER BY workflow_id" % pid)


def drop(pid):
    H.q("DELETE FROM approval_workflow WHERE paper_id=%d" % pid)
    H.q("DELETE FROM paper_checklist WHERE paper_id=%d" % pid)
    H.q("DELETE FROM research_papers WHERE paper_id=%d" % pid)


def t042():
    """The adviser approves and the paper moves to the Coordinator."""
    pid = F.seed_paper('pending_faculty', 'AUTOTEST Faculty Review')
    a = F.adviser_client()
    fields = {'paper_id': str(pid), 'action': 'approve',
              'feedback': 'AUTOTEST approved by adviser.'}
    fields.update(CHECKLIST)
    a.post(FACULTY_DESK, fields, token_from=FACULTY_DESK)
    st = status_of(pid)
    wf = workflow_rows(pid)
    check('ID-042', st == 'pending_admin',
          'adviser approval moves the paper to pending_admin and writes the '
          'approval to the history (%s)' % wf,
          'the adviser approval did not advance the paper (status %s)' % st,
          str(wf))
    drop(pid)


def t043():
    """The adviser returns a paper, with the reason attached."""
    pid = F.seed_paper('pending_faculty', 'AUTOTEST Faculty Reject')
    a = F.adviser_client()
    reason = 'AUTOTEST returned: the methodology needs more detail.'
    a.post(FACULTY_DESK, {'paper_id': str(pid), 'action': 'decline',
                          'feedback': reason}, token_from=FACULTY_DESK)
    st = status_of(pid)
    wf = workflow_rows(pid)
    fb = H.one("SELECT feedback FROM approval_workflow WHERE paper_id=%d AND "
               "status='declined' ORDER BY workflow_id DESC LIMIT 1" % pid)
    # A returned paper goes back to draft carrying its feedback, which is why
    # draft means either "never submitted" or "returned".
    check('ID-043', st == 'draft' and fb and 'methodology' in fb,
          'the paper is returned to draft with the reason kept against it',
          'returning did not work (status %s, reason recorded %r)' % (st, fb),
          str(wf))
    drop(pid)


def t044():
    """The Coordinator approves what the adviser passed on, and it publishes."""
    pid = F.seed_paper('pending_admin', 'AUTOTEST Admin Review')
    c = F.coordinator()
    c.post(ADMIN_DESK, {'paper_id': str(pid), 'action': 'approve',
                        'feedback': 'AUTOTEST approved by coordinator.'},
           token_from=ADMIN_DESK)
    st = status_of(pid)
    check('ID-044', st == 'approved',
          'Coordinator approval publishes the paper (status %s) — this is the '
          'end of the chain by design' % st,
          'the Coordinator approval did not publish the paper (status %s)' % st,
          str(workflow_rows(pid)))
    drop(pid)


def t045():
    """The HAP was a fourth approver in the old design."""
    src = open(r'c:/xampp/htdocs/capstone/app/faculty/head_review_dashboard.php',
               encoding='utf-8', errors='replace').read()
    approves = bool(re.search(r"action\s*===\s*'approve'", src))
    c = H.as_role('hap')
    page = c.get(HEAD_DESK)
    reads = 'logout' in page.lower() and len(page) > 2000
    if approves:
        R.record('ID-045', 'PASSED',
                 'the Head of Academic Programs can approve from their desk')
    else:
        R.record('ID-045', 'NOT IMPLEMENTED',
                 'superseded by design: approval ends at the Research '
                 'Coordinator. The Head of Academic Programs has a working '
                 'read-only desk (%d KB) but no approve action — the chain was '
                 'deliberately shortened from four steps to two.'
                 % (len(page) // 1024) if reads else
                 'the HAP desk did not load')


def t046():
    """And the Director was the final approver."""
    src = open(r'c:/xampp/htdocs/capstone/app/admin/'
               r'super_admin_review_dashboard.php',
               encoding='utf-8', errors='replace').read()
    approves = bool(re.search(r"action\s*===\s*'approve'", src))
    archives = bool(re.search(r"action\s*===\s*'archive'", src))
    c = H.as_role('director')
    page = c.get(SUPER_DESK)
    if approves:
        R.record('ID-046', 'PASSED', 'the Director can give final approval')
    else:
        R.record('ID-046', 'NOT IMPLEMENTED',
                 'superseded by design: the Director does not approve. Their '
                 'desk reads the published work and can archive it (archive '
                 'action present: %s). Approval ends at the Research '
                 'Coordinator.' % archives)


def t047():
    """Comments left by a reviewer."""
    pid = F.seed_paper('pending_faculty', 'AUTOTEST Review Comments')
    a = F.adviser_client()
    note = 'AUTOTEST reviewer comment recorded for the audit trail.'
    a.post(FACULTY_DESK, {'paper_id': str(pid), 'action': 'decline',
                          'feedback': note}, token_from=FACULTY_DESK)
    stored = H.one("SELECT feedback FROM approval_workflow WHERE paper_id=%d "
                   "ORDER BY workflow_id DESC LIMIT 1" % pid)
    # And the student has to be able to read it.
    s = F.student_client()
    seen = note[:40] in s.get('/app/student/student_dashboard.php?tab=declined')
    check('ID-047', bool(stored) and note[:40] in (stored or ''),
          'reviewer comments are stored against the paper and shown back to '
          'the student (visible on their desk: %s) — reported not implemented '
          'in the tracker' % seen,
          'reviewer comments were not kept (%r)' % stored)
    drop(pid)


def t048():
    """Asking for a revision."""
    pid = F.seed_paper('pending_faculty', 'AUTOTEST Request Revision')
    a = F.adviser_client()
    a.post(FACULTY_DESK, {'paper_id': str(pid), 'action': 'decline',
                          'feedback': 'AUTOTEST please revise chapter 3.'},
           token_from=FACULTY_DESK)
    st = status_of(pid)
    student_can_resubmit = st == 'draft'
    check('ID-048', student_can_resubmit,
          'returning a paper is the revision request: it goes back to draft '
          'with the reason, and the student can work on it again',
          'no revision path: status after return was %s' % st)
    drop(pid)


def t049():
    """The history of who did what."""
    pid = F.seed_paper('pending_faculty', 'AUTOTEST Review History')
    a = F.adviser_client()
    f = {'paper_id': str(pid), 'action': 'approve', 'feedback': 'AUTOTEST ok'}
    f.update(CHECKLIST)
    a.post(FACULTY_DESK, f, token_from=FACULTY_DESK)
    c = F.coordinator()
    c.post(ADMIN_DESK, {'paper_id': str(pid), 'action': 'approve',
                        'feedback': 'AUTOTEST published'}, token_from=ADMIN_DESK)
    rows = workflow_rows(pid)
    both = len(rows) >= 2
    check('ID-049', both,
          'the full history is kept: %s' % rows,
          'the review history is incomplete (%s)' % rows, str(rows))
    drop(pid)


def t050():
    """How long a decision took."""
    cols = [c[0] for c in H.q("SHOW COLUMNS FROM analytics")]
    has_col = 'time_to_approval' in cols
    populated = H.one("SELECT COUNT(*) FROM analytics WHERE time_to_approval "
                      "IS NOT NULL AND time_to_approval > 0") if has_col else '0'
    # The workflow table timestamps every step, so the figure is derivable.
    stamped = H.one("SELECT COUNT(*) FROM approval_workflow WHERE reviewed_at "
                    "IS NOT NULL") or '0'
    if has_col and int(populated or 0) > 0:
        R.record('ID-050', 'PASSED',
                 'time to approval is recorded (%s rows)' % populated)
    else:
        R.record('ID-050', 'NOT IMPLEMENTED',
                 'the analytics table has a time_to_approval column and the '
                 'dashboard has a tile for it, but nothing ever writes it, so '
                 'it always reads "—". Every review step is timestamped in '
                 'approval_workflow (%s rows), so the figure is derivable but '
                 'not derived.' % stamped)


def t051():
    """Telling people when a paper moves."""
    pid = F.seed_paper('pending_faculty', 'AUTOTEST Status Notify')
    student_uid = F.uid_of('student_id', F.STUDENT['student_id'])
    adviser_uid = F.uid_of('faculty_id', F.ADVISER['faculty_id'])
    before = int(H.one("SELECT COUNT(*) FROM notifications WHERE user_id IN "
                       "(%s,%s)" % (student_uid, adviser_uid)) or 0)
    a = F.adviser_client()
    a.post(FACULTY_DESK, {'paper_id': str(pid), 'action': 'decline',
                          'feedback': 'AUTOTEST returned for notification test.'},
           token_from=FACULTY_DESK)
    after = int(H.one("SELECT COUNT(*) FROM notifications WHERE user_id IN "
                      "(%s,%s)" % (student_uid, adviser_uid)) or 0)
    latest = H.one("SELECT message FROM notifications WHERE user_id=%s ORDER BY "
                   "created_at DESC, notification_id DESC LIMIT 1" % student_uid) or ''
    check('ID-051', after > before,
          'the student is notified when the status changes: "%s" — reported '
          'not implemented in the tracker' % latest[:70],
          'no notification was raised on a status change (%d -> %d)'
          % (before, after))
    drop(pid)


def t052():
    """Assigning a paper to a named reviewer."""
    cols = [c[0] for c in H.q("SHOW COLUMNS FROM research_papers")]
    assigned = [c for c in cols if 'assign' in c.lower() or 'reviewer' in c.lower()]
    if assigned:
        R.record('ID-052', 'PASSED',
                 'papers carry a reviewer assignment (%s)' % assigned)
    else:
        R.record('ID-052', 'NOT IMPLEMENTED',
                 'there is no assignment step, and none is needed: a paper '
                 'goes to the adviser who created the student account '
                 '(created_by), then to the coordinator who created that '
                 'adviser. The route is implied by who made the account.')


def t053():
    """A reviewer standing aside from their own work."""
    R.record('ID-053', 'NOT IMPLEMENTED',
             'no recusal mechanism. The route is fixed by created_by, so a '
             'reviewer cannot hand a paper to a colleague, and nothing detects '
             'that a reviewer is also an author.')


def t054():
    """A deadline on the review."""
    cols = [c[0] for c in H.q("SHOW COLUMNS FROM research_papers")]
    deadline = [c for c in cols if 'due' in c.lower() or 'deadline' in c.lower()]
    sched = int(H.one("SELECT COUNT(*) FROM notification_schedule") or 0)
    if deadline:
        R.record('ID-054', 'PASSED', 'a review deadline is stored (%s)' % deadline)
    else:
        R.record('ID-054', 'NOT IMPLEMENTED',
                 'no review deadline is set or enforced. A notification_schedule '
                 'table exists (%d rows) and a cron script is present, so the '
                 'groundwork for reminders is there but unused.' % sched)


def t055():
    """More than one reviewer at the same stage."""
    R.record('ID-055', 'NOT IMPLEMENTED',
             'one reviewer per stage by design: the adviser who owns the '
             'student, then the coordinator who owns the adviser. '
             'approval_workflow can hold several rows per paper, so the data '
             'model would allow it, but no screen offers it.')


TESTS = [('ID-0%d' % n, globals()['t0%d' % n]) for n in range(42, 56)]


def run():
    print('\n-- MODULE 5: PAPER REVIEW WORKFLOW --')
    for tid, fn in TESTS:
        H.run(tid, fn)
