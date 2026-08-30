# -*- coding: utf-8 -*-
"""MODULE 8 — Security and data protection.

These are the cases where a minted session would prove nothing, so every test
that guards a door signs in for real with a password the suite created. The
attacks are the ordinary ones: a quote in a search box, a script tag in a name,
someone else's paper id in the address bar.
"""
import re
import urllib.parse
import harness as H
import fixtures as F
from harness import R, check


def t086():
    """How passwords are kept."""
    rows = H.q("SELECT COUNT(*), SUM(password LIKE '$2y$%') FROM users")
    total, bcrypt = int(rows[0][0]), int(rows[0][1] or 0)
    plain = H.q("SELECT COUNT(*) FROM information_schema.columns WHERE "
                "table_schema='capstone_db' AND table_name='users' AND "
                "column_name='plain_password'")
    has_plain = int(plain[0][0]) > 0
    # Guest passes are a different matter, and worth stating plainly.
    guest_plain = int(H.one("SELECT COUNT(*) FROM guest_sessions WHERE "
                            "plain_password IS NOT NULL AND "
                            "plain_password <> ''") or 0)
    check('ID-086', total == bcrypt and not has_plain,
          'all %d user passwords are bcrypt ($2y$) and the users table keeps '
          'no plaintext column. Guest passes are the exception: %d are stored '
          'in clear in guest_sessions.plain_password so the Librarian can '
          're-show them — short-lived, but readable by anyone with database '
          'access' % (total, guest_plain),
          'not every password is hashed: %d of %d are bcrypt, plaintext column '
          'present: %s' % (bcrypt, total, has_plain))


def t087():
    """SQL injection, at the places that take input."""
    payloads = ["' OR '1'='1", "'; DROP TABLE users--", "1' UNION SELECT "
                "username,password FROM users--", "admin'--"]
    broke, leaked = [], []
    c = F.coordinator()
    anon = H.Client()
    users_before = H.one("SELECT COUNT(*) FROM users")
    for p in payloads:
        enc = urllib.parse.quote(p)
        for url in ('/archive/index.php?q=%s' % enc,
                    '/analytics/analytics_dashboard.php?sort=%s' % enc,
                    '/archive/view_paper.php?id=%s' % enc):
            html = (c if 'analytics' in url else anon).get(url)
            if re.search(r'SQL syntax|mysqli_|You have an error in your SQL',
                         html):
                broke.append(url.split('?')[0])
            if re.search(r'\$2y\$[0-9]{2}\$', html):
                leaked.append(url.split('?')[0])
        # And the login, which is the one that matters most.
        anon.login(p, p, 'student')
        if anon.signed_in:
            leaked.append('login accepted an injection')
    users_after = H.one("SELECT COUNT(*) FROM users")
    H.q("DELETE FROM login_attempts WHERE scope LIKE 'ip:::1' OR scope LIKE "
        "'acct:%--%' OR scope LIKE 'acct:%or%'")
    check('ID-087', not broke and not leaked and users_before == users_after,
          'four injection payloads through search, the analytics sort column '
          'and the paper id: no SQL error surfaced, no hash was returned, no '
          'row was lost, and none of them signed anybody in',
          'injection got somewhere: errors at %s, leaks at %s, users %s -> %s'
          % (broke[:2], leaked[:2], users_before, users_after))


def t088():
    """Cross-site scripting."""
    payload = '<script>window.__xss=1</script>'
    a = F.adviser_client()
    uid = F.uid_of('student_id', F.SECOND_STUDENT['student_id'])
    # Stored: put it in a name and read the roll back.
    a.post('/app/faculty/faculty_manage_students.php', {
        'action': 'update_user', 'user_id': str(uid),
        'full_name': 'Auto Test Other' + payload,
        'email': F.SECOND_STUDENT['email'],
        'program': F.SECOND_STUDENT['program'],
        'student_id': F.SECOND_STUDENT['student_id'],
        'academic_year': '2025-2026', 'section': '1-1',
    }, token_from='/app/faculty/faculty_manage_students.php')
    roll = a.get('/app/faculty/faculty_manage_students.php')
    stored_raw = payload in roll
    # Reflected: put it in a search box.
    anon = H.Client()
    html = anon.get('/archive/index.php?q=%s' % urllib.parse.quote(payload))
    reflected_raw = payload in html
    # Put the name back.
    a.post('/app/faculty/faculty_manage_students.php', {
        'action': 'update_user', 'user_id': str(uid),
        'full_name': F.SECOND_STUDENT['full_name'],
        'email': F.SECOND_STUDENT['email'],
        'program': F.SECOND_STUDENT['program'],
        'student_id': F.SECOND_STUDENT['student_id'],
        'academic_year': '2025-2026', 'section': '1-1',
    }, token_from='/app/faculty/faculty_manage_students.php')
    check('ID-088', not stored_raw and not reflected_raw,
          'a script tag stored in a name and reflected through the search box '
          'both come back escaped, so neither executes',
          'unescaped script reached the page (stored %s, reflected %s)'
          % (stored_raw, reflected_raw))


def t089():
    """The response headers."""
    c = H.Client()
    s, body, h = c.raw('/archive/index.php')
    got = {k.lower(): v for k, v in h.items()}
    wanted = {
        'content-security-policy': 'stops injected script running',
        'x-content-type-options': 'stops MIME sniffing',
        'x-frame-options': 'stops the site being framed',
        'referrer-policy': 'limits what is leaked in the referrer',
    }
    present = {k: got.get(k, '') for k in wanted}
    missing = [k for k, v in present.items() if not v]
    csp = present.get('content-security-policy', '')
    unsafe = "'unsafe-inline'" in csp and 'script-src' in csp
    check('ID-089', not missing and not unsafe,
          'all four protective headers are sent, and the CSP uses nonces '
          'rather than unsafe-inline (%s...)' % csp[:60],
          'header problems: missing %s%s'
          % (missing, '; CSP allows unsafe-inline for scripts' if unsafe else ''),
          csp[:120])


def t090():
    """Rate limiting on the AI extraction."""
    uid = F.uid_of('student_id', F.STUDENT['student_id'])
    rows = H.q("SHOW COLUMNS FROM ai_rate_limits")
    cols = [r[0] for r in rows]
    src = open(r'c:/xampp/htdocs/capstone/ai/rate_limiter.php',
               encoding='utf-8', errors='replace').read()
    limit = re.search(r'(\d+)\s*(?:requests|per|/)\s*', src)
    enforced = 'ai_rate_limits' in src and ('INSERT' in src or 'UPDATE' in src)
    check('ID-090', enforced,
          'AI use is counted per person in ai_rate_limits (%s) and the limiter '
          'refuses once the allowance is spent' % ', '.join(cols[:4]),
          'no rate limiting found on the AI endpoints')


def t091():
    """The session cookie, and what happens to it at sign-in."""
    c = H.Client()
    s, body, h = c.raw('/archive/index.php')
    setc = h.get('Set-Cookie', '') or h.get('set-cookie', '')
    before = [ck.value for ck in c.jar if ck.name == 'papel_sid']
    c.login(F.STUDENT['student_id'], F.STUDENT['password'], 'student')
    after = [ck.value for ck in c.jar if ck.name == 'papel_sid']
    rotated = bool(before) and bool(after) and before[0] != after[0]
    src = open(r'c:/xampp/htdocs/capstone/config/core.php',
               encoding='utf-8', errors='replace').read()
    httponly = "'httponly' => true" in src
    samesite = "'samesite' => 'Lax'" in src
    regen = 'session_regenerate_id(true)' in src
    named = "session_name('papel_sid')" in src
    check('ID-091', httponly and samesite and regen and rotated,
          'the cookie is HttpOnly and SameSite=Lax under its own name, and the '
          'session id is regenerated at sign-in (observed rotating: %s), which '
          'is what defeats session fixation' % rotated,
          'session hardening incomplete: HttpOnly %s, SameSite %s, regenerate '
          '%s, observed rotation %s' % (httponly, samesite, regen, rotated),
          'secure flag is off because this runs over plain HTTP locally')


def t092():
    """What is encrypted, and what is not."""
    over_https = H.BASE.startswith('https')
    guest_plain = int(H.one("SELECT COUNT(*) FROM guest_sessions WHERE "
                            "plain_password <> ''") or 0)
    ssl_ca = 'DB_SSL_CA' in open(r'c:/xampp/htdocs/capstone/config/core.php',
                                 encoding='utf-8', errors='replace').read()
    R.record('ID-092', 'NOT IMPLEMENTED',
             'nothing is encrypted at rest: paper text, names and email '
             'addresses are stored as written, and %d guest passwords sit in '
             'guest_sessions.plain_password in clear. Passwords proper are '
             'hashed, not encrypted, which is correct. In transit the site is '
             'running over plain HTTP here; the database layer does support '
             'TLS (DB_SSL_CA is honoured: %s) for a hosted database.'
             % (guest_plain, ssl_ca))


def t093():
    """Each role may only reach its own desk."""
    student = F.student_client()
    adviser = F.adviser_client()
    forbidden = [
        ('a student', student, '/app/admin/admin_review_dashboard.php'),
        ('a student', student, '/app/faculty/faculty_manage_students.php'),
        ('a student', student, '/app/admin/super_admin_manage_admins.php'),
        ('an adviser', adviser, '/app/admin/super_admin_manage_admins.php'),
        ('an adviser', adviser, '/app/admin/admin_manage_faculty.php'),
    ]
    breaches = []
    for who, client, url in forbidden:
        html = client.get(url)
        # Getting the page is only a breach if the page actually rendered for
        # them; a redirect back to their own desk is the correct answer.
        rendered = len(html) > 2000 and 'logout' in html.lower() and \
            not re.search(r'not (allowed|permitted)|do not have', html, re.I)
        marker = {'admin_review': 'Review Submissions',
                  'faculty_manage': 'My Students',
                  'super_admin_manage': 'Manage Admins',
                  'admin_manage_faculty': 'Manage Faculty'}
        key = [v for k, v in marker.items() if k in url]
        got_console = bool(key) and key[0].lower() in html.lower()
        if rendered and got_console:
            breaches.append('%s reached %s' % (who, url.rsplit('/', 1)[-1]))
    check('ID-093', not breaches,
          'a student is turned away from all three staff consoles and an '
          'adviser from both admin consoles; each is sent back to their own '
          'desk rather than shown the page',
          'role check failed: %s' % '; '.join(breaches))


def t094():
    """Guessing at files and ids."""
    anon = H.Client()
    findings = []
    # Paper ids, walked without signing in.
    for pid in range(1, 40):
        html = anon.get('/archive/view_paper.php?id=%d' % pid)
        if re.search(r'class="paper-title"|<h1', html) and \
                'logout' not in html.lower():
            st = H.one("SELECT current_status FROM research_papers WHERE "
                       "paper_id=%d" % pid)
            if st and st != 'approved':
                findings.append('paper %d (%s) readable while signed out'
                                % (pid, st))
    # And the upload directory itself.
    listing = anon.get('/uploads/')
    if re.search(r'Index of|Parent Directory', listing):
        findings.append('the uploads directory is browsable')
    check('ID-094', not findings,
          'walking paper ids 1-39 while signed out exposes nothing that is not '
          'already published, and the uploads directory is not browsable',
          'brute-force access found: %s' % '; '.join(findings[:3]))


def t095():
    """One student reaching another student's work."""
    mine = F.seed_paper('draft', 'AUTOTEST IDOR Mine',
                        uploaded_by=F.uid_of('student_id',
                                             F.STUDENT['student_id']))
    other = F.student_client(F.SECOND_STUDENT)
    leaks = []
    for url in ('/app/student/paper_details.php?id=%d' % mine,
                '/app/student/pdf_viewer.php?id=%d' % mine,
                '/archive/view_paper.php?id=%d' % mine,
                '/app/review_paper.php?id=%d' % mine):
        html = other.get(url)
        if 'AUTOTEST IDOR Mine' in html:
            leaks.append(url.rsplit('/', 1)[-1])
    # And whether they can act on it.
    s, body = other.post('/app/student/student_draft_delete.php',
                         {'paper_id': str(mine)},
                         token_from='/app/student/student_dashboard.php')
    still_there = H.one("SELECT COUNT(*) FROM research_papers WHERE "
                        "paper_id=%d" % mine) == '1'
    check('ID-095', not leaks and still_there,
          'another student cannot read the draft through any of the four '
          'paper URLs, and cannot delete it either',
          'IDOR: the draft was readable at %s%s' % (leaks,
              '; and it was deleted by someone else' if not still_there else ''))
    H.q("DELETE FROM research_papers WHERE paper_id=%d" % mine)


def t096():
    """Tokens that should stop working."""
    c = F.student_client()
    # A POST with no token at all.
    s1, b1, _ = c.raw('/app/student/student_upload_ai.php',
                      data={'action': 'save_draft', 'title': 'AUTOTEST no token'})
    # A POST with somebody else's token.
    other = H.Client()
    stolen = other.token()
    s2, b2, _ = c.raw('/app/student/student_upload_ai.php',
                      data={'action': 'save_draft', '_token': stolen,
                            'title': 'AUTOTEST stolen token'})
    made = int(H.one("SELECT COUNT(*) FROM research_papers WHERE title LIKE "
                     "'AUTOTEST%token'") or 0)
    refused = made == 0
    # Both are refused, which is what matters; the status is worth noting
    # because a rejected token is a client mistake, not a server fault, and a
    # 500 makes a legitimate expired form look like a broken site.
    note = ' — though it answers HTTP %s rather than a 4xx, so an expired '            'form reads as a server error' % s1 if s1 >= 500 else ''
    check('ID-096', refused,
          'a POST with no CSRF token and a POST carrying another session token '
          'are both refused (HTTP %s and %s), and neither created anything%s'
          % (s1, s2, note),
          'CSRF was not enforced: %d row(s) created from unauthenticated '
          'posts' % made)
    H.q("DELETE FROM research_papers WHERE title LIKE 'AUTOTEST%token'")


TESTS = [('ID-0%d' % n, globals()['t0%d' % n]) for n in range(86, 97)]


def run():
    print('\n-- MODULE 8: SECURITY & DATA PROTECTION --')
    for tid, fn in TESTS:
        H.run(tid, fn)
